<script setup>
// Online Bible reader. Browses the vendored public-domain translations served
// by the backend (/api/bible/*): English (Berean Standard Bible), Burmese
// (Judson 1835) and Tedim (Lai Siangtho 1932). Read-only, no auth required.
import { ref, computed, watch, onMounted } from "vue";
import { api } from "../composables/useApi.js";

const LANGS = [
  { code: "en", label: "English", note: "Berean Standard Bible" },
  { code: "my", label: "ဗမာ", note: "Judson 1835" },
  { code: "td", label: "Tedim", note: "Lai Siangtho 1932" },
];

const lang = ref("en");
const books = ref([]);
const selectedBook = ref(null); // { num, name, chapters }
const chapterNum = ref(1);
const chapter = ref(null); // { name, chapter, verses: [{num,text}] }
const loadingBooks = ref(false);
const loadingChapter = ref(false);
const error = ref("");
const bookSearch = ref("");

// Narration ("Listen") — synthesized on demand by the backend, then cached.
const audioUrl = ref("");
const loadingAudio = ref(false);
const audioError = ref("");

const filteredBooks = computed(() => {
  const q = bookSearch.value.trim().toLowerCase();
  if (!q) return books.value;
  return books.value.filter((b) => b.name.toLowerCase().includes(q));
});

const chapterList = computed(() =>
  selectedBook.value ? Array.from({ length: selectedBook.value.chapters }, (_, i) => i + 1) : []
);

async function loadBooks() {
  loadingBooks.value = true;
  error.value = "";
  try {
    const res = await api.bibleBooks(lang.value);
    books.value = res.books || [];
  } catch (e) {
    error.value = "Could not load the Bible. Please try again.";
    books.value = [];
  } finally {
    loadingBooks.value = false;
  }
}

async function loadChapter() {
  if (!selectedBook.value) return;
  loadingChapter.value = true;
  error.value = "";
  resetAudio();
  try {
    chapter.value = await api.bibleChapter(lang.value, selectedBook.value.num, chapterNum.value);
  } catch (e) {
    error.value = "Could not load that chapter.";
    chapter.value = null;
  } finally {
    loadingChapter.value = false;
  }
}

function openBook(book) {
  selectedBook.value = book;
  chapterNum.value = 1;
  loadChapter();
}

function backToBooks() {
  selectedBook.value = null;
  chapter.value = null;
}

function goChapter(n) {
  if (n < 1 || (selectedBook.value && n > selectedBook.value.chapters)) return;
  chapterNum.value = n;
  resetAudio();
  loadChapter();
}

function resetAudio() {
  audioUrl.value = "";
  audioError.value = "";
}

async function listen() {
  if (loadingAudio.value || !selectedBook.value) return;
  loadingAudio.value = true;
  audioError.value = "";
  try {
    const res = await api.bibleAudio(lang.value, selectedBook.value.num, chapterNum.value);
    audioUrl.value = res.url || "";
    if (!audioUrl.value) audioError.value = "Narration unavailable.";
  } catch (e) {
    audioError.value =
      e?.status === 409 ? "Narration isn't enabled for this translation." : "Could not generate audio.";
  } finally {
    loadingAudio.value = false;
  }
}

// Switching translation re-fetches the table of contents, then re-opens the
// same book/chapter so the reader stays on their place across languages.
watch(lang, async () => {
  const keepNum = selectedBook.value?.num;
  const keepCh = chapterNum.value;
  await loadBooks();
  if (keepNum) {
    const same = books.value.find((b) => b.num === keepNum);
    if (same) {
      selectedBook.value = same;
      chapterNum.value = Math.min(keepCh, same.chapters);
      loadChapter();
    } else {
      backToBooks();
    }
  }
});

onMounted(loadBooks);
</script>

<template>
  <div class="bible-page">
    <header class="bible-header">
      <a href="#" class="back-link">&#8592; Back to worship</a>
      <div class="bible-title-block">
        <h1 class="bible-title">📖 Online Bible</h1>
        <p class="bible-sub">Read Scripture in English, Burmese &amp; Tedim — public-domain translations.</p>
      </div>
      <div class="lang-tabs" role="group" aria-label="Translation">
        <button
          v-for="l in LANGS"
          :key="l.code"
          class="lang-btn"
          :class="{ active: lang === l.code }"
          @click="lang = l.code"
          :title="l.note"
        >
          {{ l.label }}
        </button>
      </div>
    </header>

    <p v-if="error" class="bible-error">{{ error }}</p>

    <!-- Book list (table of contents) -->
    <div v-if="!selectedBook" class="toc">
      <input
        v-model="bookSearch"
        class="search-input"
        type="search"
        placeholder="Search books…"
        aria-label="Search books"
      />
      <p v-if="loadingBooks" class="muted">Loading…</p>
      <div v-else class="book-grid">
        <button v-for="b in filteredBooks" :key="b.num" class="book-card" @click="openBook(b)">
          <span class="book-name">{{ b.name }}</span>
          <span class="book-ch">{{ b.chapters }} ch.</span>
        </button>
      </div>
      <p v-if="!loadingBooks && filteredBooks.length === 0" class="muted">No books found.</p>
    </div>

    <!-- Chapter reader -->
    <div v-else class="reader">
      <div class="reader-bar">
        <button class="link-btn" @click="backToBooks">&#8592; All books</button>
        <div class="reader-bar-right">
          <button class="listen-btn" :disabled="loadingAudio || loadingChapter" @click="listen">
            <span v-if="loadingAudio">⏳ Preparing…</span>
            <span v-else>🔊 Listen</span>
          </button>
          <select class="ch-select" :value="chapterNum" @change="goChapter(Number($event.target.value))">
            <option v-for="n in chapterList" :key="n" :value="n">Chapter {{ n }}</option>
          </select>
        </div>
      </div>

      <h2 class="reader-heading">{{ chapter?.name || selectedBook.name }} {{ chapterNum }}</h2>

      <div v-if="audioUrl" class="audio-wrap">
        <audio :src="audioUrl" controls autoplay class="audio-player"></audio>
      </div>
      <p v-if="audioError" class="audio-error">{{ audioError }}</p>

      <p v-if="loadingChapter" class="muted">Loading…</p>
      <div v-else-if="chapter" class="verses" :class="{ mm: lang !== 'en' }">
        <p v-for="v in chapter.verses" :key="v.num" class="verse">
          <sup class="vnum">{{ v.num }}</sup>{{ v.text }}
        </p>
      </div>

      <div class="reader-nav">
        <button class="nav-btn" :disabled="chapterNum <= 1" @click="goChapter(chapterNum - 1)">
          &#8592; Previous
        </button>
        <button
          class="nav-btn"
          :disabled="chapterNum >= selectedBook.chapters"
          @click="goChapter(chapterNum + 1)"
        >
          Next &#8594;
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bible-page {
  min-height: 100vh;
  padding: 0 0 4rem;
  background: var(--bg);
  color: var(--text);
}
.bible-header {
  padding: 1.25rem 1.5rem 1rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}
.back-link {
  display: inline-block;
  font-size: 0.83rem;
  color: var(--text-muted);
  text-decoration: none;
  margin-bottom: 0.6rem;
  transition: color 0.15s;
}
.back-link:hover { color: var(--primary); }
.bible-title-block { margin-bottom: 0.85rem; }
.bible-title { font-size: 1.5rem; margin: 0 0 0.25rem; }
.bible-sub { margin: 0; color: var(--text-muted); font-size: 0.9rem; }

.lang-tabs { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.lang-btn {
  padding: 0.4rem 0.9rem;
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--text);
  border-radius: 999px;
  font-size: 0.88rem;
  cursor: pointer;
  transition: all 0.15s;
}
.lang-btn.active { border-color: var(--primary); color: var(--primary); font-weight: 600; }

.bible-error {
  margin: 1rem 1.5rem 0;
  color: var(--danger);
  font-size: 0.88rem;
}

.toc, .reader { max-width: 880px; margin: 0 auto; padding: 1.25rem 1.5rem; }

.search-input {
  width: 100%;
  padding: 0.6rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font-size: 0.95rem;
  margin-bottom: 1rem;
}

.book-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 0.6rem;
}
.book-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s, transform 0.05s;
}
.book-card:hover { border-color: var(--primary); }
.book-card:active { transform: scale(0.98); }
.book-name { font-weight: 600; font-size: 0.95rem; }
.book-ch { font-size: 0.75rem; color: var(--text-muted); }

.reader-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}
.link-btn {
  background: none;
  border: none;
  color: var(--primary);
  cursor: pointer;
  font-size: 0.9rem;
  padding: 0;
}
.ch-select {
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  color: var(--text);
  font-size: 0.9rem;
}
.reader-bar-right { display: flex; align-items: center; gap: 0.6rem; }
.listen-btn {
  padding: 0.45rem 0.9rem;
  border: 1px solid var(--primary);
  border-radius: 999px;
  background: var(--surface);
  color: var(--primary);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.15s, color 0.15s;
}
.listen-btn:hover:not(:disabled) { background: var(--primary); color: #fff; }
.listen-btn:disabled { opacity: 0.6; cursor: default; }

.reader-heading { font-size: 1.3rem; margin: 0 0 1rem; }

.audio-wrap { margin: 0 0 1.1rem; }
.audio-player { width: 100%; height: 38px; }
.audio-error { color: var(--danger); font-size: 0.85rem; margin: 0 0 1rem; }

.verses { line-height: 1.85; font-size: 1.05rem; }
.verses.mm { line-height: 2.1; font-size: 1.12rem; }
.verse { margin: 0 0 0.6rem; }
.vnum {
  color: var(--primary);
  font-weight: 700;
  font-size: 0.7em;
  margin-right: 0.3rem;
  vertical-align: super;
}

.reader-nav {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 2rem;
}
.nav-btn {
  padding: 0.55rem 1.1rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  font-size: 0.9rem;
}
.nav-btn:disabled { opacity: 0.4; cursor: default; }
.nav-btn:not(:disabled):hover { border-color: var(--primary); color: var(--primary); }

.muted { color: var(--text-muted); }
</style>
