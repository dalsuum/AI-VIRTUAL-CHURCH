<!--
  Live Sticker maker — SELF-CONTAINED & REMOVABLE public page.
  Lives at #stickers. A visitor uploads any photo (vertical/horizontal); we
  auto-detect the face and pre-fill a square crop they can adjust, then compose
  5 random PNG stickers from Father's Day lyrics or typed text (auto-corrected).
  Remove by deleting this file + its #stickers route/nav-link in App.vue.
-->
<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from "vue";
import Cropper from "cropperjs";
import "cropperjs/dist/cropper.css";
import { api } from "../composables/useApi";
import AdCarousel from "./AdCarousel.vue";

const ads = ref([]);   // active ads for the box below the sticker block
const hasStickerAd = computed(() =>
  ads.value.some((a) => a.status === "active" && (a.locations || []).includes("sticker_ads"))
);

const config   = ref(null);
const phase    = ref("idle");     // idle | cropping | rendering | done | error
const errorMsg = ref("");

const token    = ref("");         // server token from detect()
const cropSrc  = ref("");         // data URL for the cropper <img>
const cropImg  = ref(null);
let cropper = null;
const detectedBox = ref(null);

const source   = ref("lyrics");   // "lyrics" | "manual"
const lyricLine = ref("");
const manualText = ref("");
const busy     = ref(false);

const jobId    = ref("");
const stickers = ref([]);
const progress = ref(0);
const stage    = ref("");
let pollTimer = null;

const suggestions = computed(() => config.value?.suggestions ?? []);
const songs       = computed(() => config.value?.songs ?? []);
const songIndex   = ref(0);
const songLines   = computed(() => songs.value[songIndex.value]?.lines ?? []);
// With a song library, offer song→line; otherwise fall back to the flat list.
const hasSongs    = computed(() => songs.value.length > 0);
const hasLyrics   = computed(() => hasSongs.value || suggestions.value.length > 0);
const maxChars    = computed(() => config.value?.max_chars ?? 120);
const enabled     = computed(() => config.value?.enabled !== false);
const pageTitle   = computed(() => config.value?.title || "Live Sticker Maker");
const pageSubtitle = computed(() => config.value?.subtitle || "Upload a photo — we'll turn it into a fun watercolor sticker.");

const chosenText = computed(() =>
  source.value === "lyrics" ? lyricLine.value : manualText.value
);

// Picking a different song resets the line to that song's first line.
watch(songIndex, () => { lyricLine.value = songLines.value[0] || ""; });

onMounted(async () => {
  try {
    config.value = await api.stickerConfig();
    source.value = hasLyrics.value ? "lyrics" : "manual";
    lyricLine.value = songLines.value[0] || config.value?.suggestions?.[0] || "";
  } catch {
    config.value = { enabled: false, suggestions: [] };
    source.value = "manual";
  }
  // Active ads for the box below the sticker block — fire-and-forget so a
  // failure never blocks the page. Shown only when an ad is tagged 'Sticker page'.
  try {
    const res = await api.fetchActiveAds();
    ads.value = res.ads || [];
  } catch { /* non-critical */ }
});

onUnmounted(() => {
  pollTimer && clearInterval(pollTimer);
  destroyCropper();
});

function destroyCropper() {
  if (cropper) { cropper.destroy(); cropper = null; }
}

// Step 1: pick a photo → detect face → show cropper pre-set to the face box.
async function onPhoto(e) {
  const file = e.target.files?.[0];
  e.target.value = "";   // allow re-selecting the same file
  if (!file) return;
  errorMsg.value = "";
  busy.value = true;
  try {
    const res = await api.stickerDetect(file);
    token.value = res.token;
    detectedBox.value = res.box;
    const reader = new FileReader();
    reader.onload = (ev) => {
      cropSrc.value = ev.target.result;
      phase.value = "cropping";
      nextTick(initCropper);
    };
    reader.readAsDataURL(file);
  } catch (err) {
    errorMsg.value = err.message || "Could not read that image.";
  } finally {
    busy.value = false;
  }
}

function initCropper() {
  if (!cropImg.value) return;
  destroyCropper();
  cropper = new Cropper(cropImg.value, {
    aspectRatio: 1,        // square stickers
    viewMode: 1,
    autoCropArea: 1,
    background: false,
    ready() {
      // Pre-position the crop box over the auto-detected face.
      const b = detectedBox.value;
      if (b && b.w && b.h) {
        cropper.setData({ x: b.x, y: b.y, width: b.w, height: b.h });
      }
    },
  });
}

// Step 2: confirm crop + text → queue the 5-sticker render → poll.
async function createStickers() {
  if (!cropper || !token.value) return;
  const text = (chosenText.value || "").trim();
  errorMsg.value = "";
  busy.value = true;
  const d = cropper.getData(true);   // rounded natural-image pixels
  const crop = { x: Math.max(0, d.x), y: Math.max(0, d.y), w: d.width, h: d.height };
  try {
    const res = await api.stickerRender({
      token: token.value,
      crop,
      text,
      source: source.value,
    });
    jobId.value = res.job_id;
    phase.value = "rendering";
    progress.value = 0;
    stage.value = "Queued";
    destroyCropper();
    poll();
  } catch (err) {
    errorMsg.value = err.message || "Could not start the sticker maker.";
    phase.value = "error";
  } finally {
    busy.value = false;
  }
}

function poll() {
  pollTimer && clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const s = await api.stickerJobStatus(jobId.value);
      progress.value = s.progress ?? progress.value;
      stage.value = s.stage ?? stage.value;
      if (s.status === "done") {
        clearInterval(pollTimer);
        stickers.value = s.stickers || [];
        phase.value = "done";
      } else if (s.status === "error") {
        clearInterval(pollTimer);
        errorMsg.value = s.message || "Something went wrong.";
        phase.value = "error";
      }
    } catch { /* keep polling */ }
  }, 1500);
}

function reset() {
  pollTimer && clearInterval(pollTimer);
  destroyCropper();
  phase.value = "idle";
  token.value = "";
  cropSrc.value = "";
  stickers.value = [];
  jobId.value = "";
  errorMsg.value = "";
  progress.value = 0;
}
</script>

<template>
  <div class="sticker-page">
    <header class="sk-top">
      <a class="sk-back" href="#">← Back</a>
      <h1>🎨 {{ pageTitle }}</h1>
      <p class="sk-sub">{{ pageSubtitle }}</p>
    </header>

    <main class="sk-main">
      <p v-if="errorMsg" class="sk-error">{{ errorMsg }}</p>

      <!-- Feature disabled by admin. -->
      <section v-if="!enabled" class="sk-render">
        <p>This feature isn't available right now. Please check back soon.</p>
      </section>

      <!-- Step 0: the red square Create button -->
      <section v-else-if="phase === 'idle'" class="sk-start">
        <label class="sk-redbox" :class="{ busy }">
          <input type="file" accept="image/jpeg,image/png,image/webp" @change="onPhoto" :disabled="busy" hidden />
          <span v-if="!busy" class="sk-redbox-inner">
            <span class="sk-plus">＋</span>
            <span class="sk-redbox-label">Create Live Sticker</span>
          </span>
          <span v-else class="sk-redbox-inner">Reading photo…</span>
        </label>
        <p class="sk-hint">Any photo works — vertical or horizontal. JPG / PNG / WebP.</p>
      </section>

      <!-- Step 1: crop + choose text -->
      <section v-else-if="phase === 'cropping'" class="sk-crop">
        <p class="sk-step">1 · Adjust the square (we centred it on the face)</p>
        <div class="sk-crop-box">
          <img ref="cropImg" :src="cropSrc" alt="crop" />
        </div>

        <p class="sk-step">2 · Sticker words</p>
        <div class="sk-source">
          <button v-if="hasLyrics" :class="{ on: source === 'lyrics' }" @click="source = 'lyrics'">Song lyrics</button>
          <button :class="{ on: source === 'manual' }" @click="source = 'manual'">Type my own</button>
        </div>

        <template v-if="source === 'lyrics'">
          <!-- Pick a song first, then a line from it. -->
          <select v-if="hasSongs" v-model.number="songIndex" class="sk-select sk-song">
            <option v-for="(s, i) in songs" :key="i" :value="i">🎵 {{ s.title }}</option>
          </select>
          <select v-model="lyricLine" class="sk-select">
            <option v-for="(s, i) in (hasSongs ? songLines : suggestions)" :key="i" :value="s">{{ s }}</option>
          </select>
        </template>
        <div v-else>
          <input v-model="manualText" :maxlength="maxChars" class="sk-input"
                 placeholder="e.g. Best Dad Ever" />
          <p class="sk-tiny">We'll auto-correct spelling for English text.</p>
        </div>

        <div class="sk-actions">
          <button class="sk-ghost" @click="reset">Cancel</button>
          <button class="sk-go" :disabled="busy" @click="createStickers">
            {{ busy ? "Starting…" : "Make my sticker ✨" }}
          </button>
        </div>
      </section>

      <!-- Step 2: rendering -->
      <section v-else-if="phase === 'rendering'" class="sk-render">
        <div class="sk-spinner"></div>
        <p>{{ stage || "Working…" }}</p>
        <div class="sk-bar"><div class="sk-bar-fill" :style="{ width: progress + '%' }"></div></div>
      </section>

      <!-- Step 3: results -->
      <section v-else-if="phase === 'done'" class="sk-done">
        <p class="sk-step">Your sticker — tap to download</p>
        <div class="sk-grid">
          <a v-for="(url, i) in stickers" :key="i" :href="url" :download="`sticker_${i + 1}.png`" class="sk-cell">
            <img :src="url" :alt="`sticker ${i + 1}`" />
          </a>
        </div>
        <div class="sk-actions">
          <button class="sk-go" @click="reset">Make another 🎨</button>
        </div>
      </section>

      <section v-else-if="phase === 'error'" class="sk-done">
        <div class="sk-actions"><button class="sk-go" @click="reset">Try again</button></div>
      </section>

      <!-- Ads box — below the sticker block. Shows only when an ad is tagged 'Sticker page'. -->
      <div v-if="hasStickerAd" class="sk-ads">
        <AdCarousel :ads="ads" location="sticker_ads" />
      </div>
    </main>
  </div>
</template>

<style scoped>
.sticker-page { max-width: 640px; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
.sk-ads { margin-top: 1.75rem; padding-top: 1.5rem; border-top: 1px solid var(--border, #2a2a2a); }
.sk-top { text-align: center; margin-bottom: 1.5rem; }
.sk-back { display: inline-block; color: var(--muted, #888); text-decoration: none; font-size: .9rem; margin-bottom: .5rem; }
.sk-top h1 { margin: .2rem 0; font-size: 1.6rem; }
.sk-sub { color: var(--muted, #888); margin: 0; }
.sk-error { background: #fde8e8; color: #b42318; padding: .7rem 1rem; border-radius: 10px; margin-bottom: 1rem; }

/* The red square box button the user asked for. */
.sk-start { text-align: center; }
.sk-redbox {
  display: flex; align-items: center; justify-content: center;
  width: 240px; height: 240px; margin: 1rem auto; cursor: pointer;
  background: linear-gradient(135deg, #ef4444, #dc2626);
  border-radius: 28px; color: #fff;
  box-shadow: 0 10px 30px rgba(220, 38, 38, .4);
  transition: transform .12s ease, box-shadow .12s ease;
}
.sk-redbox:hover { transform: translateY(-3px); box-shadow: 0 16px 40px rgba(220, 38, 38, .5); }
.sk-redbox.busy { opacity: .7; cursor: wait; }
.sk-redbox-inner { display: flex; flex-direction: column; align-items: center; gap: .4rem; }
.sk-plus { font-size: 3rem; line-height: 1; }
.sk-redbox-label { font-weight: 700; font-size: 1.05rem; }
.sk-hint { color: var(--muted, #888); font-size: .85rem; }

.sk-step { font-weight: 700; margin: 1.2rem 0 .5rem; }
.sk-crop-box { max-width: 420px; margin: 0 auto; }
.sk-crop-box img { max-width: 100%; display: block; }

.sk-source { display: flex; gap: .5rem; margin-bottom: .6rem; }
.sk-source button {
  flex: 1; padding: .55rem; border: 1px solid var(--border, #ccc); border-radius: 10px;
  background: transparent; cursor: pointer; font-size: .9rem;
}
.sk-source button.on { background: #dc2626; color: #fff; border-color: #dc2626; }
.sk-song { margin-bottom: .5rem; }
.sk-select, .sk-input {
  width: 100%; padding: .65rem; border: 1px solid var(--border, #ccc); border-radius: 10px;
  font-size: 1rem; box-sizing: border-box;
}
.sk-tiny { color: var(--muted, #888); font-size: .78rem; margin: .3rem 0 0; }

.sk-actions { display: flex; gap: .6rem; justify-content: center; margin-top: 1.4rem; }
.sk-go {
  padding: .75rem 1.6rem; border: none; border-radius: 12px; cursor: pointer;
  background: #dc2626; color: #fff; font-weight: 700; font-size: 1rem;
}
.sk-go:disabled { opacity: .6; cursor: wait; }
.sk-ghost { padding: .75rem 1.4rem; border: 1px solid var(--border, #ccc); border-radius: 12px; background: transparent; cursor: pointer; }

.sk-render { text-align: center; padding: 2.5rem 0; }
.sk-spinner {
  width: 44px; height: 44px; margin: 0 auto 1rem; border: 4px solid #f3d3d3;
  border-top-color: #dc2626; border-radius: 50%; animation: sk-spin 1s linear infinite;
}
@keyframes sk-spin { to { transform: rotate(360deg); } }
.sk-bar { width: 80%; max-width: 360px; height: 8px; margin: 1rem auto 0; background: #eee; border-radius: 99px; overflow: hidden; }
.sk-bar-fill { height: 100%; background: #dc2626; transition: width .4s ease; }

.sk-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .8rem; }
.sk-cell { border-radius: 14px; overflow: hidden; background: repeating-conic-gradient(#f0f0f0 0% 25%, #fff 0% 50%) 0 0 / 20px 20px; }
.sk-cell img { width: 100%; display: block; transition: transform .12s ease; }
.sk-cell:hover img { transform: scale(1.04); }
</style>
