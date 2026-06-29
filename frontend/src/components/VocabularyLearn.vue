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

const concepts = ref([]);
const loading = ref(true);
const loadError = ref("");
const search = ref("");
const activeCategory = ref("All");

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
});

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

    <!-- Detail (parallel multilingual view) -->
    <section v-if="selected" class="detail" :aria-label="t('learn.detailAria')">
      <button class="back-btn" @click="closeDetail">← {{ t("learn.back") }}</button>
      <h2 class="concept-name">{{ selected.english }}</h2>

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
      <div class="controls">
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
      <p v-else-if="filtered.length === 0" class="empty">{{ t("learn.noMatches") }}</p>

      <ul v-else class="concept-grid">
        <li v-for="c in filtered" :key="c.id">
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
.concept-name { margin: .25rem 0 1rem; }
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
