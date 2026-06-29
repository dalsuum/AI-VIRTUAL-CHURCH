<script setup>
import { ref, computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi.js";
import { getRegistry, normalizeLanguage, isRtlLocale } from "../i18n";

// Learner vocabulary: the curated concept list (108 words) is the seed; the AI
// renders each concept into the worshipper's language on demand and the result is
// cached server-side. Designed for PARALLEL viewing — a concept fans out across
// languages, so the detail panel can show several side by side without a redesign.
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
const explanation = ref(null);         // { status, text } for the open concept

// Detail state. `selected` is a concept row; `entries` caches one generated entry
// per language code we have loaded for it (the parallel set).
const selected = ref(null);
const entries = ref({});            // { [lang]: { status, payload, word, ... } }
const pollTimers = ref({});

const uiLang = computed(() => normalizeLanguage(locale.value));

onMounted(async () => {
  try {
    const data = await api.getVocabulary();
    concepts.value = data.vocabulary || [];
  } catch {
    loadError.value = t("learn.loadError");
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

// Cached AI explanation for the open concept in the UI language. Polls while generating.
async function requestExplain(attempt = 0) {
  if (!selected.value) return;
  const lang = NON_LEARNER.includes(uiLang.value) ? "en" : uiLang.value;
  try {
    const res = await api.explainVocab(selected.value.id, lang);
    explanation.value = { status: res.status, text: res.explanation || "" };
    if (res.status === "generating" && attempt < 8) {
      setTimeout(() => requestExplain(attempt + 1), 3500);
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

// Hebrew is a Bible/reference locale only, not a learner target (the backend rejects
// it); mirror that boundary so it never appears as a chip. See VocabEntry::NON_LEARNER_LANGUAGES.
const NON_LEARNER = ["he"];

// Other languages offered for parallel viewing (registry minus already-loaded/excluded).
const otherLangs = computed(() => {
  const reg = getRegistry();
  return Object.keys(reg).filter((code) => !(code in entries.value) && !NON_LEARNER.includes(code));
});

function langName(code) {
  return getRegistry()[code]?.native_name || code;
}

function openConcept(concept) {
  selected.value = concept;
  entries.value = {};
  explanation.value = null;
  Object.values(pollTimers.value).forEach(clearTimeout);
  pollTimers.value = {};
  // Start with the worshipper's language, unless it is a reference-only locale (e.g.
  // Hebrew) that has no learner generation — then fall back to English.
  loadLanguage(NON_LEARNER.includes(uiLang.value) ? "en" : uiLang.value);
}

function closeDetail() {
  Object.values(pollTimers.value).forEach(clearTimeout);
  pollTimers.value = {};
  selected.value = null;
}

// Fetch (and, while generating, poll) one language entry for the selected concept.
async function loadLanguage(lang, attempt = 0) {
  if (!selected.value) return;
  const id = selected.value.id;
  try {
    const res = await api.learnVocab(id, lang);
    entries.value = { ...entries.value, [lang]: { status: res.status, ...res.entry } };
    if (res.status === "generating" && attempt < 8) {
      pollTimers.value[lang] = setTimeout(() => loadLanguage(lang, attempt + 1), 3500);
    }
  } catch {
    entries.value = { ...entries.value, [lang]: { status: "error" } };
  }
}

function addLanguage(code) {
  if (!(code in entries.value)) loadLanguage(code);
}
</script>

<template>
  <div class="learn-page">
    <header class="learn-header">
      <h1 class="learn-title">{{ t("learn.title") }}</h1>
      <p class="learn-sub">{{ t("learn.subtitle") }}</p>
    </header>

    <nav v-if="authed" class="view-tabs" :aria-label="t('learn.views')">
      <button class="view-tab" :class="{ active: viewMode === 'browse' }" :aria-pressed="viewMode === 'browse'" @click="setView('browse')">{{ t("learn.browse") }}</button>
      <button class="view-tab" :class="{ active: viewMode === 'favorite' }" :aria-pressed="viewMode === 'favorite'" @click="setView('favorite')">⭐ {{ t("learn.favorites") }}</button>
      <button class="view-tab" :class="{ active: viewMode === 'viewed' }" :aria-pressed="viewMode === 'viewed'" @click="setView('viewed')">🕘 {{ t("learn.history") }}</button>
    </nav>

    <!-- Detail (parallel multilingual view) -->
    <section v-if="selected" class="detail" :aria-label="t('learn.detailAria')">
      <button class="back-btn" @click="closeDetail">← {{ t("learn.back") }}</button>
      <div class="detail-head">
        <h2 class="concept-name">{{ selected.english }}</h2>
        <button
          v-if="authed"
          class="fav-btn"
          :aria-pressed="favoriteIds.has(selected.id)"
          :aria-label="t('learn.favorite')"
          @click="toggleFavorite(selected)"
        >{{ favoriteIds.has(selected.id) ? "⭐" : "☆" }}</button>
      </div>

      <div class="explain-row">
        <button v-if="!explanation" class="explain-btn" @click="requestExplain()">🤖 {{ t("learn.explain") }}</button>
        <p v-else-if="explanation.status === 'generating'" class="muted">{{ t("learn.generating") }}</p>
        <p v-else-if="explanation.status === 'error'" class="muted">{{ t("learn.entryError") }}</p>
        <p v-else class="explanation" :dir="isRtlLocale(uiLang) ? 'rtl' : 'ltr'">{{ explanation.text }}</p>
      </div>

      <div class="parallel">
        <article
          v-for="(entry, lang) in entries"
          :key="lang"
          class="lang-card"
          :dir="isRtlLocale(lang) ? 'rtl' : 'ltr'"
          :lang="lang"
        >
          <h3 class="lang-card-title">{{ langName(lang) }}</h3>
          <p v-if="entry.status === 'generating'" class="muted">{{ t("learn.generating") }}</p>
          <p v-else-if="entry.status === 'error'" class="muted">{{ t("learn.entryError") }}</p>
          <dl v-else-if="entry.payload" class="fields">
            <dt>{{ t("learn.word") }}</dt>
            <dd class="word">{{ entry.payload.word }} <span class="pron">{{ entry.payload.pronunciation }}</span></dd>
            <dt>{{ t("learn.meaning") }}</dt><dd>{{ entry.payload.meaning }}</dd>
            <dt>{{ t("learn.example") }}</dt><dd>{{ entry.payload.example }}</dd>
            <dt v-if="entry.payload.synonyms?.length">{{ t("learn.synonyms") }}</dt>
            <dd v-if="entry.payload.synonyms?.length">{{ entry.payload.synonyms.join("、") }}</dd>
            <dt v-if="entry.payload.bible_verse">{{ t("learn.verse") }}</dt>
            <dd v-if="entry.payload.bible_verse">
              <em>{{ entry.payload.bible_verse.ref }}</em> — {{ entry.payload.bible_verse.text }}
            </dd>
          </dl>
        </article>
      </div>

      <div class="add-langs" role="group" :aria-label="t('learn.addLanguage')">
        <span class="add-label">{{ t("learn.addLanguage") }}</span>
        <button
          v-for="code in otherLangs"
          :key="code"
          class="lang-chip"
          @click="addLanguage(code)"
        >+ {{ langName(code) }}</button>
      </div>
    </section>

    <!-- Home (concept browser) -->
    <template v-else>
      <div v-if="viewMode === 'browse'" class="controls">
        <input
          v-model="search"
          class="search-input"
          type="search"
          :placeholder="t('learn.searchPlaceholder')"
          :aria-label="t('learn.searchAria')"
        />
        <div class="cat-filters" role="group" :aria-label="t('learn.filterByCategory')">
          <button
            v-for="cat in categories"
            :key="cat"
            class="cat-btn"
            :class="{ active: activeCategory === cat }"
            :aria-pressed="activeCategory === cat"
            @click="activeCategory = cat"
          >{{ cat === "All" ? t("learn.all") : cat }}</button>
        </div>
      </div>

      <p v-if="loading" class="empty">{{ t("learn.loading") }}</p>
      <p v-else-if="loadError" class="empty">{{ loadError }}</p>
      <p v-else-if="shownConcepts.length === 0" class="empty">
        {{ viewMode === "browse" ? t("learn.noMatches") : t("learn.empty") }}
      </p>

      <ul v-else class="concept-grid">
        <li v-for="c in shownConcepts" :key="c.id">
          <button class="concept-card" @click="openConcept(c)">
            <span class="concept-en">{{ c.english }}</span>
            <span v-if="c.zolai" class="concept-zo">{{ c.zolai }}</span>
            <span v-if="c.category" class="concept-cat">{{ c.category }}</span>
          </button>
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.learn-page { max-width: 960px; margin: 0 auto; padding: 1rem; }
.learn-title { font-size: 1.5rem; margin: 0; }
.learn-sub { color: var(--text-muted); margin: .25rem 0 1rem; }
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
.parallel { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
.lang-card { border: 1px solid var(--border, #ddd); border-radius: .7rem; padding: .9rem; background: var(--surface, #fff); }
.lang-card-title { margin: 0 0 .5rem; font-size: 1rem; color: var(--primary, #4338ca); }
.fields { margin: 0; }
.fields dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-top: .6rem; }
.fields dd { margin: .1rem 0 0; }
.word { font-size: 1.2rem; font-weight: 700; }
.pron { font-size: .85rem; font-weight: 400; color: var(--text-muted); }
.muted { color: var(--text-muted); }
.add-langs { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-top: 1.25rem; }
.add-label { font-size: .8rem; color: var(--text-muted); }
.lang-chip { border: 1px dashed var(--border, #bbb); background: transparent; border-radius: 1rem; padding: .25rem .7rem; cursor: pointer; font-size: .8rem; }
.lang-chip:hover, .lang-chip:focus-visible { border-style: solid; border-color: var(--primary, #4338ca); }
@media (max-width: 480px) {
  .parallel { grid-template-columns: 1fr; }
}
</style>
