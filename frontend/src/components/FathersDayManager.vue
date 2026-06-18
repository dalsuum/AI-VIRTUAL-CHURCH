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

onMounted(load);
onUnmounted(() => detectPoll && clearInterval(detectPoll));

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
</style>
