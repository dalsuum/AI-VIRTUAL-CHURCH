<script setup>
// The gate between intake and the service. Shows a live checklist of generation
// steps that tick off as each segment arrives from the worker. Doors open the
// instant every required step is done — no fixed countdown to wait for.
import { computed, onMounted, onUnmounted, ref, watch, nextTick } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const { t } = useI18n();

const props = defineProps({
  service:     { type: Object,  default: null },
  displayName: { type: String,  default: "" },
  musicSource: { type: String,  default: "" },
  mediaReady:  { type: Boolean, default: false },
  language:    { type: String,  default: "en" },
  mood:        { type: String,  default: "" },
});
const emit = defineEmits(["ready"]);

const SERVER_VOICE_MODES = new Set(["openai", "kokoro", "edge_tts", "mms_tts", "voicebox"]);
const MUSIC_SOURCES      = new Set(["suno", "youtube", "hymn", "hymn_sung", "hymn_youtube", "musicgen"]);

let timer     = null;
let cardTimer = null;
let opened    = false;

const hasService        = computed(() => props.service != null);
const welcome           = computed(() => props.service?.welcome || "");
const lockedMusicSource = computed(() => props.musicSource || props.service?.music_source || "");

// ── Step readiness ──────────────────────────────────────────────────────────

const stepServiceCreated = computed(() => hasService.value);
const stepScripture      = computed(() => !!props.service?.segments?.scripture);
const stepPrayer         = computed(() => !!props.service?.segments?.opening_prayer);

const showMusicStep = computed(() => MUSIC_SOURCES.has(lockedMusicSource.value));
const stepMusic     = computed(() => {
  if (!showMusicStep.value) return true;
  const asset = props.service?.music_asset;
  // A text fallback (provider failure notice) counts as "landed" so we don't hang.
  return asset != null;
});

const showNarrationStep = computed(() => {
  const s = props.service;
  return !!(s && s.narration_enabled !== false && SERVER_VOICE_MODES.has(s.narration_mode));
});
const stepNarration = computed(() => {
  if (!showNarrationStep.value) return true;
  return !!props.service?.audios?.opening_prayer;
});

// ── Door logic (unchanged from previous version) ───────────────────────────

const canOpen = computed(() => props.mediaReady && hasService.value);

watch(canOpen, (val) => {
  if (val && !opened) {
    opened = true;
    if (timer)     { clearInterval(timer);     timer     = null; }
    if (cardTimer) { clearInterval(cardTimer); cardTimer = null; }
    nextTick(() => emit("ready"));
  }
});

// Fallback: check canOpen every second in case the watch fires before mount.
function tick() {
  if (canOpen.value && !opened) {
    opened = true;
    clearInterval(timer);
    timer = null;
    emit("ready");
  }
}

// ── Countdown cards (unchanged) ────────────────────────────────────────────

const countdownCards     = ref([]);
const currentCardIndex   = ref(0);
const currentCardContext = ref("");
const currentCard        = computed(() => countdownCards.value[currentCardIndex.value] || null);
const cardContext         = computed(() => {
  const language = props.service?.language || props.language || "";
  const mood     = props.service?.mood     || props.mood     || "";
  return language + "|" + mood;
});

async function loadCountdownCards() {
  try {
    const language = props.service?.language || props.language || undefined;
    const mood     = props.service?.mood     || props.mood     || undefined;
    const cfg = await api.getConfig({ language, mood });
    countdownCards.value = (Array.isArray(cfg.countdown_cards) ? cfg.countdown_cards : [])
      .filter((c) => c && typeof c.text === "string" && c.text.trim())
      .map((c) => ({
        type:   ["testimony", "verse"].includes(c.type) ? c.type : "banner",
        text:   c.text.trim(),
        source: typeof c.source === "string" ? c.source.trim() : "",
      }))
      .slice(0, 16);
    currentCardIndex.value   = 0;
    currentCardContext.value = cardContext.value;
  } catch {
    countdownCards.value = [];
  }
}

watch(cardContext, (next) => {
  if (next !== currentCardContext.value) loadCountdownCards();
});

onMounted(() => {
  loadCountdownCards();
  timer     = setInterval(tick, 1000);
  cardTimer = setInterval(() => {
    if (countdownCards.value.length > 1) {
      currentCardIndex.value = (currentCardIndex.value + 1) % countdownCards.value.length;
    }
  }, 9000);
});
onUnmounted(() => {
  if (timer)     clearInterval(timer);
  if (cardTimer) clearInterval(cardTimer);
});
</script>

<template>
  <section class="preparing">
    <div class="mark" aria-hidden="true">✝</div>
    <h1 class="title">{{ t("preparing.title") }}</h1>

    <transition name="fade">
      <p v-if="welcome" class="welcome bidi-text" dir="auto">{{ welcome }}</p>
    </transition>

    <!-- Live progress checklist — replaces the fixed countdown number -->
    <div class="prep-steps">
      <div class="step" :class="{ done: stepServiceCreated, waiting: !stepServiceCreated }">
        <span class="step-dot" aria-hidden="true"></span>
        <span>{{ stepServiceCreated ? t("preparing.serviceCreated") : t("preparing.creatingService") }}</span>
      </div>
      <div class="step" :class="{ done: stepScripture, waiting: !stepScripture }">
        <span class="step-dot" aria-hidden="true"></span>
        <span>{{ stepScripture ? t("preparing.scriptureSelected") : t("preparing.selectingScripture") }}</span>
      </div>
      <div class="step" :class="{ done: stepPrayer, waiting: !stepPrayer }">
        <span class="step-dot" aria-hidden="true"></span>
        <span>{{ stepPrayer ? t("preparing.prayerComposed") : t("preparing.composingPrayer") }}</span>
      </div>
      <div v-if="showMusicStep" class="step" :class="{ done: stepMusic, waiting: !stepMusic }">
        <span class="step-dot" aria-hidden="true"></span>
        <span>{{ stepMusic ? t("preparing.musicReady") : t("preparing.loadingMusic") }}</span>
      </div>
      <div v-if="showNarrationStep" class="step" :class="{ done: stepNarration, waiting: !stepNarration }">
        <span class="step-dot" aria-hidden="true"></span>
        <span>{{ stepNarration ? t("preparing.narrationReady") : t("preparing.generatingNarration") }}</span>
      </div>
    </div>

    <transition name="fade" mode="out-in">
      <div v-if="currentCard" :key="currentCardIndex" class="wait-card">
        <span class="card-label">{{ currentCard.type === "testimony" ? t("preparing.labelTestimony") : currentCard.type === "verse" ? t("preparing.labelScripture") : t("preparing.labelWhileWait") }}</span>
        <p class="bidi-text" dir="auto">{{ currentCard.text }}</p>
        <small v-if="currentCard.source" class="bidi-text" dir="auto">{{ currentCard.source }}</small>
      </div>
    </transition>

    <p class="hint">{{ t("preparing.hint") }}</p>
  </section>
</template>

<style scoped>
.preparing {
  min-height: 70vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  gap: 1.1rem;
  padding: 2rem 1rem;
}
.mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 72px;
  border-radius: 20px;
  background: var(--primary);
  color: var(--on-primary);
  font-size: 2rem;
  box-shadow: var(--shadow);
  animation: pulse 2.4s ease-in-out infinite;
}
.title  { font-size: 1.5rem; margin: 0; letter-spacing: 0; }
.welcome {
  max-width: 34rem;
  color: var(--text);
  line-height: 1.7;
  font-size: 1.05rem;
  margin: 0.25rem 0;
}

/* ── Progress checklist ── */
.prep-steps {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  margin: 0.25rem 0;
  text-align: start;
}
.step {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.92rem;
  transition: color 0.3s ease;
}
.step.done    { color: var(--text); }
.step.waiting { color: var(--text-muted); }

.step-dot {
  flex-shrink: 0;
  width: 1rem;
  height: 1rem;
  border-radius: 50%;
  border: 2px solid var(--border);
  background: transparent;
  transition: background 0.3s ease, border-color 0.3s ease;
  position: relative;
}
.step.done .step-dot {
  background: var(--primary);
  border-color: var(--primary);
}
/* Checkmark drawn with pseudo-element */
.step.done .step-dot::after {
  content: "";
  position: absolute;
	  inset-inline-start: 2px;
  top: -1px;
  width: 5px;
  height: 8px;
  border: 2px solid var(--on-primary);
  border-top: none;
	  border-inline-start: none;
  transform: rotate(45deg);
}
/* Pulse ring on the currently-pending step */
.step.waiting .step-dot::before {
  content: "";
  position: absolute;
  inset: -4px;
  border-radius: 50%;
  border: 2px solid var(--primary);
  opacity: 0;
  animation: ring-pulse 1.8s ease-out infinite;
}

.hint { color: var(--text-faint); font-size: 0.85rem; max-width: 28rem; margin: 0.5rem 0 0; }
.wait-card {
  width: min(100%, 34rem);
  border: 1px solid var(--border);
  background: var(--surface-2);
  border-radius: var(--radius-sm);
  padding: 0.95rem 1rem;
	  text-align: start;
  box-shadow: var(--shadow-sm);
}
.wait-card p     { margin: 0.35rem 0 0; color: var(--text); line-height: 1.6; font-size: 0.95rem; }
.wait-card small { display: block; margin-top: 0.5rem; color: var(--text-faint); font-size: 0.78rem; }
.card-label      { color: var(--primary); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }

@keyframes pulse     { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.06); opacity: 0.85; } }
@keyframes ring-pulse { 0% { opacity: 0.6; transform: scale(1); } 100% { opacity: 0; transform: scale(1.9); } }

.fade-enter-active { transition: opacity 0.6s ease; }
.fade-enter-from   { opacity: 0; }
</style>
