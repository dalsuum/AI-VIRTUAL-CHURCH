<script setup>
import { ref, computed, onMounted } from "vue";
import { ChordProParser, HtmlDivFormatter } from "chordsheetjs";
import { api } from "../composables/useApi.js";

// ── Data loading ────────────────────────────────────────────────────────────
// Two sources: the static classic/modern hymn corpus (hymns_my.json: 852 hymns,
// not in the DB) and the admin-managed library read live from the backend
// (GET /songs — the single source of truth for worship songs).
const loading = ref(true);
const error   = ref("");
const allSongs = ref([]);   // { id, title, lyrics, source, url }

onMounted(async () => {
  try {
    const [hymnRes, libRes] = await Promise.all([
      fetch("/data/hymns_my.json").then((r) => r.json()),
      // Admin-managed library (may be empty / unreachable — degrade gracefully).
      api.getSongs().catch(() => ({ songs: [] })),
    ]);

    let id = 0;
    const hymns = (hymnRes.hymns || []).map((h) => ({
      id: ++id,
      title:  h.title,
      lyrics: h.lyrics,
      source: h.source,   // "hymnal" | "modern"
      hasChords: false,
      url:    null,
    }));

    // Admin-CRUD songs: Myanmar join the praise list; Zolai get their own tab.
    const library = (libRes.songs || []).map((s) => ({
      id: ++id,
      title:  s.title,
      lyrics: s.lyrics,
      source: s.language === "td" ? "zolai" : "praise",
      hasChords: !!s.has_chords,
      url:    null,
    }));

    allSongs.value = [...hymns, ...library];
  } catch (e) {
    error.value = "သီချင်းများ ဖတ်ရန် မရနိုင်ပါ။ (Could not load songs.)";
  } finally {
    loading.value = false;
  }
});

// ── Filters / list view ───────────────────────────────────────────────────────
const search       = ref("");
const activeSource = ref("all");
const selectedId   = ref(null);
const PAGE_SIZE    = 48;
const page         = ref(1);

const SOURCE_LABELS = {
  all:    "အားလုံး",
  hymnal: "ဓမ္မသီချင်း",
  modern: "ခေတ်မီသီချင်း",
  praise: "ချီးမွမ်းသီချင်း",
  zolai:  "Zolai",
};

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return allSongs.value.filter((s) => {
    if (activeSource.value !== "all" && s.source !== activeSource.value) return false;
    if (!q) return true;
    return s.title.toLowerCase().includes(q) || s.lyrics.toLowerCase().includes(q);
  });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / PAGE_SIZE)));
const pageSongs = computed(() => {
  const start = (page.value - 1) * PAGE_SIZE;
  return filtered.value.slice(start, start + PAGE_SIZE);
});

function setSource(src) {
  activeSource.value = src;
  page.value = 1;
  search.value = "";
}
function onSearch() { page.value = 1; }
function prevPage() { if (page.value > 1) page.value--; }
function nextPage() { if (page.value < totalPages.value) page.value++; }

// ── Detail view ───────────────────────────────────────────────────────────────
const activeSong = computed(() => allSongs.value.find((s) => s.id === selectedId.value) || null);

// Parse [Section] markers if present; otherwise split blank-line groups into verses.
const parsedLyrics = computed(() => {
  const raw = activeSong.value?.lyrics?.trim();
  if (!raw) return [];

  if (raw.includes("[")) {
    const sections = [];
    const regex = /\[(.*?)\]\n?([\s\S]*?)(?=\n\[|$)/g;
    let m;
    while ((m = regex.exec(raw)) !== null) {
      sections.push({ type: m[1].trim(), text: m[2].trim() });
    }
    if (sections.length) return sections;
  }

  const blocks = raw.split(/\n\s*\n/).map((b) => b.trim()).filter(Boolean);
  return blocks.map((text) => ({ type: "", text }));
});

// For songs carrying inline ChordPro chords, render a chord-over-lyric sheet.
const chordSheetHtml = computed(() => {
  if (!activeSong.value?.hasChords) return "";
  try {
    const parsed = new ChordProParser().parse(activeSong.value.lyrics);
    return new HtmlDivFormatter().format(parsed);
  } catch {
    return "";
  }
});

function isChorus(type) {
  const t = (type || "").toLowerCase();
  return t.includes("chorus") || t.includes("cho") || t.includes("ထပ်ဆို");
}

// ── Export ──────────────────────────────────────────────────────────────────
function exportTxt() {
  const song = activeSong.value;
  if (!song) return;
  let content = `${song.title}\n${SOURCE_LABELS[song.source]}\n\n`;
  parsedLyrics.value.forEach((sec) => {
    content += sec.type ? `[${sec.type}]\n${sec.text}\n\n` : `${sec.text}\n\n`;
  });
  const blob = new Blob([content], { type: "text/plain;charset=utf-8" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `${song.title}.txt`;
  link.click();
  URL.revokeObjectURL(link.href);
}

const lyricsContentRef = ref(null);
const pdfBusy = ref(false);

async function exportPdf() {
  if (!activeSong.value || !lyricsContentRef.value) return;
  pdfBusy.value = true;
  try {
    const mod = await import("html2pdf.js");
    const html2pdf = mod.default || mod;
    await html2pdf()
      .set({
        margin: 15,
        filename: `${activeSong.value.title}.pdf`,
        image: { type: "jpeg", quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: "mm", format: "a4", orientation: "portrait" },
      })
      .from(lyricsContentRef.value)
      .save();
  } finally {
    pdfBusy.value = false;
  }
}

// Worship-ready PowerPoint: a title slide + one slide per verse/section, large
// centred white text on a dark projection background. Chord markers are stripped
// so the congregation sees clean lyrics.
const pptxBusy = ref(false);

async function exportPptx() {
  const song = activeSong.value;
  if (!song) return;
  pptxBusy.value = true;
  try {
    const { default: PptxGenJS } = await import("pptxgenjs");
    const pptx = new PptxGenJS();
    pptx.layout = "LAYOUT_WIDE";            // 13.33 × 7.5 in (16:9)

    const BG = "0F172A", FG = "FFFFFF", ACC = "93C5FD";
    const FONT = "Padauk";                  // Myanmar-capable; PowerPoint falls back if absent
    const stripChords = (s) => s.replace(/\[[^\]]*\]/g, "").replace(/[^\S\n]+\n/g, "\n").trim();

    // Title slide.
    const title = pptx.addSlide();
    title.background = { color: BG };
    title.addText(song.title, { x: 0.5, y: 2.6, w: 12.33, h: 1.6, align: "center", color: FG, bold: true, fontSize: 44, fontFace: FONT });
    title.addText(SOURCE_LABELS[song.source] || "", { x: 0.5, y: 4.3, w: 12.33, h: 0.8, align: "center", color: ACC, fontSize: 24, fontFace: FONT });

    // One slide per verse/section.
    for (const sec of parsedLyrics.value) {
      const body = stripChords(sec.text);
      if (!body) continue;
      const slide = pptx.addSlide();
      slide.background = { color: BG };
      if (sec.type) {
        slide.addText(sec.type.toUpperCase(), { x: 0.5, y: 0.3, w: 12.33, h: 0.6, align: "center", color: ACC, bold: true, fontSize: 18, fontFace: FONT });
      }
      slide.addText(body, { x: 0.6, y: 0.9, w: 12.13, h: 5.9, align: "center", valign: "middle", color: FG, bold: true, fontSize: 32, fontFace: FONT, lineSpacingMultiple: 1.2 });
    }

    await pptx.writeFile({ fileName: `${song.title}.pptx` });
  } finally {
    pptxBusy.value = false;
  }
}
</script>

<template>
  <div class="lyrics-page">
    <!-- Header -->
    <header class="lyrics-header">
      <a v-if="!activeSong" href="#" class="back-link">&#8592; ဝတ်ပြုပွဲသို့ ပြန်သွားမည်</a>
      <button v-else class="back-link as-button" @click="selectedId = null">&#8592; စာရင်းသို့ ပြန်သွားမည်</button>
      <div class="title-block">
        <h1 class="lyrics-title">မြန်မာ ဝတ်ပြုသီချင်း</h1>
        <p class="lyrics-sub">
          Myanmar Worship Song Lyrics ·
          <span v-if="!loading">{{ allSongs.length.toLocaleString() }} သီချင်း</span>
          <span v-else>တင်နေသည်…</span>
        </p>
      </div>
    </header>

    <!-- Loading / error -->
    <div v-if="loading" class="status-msg">တင်နေသည်…</div>
    <div v-else-if="error" class="status-msg error-msg">{{ error }}</div>

    <!-- ── LIST VIEW ── -->
    <template v-else-if="!activeSong">
      <div class="controls">
        <input
          v-model="search"
          class="search-input"
          type="search"
          placeholder="ခေါင်းစဉ် သို့ စာသားဖြင့် ရှာပါ…"
          aria-label="Search songs"
          @input="onSearch"
        />
        <div class="source-tabs" role="tablist">
          <button
            v-for="(label, key) in SOURCE_LABELS"
            :key="key"
            role="tab"
            class="src-tab"
            :class="{ active: activeSource === key }"
            @click="setSource(key)"
          >
            {{ label }}
            <span class="src-count">
              {{ key === 'all' ? allSongs.length : allSongs.filter(s => s.source === key).length }}
            </span>
          </button>
        </div>
      </div>

      <div v-if="search || activeSource !== 'all'" class="results-info">
        {{ filtered.length }} သီချင်း တွေ့ရသည်
        <span v-if="totalPages > 1"> · စာမျက်နှာ {{ page }} / {{ totalPages }}</span>
      </div>

      <div class="song-grid">
        <p v-if="pageSongs.length === 0" class="empty">မတွေ့ပါ။</p>
        <button
          v-for="song in pageSongs"
          :key="song.id"
          class="song-card"
          @click="selectedId = song.id"
        >
          <span class="song-title">{{ song.title }}</span>
          <span class="src-badge" :class="song.source">{{ SOURCE_LABELS[song.source] }}</span>
        </button>
      </div>

      <div v-if="totalPages > 1" class="pagination">
        <button class="page-btn" :disabled="page === 1" @click="prevPage">&#8592; နောက်ဆုတ်</button>
        <span class="page-info">{{ page }} / {{ totalPages }}</span>
        <button class="page-btn" :disabled="page === totalPages" @click="nextPage">ရှေ့ဆက် &#8594;</button>
      </div>
    </template>

    <!-- ── DETAIL VIEW ── -->
    <template v-else>
      <div class="toolbar">
        <button class="tool-btn" @click="exportTxt">Export .TXT</button>
        <button class="tool-btn primary" :disabled="pdfBusy" @click="exportPdf">
          {{ pdfBusy ? "Generating…" : "Download PDF" }}
        </button>
        <button class="tool-btn primary" :disabled="pptxBusy" @click="exportPptx">
          {{ pptxBusy ? "Generating…" : "Download PPTX" }}
        </button>
      </div>

      <div ref="lyricsContentRef" class="lyrics-sheet">
        <div class="sheet-head">
          <h2>{{ activeSong.title }}</h2>
          <p class="sheet-sub">{{ SOURCE_LABELS[activeSong.source] }}</p>
        </div>

        <!-- Chord sheet (songs with inline chords) -->
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div v-if="chordSheetHtml" class="chord-sheet" v-html="chordSheetHtml"></div>

        <div v-else class="sections">
          <div
            v-for="(section, idx) in parsedLyrics"
            :key="idx"
            class="section"
            :class="{ chorus: isChorus(section.type) }"
          >
            <span v-if="section.type" class="section-label">{{ section.type }}</span>
            <p class="section-text">{{ section.text }}</p>
          </div>
        </div>
      </div>
    </template>

    <footer class="lyrics-footer">
      မြန်မာ ဝတ်ပြုသီချင်းနှင့် ဓမ္မသီချင်းများ
    </footer>
  </div>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=Padauk:wght@400;700&family=Noto+Sans+Myanmar:wght@400;600&display=swap");

.lyrics-page {
  min-height: 100vh;
  padding-bottom: 4rem;
  background: var(--bg);
  color: var(--text);
  font-family: "Padauk", "Noto Sans Myanmar", "Myanmar Text", sans-serif;
  line-height: 1.85;
}

/* Header */
.lyrics-header {
  padding: 1.25rem 1.5rem 0.75rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}
.back-link {
  display: inline-block;
  font-size: 0.83rem;
  color: var(--text-muted);
  text-decoration: none;
  margin-bottom: 0.5rem;
  transition: color 0.15s;
}
.back-link.as-button { border: none; background: none; cursor: pointer; font: inherit; padding: 0; }
.back-link:hover { color: var(--primary); }
.title-block { margin-bottom: 0.5rem; }
.lyrics-title { font-size: 1.45rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 0.2rem; }
.lyrics-sub { font-size: 0.83rem; color: var(--text-muted); margin: 0; }

/* Controls */
.controls {
  padding: 0.85rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  position: sticky;
  top: 0;
  z-index: 5;
  background: color-mix(in srgb, var(--bg) 90%, transparent);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--border);
}
.search-input {
  width: 100%;
  max-width: 420px;
  padding: 0.5rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: 0.9rem;
  outline: none;
  transition: border-color 0.15s;
}
.search-input:focus { border-color: var(--primary); }
.source-tabs { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.src-tab {
  display: inline-flex; align-items: center; gap: 0.3rem;
  padding: 0.3rem 0.8rem;
  font-size: 0.8rem; font-family: inherit;
  border: 1px solid var(--border); border-radius: 999px;
  background: transparent; color: var(--text-muted); cursor: pointer;
  transition: all 0.12s;
}
.src-tab:hover { border-color: var(--primary); color: var(--primary); }
.src-tab.active { background: var(--primary); border-color: var(--primary); color: var(--on-primary); font-weight: 600; }
.src-count { font-size: 0.72rem; opacity: 0.75; }

.results-info {
  padding: 0.55rem 1.5rem;
  font-size: 0.8rem; color: var(--text-muted);
  border-bottom: 1px solid var(--border);
}

/* List grid */
.song-grid {
  padding: 1rem 1.5rem 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 0.6rem;
}
.empty { grid-column: 1 / -1; text-align: center; color: var(--text-muted); padding: 2.5rem 0; font-size: 0.9rem; }
.song-card {
  display: flex; flex-direction: column; gap: 0.4rem;
  text-align: left; padding: 0.85rem 1rem;
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--text); cursor: pointer;
  transition: border-color 0.12s, box-shadow 0.12s;
}
.song-card:hover { border-color: var(--primary); box-shadow: var(--shadow); }
.song-title { font-weight: 500; font-size: 0.92rem; line-height: 1.5; }
.src-badge {
  align-self: flex-start;
  font-size: 0.68rem; font-weight: 600;
  padding: 0.12rem 0.5rem; border-radius: 999px; border: 1px solid;
}
.src-badge.hymnal { color: #7c3aed; border-color: #7c3aed; }
.src-badge.modern { color: #059669; border-color: #059669; }
.src-badge.praise { color: #d97706; border-color: #d97706; }
.src-badge.zolai { color: #2563eb; border-color: #2563eb; }

/* Chord sheet */
.chord-sheet { line-height: 2.6; font-size: 1.05rem; }
.chord-sheet :deep(.row) { display: flex; flex-wrap: wrap; align-items: flex-end; }
.chord-sheet :deep(.column) { display: inline-flex; flex-direction: column; }
.chord-sheet :deep(.chord) { color: var(--primary); font-weight: 700; font-family: ui-monospace, monospace; min-height: 1.2em; white-space: pre; }
.chord-sheet :deep(.chord:not(:empty)) { padding-right: 0.4em; }
.chord-sheet :deep(.lyrics) { white-space: pre; }
.chord-sheet :deep(.paragraph) { margin-bottom: 1.1rem; }
.chord-sheet :deep(.comment) { font-weight: 700; color: var(--text-muted); margin: 0.5rem 0 0.3rem; }

/* Pagination */
.pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: 1.5rem; }
.page-btn {
  padding: 0.45rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--text); font: inherit; font-size: 0.85rem; cursor: pointer;
  transition: border-color 0.12s, color 0.12s;
}
.page-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.page-btn:disabled { opacity: 0.4; cursor: default; }
.page-info { font-size: 0.85rem; color: var(--text-muted); }

/* Detail toolbar */
.toolbar { display: flex; justify-content: flex-end; gap: 0.6rem; padding: 1rem 1.5rem 0; max-width: 760px; margin: 0 auto; width: 100%; box-sizing: border-box; }
.tool-btn {
  padding: 0.4rem 0.9rem; font-size: 0.82rem; font-family: inherit;
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--text); cursor: pointer; transition: all 0.12s;
}
.tool-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.tool-btn.primary { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.tool-btn.primary:hover:not(:disabled) { background: var(--primary-hover); }
.tool-btn:disabled { opacity: 0.6; cursor: default; }

/* Lyrics sheet */
.lyrics-sheet {
  max-width: 760px; margin: 1rem auto 0; padding: 1.75rem 1.5rem;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
}
.sheet-head { text-align: center; margin-bottom: 1.75rem; }
.sheet-head h2 { font-size: 1.6rem; font-weight: 700; margin: 0 0 0.4rem; }
.sheet-sub { color: var(--text-muted); font-size: 0.85rem; margin: 0; }
.sections { display: flex; flex-direction: column; gap: 1.1rem; }
.section { padding: 0.5rem 0.25rem; }
.section.chorus {
  background: var(--primary-soft);
  border-left: 3px solid var(--primary);
  border-radius: var(--radius-sm);
  padding: 0.75rem 1rem;
}
.section-label {
  display: block; font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.05em;
  color: var(--text-faint); margin-bottom: 0.4rem;
}
.section-text {
  margin: 0; white-space: pre-wrap; word-break: break-word;
  font-size: 1rem; line-height: 2; color: var(--text);
}
.status-msg { text-align: center; color: var(--text-muted); padding: 3rem 1.5rem; font-size: 0.95rem; }
.error-msg { color: var(--danger); }

.lyrics-footer { padding: 2rem 1.5rem 0; text-align: center; font-size: 0.75rem; color: var(--text-muted); }

@media (max-width: 500px) {
  .lyrics-header, .controls, .song-grid { padding-left: 1rem; padding-right: 1rem; }
  .lyrics-title { font-size: 1.2rem; }
  .lyrics-sheet { margin-left: 1rem; margin-right: 1rem; }
}
</style>
