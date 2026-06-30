<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi.js";
import { getRegistry, normalizeLanguage, isRtlLocale } from "../i18n";

// The single Vocabulary surface. One curated concept list (108 words) is the seed.
// A single language selector spans BOTH sources, so the worshipper never sees the
// seam: curated languages render straight from the concept's column (instant,
// canonical), while AI languages are generated into vocab_entries on demand and
// cached server-side. Favourites, history and AI "Explain" sit on top.
const { t, locale } = useI18n();
const props = defineProps({ authed: { type: Boolean, default: false } });

const concepts = ref([]);
const loading = ref(true);
const loadError = ref("");
const search = ref("");
const activeCategory = ref("All");

// Per-user state (only meaningful when authed). viewMode swaps the concept source.
const viewMode = ref("browse");        // 'browse' | 'favorite' | 'viewed'
const myItems = ref([]);               // rows for favorite/viewed views
const favoriteIds = ref(new Set());    // concept ids the user has starred
const explanation = ref(null);         // { status, text } for the open concept+lang

// Detail state. `selected` is a concept row; `entries` caches AI entries by language
// code (curated languages need no fetch — the word is already on the concept row).
const selected = ref(null);
const entries = ref({});            // { [aiCode]: { status, payload, word, ... } }
const pollTimers = ref({});

// Unified language list. Curated descriptors carry the `column` to read off the
// concept row; `code` is the learner code where one exists — present for every AI
// language and for the three curated columns that double as learner locales (Tedim,
// Burmese, English). Its presence gates AI "Explain" (the backend rejects langs
// outside VocabEntry::learnerLanguages — i.e. the Chin-only columns + Hebrew).
const CURATED = [
  { key: "zolai",   column: "zolai",   label: "Zolai (Tedim)", code: "td" },
  { key: "falam",   column: "falam",   label: "Falam" },
  { key: "hakha",   column: "hakha",   label: "Hakha" },
  { key: "matu",    column: "matu",    label: "Matu" },
  { key: "mizo",    column: "mizo",    label: "Mizo" },
  { key: "paite",   column: "paite",   label: "Paite" },
  { key: "sizang",  column: "sizang",  label: "Sizang" },
  { key: "burmese", column: "burmese", label: "Burmese", code: "my" },
  { key: "hebrew",  column: "hebrew",  label: "Hebrew", rtl: true },
  { key: "english", column: "english", label: "English", code: "en" },
];
// AI-only languages: registry learner locales with no curated column.
const AI_CODES = ["fr", "de", "es", "ja", "zh-CN", "ko", "hi", "ta", "th", "ar"];

const languages = computed(() => {
  const reg = getRegistry();
  const ai = AI_CODES
    .filter((code) => reg[code]) // only offer AI langs the backend registry knows
    .map((code) => ({ key: code, code, label: reg[code]?.native_name || code, source: "ai" }));
  return [...CURATED.map((c) => ({ ...c, source: "curated" })), ...ai];
});

// Default selection: the worshipper's locale mapped to its curated column when it is
// one (en→english, my→burmese, td→zolai), else the matching AI code, else English.
const LOCALE_TO_CURATED = { en: "english", my: "burmese", td: "zolai" };
const uiNorm = normalizeLanguage(locale.value);
const selectedLang = ref(
  LOCALE_TO_CURATED[uiNorm] || (AI_CODES.includes(uiNorm) ? uiNorm : "english"),
);

const currentLang = computed(
  () => languages.value.find((l) => l.key === selectedLang.value) || languages.value[0],
);
const canExplain = computed(() => Boolean(currentLang.value?.code));
const currentRtl = computed(() =>
  currentLang.value.source === "curated" ? Boolean(currentLang.value.rtl) : isRtlLocale(currentLang.value.code),
);

// The entry rendered for the open concept in the selected language.
const currentEntry = computed(() => {
  if (!selected.value) return null;
  const lang = currentLang.value;
  if (lang.source === "curated") {
    return { source: "curated", word: selected.value[lang.column] || "" };
  }
  return { source: "ai", ...(entries.value[lang.code] || { status: "generating" }) };
});

onMounted(async () => {
  try {
    const data = await api.getVocabulary();
    concepts.value = data.vocabulary || [];
  } catch {
    loadError.value = t("vocabulary.loadError");
  } finally {
    loading.value = false;
  }
  if (props.authed) {
    try {
      const fav = await api.myVocabulary("favorite");
      favoriteIds.value = new Set((fav.items || []).map((i) => i.vocabulary_id));
    } catch { /* favorites are optional chrome */ }
  }
});

// Switch between browse / favorites / history. The latter two pull the per-user list
// and resolve each row back to its full concept (so cards render identically).
async function setView(mode) {
  viewMode.value = mode;
  selected.value = null;
  if (mode === "browse") return;
  try {
    const res = await api.myVocabulary(mode);
    const byId = new Map(concepts.value.map((c) => [c.id, c]));
    myItems.value = (res.items || []).map((i) => i.vocabulary || byId.get(i.vocabulary_id)).filter(Boolean);
  } catch {
    myItems.value = [];
  }
}

const shownConcepts = computed(() => (viewMode.value === "browse" ? filtered.value : myItems.value));

async function toggleFavorite(concept) {
  const id = concept.id;
  const has = favoriteIds.value.has(id);
  const next = new Set(favoriteIds.value);
  has ? next.delete(id) : next.add(id);
  favoriteIds.value = next;
  try {
    has ? await api.unfavoriteVocab(id) : await api.favoriteVocab(id);
  } catch {
    favoriteIds.value = new Set(favoriteIds.value); // best-effort; leave optimistic state
  }
}

// Cached AI explanation for the open concept in the selected language. Polls while
// generating (capped — see MAX_POLLS); only callable when the language has a learner
// code (see canExplain).
async function requestExplain(attempt = 0) {
  if (!selected.value || !canExplain.value) return;
  try {
    const res = await api.explainVocab(selected.value.id, currentLang.value.code);
    if (res.status === "generating" && attempt >= MAX_POLLS) {
      explanation.value = { status: "timeout", text: "" };
      return;
    }
    explanation.value = { status: res.status, text: res.explanation || "" };
    if (res.status === "generating") {
      setTimeout(() => requestExplain(attempt + 1), 3800);
    }
  } catch {
    explanation.value = { status: "error", text: "" };
  }
}

const categories = computed(() => {
  const cats = [...new Set(concepts.value.map((c) => c.category).filter(Boolean))];
  return ["All", ...cats.sort()];
});

// Search ranking: exact → prefix → fuzzy substring, over the English + Zolai seed
// (English fallback is inherent — English is always one of the seed columns).
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  let list = concepts.value;
  if (activeCategory.value !== "All") {
    list = list.filter((c) => c.category === activeCategory.value);
  }
  if (!q) return list;
  const fields = (c) => [c.english, c.zolai].filter(Boolean).map((s) => s.toLowerCase());
  const rank = (c) => {
    const fs = fields(c);
    if (fs.some((f) => f === q)) return 0;          // exact
    if (fs.some((f) => f.startsWith(q))) return 1;  // prefix
    if (fs.some((f) => f.includes(q))) return 2;    // fuzzy substring
    return 99;
  };
  return list
    .map((c) => [rank(c), c])
    .filter(([r]) => r < 99)
    .sort((a, b) => a[0] - b[0])
    .map(([, c]) => c);
});

// Concept-card gloss: the selected curated word when browsing a Chin/Zo tongue,
// otherwise the Zolai seed (AI words aren't loaded until a concept is opened).
function conceptGloss(concept) {
  const lang = currentLang.value;
  if (lang.source === "curated" && lang.key !== "english") {
    return concept[lang.column] || concept.zolai || "";
  }
  return concept.zolai || "";
}

// Fetch (and, while generating, poll) one AI language entry for the open concept.
// ~45s of polling (token-dense scripts are slow); if it never lands, surface a clear
// 'timeout' state with a Retry rather than spinning forever.
const MAX_POLLS = 12;
async function loadLanguage(code, attempt = 0) {
  if (!selected.value) return;
  const id = selected.value.id;
  try {
    const res = await api.learnVocab(id, code);
    if (res.status === "generating" && attempt >= MAX_POLLS) {
      entries.value = { ...entries.value, [code]: { status: "timeout" } };
      return;
    }
    entries.value = { ...entries.value, [code]: { status: res.status, ...res.entry } };
    if (res.status === "generating") {
      pollTimers.value[code] = setTimeout(() => loadLanguage(code, attempt + 1), 3800);
    }
  } catch {
    entries.value = { ...entries.value, [code]: { status: "error" } };
  }
}

// Ensure the selected AI language is loaded for the open concept (curated needs none).
function syncLanguage() {
  const lang = currentLang.value;
  if (selected.value && lang.source === "ai" && !(lang.code in entries.value)) {
    loadLanguage(lang.code);
  }
}

function openConcept(concept) {
  selected.value = concept;
  entries.value = {};
  explanation.value = null;
  Object.values(pollTimers.value).forEach(clearTimeout);
  pollTimers.value = {};
  syncLanguage();
}

function closeDetail() {
  Object.values(pollTimers.value).forEach(clearTimeout);
  pollTimers.value = {};
  selected.value = null;
}

// Switching the language re-resets the (per-language) explanation and loads on demand.
watch(selectedLang, () => {
  explanation.value = null;
  syncLanguage();
});
</script>

<template>
  <div class="learn-page">
    <header class="learn-header">
      <div class="header-row">
        <div>
          <h1 class="learn-title">{{ t("vocabulary.title") }}</h1>
          <p class="learn-sub">{{ t("vocabulary.subtitle") }}</p>
        </div>
        <label class="lang-picker">
          <span class="lang-picker-label">{{ t("vocabulary.languageLabel") }}</span>
          <select v-model="selectedLang" class="lang-select" :aria-label="t('vocabulary.chooseDisplay')">
            <option v-for="l in languages" :key="l.key" :value="l.key">{{ l.label }}</option>
          </select>
        </label>
      </div>
    </header>

    <nav v-if="authed" class="view-tabs" :aria-label="t('vocabulary.views')">
      <button class="view-tab" :class="{ active: viewMode === 'browse' }" :aria-pressed="viewMode === 'browse'" @click="setView('browse')">{{ t("vocabulary.browse") }}</button>
      <button class="view-tab" :class="{ active: viewMode === 'favorite' }" :aria-pressed="viewMode === 'favorite'" @click="setView('favorite')">⭐ {{ t("vocabulary.favorites") }}</button>
      <button class="view-tab" :class="{ active: viewMode === 'viewed' }" :aria-pressed="viewMode === 'viewed'" @click="setView('viewed')">🕘 {{ t("vocabulary.history") }}</button>
    </nav>

    <!-- Detail (selected language) -->
    <section v-if="selected" class="detail" :aria-label="t('vocabulary.detailAria')">
      <button class="back-btn" @click="closeDetail">← {{ t("vocabulary.back") }}</button>
      <div class="detail-head">
        <h2 class="concept-name">{{ selected.english }}</h2>
        <button
          v-if="authed"
          class="fav-btn"
          :aria-pressed="favoriteIds.has(selected.id)"
          :aria-label="t('vocabulary.favorite')"
          @click="toggleFavorite(selected)"
        >{{ favoriteIds.has(selected.id) ? "⭐" : "☆" }}</button>
      </div>

      <div v-if="canExplain" class="explain-row">
        <button v-if="!explanation" class="explain-btn" @click="requestExplain()">🤖 {{ t("vocabulary.explain") }}</button>
        <p v-else-if="explanation.status === 'generating'" class="muted">{{ t("vocabulary.generating") }}</p>
        <div v-else-if="explanation.status === 'timeout' || explanation.status === 'error'" class="retry-row">
          <span class="muted">{{ t("vocabulary.tookTooLong") }}</span>
          <button class="explain-btn" @click="requestExplain()">↻ {{ t("vocabulary.retry") }}</button>
        </div>
        <p v-else class="explanation" :dir="currentRtl ? 'rtl' : 'ltr'">{{ explanation.text }}</p>
      </div>

      <article class="lang-card" :dir="currentRtl ? 'rtl' : 'ltr'" :lang="currentLang.code || null">
        <h3 class="lang-card-title">{{ currentLang.label }}</h3>

        <template v-if="currentEntry.source === 'curated'">
          <p v-if="currentEntry.word" class="word">{{ currentEntry.word }}</p>
          <p v-else class="muted">{{ t("vocabulary.noWord") }}</p>
        </template>

        <template v-else>
          <p v-if="currentEntry.status === 'generating'" class="muted">{{ t("vocabulary.generating") }}</p>
          <div v-else-if="!currentEntry.payload" class="retry-row">
            <span class="muted">{{ t("vocabulary.tookTooLong") }}</span>
            <button class="explain-btn" @click="loadLanguage(currentLang.code, 0)">↻ {{ t("vocabulary.retry") }}</button>
          </div>
          <dl v-else class="fields">
            <dt>{{ t("vocabulary.word") }}</dt>
            <dd class="word">{{ currentEntry.payload.word }} <span class="pron">{{ currentEntry.payload.pronunciation }}</span></dd>
            <dt>{{ t("vocabulary.meaning") }}</dt><dd>{{ currentEntry.payload.meaning }}</dd>
            <dt>{{ t("vocabulary.example") }}</dt><dd>{{ currentEntry.payload.example }}</dd>
            <dt v-if="currentEntry.payload.synonyms?.length">{{ t("vocabulary.synonyms") }}</dt>
            <dd v-if="currentEntry.payload.synonyms?.length">{{ currentEntry.payload.synonyms.join("、") }}</dd>
            <dt v-if="currentEntry.payload.bible_verse">{{ t("vocabulary.verse") }}</dt>
            <dd v-if="currentEntry.payload.bible_verse">
              <em>{{ currentEntry.payload.bible_verse.ref }}</em> — {{ currentEntry.payload.bible_verse.text }}
            </dd>
          </dl>
        </template>
      </article>
    </section>

    <!-- Home (concept browser) -->
    <template v-else>
      <div v-if="viewMode === 'browse'" class="controls">
        <input
          v-model="search"
          class="search-input"
          type="search"
          :placeholder="t('vocabulary.searchPlaceholder')"
          :aria-label="t('vocabulary.searchAria')"
        />
        <div class="cat-filters" role="group" :aria-label="t('vocabulary.filterByCategory')">
          <button
            v-for="cat in categories"
            :key="cat"
            class="cat-btn"
            :class="{ active: activeCategory === cat }"
            :aria-pressed="activeCategory === cat"
            @click="activeCategory = cat"
          >{{ cat === "All" ? t("vocabulary.all") : cat }}</button>
        </div>
      </div>

      <p v-if="loading" class="empty">{{ t("vocabulary.loading") }}</p>
      <p v-else-if="loadError" class="empty">{{ loadError }}</p>
      <p v-else-if="shownConcepts.length === 0" class="empty">
        {{ viewMode === "browse" ? t("vocabulary.noMatches") : t("vocabulary.empty") }}
      </p>

      <ul v-else class="concept-grid">
        <li v-for="c in shownConcepts" :key="c.id">
          <button class="concept-card" @click="openConcept(c)">
            <span class="concept-en">{{ c.english }}</span>
            <span v-if="conceptGloss(c)" class="concept-zo">{{ conceptGloss(c) }}</span>
            <span v-if="c.category" class="concept-cat">{{ c.category }}</span>
          </button>
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.learn-page { max-width: 960px; margin: 0 auto; padding: 1rem; }
.header-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.learn-title { font-size: 1.5rem; margin: 0; }
.learn-sub { color: var(--text-muted); margin: .25rem 0 1rem; }
.lang-picker { display: flex; flex-direction: column; gap: .25rem; }
.lang-picker-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); }
.lang-select {
  padding: .5rem .85rem; border: 1px solid var(--border, #ccc); border-radius: .6rem;
  background: var(--surface, #fff); color: var(--text); font-size: .9rem; cursor: pointer;
}
.lang-select:focus { border-color: var(--primary, #4338ca); outline: none; }
.controls { display: flex; flex-direction: column; gap: .75rem; margin-bottom: 1rem; }
.search-input {
  width: 100%; padding: .65rem .85rem; border: 1px solid var(--border, #ccc);
  border-radius: .6rem; font-size: 1rem;
}
.cat-filters { display: flex; flex-wrap: wrap; gap: .4rem; }
.cat-btn {
  border: 1px solid var(--border, #ccc); background: transparent; border-radius: 1rem;
  padding: .3rem .8rem; cursor: pointer; font-size: .85rem;
}
.cat-btn.active { background: var(--primary, #4338ca); color: #fff; border-color: transparent; }
.concept-grid {
  list-style: none; padding: 0; margin: 0; display: grid; gap: .6rem;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
}
.concept-card {
  width: 100%; text-align: left; display: flex; flex-direction: column; gap: .15rem;
  padding: .7rem .8rem; border: 1px solid var(--border, #ddd); border-radius: .6rem;
  background: var(--surface, #fff); cursor: pointer;
}
.concept-card:hover, .concept-card:focus-visible { border-color: var(--primary, #4338ca); }
.concept-en { font-weight: 600; }
.concept-zo { color: var(--text-muted); font-size: .85rem; }
.concept-cat { font-size: .7rem; color: var(--primary, #4338ca); margin-top: .2rem; }
.empty { text-align: center; color: var(--text-muted); padding: 2rem 0; }
.back-btn { background: none; border: none; color: var(--primary, #4338ca); cursor: pointer; padding: .25rem 0; font-size: .95rem; }
.concept-name { margin: .25rem 0; }
.view-tabs { display: flex; gap: .4rem; margin-bottom: 1rem; }
.view-tab { border: 1px solid var(--border, #ccc); background: transparent; border-radius: 1rem; padding: .3rem .9rem; cursor: pointer; font-size: .85rem; }
.view-tab.active { background: var(--primary, #4338ca); color: #fff; border-color: transparent; }
.detail-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin: .25rem 0 .75rem; }
.fav-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; line-height: 1; color: var(--primary, #4338ca); }
.explain-row { margin-bottom: 1rem; }
.explain-btn { border: 1px solid var(--primary, #4338ca); color: var(--primary, #4338ca); background: transparent; border-radius: .6rem; padding: .4rem .9rem; cursor: pointer; font-size: .9rem; }
.explanation { white-space: pre-wrap; line-height: 1.6; background: var(--surface, #f7f7fb); border-radius: .6rem; padding: .8rem; }
.retry-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.lang-card { border: 1px solid var(--border, #ddd); border-radius: .7rem; padding: .9rem; background: var(--surface, #fff); max-width: 420px; }
.lang-card-title { margin: 0 0 .5rem; font-size: 1rem; color: var(--primary, #4338ca); }
.fields { margin: 0; }
.fields dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-top: .6rem; }
.fields dd { margin: .1rem 0 0; }
.word { font-size: 1.2rem; font-weight: 700; }
.pron { font-size: .85rem; font-weight: 400; color: var(--text-muted); }
.muted { color: var(--text-muted); }
</style>
