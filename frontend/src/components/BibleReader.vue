<script setup>
// Online Bible reader. Browses the vendored public-domain translations served
// by the backend (/api/bible/*): English (Berean Standard Bible), Burmese
// (Judson 1835) and Tedim (Lai Siangtho 1932). Read-only, no auth required.
import { ref, computed, watch, onMounted } from "vue";
import { api } from "../composables/useApi.js";

const LANGS = [
  { code: "en", label: "English", note: "Berean Standard Bible (2020)" },
  { code: "my", label: "ဗမာ", note: "Judson 1835" },
  { code: "td", label: "Tedim", note: "Lai Siangtho 1932" },
  // Hebrew Tanakh (Westminster Leningrad Codex) — Old Testament only, RTL.
  { code: "he", label: "עברית", note: "Westminster Leningrad Codex (Tanakh)" },
];

// Right-to-left scripts (Hebrew) need the reader heading and verse body laid out
// RTL. Kept as a set so future RTL translations are a one-line addition.
const RTL_LANGS = new Set(["he"]);

const lang = ref("en");
const isRtl = computed(() => RTL_LANGS.has(lang.value));
// Version/year label for the currently selected translation (shown beneath
// the language tabs so readers know which edition they're reading).
const activeNote = computed(() => LANGS.find((l) => l.code === lang.value)?.note || "");
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
const audioEl = ref(null);
const bgMusicEl = ref(null);

// Playback speed for the narration, remembered per-device. Highlighting stays
// in sync automatically: it maps currentTime/duration, and a faster rate only
// advances currentTime faster while duration is unchanged.
const SPEED_KEY = "bible_speed";
const SPEEDS = [0.75, 1, 1.25, 1.5];
const _storedSpeed = Number(localStorage.getItem(SPEED_KEY));
const playbackRate = ref(SPEEDS.includes(_storedSpeed) ? _storedSpeed : 1);
function applyRate() {
  if (audioEl.value) audioEl.value.playbackRate = playbackRate.value;
}
function setSpeed(rate) {
  playbackRate.value = rate;
  localStorage.setItem(SPEED_KEY, String(rate));
  applyRate();
  // The speed control only retimes the narration (audioEl.playbackRate). The
  // background music is a separate element left at its own natural tempo, so it
  // keeps looping smoothly underneath at any narration speed — no stop/resume.
}

// Reader config from admin: which languages can be narrated + highlight toggle
// + optional looping background music played softly behind the narration.
// bg_music_mode: 'off' | 'static' (admin mp3 URL) | 'ai' (generated per chapter
// theme + the reader's local time of day).
const config = ref({
  narratable: {},
  text_highlight: true,
  bg_music_mode: "off",
  bg_music_url: "",
  bg_music_volume: 0.15,
});
// In AI mode the loop URL is resolved per chapter from the backend (cached or
// freshly generated); in static mode it's the admin's fixed URL.
const aiMusicUrl = ref("");
const bgMusicUrl = computed(() => {
  const mode = config.value.bg_music_mode || "off";
  if (mode === "ai") return aiMusicUrl.value || "";
  if (mode === "static") return config.value.bg_music_url || "";
  return "";
});

// AI mode: ask the backend for this chapter's loop (keyed by theme + the
// reader's local time of day). Returns a cached URL instantly, or null while
// it's still generating — in which case this visit simply plays no music.
async function resolveAiBgMusic() {
  aiMusicUrl.value = "";
  if (config.value.bg_music_mode !== "ai" || !selectedBook.value) return;
  try {
    const res = await api.bibleBgMusic(
      lang.value, selectedBook.value.num, chapterNum.value, new Date().getHours()
    );
    aiMusicUrl.value = res?.url || "";
  } catch (e) {
    aiMusicUrl.value = "";
  }
}
const bgMusicVolume = computed(() => {
  const v = Number(config.value.bg_music_volume);
  return Number.isFinite(v) ? Math.min(1, Math.max(0, v)) : 0.15;
});

// Whether the admin has any background music configured (static URL or AI mode).
// The reader's own on/off button only appears when there's music to control.
const musicAvailable = computed(() => (config.value.bg_music_mode || "off") !== "off");

// Background music is also a personal preference, remembered per-device: even
// when the admin enables it, a reader can mute it for themselves. Defaults to on.
const BGM_PREF_KEY = "bible_bg_music";
const bgMusicPref = ref(localStorage.getItem(BGM_PREF_KEY) !== "0");
function toggleBgMusic() {
  bgMusicPref.value = !bgMusicPref.value;
  localStorage.setItem(BGM_PREF_KEY, bgMusicPref.value ? "1" : "0");
  if (!bgMusicPref.value) {
    syncBgMusic("stop");
  } else if (audioEl.value && !audioEl.value.paused) {
    syncBgMusic("play"); // turned back on mid-narration → resume under the voice
  }
}

// Keep the background track in lockstep with the narration: it starts, pauses
// and stops with the spoken audio so the two never drift or play alone.
function syncBgMusic(action) {
  const bg = bgMusicEl.value;
  if (!bg || !bgMusicUrl.value) return;
  if (action === "play") {
    if (!bgMusicPref.value) return; // reader muted the background music
    // Note: narration speed never gates the music — the loop plays at its own
    // natural tempo regardless of how fast/slow the voice is set.
    bg.volume = bgMusicVolume.value;
    bg.play().catch(() => {});
  } else if (action === "pause") {
    bg.pause();
  } else if (action === "stop") {
    bg.pause();
    bg.currentTime = 0;
  }
}
const canNarrate = computed(() => config.value.narratable?.[lang.value] !== false);

// Continuous reading: when on, the narration doesn't stop at the end of the
// chapter — it rolls into the next chapter, and on through the next book
// (across both testaments) until the very end of the Bible. It's a personal
// preference, remembered per-device and OFF by default; readers opt in.
const CONTINUOUS_KEY = "bible_continuous";
const continuousRead = ref(localStorage.getItem(CONTINUOUS_KEY) === "1");
function toggleContinuous() {
  continuousRead.value = !continuousRead.value;
  localStorage.setItem(CONTINUOUS_KEY, continuousRead.value ? "1" : "0");
}

// Move to the next chapter for continuous playback: next chapter in the same
// book, else chapter 1 of the next book in the table of contents. Returns false
// when there's nothing after the current spot (the end of the Bible), so the
// caller can simply stop. Awaits the chapter load before auto-narrating.
async function continueNarration() {
  if (!continuousRead.value || !selectedBook.value) return;
  if (chapterNum.value < selectedBook.value.chapters) {
    chapterNum.value += 1;
  } else {
    const idx = books.value.findIndex((b) => b.num === selectedBook.value.num);
    // Roll on to the next readable book, skipping greyed placeholders (e.g. the
    // NT under the Hebrew Tanakh) so continuous reading stops cleanly at the end
    // of the available canon rather than on an empty book.
    const next = idx >= 0
      ? books.value.slice(idx + 1).find((b) => b.available !== false)
      : null;
    if (!next) return; // reached the end of the available canon — stop.
    selectedBook.value = next;
    chapterNum.value = 1;
  }
  await loadChapter();
  await listen();
}

// Called when the narration audio finishes. Clears the highlight and stops the
// background loop, then (in continuous mode) rolls into the next chapter.
function onNarrationEnded() {
  highlightedVerse.value = -1;
  syncBgMusic("stop");
  continueNarration();
}

// Verse highlighting is a personal reading preference: the admin setting
// (config.text_highlight) is only the default a first-time reader sees; once a
// reader flips the switch their choice is remembered per-device and wins.
const HL_PREF_KEY = "bible_highlight";
const _storedHl = localStorage.getItem(HL_PREF_KEY);
const highlightPref = ref(_storedHl === null ? null : _storedHl === "1");
const highlightEnabled = computed(() =>
  highlightPref.value === null ? config.value.text_highlight !== false : highlightPref.value
);
function toggleHighlight() {
  const next = !highlightEnabled.value;
  highlightPref.value = next;
  localStorage.setItem(HL_PREF_KEY, next ? "1" : "0");
  if (!next) highlightedVerse.value = -1;
}

// The frozen control panel can be collapsed to a slim handle (back · title ·
// toggle) so the verse area gets the whole screen; remembered per-device.
const CONTROLS_KEY = "bible_controls_open";
const controlsOpen = ref(localStorage.getItem(CONTROLS_KEY) !== "0");
function toggleControls() {
  controlsOpen.value = !controlsOpen.value;
  localStorage.setItem(CONTROLS_KEY, controlsOpen.value ? "1" : "0");
}

// --- Reading comfort: text size (for older eyes) -------------------------
// A scale factor applied to the verse font-size, remembered per-device.
const SIZE_KEY = "bible_text_size";
const TEXT_SIZES = [
  { id: "normal", label: "Normal", scale: 1 },
  { id: "medium", label: "Medium", scale: 1.2 },
  { id: "large",  label: "Large",  scale: 1.45 },
];
const _storedSize = localStorage.getItem(SIZE_KEY);
const textSize = ref(TEXT_SIZES.some((s) => s.id === _storedSize) ? _storedSize : "normal");
const verseScale = computed(() => TEXT_SIZES.find((s) => s.id === textSize.value)?.scale ?? 1);
function setTextSize(id) {
  textSize.value = id;
  localStorage.setItem(SIZE_KEY, id);
}

// --- Reading background ("peaceful" reading themes) ----------------------
// Each preset is a self-contained {bg, text} pair so contrast stays readable
// regardless of the app's dark/light mode. 'default' follows the app theme.
const BG_KEY = "bible_bg_theme";
const BG_THEMES = [
  { id: "default", label: "Default", bg: "",        text: "",        swatch: "" },
  { id: "sepia",   label: "Sepia",   bg: "#f4ecd8", text: "#5b4636", swatch: "#f4ecd8" },
  { id: "cream",   label: "Cream",   bg: "#faf3e0", text: "#4a4032", swatch: "#faf3e0" },
  { id: "mint",    label: "Mint",    bg: "#e7f1ea", text: "#27392f", swatch: "#e7f1ea" },
  { id: "sky",     label: "Sky",     bg: "#e9f0f7", text: "#26323f", swatch: "#e9f0f7" },
  { id: "night",   label: "Night",   bg: "#0f1722", text: "#c7d2dc", swatch: "#0f1722" },
];
const _storedBg = localStorage.getItem(BG_KEY);
const bgTheme = ref(BG_THEMES.some((t) => t.id === _storedBg) ? _storedBg : "default");
function setBgTheme(id) {
  bgTheme.value = id;
  localStorage.setItem(BG_KEY, id);
}
// Inline style applied to the scrollable verse region: the chosen bg + text
// colour, plus the text-size scale variable consumed by .verses font-size.
const readingStyle = computed(() => {
  const t = BG_THEMES.find((x) => x.id === bgTheme.value) || BG_THEMES[0];
  const style = { "--verse-scale": String(verseScale.value) };
  if (t.bg) { style.background = t.bg; style.color = t.text; }
  return style;
});

// --- Verse selection + copy (for notes / social / sharing) ---------------
// Tap verses to (de)select; a floating bar offers Copy/Clear. Off by default so
// taps don't interfere with reading until the reader enters select mode.
const selectMode = ref(false);
const selectedVerses = ref(new Set());
function toggleSelectMode() {
  selectMode.value = !selectMode.value;
  if (!selectMode.value) selectedVerses.value = new Set();
}
function toggleVerse(num) {
  if (!selectMode.value) return;
  const next = new Set(selectedVerses.value);
  next.has(num) ? next.delete(num) : next.add(num);
  selectedVerses.value = next;
}
function clearSelection() {
  selectedVerses.value = new Set();
}
const selectedCount = computed(() => selectedVerses.value.size);

// Build a "Genesis 1:1-3, 5 (Berean Standard Bible)" style reference for the
// current selection, collapsing consecutive verse numbers into ranges.
function selectionReference() {
  const nums = [...selectedVerses.value].sort((a, b) => a - b);
  if (!nums.length) return "";
  const parts = [];
  let start = nums[0], prev = nums[0];
  for (let i = 1; i <= nums.length; i++) {
    if (nums[i] === prev + 1) { prev = nums[i]; continue; }
    parts.push(start === prev ? `${start}` : `${start}-${prev}`);
    start = prev = nums[i];
  }
  const book = chapter.value?.name || selectedBook.value?.name || "";
  const ref = `${book} ${chapterNum.value}:${parts.join(", ")}`;
  return activeNote.value ? `${ref} (${activeNote.value})` : ref;
}

const copyStatus = ref("");
async function copySelection() {
  const verses = chapter.value?.verses || [];
  const picked = verses.filter((v) => selectedVerses.value.has(v.num));
  if (!picked.length) return;
  const body = picked.map((v) => `${v.num} ${v.text}`).join("\n");
  const text = `${selectionReference()}\n\n${body}`;
  try {
    await navigator.clipboard.writeText(text);
    copyStatus.value = `Copied ${picked.length} verse${picked.length > 1 ? "s" : ""}`;
  } catch {
    copyStatus.value = "Copy failed — long-press to select instead";
  }
  setTimeout(() => { copyStatus.value = ""; }, 2500);
}

// Leaving a chapter clears any in-progress selection so it doesn't carry over.
watch([selectedBook, chapterNum, lang], () => { selectedVerses.value = new Set(); });

// Verse highlighting: with no per-verse timings, map the audio's playback
// position proportionally across the chapter, weighted by each verse's length
// (longer verses take longer to read). Mirrors the service player's approach.
const highlightedVerse = ref(-1);
const verseBounds = computed(() => {
  const verses = chapter.value?.verses || [];
  const bounds = [];
  let total = 0;
  for (const v of verses) {
    total += (v.text || "").length + 1; // +1 so empty verses still advance
    bounds.push(total);
  }
  return { bounds, total };
});

function onAudioTime(ev) {
  if (!highlightEnabled.value) return;
  const el = ev.target;
  const { bounds, total } = verseBounds.value;
  if (!el.duration || !total || !bounds.length) return;
  const pos = (el.currentTime / el.duration) * total;
  let i = bounds.findIndex((b) => pos < b);
  if (i === -1) i = bounds.length - 1;
  if (i !== highlightedVerse.value) {
    highlightedVerse.value = i;
    scrollVerseIntoView(i);
  }
}

function scrollVerseIntoView(i) {
  const node = document.getElementById(`verse-${i}`);
  if (node) node.scrollIntoView({ block: "center", behavior: "smooth" });
}

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
  // Greyed books (a partial-canon translation's missing books, e.g. the NT under
  // the Hebrew Tanakh) aren't readable — ignore taps on them.
  if (book.available === false) return;
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
  aiMusicUrl.value = "";
  highlightedVerse.value = -1;
  syncBgMusic("stop");
}

async function listen() {
  if (loadingAudio.value || !selectedBook.value) return;
  loadingAudio.value = true;
  audioError.value = "";
  // Resolve the AI background loop alongside the narration so it's ready to
  // start the moment the spoken audio plays (no-op in 'off'/'static' modes).
  resolveAiBgMusic();
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
    if (same && same.available !== false) {
      selectedBook.value = same;
      chapterNum.value = Math.min(keepCh, same.chapters);
      loadChapter();
    } else {
      backToBooks();
    }
  }
});

onMounted(() => {
  loadBooks();
  api.bibleConfig().then((c) => { config.value = c; }).catch(() => {});
});
</script>

<template>
  <div class="bible-page" :class="{ reading: selectedBook }">
    <!-- While reading, the app header folds away with the controls so a
         collapsed panel leaves only the slim handle bar above the verses. -->
    <header v-show="!selectedBook || controlsOpen" class="bible-header">
      <a href="#" class="back-link">&#8592; Back to worship</a>
      <div class="bible-title-block">
        <h1 class="bible-title">📖 Online Bible</h1>
        <p class="bible-sub">Read Scripture in English (Berean Standard Bible, 2020), Burmese (Judson, 1835), Tedim (Lai Siangtho, 1932) &amp; Hebrew (Westminster Leningrad Codex — Tanakh) — public-domain translations.</p>
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
      <p class="bible-version">{{ activeNote }}</p>
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
        <button
          v-for="b in filteredBooks"
          :key="b.num"
          class="book-card"
          :class="{ unavailable: b.available === false }"
          :disabled="b.available === false"
          :title="b.available === false ? 'Not in this translation' : b.name"
          @click="openBook(b)"
        >
          <span class="book-name">{{ b.name }}</span>
          <span class="book-ch">{{ b.available === false ? '—' : b.chapters + ' ch.' }}</span>
        </button>
      </div>
      <p v-if="!loadingBooks && filteredBooks.length === 0" class="muted">No books found.</p>
    </div>

    <!-- Chapter reader -->
    <div v-else class="reader">
      <!-- Frozen top panel: controls + chapter title + player stay pinned while
           only the verses scroll (like Excel freeze panes). -->
      <div class="reader-top">
      <div class="reader-handle">
        <button class="link-btn" @click="backToBooks" aria-label="All books" title="All books">
          <span aria-hidden="true">&#8592;</span><span class="btn-label"> All books</span>
        </button>
        <h2 class="reader-heading" :dir="isRtl ? 'rtl' : 'ltr'">{{ chapter?.name || selectedBook.name }} {{ chapterNum }}</h2>
        <button
          type="button"
          class="collapse-btn"
          :aria-expanded="controlsOpen"
          :aria-label="controlsOpen ? 'Hide controls' : 'Show controls'"
          :title="controlsOpen ? 'Hide controls' : 'Show controls'"
          @click="toggleControls"
        >
          <span aria-hidden="true">{{ controlsOpen ? '▴' : '▾' }}</span>
          <span class="btn-label"> {{ controlsOpen ? 'Hide' : 'Controls' }}</span>
        </button>
      </div>

      <div v-show="controlsOpen" class="reader-collapsible">
        <div class="reader-bar-right">
          <button
            v-if="canNarrate"
            class="listen-btn"
            :disabled="loadingAudio || loadingChapter"
            :aria-label="loadingAudio ? 'Preparing audio' : 'Listen'"
            :title="loadingAudio ? 'Preparing audio' : 'Listen'"
            @click="listen"
          >
            <span aria-hidden="true">{{ loadingAudio ? '⏳' : '🔊' }}</span>
            <span class="btn-label"> {{ loadingAudio ? 'Preparing…' : 'Listen' }}</span>
          </button>
          <button
            type="button"
            class="icon-toggle"
            :class="{ active: highlightEnabled }"
            :aria-pressed="highlightEnabled"
            :aria-label="`Verse highlighting ${highlightEnabled ? 'on' : 'off'}`"
            :title="`Verse highlighting ${highlightEnabled ? 'on' : 'off'}`"
            @click="toggleHighlight"
          >
            <span aria-hidden="true">✨</span><span class="btn-label"> Highlight: {{ highlightEnabled ? 'On' : 'Off' }}</span>
          </button>
          <button
            v-if="canNarrate"
            type="button"
            class="icon-toggle"
            :class="{ active: continuousRead }"
            :aria-pressed="continuousRead"
            :aria-label="`Read whole Bible continuously ${continuousRead ? 'on' : 'off'}`"
            :title="`Read whole Bible continuously ${continuousRead ? 'on' : 'off'}`"
            @click="toggleContinuous"
          >
            <span aria-hidden="true">📖</span><span class="btn-label"> Continuous: {{ continuousRead ? 'On' : 'Off' }}</span>
          </button>
          <button
            v-if="musicAvailable"
            type="button"
            class="icon-toggle"
            :class="{ active: bgMusicPref }"
            :aria-pressed="bgMusicPref"
            :aria-label="`Background music ${bgMusicPref ? 'on' : 'off'}`"
            :title="`Background music ${bgMusicPref ? 'on' : 'off'}`"
            @click="toggleBgMusic"
          >
            <span aria-hidden="true">🎵</span><span class="btn-label"> Music: {{ bgMusicPref ? 'On' : 'Off' }}</span>
          </button>
          <button
            type="button"
            class="icon-toggle"
            :class="{ active: selectMode }"
            :aria-pressed="selectMode"
            :aria-label="`Select verses to copy ${selectMode ? 'on' : 'off'}`"
            :title="`Select verses to copy ${selectMode ? 'on' : 'off'}`"
            @click="toggleSelectMode"
          >
            <span aria-hidden="true">📋</span><span class="btn-label"> Select: {{ selectMode ? 'On' : 'Off' }}</span>
          </button>
          <select class="ch-select" :value="chapterNum" aria-label="Chapter" @change="goChapter(Number($event.target.value))">
            <option v-for="n in chapterList" :key="n" :value="n">Chapter {{ n }}</option>
          </select>
        </div>

        <!-- Reading comfort: text size + peaceful background colour. -->
        <div class="reader-prefs">
          <span class="prefs-label" aria-hidden="true">Aa</span>
          <button
            v-for="s in TEXT_SIZES"
            :key="s.id"
            type="button"
            class="pref-chip"
            :class="{ active: textSize === s.id }"
            @click="setTextSize(s.id)"
          >{{ s.label }}</button>
          <span class="prefs-sep" aria-hidden="true">·</span>
          <span class="prefs-label">Color</span>
          <button
            v-for="t in BG_THEMES"
            :key="t.id"
            type="button"
            class="swatch"
            :class="{ active: bgTheme === t.id, 'swatch-default': t.id === 'default' }"
            :style="t.swatch ? { background: t.swatch } : {}"
            :aria-label="`Reading background ${t.label}`"
            :title="t.label"
            @click="setBgTheme(t.id)"
          >{{ t.id === 'default' ? 'A' : '' }}</button>
        </div>
      </div>
      <!-- /reader-collapsible -->

      <!-- Player + speed stay visible whenever narration is loaded, even with
           the controls collapsed, so you can read with the panel folded away. -->
      <div v-if="audioUrl" class="audio-wrap">
        <audio
          ref="audioEl"
          :src="audioUrl"
          controls
          autoplay
          class="audio-player"
          @loadedmetadata="applyRate"
          @timeupdate="onAudioTime"
          @play="syncBgMusic('play')"
          @pause="syncBgMusic('pause')"
          @ended="onNarrationEnded"
        ></audio>
        <audio
          v-if="bgMusicUrl"
          ref="bgMusicEl"
          :src="bgMusicUrl"
          loop
          preload="auto"
          aria-hidden="true"
        ></audio>
        <div class="speed-row" role="group" aria-label="Playback speed">
          <span class="speed-label">Speed</span>
          <button
            v-for="s in SPEEDS"
            :key="s"
            type="button"
            class="speed-btn"
            :class="{ active: playbackRate === s }"
            @click="setSpeed(s)"
          >
            {{ s === 1 ? 'Normal' : s + '×' }}
          </button>
        </div>
      </div>
      <p v-if="audioError" class="audio-error">{{ audioError }}</p>
      </div>
      <!-- /reader-top -->

      <!-- The only scrolling region: verses scroll while the top bar and the
           Prev/Next footer stay frozen (Excel-style freeze panes). -->
      <div class="verses-scroll" :style="readingStyle">
        <p v-if="loadingChapter" class="muted">Loading…</p>
        <div
          v-else-if="chapter"
          class="verses"
          :class="{ mm: lang !== 'en', rtl: isRtl, selecting: selectMode }"
          :dir="isRtl ? 'rtl' : 'ltr'"
        >
          <p
            v-for="(v, i) in chapter.verses"
            :key="v.num"
            :id="`verse-${i}`"
            class="verse"
            :class="{
              highlight: highlightEnabled && i === highlightedVerse,
              selected: selectedVerses.has(v.num),
            }"
            @click="toggleVerse(v.num)"
          >
            <sup class="vnum">{{ v.num }}</sup>{{ v.text }}
          </p>
        </div>
      </div>

      <!-- Selection action bar: copy the picked verses (with reference) to share. -->
      <div v-if="selectMode && selectedCount > 0" class="copy-bar">
        <span class="copy-count">{{ selectedCount }} selected</span>
        <span v-if="copyStatus" class="copy-status">{{ copyStatus }}</span>
        <button type="button" class="copy-clear" @click="clearSelection">Clear</button>
        <button type="button" class="copy-btn" @click="copySelection">📋 Copy</button>
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

/* Reading a chapter: turn the page into a fixed-height flex column so the top
   bar and Prev/Next footer stay frozen and ONLY the verse list scrolls — a real
   Excel-style freeze-panes layout that doesn't depend on position:sticky. */
.bible-page.reading {
  height: 100vh;          /* fallback for browsers without dvh */
  height: 100dvh;
  min-height: 0;
  padding-bottom: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.bible-page.reading .bible-header { flex: 0 0 auto; padding-bottom: 0.6rem; }
.bible-page.reading .bible-title-block { margin-bottom: 0.5rem; }
.bible-page.reading .bible-sub { display: none; } /* reclaim vertical space */
.bible-page.reading .reader {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding-top: 0.75rem;
  padding-bottom: 0;
}
/* The one scrollable region. */
.verses-scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  -webkit-overflow-scrolling: touch;
  padding-top: 0.75rem;
  transition: background 0.25s, color 0.25s;
}
/* A chosen reading background bleeds to the full reader width + bottom. */
.verses-scroll[style*="background"] { margin: 0 -1.5rem; padding-left: 1.5rem; padding-right: 1.5rem; }
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
.bible-version { margin: 0.4rem 0 0; color: var(--text-muted); font-size: 0.8rem; font-style: italic; }

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
/* Greyed placeholder for books a translation doesn't cover (e.g. the New
   Testament under the Hebrew Tanakh): visible for context, but not selectable. */
.book-card.unavailable {
  opacity: 0.4;
  cursor: not-allowed;
  filter: grayscale(1);
}
.book-card.unavailable:hover { border-color: var(--border); }
.book-card.unavailable:active { transform: none; }
.book-name { font-weight: 600; font-size: 0.95rem; }
.book-ch { font-size: 0.75rem; color: var(--text-muted); }

/* Frozen header: controls + title + player pin to the top of the viewport
   while the verses scroll beneath. Negative margins let its background span
   the full reader width; the inner padding restores the original spacing. */
/* Frozen top panel — a non-shrinking flex row at the top of the reader column
   (see .bible-page.reading). It never scrolls; only .verses-scroll does. */
.reader-top {
  flex: 0 0 auto;
  background: var(--bg);
  margin: 0 -1.5rem;
  padding: 0.6rem 1.5rem 0.5rem;
  border-bottom: 1px solid var(--border);
}
.reader-top .audio-wrap { margin-bottom: 0.4rem; }

/* Slim always-visible handle: back · chapter title · collapse toggle. The body
   below it (controls + player + speed) collapses to give verses the screen. */
.reader-handle {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-bottom: 0.5rem;
}
.reader-handle .reader-heading {
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
  font-size: 1.15rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.collapse-btn {
  flex: 0 0 auto;
  padding: 0.4rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: border-color 0.15s, color 0.15s;
}
.collapse-btn:hover { border-color: var(--primary); color: var(--primary); }
.reader-collapsible .reader-bar-right { margin-bottom: 0.5rem; }

/* Controls wrap instead of overflowing the phone width (which was clipping the
   verses and player off the right edge). */
.reader-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 0.75rem;
  flex-wrap: wrap;
  margin-bottom: 0.75rem;
}
.link-btn {
  background: none;
  border: none;
  color: var(--primary);
  cursor: pointer;
  font-size: 0.9rem;
  padding: 0.3rem 0;
  white-space: nowrap;
}
.ch-select {
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  color: var(--text);
  font-size: 0.9rem;
}
.reader-bar-right {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}
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

.icon-toggle {
  padding: 0.45rem 0.8rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.icon-toggle.active { border-color: var(--primary); color: var(--primary); background: var(--primary-soft, rgba(99,179,237,0.12)); }

/* Phones: collapse the action/toggle buttons to icon-only so the whole bar
   fits without horizontal scroll. Labels return on wider screens. */
@media (max-width: 560px) {
  .listen-btn .btn-label,
  .icon-toggle .btn-label,
  .link-btn .btn-label { display: none; }
  .listen-btn,
  .icon-toggle { padding: 0.45rem 0.7rem; font-size: 1rem; }
  .link-btn { font-size: 1.1rem; }
}

.reader-heading { font-size: 1.3rem; margin: 0 0 1rem; }

.audio-wrap { margin: 0 0 1.1rem; }
.audio-player { width: 100%; max-width: 100%; height: 38px; }
.audio-error { color: var(--danger); font-size: 0.85rem; margin: 0 0 1rem; }

.speed-row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.5rem;
  flex-wrap: wrap;
}
.speed-label { font-size: 0.78rem; color: var(--text-muted); margin-right: 0.15rem; }
.speed-btn {
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.78rem;
  cursor: pointer;
  transition: border-color 0.15s, color 0.15s;
}
.speed-btn.active { border-color: var(--primary); color: var(--primary); font-weight: 600; }

.verses { line-height: 1.85; font-size: calc(1.05rem * var(--verse-scale, 1)); overflow-wrap: break-word; }
.verses.mm { line-height: 2.1; font-size: calc(1.12rem * var(--verse-scale, 1)); }
/* Right-to-left scripts (Hebrew): flow verses RTL and give the larger pointed
   text room to breathe. The verse number sits to the right of the text. */
.verses.rtl { line-height: 2.2; font-size: calc(1.18rem * var(--verse-scale, 1)); text-align: right; }
.verses.rtl .vnum { margin-right: 0; margin-left: 0.3rem; }
.verse { margin: 0 0 0.6rem; padding: 0.1rem 0.35rem; border-radius: 6px; transition: background 0.25s; }
.verse.highlight { background: rgba(99, 179, 237, 0.30); box-shadow: 0 0 0 6px rgba(99, 179, 237, 0.30); }
/* Select mode: tappable verses + a clear "picked" state for copying. */
.verses.selecting .verse { cursor: pointer; }
.verses.selecting .verse:hover { background: rgba(99, 179, 237, 0.12); }
.verse.selected { background: rgba(99, 179, 237, 0.22); box-shadow: inset 3px 0 0 var(--primary); }
.vnum {
  color: var(--primary);
  font-weight: 700;
  font-size: 0.7em;
  margin-right: 0.3rem;
  vertical-align: super;
}

/* Reading-comfort row: text size chips + background-colour swatches. */
.reader-prefs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.5rem;
}
.prefs-label { font-size: 0.78rem; color: var(--text-muted); }
.prefs-sep { color: var(--text-muted); margin: 0 0.15rem; }
.pref-chip {
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.78rem;
  cursor: pointer;
  transition: border-color 0.15s, color 0.15s;
}
.pref-chip.active { border-color: var(--primary); color: var(--primary); font-weight: 600; }
.swatch {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  border: 1px solid var(--border);
  cursor: pointer;
  padding: 0;
  font-size: 0.75rem;
  color: var(--text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.swatch.swatch-default { background: var(--surface); }
.swatch.active { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }

/* Floating selection action bar (above the Prev/Next footer). */
.copy-bar {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0 -1.5rem;
  padding: 0.5rem 1.5rem;
  background: var(--surface);
  border-top: 1px solid var(--border);
}
.copy-count { font-size: 0.85rem; font-weight: 600; }
.copy-status { font-size: 0.8rem; color: var(--primary); }
.copy-clear {
  margin-left: auto;
  background: none;
  border: none;
  color: var(--text-muted);
  font-size: 0.85rem;
  cursor: pointer;
}
.copy-btn {
  padding: 0.4rem 0.9rem;
  border: 1px solid var(--primary);
  border-radius: 999px;
  background: var(--primary);
  color: #fff;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
}

/* Frozen footer: Previous/Next stay pinned to the bottom of the viewport so
   chapter navigation is always reachable without scrolling to the end. */
.reader-nav {
  flex: 0 0 auto;
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin: 0 -1.5rem;
  padding: 0.7rem 1.5rem;
  background: var(--bg);
  border-top: 1px solid var(--border);
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
