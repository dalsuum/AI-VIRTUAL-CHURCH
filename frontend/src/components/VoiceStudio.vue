<template>
  <div class="voice-studio">
    <div class="vs-header">
      <h2>Voice Studio</h2>
      <p class="vs-sub">Record your voice to train a custom TTS model for your language.</p>
    </div>

    <!-- Language selector -->
    <div class="vs-lang-bar">
      <button
        v-for="l in langs"
        :key="l.code"
        :class="['lang-btn', { active: lang === l.code }]"
        @click="switchLang(l.code)"
      >
        {{ l.label }}
        <span class="lang-count" v-if="progress[l.code]">
          {{ progress[l.code].recorded }}/{{ progress[l.code].total }}
        </span>
      </button>

      <button class="export-btn" @click="exportDataset" :disabled="exporting">
        {{ exporting ? 'Preparing…' : 'Export Dataset' }}
      </button>
    </div>

    <!-- Progress bar -->
    <div class="vs-progress" v-if="currentProgress">
      <div class="progress-track">
        <div class="progress-fill" :style="{ width: progressPct + '%' }"></div>
      </div>
      <span class="progress-label">{{ currentProgress.recorded }} / {{ currentProgress.total }} recorded ({{ progressPct }}%)</span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="vs-loading">Loading sentences…</div>

    <!-- Main recording card -->
    <div v-else-if="currentSentence" class="vs-card">
      <div class="sentence-nav">
        <button class="nav-btn" @click="goPrev" :disabled="cursor <= 0">&#8592;</button>
        <span class="sentence-index">{{ cursor + 1 }} / {{ sentences.length }}</span>
        <button class="nav-btn" @click="goNext" :disabled="cursor >= sentences.length - 1">&#8594;</button>
      </div>

      <div class="sentence-text" :lang="lang === 'my' ? 'my' : 'ctd'">
        {{ currentSentence.text }}
      </div>

      <div class="recorded-badge" v-if="isRecorded">
        ✓ Recorded
      </div>

      <!-- Recorder -->
      <div class="recorder">
        <button
          class="rec-btn"
          :class="{ recording: isRecording }"
          @click="toggleRecord"
          :disabled="!!audioBlob && !isRecording"
        >
          <span class="rec-icon">{{ isRecording ? '⏹' : '●' }}</span>
          {{ isRecording ? 'Stop' : 'Record' }}
          <span v-if="isRecording" class="rec-timer">{{ recTimer }}s</span>
        </button>

        <!-- Playback -->
        <div v-if="audioBlob" class="playback">
          <audio ref="audioEl" :src="audioBlobUrl" controls></audio>
          <div class="playback-actions">
            <button class="action-btn accept" @click="acceptRecording" :disabled="saving">
              {{ saving ? 'Saving…' : '✓ Accept' }}
            </button>
            <button class="action-btn retry" @click="discardRecording" :disabled="saving">
              ✕ Re-record
            </button>
          </div>
        </div>

        <button v-if="!audioBlob && !isRecording" class="skip-btn" @click="goNext">
          Skip →
        </button>
      </div>

      <!-- Delete existing recording -->
      <button
        v-if="isRecorded && !audioBlob"
        class="delete-btn"
        @click="deleteRecording"
      >
        Delete recording
      </button>
    </div>

    <div v-else-if="!loading" class="vs-empty">
      No sentences found for this language.
    </div>

    <!-- Jump to unrecorded -->
    <div class="vs-jump" v-if="sentences.length">
      <button class="jump-btn" @click="jumpToNext">Jump to next unrecorded</button>
    </div>

    <div v-if="statusMsg" class="vs-status" :class="statusClass">{{ statusMsg }}</div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { api } from "../composables/useApi";

const langs = [
  { code: "td", label: "Tedim (Zolai)" },
  { code: "my", label: "Myanmar (Burmese)" },
];

const lang       = ref("td");
const sentences  = ref([]);
const recordedIds = ref(new Set());
const cursor     = ref(0);
const loading    = ref(false);
const saving     = ref(false);
const exporting  = ref(false);
const statusMsg  = ref("");
const statusClass = ref("ok");
const progress   = ref({ td: null, my: null });

// recorder state
const isRecording  = ref(false);
const audioBlob    = ref(null);
const audioBlobUrl = ref(null);
const recTimer     = ref(0);
const audioEl      = ref(null);

let mediaRecorder = null;
let chunks        = [];
let timerInterval = null;

const currentSentence = computed(() => sentences.value[cursor.value] ?? null);
const isRecorded      = computed(() =>
  currentSentence.value ? recordedIds.value.has(currentSentence.value.id) : false
);
const currentProgress = computed(() => progress.value[lang.value]);
const progressPct     = computed(() => {
  const p = currentProgress.value;
  if (!p || p.total === 0) return 0;
  return Math.round((p.recorded / p.total) * 100);
});

async function loadScript(code) {
  loading.value = true;
  sentences.value = [];
  recordedIds.value = new Set();
  cursor.value = 0;
  try {
    const res = await api.get(`/admin/voice-studio/script/${code}`);
    sentences.value = res.data.sentences;
    recordedIds.value = new Set(res.data.recorded_ids);
  } catch (e) {
    showStatus("Failed to load script: " + (e.response?.data?.error ?? e.message), "error");
  } finally {
    loading.value = false;
  }
}

async function loadProgress(code) {
  try {
    const res = await api.get(`/admin/voice-studio/progress/${code}`);
    progress.value[code] = res.data;
  } catch {}
}

async function switchLang(code) {
  discardRecording();
  lang.value = code;
  await Promise.all([loadScript(code), loadProgress(code)]);
}

function goPrev() {
  discardRecording();
  if (cursor.value > 0) cursor.value--;
}

function goNext() {
  discardRecording();
  if (cursor.value < sentences.value.length - 1) cursor.value++;
}

function jumpToNext() {
  discardRecording();
  const start = cursor.value + 1;
  for (let i = start; i < sentences.value.length; i++) {
    if (!recordedIds.value.has(sentences.value[i].id)) {
      cursor.value = i;
      return;
    }
  }
  // wrap from beginning
  for (let i = 0; i < cursor.value; i++) {
    if (!recordedIds.value.has(sentences.value[i].id)) {
      cursor.value = i;
      return;
    }
  }
  showStatus("All sentences recorded!", "ok");
}

async function toggleRecord() {
  if (isRecording.value) {
    stopRecording();
  } else {
    await startRecording();
  }
}

async function startRecording() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    chunks = [];
    const mimeType = MediaRecorder.isTypeSupported("audio/webm;codecs=opus")
      ? "audio/webm;codecs=opus"
      : "audio/webm";
    mediaRecorder = new MediaRecorder(stream, { mimeType });
    mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };
    mediaRecorder.onstop = () => {
      const blob = new Blob(chunks, { type: mimeType });
      audioBlob.value = blob;
      audioBlobUrl.value = URL.createObjectURL(blob);
      stream.getTracks().forEach((t) => t.stop());
    };
    mediaRecorder.start(250);
    isRecording.value = true;
    recTimer.value = 0;
    timerInterval = setInterval(() => {
      recTimer.value++;
      if (recTimer.value >= 30) stopRecording(); // safety cap
    }, 1000);
  } catch (e) {
    showStatus("Microphone access denied: " + e.message, "error");
  }
}

function stopRecording() {
  if (mediaRecorder && mediaRecorder.state !== "inactive") {
    mediaRecorder.stop();
  }
  clearInterval(timerInterval);
  isRecording.value = false;
}

function discardRecording() {
  stopRecording();
  if (audioBlobUrl.value) URL.revokeObjectURL(audioBlobUrl.value);
  audioBlob.value    = null;
  audioBlobUrl.value = null;
  recTimer.value     = 0;
  chunks = [];
}

async function acceptRecording() {
  if (!audioBlob.value || !currentSentence.value) return;
  saving.value = true;
  try {
    const form = new FormData();
    form.append("lang", lang.value);
    form.append("id",   currentSentence.value.id);
    form.append("text", currentSentence.value.text);
    form.append("audio", audioBlob.value, "recording.webm");

    await api.post("/admin/voice-studio/recording", form, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    recordedIds.value.add(currentSentence.value.id);
    await loadProgress(lang.value);
    discardRecording();
    showStatus("Saved!", "ok");
    goNext();
  } catch (e) {
    showStatus("Save failed: " + (e.response?.data?.error ?? e.message), "error");
  } finally {
    saving.value = false;
  }
}

async function deleteRecording() {
  if (!currentSentence.value) return;
  try {
    await api.delete(`/admin/voice-studio/recording/${lang.value}/${currentSentence.value.id}`);
    recordedIds.value.delete(currentSentence.value.id);
    await loadProgress(lang.value);
    showStatus("Deleted.", "ok");
  } catch (e) {
    showStatus("Delete failed.", "error");
  }
}

async function exportDataset() {
  exporting.value = true;
  try {
    const res = await api.get(`/admin/voice-studio/export/${lang.value}`, { responseType: "blob" });
    const url  = URL.createObjectURL(res.data);
    const a    = document.createElement("a");
    a.href     = url;
    a.download = `${lang.value}_voice_dataset.zip`;
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    showStatus("Export failed: " + (e.response?.data?.error ?? e.message), "error");
  } finally {
    exporting.value = false;
  }
}

function showStatus(msg, cls = "ok") {
  statusMsg.value   = msg;
  statusClass.value = cls;
  setTimeout(() => { statusMsg.value = ""; }, 3500);
}

onMounted(async () => {
  await Promise.all([
    loadScript("td"),
    loadProgress("td"),
    loadProgress("my"),
  ]);
});
</script>

<style scoped>
.voice-studio { max-width: 680px; margin: 0 auto; padding: 1rem 0; }

.vs-header h2 { margin: 0 0 .25rem; font-size: 1.25rem; }
.vs-sub { margin: 0 0 1.25rem; color: var(--text-muted, #666); font-size: .875rem; }

.vs-lang-bar {
  display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; align-items: center;
}
.lang-btn {
  padding: .4rem .9rem; border-radius: 6px; border: 1px solid var(--border, #ccc);
  background: transparent; cursor: pointer; font-size: .875rem; display: flex; gap: .4rem; align-items: center;
}
.lang-btn.active { background: var(--primary, #2563eb); color: #fff; border-color: var(--primary, #2563eb); }
.lang-count { font-size: .75rem; opacity: .8; }
.export-btn {
  margin-left: auto; padding: .4rem .9rem; border-radius: 6px;
  background: var(--surface-2, #f3f4f6); border: 1px solid var(--border, #ccc);
  cursor: pointer; font-size: .875rem;
}
.export-btn:hover { background: var(--surface-3, #e5e7eb); }

.vs-progress { margin-bottom: 1.25rem; }
.progress-track { height: 8px; border-radius: 4px; background: var(--surface-2, #e5e7eb); overflow: hidden; margin-bottom: .3rem; }
.progress-fill  { height: 100%; background: var(--primary, #2563eb); transition: width .3s; border-radius: 4px; }
.progress-label { font-size: .75rem; color: var(--text-muted, #666); }

.vs-loading, .vs-empty { text-align: center; padding: 2rem; color: var(--text-muted, #888); }

.vs-card {
  background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
  border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem;
}

.sentence-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.nav-btn { background: none; border: 1px solid var(--border, #ccc); border-radius: 6px; padding: .3rem .7rem; cursor: pointer; font-size: 1rem; }
.nav-btn:disabled { opacity: .3; cursor: default; }
.sentence-index { font-size: .85rem; color: var(--text-muted, #666); }

.sentence-text {
  font-size: 1.35rem; line-height: 1.7; text-align: center;
  padding: 1.25rem 0; min-height: 90px; font-weight: 500;
  border-top: 1px solid var(--border, #f0f0f0);
  border-bottom: 1px solid var(--border, #f0f0f0);
  margin-bottom: 1rem;
}

.recorded-badge {
  text-align: center; color: #16a34a; font-size: .85rem; margin-bottom: .75rem;
}

.recorder { display: flex; flex-direction: column; align-items: center; gap: .75rem; }

.rec-btn {
  display: flex; align-items: center; gap: .5rem;
  padding: .65rem 1.6rem; border-radius: 999px; border: none;
  background: var(--primary, #2563eb); color: #fff; font-size: 1rem; cursor: pointer;
  transition: background .15s;
}
.rec-btn.recording { background: #dc2626; animation: pulse 1s infinite; }
.rec-btn:disabled  { opacity: .5; cursor: default; }
.rec-icon  { font-size: 1.1rem; }
.rec-timer { font-size: .8rem; opacity: .85; }

@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .7; } }

.playback { display: flex; flex-direction: column; align-items: center; gap: .6rem; width: 100%; }
.playback audio { width: 100%; border-radius: 8px; }
.playback-actions { display: flex; gap: .75rem; }

.action-btn {
  padding: .5rem 1.25rem; border-radius: 6px; border: none; cursor: pointer; font-size: .9rem;
}
.action-btn.accept { background: #16a34a; color: #fff; }
.action-btn.retry  { background: var(--surface-2, #f3f4f6); border: 1px solid var(--border, #ccc); }
.action-btn:disabled { opacity: .5; cursor: default; }

.skip-btn {
  background: none; border: none; color: var(--text-muted, #888); cursor: pointer; font-size: .875rem;
}
.skip-btn:hover { text-decoration: underline; }

.delete-btn {
  display: block; margin: .75rem auto 0;
  background: none; border: none; color: #dc2626; font-size: .8rem; cursor: pointer;
}
.delete-btn:hover { text-decoration: underline; }

.vs-jump { text-align: center; margin-bottom: .75rem; }
.jump-btn {
  background: none; border: 1px solid var(--border, #ccc); border-radius: 6px;
  padding: .4rem 1rem; cursor: pointer; font-size: .85rem;
}
.jump-btn:hover { background: var(--surface-2, #f3f4f6); }

.vs-status {
  text-align: center; padding: .5rem 1rem; border-radius: 6px;
  font-size: .875rem; margin-top: .5rem;
}
.vs-status.ok    { background: #dcfce7; color: #15803d; }
.vs-status.error { background: #fee2e2; color: #b91c1c; }
</style>
