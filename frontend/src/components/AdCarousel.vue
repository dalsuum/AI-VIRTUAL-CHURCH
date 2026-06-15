<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { api } from '../composables/useApi.js';

const props = defineProps({
  ads:          { type: Array,  default: () => [] }, // all active ads for this service
  location:     { type: String, required: true },     // 'start' | 'between' | 'end'
  sessionToken: { type: String, default: '' },
  language:     { type: String, default: '' },
  mood:         { type: String, default: '' },
});
const emit = defineEmits(['done']); // ad finished (auto or manual dismiss)

// Filter ads for this location
const locationAds = computed(() =>
  props.ads.filter(ad => ad.status === 'active' && (ad.locations || []).includes(props.location))
);

// Pick first matching ad (targeting already done server-side)
const ad = computed(() => locationAds.value[0] || null);
const slides = computed(() => ad.value?.slides || []);

const slideIndex = ref(0);
const currentSlide = computed(() => slides.value[slideIndex.value] || null);
const total = computed(() => slides.value.length);

// Duration for current slide: slide override → ad default → 5s
const slideDuration = computed(() => {
  const s = currentSlide.value;
  return (s?.duration_seconds || ad.value?.slide_duration || 5) * 1000;
});

let timer = null;
let shownAt = null;
let impressionSent = false;

function startTimer() {
  clearTimeout(timer);
  timer = setTimeout(() => nextSlide(), slideDuration.value);
}

watch(ad, (newAd) => {
  if (!newAd) return;
  slideIndex.value = 0;
  impressionSent = false;
  shownAt = Date.now();
  startTimer();
  sendImpression();
}, { immediate: true });

watch(slideIndex, () => {
  startTimer();
});

function nextSlide() {
  if (slideIndex.value < total.value - 1) {
    slideIndex.value++;
  } else {
    dismiss();
  }
}

function prevSlide() {
  if (slideIndex.value > 0) slideIndex.value--;
}

function dismiss() {
  clearTimeout(timer);
  const durationMs = shownAt ? Date.now() - shownAt : 0;
  updateImpression(durationMs, false);
  emit('done');
}

function handleClick() {
  if (!currentSlide.value?.link_url) return;
  updateImpression(shownAt ? Date.now() - shownAt : 0, true);
  window.open(currentSlide.value.link_url, '_blank', 'noopener,noreferrer');
}

async function sendImpression() {
  if (!ad.value || impressionSent) return;
  impressionSent = true;
  try {
    await api.trackAdImpression({
      ad_id: ad.value.id,
      ad_slide_id: currentSlide.value?.id || null,
      location: props.location,
      duration_ms: 0,
      clicked: false,
      session_token: props.sessionToken || null,
      language: props.language || null,
      mood: props.mood || null,
    });
  } catch { /* non-critical */ }
}

async function updateImpression(duration_ms, clicked) {
  if (!ad.value) return;
  try {
    await api.trackAdImpression({
      ad_id: ad.value.id,
      ad_slide_id: currentSlide.value?.id || null,
      location: props.location,
      duration_ms,
      clicked,
      session_token: props.sessionToken || null,
      language: props.language || null,
      mood: props.mood || null,
    });
  } catch { /* non-critical */ }
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
  <div v-if="ad" class="ad-carousel">
    <!-- HTML ad -->
    <template v-if="ad.type === 'html' && ad.html_content">
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div class="ad-html" v-html="ad.html_content"></div>
    </template>

    <!-- Slideshow ad -->
    <template v-else-if="ad.type === 'slideshow' && total > 0">
      <div class="slide-stage" :class="{ clickable: currentSlide?.link_url }" @click="handleClick">
        <!-- Image slide -->
        <img
          v-if="currentSlide?.image_url || currentSlide?.image_path"
          :src="currentSlide.image_url || `/storage/${currentSlide.image_path}`"
          class="slide-img"
          :alt="ad.title"
        />
        <!-- HTML slide -->
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div v-else-if="currentSlide?.html_content" class="slide-html" v-html="currentSlide.html_content"></div>
      </div>

      <!-- Slide controls: dots -->
      <div v-if="total > 1" class="slide-controls">
        <button class="ctrl-btn" :disabled="slideIndex === 0" @click.stop="prevSlide">&#8249;</button>
        <span v-for="(_, i) in slides" :key="i" class="dot" :class="{ active: i === slideIndex }"></span>
        <button class="ctrl-btn" :disabled="slideIndex === total - 1" @click.stop="nextSlide">&#8250;</button>
      </div>

      <!-- Progress bar for current slide -->
      <div class="progress-bar-wrap">
        <div class="progress-bar" :style="{ animationDuration: slideDuration + 'ms' }" :key="`${ad.id}-${slideIndex}`"></div>
      </div>
    </template>

    <!-- Ad label + skip -->
    <div class="ad-footer">
      <span class="ad-label">Ad</span>
      <span class="ad-title-text">{{ ad.title }}</span>
      <button class="skip-btn" @click="dismiss">Skip ›</button>
    </div>
  </div>
</template>

<style scoped>
.ad-carousel {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  overflow: hidden;
  background: var(--surface);
}
.ad-html { padding: .75rem; }
.ad-html :deep(ins), .ad-html :deep(iframe), .ad-html :deep(img) { max-width: 100%; }

.slide-stage { position: relative; width: 100%; aspect-ratio: 16/9; overflow: hidden; background: #000; }
.slide-stage.clickable { cursor: pointer; }
.slide-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.slide-html { width: 100%; height: 100%; overflow: hidden; }
.slide-html :deep(*) { max-width: 100%; }

.slide-controls { display: flex; align-items: center; justify-content: center; gap: .5rem; padding: .4rem .75rem; background: var(--surface-2, #f9fafb); }
.ctrl-btn { background: none; border: 1px solid var(--border); border-radius: 999px; width: 1.7rem; height: 1.7rem; cursor: pointer; font-size: 1rem; display: flex; align-items: center; justify-content: center; }
.ctrl-btn:disabled { opacity: .3; cursor: default; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: var(--border); transition: background .15s; }
.dot.active { background: var(--primary); }

.progress-bar-wrap { height: 3px; background: var(--border); }
.progress-bar { height: 100%; background: var(--primary); animation: progress-fill linear forwards; width: 0; }
@keyframes progress-fill { from { width: 0 } to { width: 100% } }

.ad-footer { display: flex; align-items: center; gap: .6rem; padding: .3rem .75rem; background: var(--surface-2, #f9fafb); border-top: 1px solid var(--border); font-size: .78rem; }
.ad-label { background: #f59e0b; color: #fff; border-radius: 3px; padding: .05rem .35rem; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; flex-shrink: 0; }
.ad-title-text { flex: 1; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.skip-btn { background: none; border: none; cursor: pointer; color: var(--primary); font-size: .78rem; padding: 0; white-space: nowrap; }
</style>
