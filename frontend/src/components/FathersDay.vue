<!--
  Father's Day (Special Day) MV — SELF-CONTAINED & REMOVABLE public page.
  Lives at #fathers-day. A visitor uploads photo(s) of their father, picks an
  effect, and downloads a vertical MV set to the admin-provided song + lyrics.
  Remove by deleting this file + its #fathers-day route/nav-link in App.vue.
-->
<script setup>
import { ref, computed, onMounted, onUnmounted } from "vue";
import { api } from "../composables/useApi";

const loading   = ref(true);
const config    = ref(null);          // { enabled, title, subtitle, default_effect, effects, max_photos }
const photos    = ref([]);            // File[]
const previews  = ref([]);            // object URLs
const effect    = ref("slide");
const phase     = ref("idle");        // idle | rendering | done | error
const errorMsg  = ref("");
const jobId     = ref(null);
const downloadUrl = ref("");
const dragActive = ref(false);
const targetProgress = ref(0);   // last % reported by the server
const shownProgress  = ref(0);   // eased value actually displayed
const stageLabel     = ref("Starting…");
const finished       = ref(false);

let pollTimer = null;
let easeTimer = null;

const effectLabels = {
  slide: "Slideshow",
  fade: "Crossfade",
  kenburns: "Gentle zoom",
};

const canGenerate = computed(() => photos.value.length > 0 && phase.value !== "rendering");
const maxPhotos   = computed(() => config.value?.max_photos ?? 6);

onMounted(async () => {
  try {
    config.value = await api.fdPublicConfig();
    effect.value = config.value?.default_effect || "slide";
  } catch {
    config.value = { enabled: false };
  } finally {
    loading.value = false;
  }
});

onUnmounted(() => {
  pollTimer && clearInterval(pollTimer);
  easeTimer && clearInterval(easeTimer);
  previews.value.forEach((u) => URL.revokeObjectURL(u));
});

// Ease the displayed % toward the server target so the bar glides instead of
// jumping between stages. Never quite reaches the target until "done" so it
// keeps creeping during a long ffmpeg step.
function startEasing() {
  easeTimer && clearInterval(easeTimer);
  easeTimer = setInterval(() => {
    // While running, creep toward target but stay a touch behind so the bar
    // keeps moving during a long ffmpeg step. Once finished, drive to 100.
    const ceiling = finished.value ? 100 : Math.min(targetProgress.value, 96);
    if (shownProgress.value < ceiling) {
      shownProgress.value = Math.min(ceiling, shownProgress.value + 1);
    } else if (finished.value && shownProgress.value >= 100) {
      clearInterval(easeTimer);
      phase.value = "done";
      triggerDownload();
    }
  }, 90);
}

function addFiles(fileList) {
  const incoming = Array.from(fileList).filter((f) => /^image\/(jpe?g|png|webp)$/.test(f.type));
  for (const f of incoming) {
    if (photos.value.length >= maxPhotos.value) break;
    if (f.size > 8 * 1024 * 1024) { errorMsg.value = `${f.name} is larger than 8 MB.`; continue; }
    photos.value.push(f);
    previews.value.push(URL.createObjectURL(f));
  }
}

function onFileInput(e) { addFiles(e.target.files); e.target.value = ""; }
function onDrop(e) { dragActive.value = false; addFiles(e.dataTransfer.files); }

function removePhoto(i) {
  URL.revokeObjectURL(previews.value[i]);
  photos.value.splice(i, 1);
  previews.value.splice(i, 1);
}

async function generate() {
  errorMsg.value = "";
  phase.value = "rendering";
  targetProgress.value = 0;
  shownProgress.value = 0;
  finished.value = false;
  stageLabel.value = "Starting…";
  startEasing();
  try {
    const { job_id } = await api.fdRender(photos.value, effect.value);
    jobId.value = job_id;
    pollTimer = setInterval(checkStatus, 2000);
    checkStatus();
  } catch (e) {
    phase.value = "error";
    easeTimer && clearInterval(easeTimer);
    errorMsg.value = e.message || "Something went wrong. Please try again.";
  }
}

async function checkStatus() {
  if (!jobId.value) return;
  try {
    const s = await api.fdJobStatus(jobId.value);
    if (typeof s.progress === "number") targetProgress.value = s.progress;
    if (s.stage) stageLabel.value = s.stage;
    if (s.status === "done") {
      clearInterval(pollTimer);
      targetProgress.value = 100;
      stageLabel.value = "Finishing up…";
      downloadUrl.value = api.fdDownloadUrl(jobId.value);
      finished.value = true;   // easing drives the bar to 100, then flips to done
    } else if (s.status === "error") {
      clearInterval(pollTimer);
      easeTimer && clearInterval(easeTimer);
      phase.value = "error";
      errorMsg.value = s.message || "The video could not be generated.";
    }
  } catch {
    /* transient — keep polling */
  }
}

function triggerDownload() {
  const a = document.createElement("a");
  a.href = downloadUrl.value;
  a.download = "fathers-day.mp4";
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function reset() {
  previews.value.forEach((u) => URL.revokeObjectURL(u));
  photos.value = [];
  previews.value = [];
  jobId.value = null;
  downloadUrl.value = "";
  phase.value = "idle";
  errorMsg.value = "";
  targetProgress.value = 0;
  shownProgress.value = 0;
  finished.value = false;
}
</script>

<template>
  <div class="fd-page">
    <a class="fd-back" href="#">← Back to worship</a>

    <div v-if="loading" class="fd-card fd-center"><p>Loading…</p></div>

    <div v-else-if="!config.enabled" class="fd-card fd-center">
      <div class="fd-hero-mark">🎬</div>
      <h1>Not available right now</h1>
      <p class="fd-muted">This special-day video maker isn't open at the moment. Please check back later.</p>
    </div>

    <div v-else class="fd-card">
      <header class="fd-head">
        <div class="fd-hero-mark">💙</div>
        <h1>{{ config.title }}</h1>
        <p class="fd-muted">{{ config.subtitle }}</p>
      </header>

      <!-- Upload -->
      <div
        class="fd-drop"
        :class="{ active: dragActive }"
        @dragover.prevent="dragActive = true"
        @dragleave.prevent="dragActive = false"
        @drop.prevent="onDrop"
        @click="$refs.fileInput.click()"
      >
        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="onFileInput" />
        <div class="fd-drop-inner">
          <span class="fd-drop-icon">📷</span>
          <p><strong>Tap to add photos</strong> of your father</p>
          <p class="fd-muted small">JPG / PNG / WEBP · up to {{ maxPhotos }} photos · 8 MB each</p>
        </div>
      </div>

      <div v-if="previews.length" class="fd-thumbs">
        <div v-for="(src, i) in previews" :key="i" class="fd-thumb">
          <img :src="src" alt="" />
          <button class="fd-thumb-x" @click="removePhoto(i)" aria-label="Remove">×</button>
        </div>
      </div>

      <!-- Effect -->
      <div v-if="photos.length > 1" class="fd-effects">
        <p class="fd-label">Effect</p>
        <div class="fd-effect-row">
          <button
            v-for="opt in config.effects"
            :key="opt"
            class="fd-chip"
            :class="{ active: effect === opt }"
            @click="effect = opt"
          >{{ effectLabels[opt] || opt }}</button>
        </div>
      </div>

      <p v-if="errorMsg" class="fd-error">{{ errorMsg }}</p>

      <!-- Actions / progress -->
      <div v-if="phase === 'rendering'" class="fd-progress">
        <div class="fd-bar"><div class="fd-bar-fill" :style="{ width: shownProgress + '%' }"></div></div>
        <p class="fd-progress-pct">{{ shownProgress }}%</p>
        <p class="fd-progress-stage">{{ stageLabel }}</p>
      </div>

      <div v-else-if="phase === 'done'" class="fd-done">
        <p class="fd-done-msg">🎉 Your video is ready!</p>
        <a class="fd-btn primary" :href="downloadUrl" download="fathers-day.mp4">⬇ Download again</a>
        <button class="fd-btn ghost" @click="reset">Make another</button>
      </div>

      <button v-else class="fd-btn primary big" :disabled="!canGenerate" @click="generate">
        Create video 🎬
      </button>

      <p class="fd-muted small fd-foot">Your photos are used only to make this video and are removed after.</p>
    </div>
  </div>
</template>

<style scoped>
.fd-page { max-width: 560px; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
.fd-back { display: inline-block; margin-bottom: 1rem; color: var(--text-muted); text-decoration: none; font-size: 0.85rem; }
.fd-back:hover { color: var(--primary); }
.fd-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); box-shadow: var(--shadow); padding: 1.75rem 1.5rem;
}
.fd-center { text-align: center; }
.fd-head { text-align: center; margin-bottom: 1.5rem; }
.fd-hero-mark { font-size: 2.5rem; }
.fd-head h1 { font-size: 1.5rem; margin: 0.4rem 0 0.25rem; letter-spacing: -0.02em; }
.fd-muted { color: var(--text-muted); line-height: 1.5; }
.small { font-size: 0.78rem; }

.fd-drop {
  border: 2px dashed var(--border); border-radius: var(--radius);
  padding: 2rem 1rem; text-align: center; cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.fd-drop:hover, .fd-drop.active { border-color: var(--primary); background: var(--primary-soft); }
.fd-drop-icon { font-size: 2rem; display: block; margin-bottom: 0.5rem; }
.fd-drop-inner p { margin: 0.15rem 0; }

.fd-thumbs { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 1rem; }
.fd-thumb { position: relative; width: 72px; height: 96px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); }
.fd-thumb img { width: 100%; height: 100%; object-fit: cover; }
.fd-thumb-x {
  position: absolute; top: 2px; right: 2px; width: 20px; height: 20px;
  border: none; border-radius: 50%; background: rgba(0,0,0,0.6); color: #fff;
  font-size: 0.9rem; line-height: 1; cursor: pointer;
}

.fd-effects { margin-top: 1.25rem; }
.fd-label { font-size: 0.8rem; font-weight: 600; margin: 0 0 0.5rem; }
.fd-effect-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.fd-chip {
  padding: 0.45rem 0.9rem; border: 1px solid var(--border); border-radius: 999px;
  background: transparent; color: var(--text); cursor: pointer; font-size: 0.85rem;
  transition: all 0.12s;
}
.fd-chip.active { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }

.fd-error { color: var(--danger); font-size: 0.85rem; margin: 1rem 0 0; }

.fd-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
  border: none; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600;
  padding: 0.7rem 1.2rem; font-size: 0.95rem; text-decoration: none;
}
.fd-btn.primary { background: var(--primary); color: var(--on-primary); }
.fd-btn.primary:disabled { opacity: 0.5; cursor: not-allowed; }
.fd-btn.ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
.fd-btn.big { width: 100%; margin-top: 1.5rem; padding: 0.85rem; font-size: 1rem; }

.fd-progress { text-align: center; margin-top: 1.5rem; }
.fd-bar {
  width: 100%; height: 10px; border-radius: 999px; overflow: hidden;
  background: var(--border);
}
.fd-bar-fill {
  height: 100%; border-radius: 999px;
  background: linear-gradient(90deg, var(--primary), color-mix(in srgb, var(--primary) 60%, #8b5cf6));
  transition: width 0.12s linear;
}
.fd-progress-pct { font-size: 1.4rem; font-weight: 700; margin: 0.75rem 0 0.15rem; }
.fd-progress-stage { color: var(--text-muted); font-size: 0.85rem; margin: 0; }

.fd-done { text-align: center; margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.6rem; align-items: center; }
.fd-done-msg { font-size: 1.05rem; font-weight: 600; }
.fd-foot { text-align: center; margin-top: 1.25rem; }
</style>
