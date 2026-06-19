<!--
  Father's Day (Special Day) MV — SELF-CONTAINED & REMOVABLE public page.
  Lives at #fathers-day. A visitor uploads photo(s) of their father, picks an
  effect, and downloads a vertical MV set to the admin-provided song + lyrics.
  Remove by deleting this file + its #fathers-day route/nav-link in App.vue.
-->
<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from "vue";
import Cropper from "cropperjs";
import "cropperjs/dist/cropper.css";
import AdCarousel from "./AdCarousel.vue";
import { api } from "../composables/useApi";

const loading   = ref(true);
const config    = ref(null);          // { enabled, title, subtitle, default_effect, effects, max_photos }
const photos    = ref([]);            // File[]
const previews  = ref([]);            // object URLs
const effect    = ref("slide");
const songId    = ref("");            // chosen song
const phase     = ref("idle");        // idle | rendering | done | error
const errorMsg  = ref("");
const jobId     = ref(null);
const downloadUrl = ref("");
const dragActive = ref(false);
const ads        = ref([]);      // active ads for the box below the block
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

const songs       = computed(() => config.value?.songs ?? []);
const selectedSong = computed(() => songs.value.find((s) => s.id === songId.value) || null);

// ── Song length choice (full vs a clip the visitor picks) ────────────────────
const songMode   = ref("full");   // 'full' | 'clip'
const clipStartVal = ref(0);
const clipEndVal   = ref(0);
const clipAudio  = ref(null);
const clipUrl    = ref("");
const clipLoading = ref(false);

// When the chosen song changes, default to the admin's suggested clip if any.
watch(songId, async (id) => {
  songMode.value = "full";
  clipStartVal.value = 0; clipEndVal.value = 0;
  const s = songs.value.find((x) => x.id === id);
  if (s?.clip_enabled && s.clip_end - s.clip_start >= 1) {
    songMode.value = "clip";
    clipStartVal.value = s.clip_start;
    clipEndVal.value = s.clip_end;
  }
  await loadClipAudio(id);
});

async function loadClipAudio(id) {
  if (clipUrl.value) { URL.revokeObjectURL(clipUrl.value); clipUrl.value = ""; }
  if (!id) return;
  clipLoading.value = true;
  try {
    const blob = await api.fdPublicSongBlob(id);
    clipUrl.value = URL.createObjectURL(blob);
  } catch { /* clip picker just won't have audio */ }
  finally { clipLoading.value = false; }
}

function setClipStart() { if (clipAudio.value) clipStartVal.value = Math.round(clipAudio.value.currentTime * 10) / 10; }
function setClipEnd()   { if (clipAudio.value) clipEndVal.value = Math.round(clipAudio.value.currentTime * 10) / 10; }
function previewClip()  { const a = clipAudio.value; if (a) { a.currentTime = clipStartVal.value; a.play(); } }
const clipLen = computed(() => Math.max(0, clipEndVal.value - clipStartVal.value));

const hasSpecialDayAd = computed(() =>
  ads.value.some((a) => a.status === "active" && (a.locations || []).includes("special_day"))
);
const canGenerate = computed(() => photos.value.length > 0 && !!songId.value && phase.value !== "rendering");
const maxPhotos   = computed(() => config.value?.max_photos ?? 6);

onMounted(async () => {
  try {
    config.value = await api.fdPublicConfig();
    effect.value = config.value?.default_effect || "slide";
    songId.value = config.value?.songs?.[0]?.id || "";
  } catch {
    config.value = { enabled: false };
  } finally {
    loading.value = false;
  }
  // Active ads for the box below the block — fire-and-forget so a failure
  // never blocks the page.
  try {
    const res = await api.fetchActiveAds();
    ads.value = res.ads || [];
  } catch { /* non-critical */ }
});

onUnmounted(() => {
  pollTimer && clearInterval(pollTimer);
  easeTimer && clearInterval(easeTimer);
  destroyCropper();
  if (clipUrl.value) URL.revokeObjectURL(clipUrl.value);
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

// ── Crop-to-vertical editor ─────────────────────────────────────────────────
// Every photo is cropped to 9:16 in the browser before upload, so the framing is
// the user's choice and horizontal/odd-ratio photos always fit the vertical MV.
const cropQueue = ref([]);     // Files still to crop
const cropSrc   = ref("");     // data URL of the image being cropped
const cropImg   = ref(null);   // <img> ref for cropperjs
let cropper = null;

function enqueueFiles(fileList) {
  errorMsg.value = "";
  const room = maxPhotos.value - photos.value.length - cropQueue.value.length;
  const incoming = Array.from(fileList)
    .filter((f) => /^image\/(jpe?g|png|webp)$/.test(f.type))
    .filter((f) => {
      if (f.size > 8 * 1024 * 1024) { errorMsg.value = `${f.name} is larger than 8 MB.`; return false; }
      return true;
    })
    .slice(0, Math.max(0, room));
  if (!incoming.length) return;
  cropQueue.value.push(...incoming);
  if (!cropSrc.value) startNextCrop();
}

function startNextCrop() {
  destroyCropper();
  const next = cropQueue.value[0];
  if (!next) { cropSrc.value = ""; return; }
  const reader = new FileReader();
  reader.onload = (ev) => {
    cropSrc.value = ev.target.result;
    nextTick(() => {
      if (!cropImg.value) return;
      cropper = new Cropper(cropImg.value, {
        aspectRatio: 9 / 16,   // vertical, matches the 720x1280 output
        viewMode: 1,
        autoCropArea: 1,
        background: false,
      });
    });
  };
  reader.readAsDataURL(next);
}

function destroyCropper() {
  if (cropper) { cropper.destroy(); cropper = null; }
}

async function confirmCrop() {
  if (!cropper) return;
  const canvas = cropper.getCroppedCanvas({ maxWidth: 1080, maxHeight: 1920, imageSmoothingQuality: "high" });
  const blob = await new Promise((res) => canvas.toBlob(res, "image/jpeg", 0.9));
  if (blob) {
    const file = new File([blob], `photo_${Date.now()}.jpg`, { type: "image/jpeg" });
    photos.value.push(file);
    previews.value.push(URL.createObjectURL(file));
  }
  cropQueue.value.shift();
  startNextCrop();
}

function skipCrop() {
  cropQueue.value.shift();
  startNextCrop();
}

function cancelCropAll() {
  cropQueue.value = [];
  destroyCropper();
  cropSrc.value = "";
}

function onFileInput(e) { enqueueFiles(e.target.files); e.target.value = ""; }
function onDrop(e) { dragActive.value = false; enqueueFiles(e.dataTransfer.files); }

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
    const opts = (songMode.value === "clip" && clipLen.value >= 1)
      ? { clipStart: clipStartVal.value, clipEnd: clipEndVal.value }
      : { full: true };
    const { job_id } = await api.fdRender(photos.value, effect.value, songId.value, opts);
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

// ── Sharing (mirrors the Live Sticker feature) ───────────────────────────────
const shareNote = ref("");
const sharing   = ref(false);
// Clean public share link on the MAIN domain (Open-Graph video preview, no api.*).
const shareUrl = computed(() => (jobId.value ? `${window.location.origin}/v/${jobId.value}` : ""));
const shareTitle = computed(() => config.value?.title || "My Father's Day video");

async function fetchVideoFile() {
  const res = await fetch(api.fdDownloadUrl(jobId.value), { credentials: "include" });
  if (!res.ok) throw new Error("fetch failed");
  const blob = await res.blob();
  return new File([blob], "fathers-day.mp4", { type: blob.type || "video/mp4" });
}

// Facebook (and others) split an uploaded video longer than ~90s into multiple
// Reels. So we only NATIVE-share files at/under this; longer videos share as a
// single link (one post, tap to play) to avoid the multi-reel mess.
const REEL_MAX_SEC = 90;

function saveBlob(file) {
  const u = URL.createObjectURL(file);
  const a = document.createElement("a");
  a.href = u; a.download = file.name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(u), 5000);
}

async function shareNativeLink() {
  // Single post: hand the social app a URL (one feed post + tap-to-play card).
  if (navigator.share) {
    await navigator.share({ title: shareTitle.value, text: shareTitle.value, url: shareUrl.value });
  } else {
    await copyLink();
    shareNote.value = "Link copied — paste it to post once (it won't be split).";
  }
}

// Is the rendered video longer than one reel? Decided from the visitor's choice
// (full song, or a clip over the limit) — no need to download the file to measure.
const isLongShare = computed(() => songMode.value === "full" || clipLen.value > REEL_MAX_SEC);

async function shareVideo() {
  shareNote.value = ""; sharing.value = true;
  try {
    // Long video → share as ONE link so Facebook can't split it into many reels.
    if (isLongShare.value) {
      await shareNativeLink();
      return;
    }
    // Short clip → native upload = one clean reel that plays in the feed.
    const file = await fetchVideoFile();
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      await navigator.share({ files: [file], title: shareTitle.value, text: shareTitle.value });
    } else {
      saveBlob(file);
      shareNote.value = "Saved the video — attach it in your app to share.";
    }
  } catch (e) {
    if (e?.name !== "AbortError") {
      shareNote.value = "Couldn't open the share menu. Try Save, or the link option below.";
    }
  } finally {
    sharing.value = false;
  }
}

async function saveVideo() {
  try { saveBlob(await fetchVideoFile()); }
  catch { window.open(downloadUrl.value, "_blank"); }
}

async function copyLink() {
  shareNote.value = "";
  try { await navigator.clipboard.writeText(shareUrl.value); shareNote.value = "Link copied!"; }
  catch { shareNote.value = shareUrl.value; }
}

function socialShare(target) {
  const u = encodeURIComponent(shareUrl.value);
  const t = encodeURIComponent(shareTitle.value);
  const links = {
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${u}`,
    x:        `https://twitter.com/intent/tweet?url=${u}&text=${t}`,
    whatsapp: `https://wa.me/?text=${t}%20${u}`,
    telegram: `https://t.me/share/url?url=${u}&text=${t}`,
    viber:    `viber://forward?text=${t}%20${u}`,
  };
  if (links[target]) window.open(links[target], "_blank", "noopener");
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
  shareNote.value = "";
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

      <!-- Choose a song -->
      <div v-if="songs.length" class="fd-songs">
        <p class="fd-label">🎵 Choose a song</p>
        <div class="fd-song-row">
          <button
            v-for="s in songs"
            :key="s.id"
            class="fd-chip"
            :class="{ active: songId === s.id }"
            @click="songId = s.id"
          >{{ s.title }}</button>
        </div>
      </div>

      <!-- Length: full song or pick a part -->
      <div v-if="songId" class="fd-songs">
        <p class="fd-label">⏱ Song length</p>
        <div class="fd-song-row">
          <button class="fd-chip" :class="{ active: songMode === 'full' }" @click="songMode = 'full'">Full song</button>
          <button class="fd-chip" :class="{ active: songMode === 'clip' }" @click="songMode = 'clip'">Pick a part</button>
        </div>

        <div v-if="songMode === 'clip'" class="fd-clip">
          <p v-if="clipLoading" class="fd-muted small">Loading song…</p>
          <audio v-show="clipUrl" ref="clipAudio" :src="clipUrl" controls style="width:100%"></audio>
          <div class="fd-clip-row">
            <button class="fd-btn ghost sm" @click="setClipStart">⏮ Set start</button>
            <span class="fd-clip-t">{{ clipStartVal }}s</span>
            <button class="fd-btn ghost sm" @click="setClipEnd">Set end ⏭</button>
            <span class="fd-clip-t">{{ clipEndVal }}s</span>
            <button class="fd-btn ghost sm" @click="previewClip">▶ Preview</button>
          </div>
          <p class="fd-muted small">Clip length: {{ clipLen.toFixed(1) }}s — play, then mark where your part starts and ends.</p>
        </div>
      </div>

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

      <!-- Crop-to-vertical editor -->
      <div v-if="cropSrc" class="fd-crop-modal">
        <div class="fd-crop-box">
          <h3>Crop to fit</h3>
          <p class="fd-muted small">Drag and pinch/scroll to frame your father. The video is vertical, so this keeps the part you want.</p>
          <div class="fd-crop-area">
            <img ref="cropImg" :src="cropSrc" alt="" />
          </div>
          <p class="fd-muted small" style="text-align:center">{{ cropQueue.length }} photo{{ cropQueue.length === 1 ? '' : 's' }} left to crop</p>
          <div class="fd-crop-actions">
            <button class="fd-btn primary" @click="confirmCrop">✓ Use this</button>
            <button class="fd-btn ghost" @click="skipCrop">Skip</button>
            <button class="fd-btn ghost" @click="cancelCropAll">Cancel all</button>
          </div>
        </div>
      </div>

      <!-- Actions / progress -->
      <div v-if="phase === 'rendering'" class="fd-progress">
        <div class="fd-bar"><div class="fd-bar-fill" :style="{ width: shownProgress + '%' }"></div></div>
        <p class="fd-progress-pct">{{ shownProgress }}%</p>
        <p class="fd-progress-stage">{{ stageLabel }}</p>
      </div>

      <div v-else-if="phase === 'done'" class="fd-done">
        <p class="fd-done-msg">🎉 Your video is ready!</p>
        <button class="fd-btn primary big" :disabled="sharing" @click="shareVideo">📤 Share video</button>
        <p class="fd-muted small">Short clips post as one video; longer ones post as a single link — never split into multiple posts.</p>
        <button class="fd-btn ghost" @click="saveVideo">⬇ Save to device</button>

        <details class="fd-linkshare">
          <summary>Or share a link</summary>
          <p class="fd-muted small">A link shows a preview card that opens the video on tap (not a native upload).</p>
          <div class="fd-social">
            <button class="fd-soc" title="Facebook" @click="socialShare('facebook')">f</button>
            <button class="fd-soc" title="X" @click="socialShare('x')">𝕏</button>
            <button class="fd-soc" title="WhatsApp" @click="socialShare('whatsapp')">✆</button>
            <button class="fd-soc" title="Telegram" @click="socialShare('telegram')">✈</button>
            <button class="fd-soc" title="Viber" @click="socialShare('viber')">V</button>
            <button class="fd-soc wide" @click="copyLink">🔗 Copy link</button>
          </div>
        </details>

        <p v-if="shareNote" class="fd-muted small">{{ shareNote }}</p>
        <button class="fd-btn ghost" @click="reset">Make another</button>
      </div>

      <button v-else class="fd-btn primary big" :disabled="!canGenerate" @click="generate">
        Create video 🎬
      </button>

      <p class="fd-muted small fd-foot">Your photos are used only to make this video and are removed after.</p>
    </div>

    <!-- Ads box — below the block. Shows only when an ad is tagged 'Special Day pages'. -->
    <div v-if="hasSpecialDayAd" class="fd-ads">
      <AdCarousel :ads="ads" location="special_day" />
    </div>
  </div>
</template>

<style scoped>
.fd-page { max-width: 560px; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
.fd-ads { margin-top: 1.5rem; }
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

.fd-crop-modal {
  position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,0.7); padding: 1rem;
}
.fd-crop-box {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 1.25rem; width: 100%; max-width: 460px;
}
.fd-crop-box h3 { margin: 0 0 0.25rem; }
.fd-crop-area { width: 100%; height: 50vh; margin: 0.75rem 0; background: #000; border-radius: var(--radius-sm); overflow: hidden; }
.fd-crop-area img { display: block; max-width: 100%; }
.fd-crop-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.fd-songs { margin-bottom: 1.25rem; }
.fd-song-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.fd-clip { margin-top: 0.75rem; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.fd-clip-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin: 0.6rem 0 0.3rem; }
.fd-clip-t { font-family: monospace; font-size: 0.85rem; color: var(--text-muted); }
.fd-btn.sm { padding: 0.4rem 0.7rem; font-size: 0.82rem; }
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

.fd-linkshare { width: 100%; text-align: center; margin-top: 0.25rem; }
.fd-linkshare summary { cursor: pointer; color: var(--text-muted); font-size: 0.85rem; }
.fd-linkshare summary:hover { color: var(--primary); }
.fd-social { display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center; margin-top: 0.5rem; }
.fd-soc {
  width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--border);
  background: transparent; color: var(--text); cursor: pointer; font-size: 1rem;
  display: inline-flex; align-items: center; justify-content: center;
}
.fd-soc:hover { border-color: var(--primary); color: var(--primary); }
.fd-soc.wide { width: auto; border-radius: 999px; padding: 0 0.9rem; font-size: 0.85rem; }
.fd-done { text-align: center; margin-top: 1.5rem; display: flex; flex-direction: column; gap: 0.6rem; align-items: center; }
.fd-done-msg { font-size: 1.05rem; font-weight: 600; }
.fd-foot { text-align: center; margin-top: 1.25rem; }
</style>
