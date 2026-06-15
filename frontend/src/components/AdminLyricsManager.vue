<script setup>
import { ref, computed, onMounted } from "vue";
import { ChordProParser, HtmlDivFormatter } from "chordsheetjs";
import { api } from "../composables/useApi.js";

// ── Library state ─────────────────────────────────────────────────────────────
const songs   = ref([]);
const loading = ref(true);
const error   = ref("");

const LANGUAGES = [
  { value: "my", label: "မြန်မာ (Myanmar)" },
  { value: "td", label: "Zolai (Tedim)" },
];
const CATEGORIES = ["Worship", "Praise", "Hymn", "Special"];

const filterLang = ref("all");
const search     = ref("");

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return songs.value.filter((s) => {
    if (filterLang.value !== "all" && s.language !== filterLang.value) return false;
    if (!q) return true;
    return s.title.toLowerCase().includes(q) || (s.artist || "").toLowerCase().includes(q);
  });
});

async function loadSongs() {
  loading.value = true;
  error.value = "";
  try {
    const { songs: list } = await api.getSongs();
    songs.value = list || [];
  } catch (e) {
    error.value = e.data?.message || e.message || "Could not load songs.";
  } finally {
    loading.value = false;
  }
}
onMounted(loadSongs);

// ── Editor state ──────────────────────────────────────────────────────────────
const blankForm = () => ({
  id: null,
  language: "my",
  title: "",
  artist: "",
  category: "",
  lyrics: "",
});
const form      = ref(blankForm());
const editing   = ref(false);
const isSaving  = ref(false);
const message   = ref("");
const status    = ref(""); // 'success' | 'error'
const lyricsRef = ref(null);

function newSong() {
  form.value = blankForm();
  editing.value = true;
  message.value = "";
}
function editSong(song) {
  form.value = {
    id: song.id,
    language: song.language,
    title: song.title,
    artist: song.artist || "",
    category: song.category || "",
    lyrics: song.lyrics || "",
  };
  editing.value = true;
  message.value = "";
}
function cancelEdit() {
  editing.value = false;
  form.value = blankForm();
}

async function deleteSong(song) {
  if (!confirm(`Delete "${song.title}"? This cannot be undone.`)) return;
  try {
    await api.adminDeleteSong(song.id);
    songs.value = songs.value.filter((s) => s.id !== song.id);
    if (form.value.id === song.id) cancelEdit();
  } catch (e) {
    alert(`Delete failed: ${e.data?.message || e.message}`);
  }
}

async function save() {
  if (!form.value.title.trim() || !form.value.lyrics.trim()) return;
  isSaving.value = true;
  message.value = "";
  status.value = "";
  const payload = {
    language: form.value.language,
    title: form.value.title.trim(),
    artist: form.value.artist.trim() || null,
    category: form.value.category || null,
    lyrics: form.value.lyrics,
  };
  try {
    if (form.value.id) {
      const { song } = await api.adminUpdateSong(form.value.id, payload);
      const i = songs.value.findIndex((s) => s.id === song.id);
      if (i !== -1) songs.value[i] = song;
    } else {
      const { song } = await api.adminCreateSong(payload);
      songs.value.push(song);
    }
    message.value = "Saved to the song library.";
    status.value = "success";
    editing.value = false;
    form.value = blankForm();
  } catch (e) {
    message.value = `Error: ${e.data?.message || e.message || "Could not save."}`;
    status.value = "error";
  } finally {
    isSaving.value = false;
    setTimeout(() => (message.value = ""), 5000);
  }
}

// ── Bulk export / import ───────────────────────────────────────────────────────
// Export reflects the current view (language filter + search) so admins can export
// "all", just Myanmar, just a search, etc. Import is CSV/JSON only — reliable,
// structured formats; the backend skips songs that already exist.
const exportOpen = ref(false);
const importing  = ref(false);
const fileInput  = ref(null);

const EXPORT_COLS = ["language", "title", "artist", "category", "lyrics", "has_chords", "source", "url"];

function stamp() { return new Date().toISOString().slice(0, 10); }

function downloadBlob(content, filename, mime) {
  const blob = new Blob([content], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function csvCell(v) {
  const s = v == null ? "" : String(v);
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportCsv() {
  const lines = [EXPORT_COLS.join(",")];
  for (const s of filtered.value) lines.push(EXPORT_COLS.map((c) => csvCell(s[c])).join(","));
  // Leading BOM so Excel reads the Myanmar/Tedim UTF-8 correctly.
  downloadBlob("﻿" + lines.join("\r\n"), `songs-${stamp()}.csv`, "text/csv;charset=utf-8");
}

function exportJson() {
  const rows = filtered.value.map((s) => Object.fromEntries(EXPORT_COLS.map((c) => [c, s[c] ?? null])));
  downloadBlob(JSON.stringify(rows, null, 2), `songs-${stamp()}.json`, "application/json");
}

function exportTxt() {
  const sep = "\n" + "—".repeat(40) + "\n\n";
  const blocks = filtered.value.map((s) => {
    const head = [`Title: ${s.title}`];
    if (s.artist) head.push(`Artist: ${s.artist}`);
    head.push(`Language: ${s.language.toUpperCase()}`);
    if (s.category) head.push(`Category: ${s.category}`);
    return head.join("\n") + "\n\n" + (s.lyrics || "");
  });
  downloadBlob(blocks.join(sep) + "\n", `songs-${stamp()}.txt`, "text/plain;charset=utf-8");
}

async function exportPdf() {
  const container = document.createElement("div");
  container.style.cssText = "font-family:'Padauk','Noto Sans Myanmar',sans-serif;padding:8px;color:#111;";
  container.innerHTML = filtered.value.map((s) => `
    <section style="page-break-inside:avoid;margin-bottom:26px;">
      <h2 style="margin:0 0 2px;font-size:17px;">${escapeHtml(s.title)}</h2>
      <div style="font-size:11px;color:#555;margin-bottom:8px;">${
        [s.artist, s.language?.toUpperCase(), s.category].filter(Boolean).map(escapeHtml).join(" · ")
      }</div>
      <pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;line-height:1.7;margin:0;">${escapeHtml(s.lyrics || "")}</pre>
    </section>`).join("");
  const { default: html2pdf } = await import("html2pdf.js");
  await html2pdf()
    .set({ margin: 10, filename: `songs-${stamp()}.pdf`, html2canvas: { scale: 2 }, jsPDF: { unit: "mm", format: "a4" } })
    .from(container)
    .save();
}

function doExport(fmt) {
  exportOpen.value = false;
  if (!filtered.value.length) return;
  ({ csv: exportCsv, txt: exportTxt, pdf: exportPdf, json: exportJson })[fmt]?.();
}

function triggerImport() { fileInput.value?.click(); }

async function onImportFile(e) {
  const file = e.target.files?.[0];
  e.target.value = ""; // let the same file be re-selected later
  if (!file) return;
  importing.value = true;
  message.value = "";
  status.value = "";
  try {
    const res = await api.adminImportSongs(file);
    await loadSongs();
    let msg = `Imported ${res.imported}, skipped ${res.skipped} (already present).`;
    if (res.errors?.length) msg += ` ${res.errors.length} row(s) skipped for errors.`;
    message.value = msg;
    status.value = "success";
  } catch (err) {
    message.value = `Import failed: ${err.data?.message || err.message || "Unknown error."}`;
    status.value = "error";
  } finally {
    importing.value = false;
    setTimeout(() => (message.value = ""), 8000);
  }
}

// ── Chord helpers ──────────────────────────────────────────────────────────────
// Common worship chords for one-click insertion at the cursor (manual entry).
const CHORD_PALETTE = ["C", "D", "E", "F", "G", "A", "B", "Am", "Em", "Dm", "G7", "Csus4", "C/E"];

function insertChord(chord) {
  const el = lyricsRef.value;
  const text = form.value.lyrics;
  const start = el ? el.selectionStart : text.length;
  const end = el ? el.selectionEnd : text.length;
  const token = `[${chord}]`;
  form.value.lyrics = text.slice(0, start) + token + text.slice(end);
  // Restore caret just after the inserted chord.
  requestAnimationFrame(() => {
    if (!el) return;
    el.focus();
    const pos = start + token.length;
    el.setSelectionRange(pos, pos);
  });
}

// Live ChordPro → HTML preview. Falls back to plain text if parsing fails
// (e.g. while the admin is mid-typing an unbalanced bracket).
const previewHtml = computed(() => {
  const raw = form.value.lyrics.trim();
  if (!raw) return "";
  try {
    const parsed = new ChordProParser().parse(raw);
    return new HtmlDivFormatter().format(parsed);
  } catch {
    return `<pre class="raw-fallback">${escapeHtml(raw)}</pre>`;
  }
});

function escapeHtml(s) {
  return s.replace(/[&<>"']/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

// ── AI chord detection (gated by admin setting) ───────────────────────────────
const aiChordsEnabled = ref(false);
const aiChordsModel   = ref("");
onMounted(async () => {
  try {
    const s = await api.adminSettings();
    aiChordsEnabled.value = !!s.ai_chords_enabled;
    aiChordsModel.value = s.ai_chords_model || "";
  } catch { /* settings read is best-effort; AI button just stays hidden */ }
});

const langLabel = (v) => LANGUAGES.find((l) => l.value === v)?.label || v;
</script>

<template>
  <div class="lyrics-mgr">
    <!-- ── LIBRARY LIST ── -->
    <div v-if="!editing" class="panel">
      <div class="panel-head">
        <h2>Song Library</h2>
        <div class="head-actions">
          <div class="menu">
            <button class="btn" :disabled="!filtered.length" @click="exportOpen = !exportOpen">Export ▾</button>
            <div v-if="exportOpen" class="menu-pop">
              <button type="button" @click="doExport('csv')">CSV</button>
              <button type="button" @click="doExport('txt')">TXT</button>
              <button type="button" @click="doExport('pdf')">PDF</button>
              <button type="button" @click="doExport('json')">JSON</button>
            </div>
          </div>
          <button class="btn" :disabled="importing" @click="triggerImport">
            {{ importing ? "Importing…" : "Import" }}
          </button>
          <input ref="fileInput" type="file" accept=".csv,.json,application/json,text/csv" hidden @change="onImportFile" />
          <button class="btn primary" @click="newSong">+ New Song</button>
        </div>
      </div>

      <div class="filters">
        <input v-model="search" class="inp" type="search" placeholder="Search title or artist…" />
        <div class="lang-tabs">
          <button class="tab" :class="{ active: filterLang === 'all' }" @click="filterLang = 'all'">All</button>
          <button v-for="l in LANGUAGES" :key="l.value" class="tab"
                  :class="{ active: filterLang === l.value }" @click="filterLang = l.value">
            {{ l.label }}
          </button>
        </div>
      </div>

      <p v-if="message" class="msg" :class="status">{{ message }}</p>
      <p v-if="loading" class="muted">Loading…</p>
      <p v-else-if="error" class="msg error">{{ error }}</p>
      <p v-else-if="filtered.length === 0" class="muted">No songs yet. Click “New Song” to add one.</p>

      <table v-else class="tbl">
        <thead>
          <tr><th>Title</th><th>Artist</th><th>Lang</th><th>Category</th><th>Chords</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="s in filtered" :key="s.id">
            <td class="t-title">{{ s.title }}</td>
            <td>{{ s.artist || "—" }}</td>
            <td>{{ s.language.toUpperCase() }}</td>
            <td>{{ s.category || "—" }}</td>
            <td>{{ s.has_chords ? "♪" : "—" }}</td>
            <td class="t-actions">
              <button class="btn sm" @click="editSong(s)">Edit</button>
              <button class="btn sm danger" @click="deleteSong(s)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── EDITOR ── -->
    <div v-else class="panel">
      <div class="panel-head">
        <h2>{{ form.id ? "Edit Song" : "New Song" }}</h2>
        <button class="btn" @click="cancelEdit">← Back to list</button>
      </div>

      <form class="editor" @submit.prevent="save">
        <div class="grid">
          <label class="fld">
            <span>Title *</span>
            <input v-model="form.title" class="inp" required placeholder="Song title" />
          </label>
          <label class="fld">
            <span>Artist</span>
            <input v-model="form.artist" class="inp" placeholder="e.g. Hillsong Myanmar" />
          </label>
          <label class="fld">
            <span>Language *</span>
            <select v-model="form.language" class="inp">
              <option v-for="l in LANGUAGES" :key="l.value" :value="l.value">{{ l.label }}</option>
            </select>
          </label>
          <label class="fld">
            <span>Category</span>
            <select v-model="form.category" class="inp">
              <option value="">—</option>
              <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
            </select>
          </label>
        </div>

        <div class="chord-bar">
          <span class="chord-bar-label">Insert chord:</span>
          <button v-for="c in CHORD_PALETTE" :key="c" type="button" class="chip" @click="insertChord(c)">{{ c }}</button>
          <button v-if="aiChordsEnabled" type="button" class="chip ai" disabled
                  :title="`AI model: ${aiChordsModel || 'configure in Settings'} (coming soon)`">
            ✨ AI chords
          </button>
        </div>

        <div class="editor-split">
          <label class="fld grow">
            <span>Lyrics &amp; chords (ChordPro: <code>[G]Amazing [C]grace</code>; sections: <code>[Verse 1]</code>)</span>
            <textarea ref="lyricsRef" v-model="form.lyrics" class="inp mono" rows="16" required
                      placeholder="[Verse 1]&#10;[G]Amazing [G7]grace how [C]sweet the [G]sound&#10;&#10;[Chorus]&#10;..."></textarea>
          </label>
          <div class="preview">
            <span class="preview-label">Preview</span>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div class="chord-sheet" v-html="previewHtml"></div>
          </div>
        </div>

        <p v-if="message" class="msg" :class="status">{{ message }}</p>

        <div class="actions">
          <button type="button" class="btn" @click="cancelEdit">Cancel</button>
          <button type="submit" class="btn primary" :disabled="isSaving">
            {{ isSaving ? "Saving…" : (form.id ? "Update Song" : "Save Song") }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.lyrics-mgr { color: var(--text); }
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius, 12px); padding: 1.25rem 1.4rem; }
.panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; gap: 0.6rem; flex-wrap: wrap; }
.panel-head h2 { font-size: 1.3rem; font-weight: 700; margin: 0; }
.head-actions { display: flex; align-items: center; gap: 0.5rem; }
.menu { position: relative; }
.menu-pop { position: absolute; right: 0; top: calc(100% + 0.3rem); z-index: 10; display: flex; flex-direction: column; min-width: 120px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.3rem; box-shadow: 0 8px 24px rgba(0,0,0,0.25); }
.menu-pop button { text-align: left; padding: 0.45rem 0.7rem; border: none; background: transparent; color: var(--text); cursor: pointer; font: inherit; font-size: 0.85rem; border-radius: 6px; }
.menu-pop button:hover { background: var(--bg); color: var(--primary); }

.filters { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; margin-bottom: 0.9rem; }
.lang-tabs { display: flex; gap: 0.35rem; flex-wrap: wrap; }
.tab { padding: 0.32rem 0.8rem; border: 1px solid var(--border); border-radius: 999px; background: transparent; color: var(--text-muted); cursor: pointer; font: inherit; font-size: 0.82rem; }
.tab.active { background: var(--primary); border-color: var(--primary); color: var(--on-primary); font-weight: 600; }

.inp { width: 100%; padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; box-sizing: border-box; }
.inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-soft); }
.mono { font-family: ui-monospace, "SF Mono", Menlo, monospace; line-height: 1.7; resize: vertical; }

.tbl { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tbl th, .tbl td { text-align: left; padding: 0.55rem 0.6rem; border-bottom: 1px solid var(--border); }
.tbl th { color: var(--text-muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; }
.t-title { font-weight: 600; }
.t-actions { display: flex; gap: 0.4rem; justify-content: flex-end; }

.btn { padding: 0.45rem 0.9rem; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); cursor: pointer; font: inherit; font-size: 0.85rem; }
.btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.btn.primary { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.btn.primary:hover:not(:disabled) { background: var(--primary-hover, var(--primary)); color: var(--on-primary); }
.btn.sm { padding: 0.28rem 0.6rem; font-size: 0.78rem; }
.btn.danger:hover { border-color: var(--danger, #dc2626); color: var(--danger, #dc2626); }
.btn:disabled { opacity: 0.6; cursor: default; }

.editor { display: flex; flex-direction: column; gap: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.8rem; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.85rem; }
.fld > span { color: var(--text-muted); }
.fld code { font-size: 0.78rem; background: var(--bg); padding: 0 0.25rem; border-radius: 4px; }
.fld.grow { flex: 1; }

.chord-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; padding: 0.6rem 0.7rem; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; }
.chord-bar-label { font-size: 0.8rem; color: var(--text-muted); margin-right: 0.3rem; }
.chip { padding: 0.2rem 0.55rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); cursor: pointer; font: inherit; font-size: 0.8rem; font-weight: 600; }
.chip:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.chip.ai { margin-left: auto; opacity: 0.75; cursor: not-allowed; }

.editor-split { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 760px) { .editor-split { grid-template-columns: 1fr; } }
.preview { display: flex; flex-direction: column; gap: 0.3rem; }
.preview-label { font-size: 0.85rem; color: var(--text-muted); }
.chord-sheet { flex: 1; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); padding: 0.9rem 1rem; overflow: auto; min-height: 200px; font-family: "Padauk", "Noto Sans Myanmar", sans-serif; line-height: 2.4; }
.chord-sheet .raw-fallback { white-space: pre-wrap; font-family: ui-monospace, monospace; line-height: 1.6; }

.actions { display: flex; justify-content: flex-end; gap: 0.6rem; }
.msg { font-size: 0.85rem; margin: 0.2rem 0; }
.msg.success { color: var(--success, #16a34a); }
.msg.error { color: var(--danger, #dc2626); }
.muted { color: var(--text-muted); font-size: 0.9rem; }
</style>

<!-- ChordSheetJS HtmlDivFormatter output classes (unscoped so v-html is styled). -->
<style>
.chord-sheet .row { display: flex; flex-wrap: wrap; align-items: flex-end; }
.chord-sheet .column { display: inline-flex; flex-direction: column; }
.chord-sheet .chord { color: var(--primary, #2563eb); font-weight: 700; font-family: ui-monospace, monospace; min-height: 1.2em; white-space: pre; }
.chord-sheet .chord:not(:empty) { padding-right: 0.4em; }
.chord-sheet .lyrics { white-space: pre; }
.chord-sheet .paragraph { margin-bottom: 1rem; }
.chord-sheet .comment { font-weight: 700; color: var(--text-muted); margin: 0.4rem 0 0.2rem; }
</style>
