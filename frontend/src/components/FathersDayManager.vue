<!--
  Father's Day (Special Day) MV — admin manager (SELF-CONTAINED & REMOVABLE).
  Upload the song, paste lyrics, toggle enable + lyric-sync + default effect.
  Remove with the rest of the feature (see FathersDayController).
-->
<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { api } from "../composables/useApi";

const cfg = ref(null);
const loading = ref(true);
const saving = ref(false);
const uploading = ref(false);
const msg = ref("");
const err = ref("");
const songFile = ref(null);

const EFFECTS = [
  { value: "slide", label: "Slideshow (hard cut)" },
  { value: "fade", label: "Crossfade" },
  { value: "kenburns", label: "Gentle zoom (Ken Burns)" },
];

// ── Tap-to-sync ────────────────────────────────────────────────────────────
const audioEl   = ref(null);
const syncOpen  = ref(false);
const syncLines = ref([]);     // displayable lyric lines (no tags/markers)
const syncTimes = ref([]);     // seconds per line (null = not tapped yet)
const syncIdx   = ref(0);      // next line to stamp
const syncObjUrl = ref("");
const isPlaying = ref(false);

// Lines exactly as the video shows them: drop blank + [Section] markers, strip
// any existing [mm:ss.xx] tags so re-syncing starts clean.
function displayableLines(text) {
  return (text || "")
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter((l) => l !== "" && !/^\[[^\]]*\]$/.test(l))
    .map((l) => l.replace(/\[[0-9:.]+\]/g, "").trim())
    .filter((l) => l !== "");
}

async function openSync() {
  err.value = ""; msg.value = "";
  try {
    const blob = await api.fdAdminSongBlob();
    if (syncObjUrl.value) URL.revokeObjectURL(syncObjUrl.value);
    syncObjUrl.value = URL.createObjectURL(blob);
    syncLines.value = displayableLines(cfg.value.lyrics);
    syncTimes.value = syncLines.value.map(() => null);
    syncIdx.value = 0;
    syncOpen.value = true;
  } catch (e) {
    err.value = e.message || "Could not load the song. Upload one first.";
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
  if (syncIdx.value > 0) {
    syncIdx.value--;
    syncTimes.value[syncIdx.value] = null;
  }
}
function restartSync() {
  const a = audioEl.value;
  if (a) { a.currentTime = 0; }
  syncTimes.value = syncLines.value.map(() => null);
  syncIdx.value = 0;
}
function togglePlay() {
  const a = audioEl.value;
  if (!a) return;
  a.paused ? a.play() : a.pause();
}

async function saveSync() {
  // Build an LRC from the tapped times (only stamped lines carry a tag).
  const lrc = syncLines.value
    .map((line, i) => (syncTimes.value[i] != null ? `${fmtLrc(syncTimes.value[i])} ${line}` : line))
    .join("\n");
  cfg.value.lyrics = lrc;
  cfg.value.sync_enabled = true;
  await save();
  syncOpen.value = false;
  if (audioEl.value) audioEl.value.pause();
}

function onKey(e) {
  if (!syncOpen.value) return;
  if (e.code === "Space") { e.preventDefault(); tap(); }
}

onMounted(() => { load(); window.addEventListener("keydown", onKey); });
onUnmounted(() => {
  detectPoll && clearInterval(detectPoll);
  window.removeEventListener("keydown", onKey);
  if (syncObjUrl.value) URL.revokeObjectURL(syncObjUrl.value);
});

let detectPoll = null;
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

// While vocal detection runs in the background, refresh until it lands.
function watchDetection() {
  if (detectPoll) { clearInterval(detectPoll); detectPoll = null; }
  if (cfg.value?.vocal_start_status !== "detecting") return;
  detectPoll = setInterval(async () => {
    try {
      const fresh = await api.fdAdminShow();
      if (fresh.vocal_start_status !== "detecting") {
        cfg.value.vocal_start = fresh.vocal_start;
        cfg.value.vocal_start_status = fresh.vocal_start_status;
        clearInterval(detectPoll); detectPoll = null;
      }
    } catch { /* keep polling */ }
  }, 5000);
}

async function save() {
  saving.value = true; msg.value = ""; err.value = "";
  try {
    cfg.value = await api.fdAdminSave({
      enabled: !!cfg.value.enabled,
      title: cfg.value.title || "",
      subtitle: cfg.value.subtitle || "",
      lyrics: cfg.value.lyrics || "",
      sync_enabled: !!cfg.value.sync_enabled,
      default_effect: cfg.value.default_effect || "slide",
      vocal_start: cfg.value.vocal_start === "" || cfg.value.vocal_start == null
        ? null : Number(cfg.value.vocal_start),
    });
    msg.value = "Saved.";
  } catch (e) {
    err.value = e.message || "Save failed.";
  } finally {
    saving.value = false;
  }
}

async function uploadSong() {
  if (!songFile.value) return;
  uploading.value = true; msg.value = ""; err.value = "";
  try {
    cfg.value = await api.fdAdminUploadSong(songFile.value);
    songFile.value = null;
    msg.value = "Song uploaded. Detecting where the vocals start…";
    watchDetection();
  } catch (e) {
    err.value = e.message || "Upload failed.";
  } finally {
    uploading.value = false;
  }
}
</script>

<template>
  <section class="fdm">
    <h2>Special Day Music Video</h2>
    <p class="hint">
      Visitors upload photo(s) of their father at <code>#fathers-day</code> and download a
      vertical MV set to the song + lyrics below. Reusable for any special day.
    </p>

    <p v-if="loading">Loading…</p>

    <div v-else-if="cfg" class="fdm-grid">
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

      <div class="field">
        <label>Lyrics</label>
        <textarea v-model="cfg.lyrics" rows="10"
          placeholder="One line per row.&#10;For time-synced highlight use LRC tags:&#10;[00:12.50] First line&#10;[00:18.20] Second line"></textarea>
        <label class="toggle small">
          <input type="checkbox" v-model="cfg.sync_enabled" />
          <span>Time-synced highlight (use <code>[mm:ss.xx]</code> tags). Off = lines split evenly across the song.</span>
        </label>
        <button class="btn" type="button" :disabled="!cfg.has_song" @click="openSync" style="margin-top:0.5rem">
          🎤 Tap-to-sync lyrics
        </button>
        <p v-if="!cfg.has_song" class="hint small">Upload a song first to sync.</p>
      </div>

      <!-- Tap-to-sync overlay -->
      <div v-if="syncOpen" class="sync-modal" @click.self="syncOpen = false">
        <div class="sync-box">
          <h3>Tap each line as it's sung</h3>
          <p class="hint small">Play the song, then press <strong>Space</strong> (or tap the button) exactly when each line starts. Use Undo if you mistime one.</p>

          <audio ref="audioEl" :src="syncObjUrl" controls style="width:100%"
                 @play="isPlaying = true" @pause="isPlaying = false"></audio>

          <div class="sync-lines">
            <div v-for="(line, i) in syncLines" :key="i"
                 class="sync-line"
                 :class="{ done: syncTimes[i] != null, current: i === syncIdx }">
              <span class="t">{{ syncTimes[i] != null ? fmtLrc(syncTimes[i]) : "··:··" }}</span>
              <span class="x">{{ line }}</span>
            </div>
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
              <button class="btn ghost" type="button" @click="syncOpen = false">Cancel</button>
            </div>
          </div>
        </div>
      </div>

      <div class="field">
        <label>Lyrics start at — vocals onset (seconds)</label>
        <div class="inline">
          <input type="number" min="0" max="600" step="0.1" v-model="cfg.vocal_start" style="max-width:120px" />
          <span class="hint small" :class="{ detecting: cfg.vocal_start_status === 'detecting' }">
            <template v-if="cfg.vocal_start_status === 'detecting'">⏳ Detecting vocals… (auto, ~1–2 min after upload)</template>
            <template v-else-if="cfg.vocal_start_status === 'ready'">✓ Auto-detected — edit to override</template>
            <template v-else-if="cfg.vocal_start_status === 'failed'">⚠ Auto-detect failed — set manually</template>
            <template v-else>Set the second the singing starts (0 = from the beginning)</template>
          </span>
        </div>
        <p class="hint small">Lyrics are held during the intro and spread across the song from this point.</p>
      </div>

      <div class="field">
        <label>Song ({{ cfg.has_song ? "uploaded: " + cfg.song_ext : "none yet" }})</label>
        <input type="file" accept="audio/mpeg,audio/wav,.mp3,.wav" @change="songFile = $event.target.files[0]" />
        <button class="btn" :disabled="!songFile || uploading" @click="uploadSong">
          {{ uploading ? "Uploading…" : "Upload song" }}
        </button>
        <p class="hint small">MP3 or WAV, up to 50 MB.</p>
      </div>

      <div class="actions">
        <button class="btn primary" :disabled="saving" @click="save">{{ saving ? "Saving…" : "Save settings" }}</button>
        <span v-if="msg" class="ok">{{ msg }}</span>
        <span v-if="err" class="bad">{{ err }}</span>
      </div>

      <p v-if="cfg.enabled && !cfg.has_song" class="bad small">
        ⚠ The page won't show to visitors until a song is uploaded.
      </p>
    </div>
  </section>
</template>

<style scoped>
.fdm { max-width: 640px; }
.fdm h2 { margin: 0 0 0.25rem; }
.hint { color: var(--text-muted); font-size: 0.85rem; line-height: 1.5; }
.hint.small, .small { font-size: 0.78rem; }
.fdm-grid { display: flex; flex-direction: column; gap: 1.1rem; margin-top: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field > label { font-size: 0.82rem; font-weight: 600; }
input[type="text"], select, textarea {
  padding: 0.55rem 0.65rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--bg); color: var(--text); font: inherit;
}
textarea { resize: vertical; font-family: monospace; }
.inline { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.detecting { color: var(--primary); }
.toggle { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; }
.toggle.small span { font-size: 0.78rem; color: var(--text-muted); }
.actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.btn { padding: 0.5rem 0.9rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: transparent; color: var(--text); cursor: pointer; font: inherit; align-self: flex-start; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn.primary { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.ok { color: var(--success, green); font-size: 0.85rem; }
.bad { color: var(--danger); font-size: 0.85rem; }
code { background: var(--primary-soft); padding: 0 0.25rem; border-radius: 4px; }

.sync-modal {
  position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,0.6); padding: 1rem;
}
.sync-box {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 1.25rem; width: 100%; max-width: 520px;
  max-height: 90vh; overflow-y: auto;
}
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
.btn.big { width: 100%; padding: 0.8rem; font-size: 1rem; }
.btn.ghost { background: transparent; }
</style>
