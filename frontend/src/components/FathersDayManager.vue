<!--
  Father's Day (Special Day) MV — admin manager (SELF-CONTAINED & REMOVABLE).
  Global settings + a song library (each song: file, title, lyrics, sync mode,
  detected vocal-onset, tap-to-sync). Remove with the rest of the feature.
-->
<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { api } from "../composables/useApi";

const cfg = ref(null);
const loading = ref(true);
const saving = ref(false);
const adding = ref(false);
const msg = ref("");
const err = ref("");
const newSong = ref({ file: null, title: "" });

const EFFECTS = [
  { value: "slide", label: "Slideshow (hard cut)" },
  { value: "fade", label: "Crossfade" },
  { value: "kenburns", label: "Gentle zoom (Ken Burns)" },
];

let detectPoll = null;

onMounted(() => { load(); window.addEventListener("keydown", onKey); });
onUnmounted(() => {
  detectPoll && clearInterval(detectPoll);
  window.removeEventListener("keydown", onKey);
  if (syncObjUrl.value) URL.revokeObjectURL(syncObjUrl.value);
  if (clipObjUrl.value) URL.revokeObjectURL(clipObjUrl.value);
});

async function load() {
  loading.value = true;
  try {
    cfg.value = await api.fdAdminShow();
    watchDetection();
  } catch (e) {
    err.value = e.message || "Failed to load configuration.";
  } finally {
    loading.value = false;
  }
}

function watchDetection() {
  if (detectPoll) { clearInterval(detectPoll); detectPoll = null; }
  const anyDetecting = (cfg.value?.songs || []).some((s) => s.vocal_start_status === "detecting");
  if (!anyDetecting) return;
  detectPoll = setInterval(async () => {
    try {
      const fresh = await api.fdAdminShow();
      // merge detection results without clobbering unsaved lyric/title edits
      fresh.songs.forEach((fs) => {
        const cur = cfg.value.songs.find((s) => s.id === fs.id);
        if (cur) { cur.vocal_start = fs.vocal_start; cur.vocal_start_status = fs.vocal_start_status; }
      });
      if (!fresh.songs.some((s) => s.vocal_start_status === "detecting")) {
        clearInterval(detectPoll); detectPoll = null;
      }
    } catch { /* keep polling */ }
  }, 5000);
}

async function saveSettings() {
  saving.value = true; msg.value = ""; err.value = "";
  try {
    const r = await api.fdAdminSave({
      enabled: !!cfg.value.enabled,
      title: cfg.value.title || "",
      subtitle: cfg.value.subtitle || "",
      default_effect: cfg.value.default_effect || "slide",
    });
    cfg.value.updated_at = r.updated_at;
    msg.value = "Settings saved.";
  } catch (e) {
    err.value = e.message || "Save failed.";
  } finally {
    saving.value = false;
  }
}

async function resetUsage() {
  if (!confirm("Reset the visitor traffic count for this page to zero?")) return;
  saving.value = true; msg.value = ""; err.value = "";
  try {
    const r = await api.fdResetUsage();
    cfg.value.usage = r.usage;
    msg.value = "Traffic count reset.";
  } catch (e) {
    err.value = e.message || "Reset failed.";
  } finally {
    saving.value = false;
  }
}

async function addSong() {
  if (!newSong.value.file) return;
  adding.value = true; msg.value = ""; err.value = "";
  try {
    cfg.value = await api.fdAddSong(newSong.value.file, newSong.value.title);
    newSong.value = { file: null, title: "" };
    msg.value = "Song added. Detecting where the vocals start…";
    watchDetection();
  } catch (e) {
    err.value = e.message || "Upload failed.";
  } finally {
    adding.value = false;
  }
}

async function saveSong(song) {
  msg.value = ""; err.value = "";
  try {
    cfg.value = await api.fdUpdateSong(song.id, {
      title: song.title || "",
      lyrics: song.lyrics || "",
      sync_enabled: !!song.sync_enabled,
      vocal_start: song.vocal_start === "" || song.vocal_start == null ? null : Number(song.vocal_start),
      clip_enabled: !!song.clip_enabled,
      clip_start: song.clip_start === "" || song.clip_start == null ? null : Number(song.clip_start),
      clip_end: song.clip_end === "" || song.clip_end == null ? null : Number(song.clip_end),
    });
    msg.value = "Song saved.";
  } catch (e) {
    err.value = e.message || "Save failed.";
  }
}

async function removeSong(song) {
  if (!confirm(`Delete "${song.title}"? This can't be undone.`)) return;
  try {
    cfg.value = await api.fdDeleteSong(song.id);
    msg.value = "Song deleted.";
  } catch (e) {
    err.value = e.message || "Delete failed.";
  }
}

// ── Tap-to-sync (per song) ──────────────────────────────────────────────────
const audioEl = ref(null);
const syncSong = ref(null);
const syncLines = ref([]);
const syncTimes = ref([]);
const syncIdx = ref(0);
const syncObjUrl = ref("");
const isPlaying = ref(false);

function displayableLines(text) {
  return (text || "")
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter((l) => l !== "" && !/^\[[^\]]*\]$/.test(l))
    .map((l) => l.replace(/\[[0-9:.]+\]/g, "").trim())
    .filter((l) => l !== "");
}

async function openSync(song) {
  err.value = ""; msg.value = "";
  try {
    const blob = await api.fdSongBlob(song.id);
    if (syncObjUrl.value) URL.revokeObjectURL(syncObjUrl.value);
    syncObjUrl.value = URL.createObjectURL(blob);
    syncSong.value = song;
    syncLines.value = displayableLines(song.lyrics);
    syncTimes.value = syncLines.value.map(() => null);
    syncIdx.value = 0;
  } catch (e) {
    err.value = e.message || "Could not load the song.";
  }
}

function fmtLrc(sec) {
  const m = Math.floor(sec / 60);
  const s = (sec - m * 60).toFixed(2).padStart(5, "0");
  return `[${String(m).padStart(2, "0")}:${s}]`;
}
function tap() {
  const a = audioEl.value;
  if (!a || syncIdx.value >= syncLines.value.length) return;
  syncTimes.value[syncIdx.value] = a.currentTime;
  syncIdx.value++;
}
function undoTap() {
  if (syncIdx.value > 0) { syncIdx.value--; syncTimes.value[syncIdx.value] = null; }
}
function restartSync() {
  if (audioEl.value) audioEl.value.currentTime = 0;
  syncTimes.value = syncLines.value.map(() => null);
  syncIdx.value = 0;
}
function togglePlay() {
  const a = audioEl.value; if (!a) return;
  a.paused ? a.play() : a.pause();
}
async function saveSync() {
  const lrc = syncLines.value
    .map((line, i) => (syncTimes.value[i] != null ? `${fmtLrc(syncTimes.value[i])} ${line}` : line))
    .join("\n");
  try {
    cfg.value = await api.fdUpdateSong(syncSong.value.id, { lyrics: lrc, sync_enabled: true });
    msg.value = "Timings saved.";
    closeSync();
  } catch (e) {
    err.value = e.message || "Save failed.";
  }
}
function closeSync() {
  if (audioEl.value) audioEl.value.pause();
  syncSong.value = null;
}
function onKey(e) {
  if (!syncSong.value) return;
  if (e.code === "Space") { e.preventDefault(); tap(); }
}

// ── Clip picker (chorus/hook range) ─────────────────────────────────────────
const clipSong = ref(null);
const clipAudio = ref(null);
const clipObjUrl = ref("");
const clipStartVal = ref(0);
const clipEndVal = ref(0);

async function openClip(song) {
  err.value = ""; msg.value = "";
  try {
    const blob = await api.fdSongBlob(song.id);
    if (clipObjUrl.value) URL.revokeObjectURL(clipObjUrl.value);
    clipObjUrl.value = URL.createObjectURL(blob);
    clipSong.value = song;
    clipStartVal.value = Number(song.clip_start) || 0;
    clipEndVal.value = Number(song.clip_end) || 0;
  } catch (e) {
    err.value = e.message || "Could not load the song.";
  }
}
function setClipStart() { if (clipAudio.value) clipStartVal.value = Math.round(clipAudio.value.currentTime * 10) / 10; }
function setClipEnd() { if (clipAudio.value) clipEndVal.value = Math.round(clipAudio.value.currentTime * 10) / 10; }
function previewClip() {
  const a = clipAudio.value; if (!a) return;
  a.currentTime = clipStartVal.value; a.play();
}
async function saveClip() {
  if (clipEndVal.value - clipStartVal.value < 1) { err.value = "Clip end must be at least 1s after start."; return; }
  try {
    cfg.value = await api.fdUpdateSong(clipSong.value.id, {
      clip_enabled: true,
      clip_start: Number(clipStartVal.value),
      clip_end: Number(clipEndVal.value),
    });
    msg.value = "Clip saved.";
    closeClip();
  } catch (e) {
    err.value = e.message || "Save failed.";
  }
}
function closeClip() {
  if (clipAudio.value) clipAudio.value.pause();
  clipSong.value = null;
}
</script>

<template>
  <section class="fdm">
    <h2>Special Day Music Video</h2>
    <p class="hint">
      Visitors pick a song, upload photo(s) of their father at <code>#fathers-day</code>,
      and download a vertical MV. Reusable for any special day.
    </p>

    <p v-if="loading">Loading…</p>

    <div v-else-if="cfg" class="fdm-grid">
      <!-- Global settings -->
      <label class="toggle">
        <input type="checkbox" v-model="cfg.enabled" />
        <span><strong>Enabled</strong> — show the page to visitors</span>
      </label>
      <div class="field">
        <label>Page title</label>
        <input type="text" v-model="cfg.title" maxlength="120" placeholder="Happy Father's Day" />
      </div>
      <div class="field">
        <label>Subtitle</label>
        <input type="text" v-model="cfg.subtitle" maxlength="200" placeholder="Make a music video for your father" />
      </div>
      <div class="field">
        <label>Default effect (for multiple photos)</label>
        <select v-model="cfg.default_effect">
          <option v-for="e in EFFECTS" :key="e.value" :value="e.value">{{ e.label }}</option>
        </select>
      </div>
      <div class="actions">
        <button class="btn primary" :disabled="saving" @click="saveSettings">{{ saving ? "Saving…" : "Save settings" }}</button>
      </div>

      <div v-if="cfg.usage" class="usage">
        <span>Visitor traffic: <strong>{{ cfg.usage.total }}</strong> total · {{ cfg.usage.today }} today</span>
        <button class="btn" type="button" :disabled="saving" @click="resetUsage">Reset count</button>
      </div>

      <hr />

      <!-- Song library -->
      <h3>Songs ({{ cfg.songs.length }})</h3>
      <p v-if="cfg.enabled && cfg.songs.length === 0" class="bad small">⚠ Add at least one song or the page won't show to visitors.</p>

      <div v-for="song in cfg.songs" :key="song.id" class="song-card">
        <div class="field">
          <label>Title</label>
          <input type="text" v-model="song.title" maxlength="120" />
        </div>

        <div class="field">
          <label>Lyrics</label>
          <textarea v-model="song.lyrics" rows="6"
            placeholder="One line per row. Section markers like [Verse 1] are ignored."></textarea>
          <label class="toggle small">
            <input type="checkbox" v-model="song.sync_enabled" />
            <span>Time-synced highlight (LRC <code>[mm:ss.xx]</code>). Off = even split from vocals.</span>
          </label>
        </div>

        <div class="field">
          <label>Lyrics start — vocal onset (sec)</label>
          <div class="inline">
            <input type="number" min="0" max="600" step="0.1" v-model="song.vocal_start" style="max-width:110px" />
            <span class="hint small" :class="{ detecting: song.vocal_start_status === 'detecting' }">
              <template v-if="song.vocal_start_status === 'detecting'">⏳ Detecting vocals…</template>
              <template v-else-if="song.vocal_start_status === 'ready'">✓ Auto-detected — edit to override</template>
              <template v-else-if="song.vocal_start_status === 'failed'">⚠ Auto-detect failed — set manually</template>
            </span>
          </div>
        </div>

        <div class="field">
          <label class="toggle">
            <input type="checkbox" v-model="song.clip_enabled" />
            <span><strong>Use chorus/hook clip only</strong> — shorter video, saves server resources</span>
          </label>
          <div v-if="song.clip_enabled" class="inline" style="margin-top:0.4rem">
            <label class="small">Start (s) <input type="number" min="0" step="0.1" v-model="song.clip_start" style="max-width:90px" /></label>
            <label class="small">End (s) <input type="number" min="0" step="0.1" v-model="song.clip_end" style="max-width:90px" /></label>
            <button class="btn" type="button" @click="openClip(song)">🎧 Pick from audio</button>
          </div>
          <p v-if="song.clip_enabled" class="hint small">The video uses only this slice (e.g. the chorus). Lyrics shown are the ones sung within it.</p>
        </div>

        <div class="sync-row">
          <button class="btn" type="button" @click="openSync(song)">🎤 Tap-to-sync</button>
          <button class="btn primary" type="button" @click="saveSong(song)">💾 Save song</button>
          <button class="btn ghost danger" type="button" @click="removeSong(song)">🗑 Delete</button>
        </div>
      </div>

      <!-- Add song -->
      <div class="song-card add">
        <h4>Add a song</h4>
        <div class="field">
          <label>Title (optional)</label>
          <input type="text" v-model="newSong.title" maxlength="120" placeholder="e.g. ဖေဖေ — For My Father" />
        </div>
        <div class="field">
          <label>Audio file (MP3 / WAV, up to 50 MB)</label>
          <input type="file" accept="audio/mpeg,audio/wav,.mp3,.wav" @change="newSong.file = $event.target.files[0]" />
        </div>
        <button class="btn primary" :disabled="!newSong.file || adding" @click="addSong">
          {{ adding ? "Uploading…" : "➕ Add song" }}
        </button>
      </div>

      <p v-if="msg" class="ok">{{ msg }}</p>
      <p v-if="err" class="bad">{{ err }}</p>
    </div>

    <!-- Tap-to-sync overlay -->
    <div v-if="syncSong" class="sync-modal" @click.self="closeSync">
      <div class="sync-box">
        <h3>Sync “{{ syncSong.title }}”</h3>
        <p class="hint small">Play the song, then press <strong>Space</strong> (or tap) exactly when each line starts being sung.</p>
        <audio ref="audioEl" :src="syncObjUrl" controls style="width:100%"
               @play="isPlaying = true" @pause="isPlaying = false"></audio>
        <div class="sync-lines">
          <div v-for="(line, i) in syncLines" :key="i" class="sync-line"
               :class="{ done: syncTimes[i] != null, current: i === syncIdx }">
            <span class="t">{{ syncTimes[i] != null ? fmtLrc(syncTimes[i]) : "··:··" }}</span>
            <span class="x">{{ line }}</span>
          </div>
          <p v-if="syncLines.length === 0" class="hint small" style="padding:0.6rem">No lyric lines — add lyrics to this song first.</p>
        </div>
        <div class="sync-controls">
          <button class="btn primary big" type="button" @click="tap" :disabled="syncIdx >= syncLines.length">
            ⏱ Tap line {{ Math.min(syncIdx + 1, syncLines.length) }} / {{ syncLines.length }}
          </button>
          <div class="sync-row">
            <button class="btn" type="button" @click="togglePlay">{{ isPlaying ? "⏸ Pause" : "▶ Play" }}</button>
            <button class="btn" type="button" @click="undoTap" :disabled="syncIdx === 0">↩ Undo</button>
            <button class="btn" type="button" @click="restartSync">⟲ Restart</button>
          </div>
          <div class="sync-row">
            <button class="btn primary" type="button" @click="saveSync">💾 Save timings</button>
            <button class="btn ghost" type="button" @click="closeSync">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Clip picker overlay -->
    <div v-if="clipSong" class="sync-modal" @click.self="closeClip">
      <div class="sync-box">
        <h3>Clip “{{ clipSong.title }}”</h3>
        <p class="hint small">Play to find the chorus/hook, then mark its start and end. The video uses only this slice.</p>
        <audio ref="clipAudio" :src="clipObjUrl" controls style="width:100%"></audio>
        <div class="inline" style="margin:0.8rem 0">
          <button class="btn" type="button" @click="setClipStart">⏮ Set start</button>
          <span class="t">{{ clipStartVal }}s</span>
          <button class="btn" type="button" @click="setClipEnd">Set end ⏭</button>
          <span class="t">{{ clipEndVal }}s</span>
          <button class="btn" type="button" @click="previewClip">▶ Preview</button>
        </div>
        <p class="hint small">Length: {{ Math.max(0, (clipEndVal - clipStartVal)).toFixed(1) }}s</p>
        <div class="sync-row">
          <button class="btn primary" type="button" @click="saveClip">💾 Save clip</button>
          <button class="btn ghost" type="button" @click="closeClip">Cancel</button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.fdm { max-width: 660px; }
.fdm h2 { margin: 0 0 0.25rem; }
.hint { color: var(--text-muted); font-size: 0.85rem; line-height: 1.5; }
.hint.small, .small { font-size: 0.78rem; }
.fdm-grid { display: flex; flex-direction: column; gap: 1.1rem; margin-top: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field > label { font-size: 0.82rem; font-weight: 600; }
input[type="text"], input[type="number"], select, textarea {
  padding: 0.55rem 0.65rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--bg); color: var(--text); font: inherit;
}
textarea { resize: vertical; font-family: monospace; }
.toggle { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; }
.toggle.small span { font-size: 0.78rem; color: var(--text-muted); }
.inline { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.detecting { color: var(--primary); }
.actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.usage { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; font-size: .9rem; color: var(--muted, #888); }
hr { border: 0; border-top: 1px solid var(--border); width: 100%; margin: 0.5rem 0; }
.song-card {
  border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem;
  display: flex; flex-direction: column; gap: 0.9rem; background: var(--bg);
}
.song-card.add { background: var(--primary-soft); }
.song-card h4 { margin: 0; }
.btn { padding: 0.5rem 0.9rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: transparent; color: var(--text); cursor: pointer; font: inherit; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn.primary { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.btn.ghost { background: transparent; }
.btn.danger { color: var(--danger); border-color: var(--danger); }
.btn.big { width: 100%; padding: 0.8rem; font-size: 1rem; }
.ok { color: var(--success, green); font-size: 0.85rem; }
.bad { color: var(--danger); font-size: 0.85rem; }
code { background: var(--primary-soft); padding: 0 0.25rem; border-radius: 4px; }

.sync-modal { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.6); padding: 1rem; }
.sync-box { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 1.25rem; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; }
.sync-box h3 { margin: 0 0 0.25rem; }
.sync-lines { margin: 0.9rem 0; max-height: 38vh; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.sync-line { display: flex; gap: 0.6rem; padding: 0.35rem 0.6rem; font-size: 0.88rem; border-bottom: 1px solid var(--border); }
.sync-line:last-child { border-bottom: 0; }
.sync-line .t { font-family: monospace; color: var(--text-muted); min-width: 64px; }
.sync-line.done .t { color: var(--success, green); }
.sync-line.current { background: var(--primary-soft); }
.sync-line.current .x { font-weight: 600; }
.sync-controls { display: flex; flex-direction: column; gap: 0.6rem; }
.sync-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
</style>
