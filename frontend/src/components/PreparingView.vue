<script setup>
// The gate between intake and the service. Shows the church mark and a gentle
// countdown while the AI pipeline composes the service in the background. For a
// registered worshipper, the mood-aware "welcome back" greeting fades in as soon as
// it lands. The doors open only when the parent confirms the worship media and the
// first narrated prayer are ready.
import { computed, onMounted, onUnmounted, ref, watch, nextTick } from "vue";
import { api } from "../composables/useApi";

const props = defineProps({
  // The polled service object (null until the first poll returns). We read its
  // `status` and `welcome` fields.
  service: { type: Object, default: null },
  // The worshipper's remembered display name, for the fallback greeting.
  displayName: { type: String, default: "" },
  // The chosen worship-music mode, which sets how long composition takes and so
  // how long the countdown runs: "suno" (AI-composed, ~2 min) | "youtube"/"hymn"
  // (return in seconds — YouTube lookup / a pre-rendered public-domain hymn).
  musicSource: { type: String, default: "" },
  // True once the opening experience is ready: worship music has landed when
  // expected, and server narration has produced the opening-prayer audio.
  mediaReady: { type: Boolean, default: false },
  // Service language known at intake time — used immediately on mount so the
  // first card fetch uses the right Bible translation before the poll returns.
  language: { type: String, default: "en" },
  // Worshipper's selected mood at intake time — used for initial verse matching.
  mood: { type: String, default: "" },
});
const emit = defineEmits(["ready"]);

// Myanmar/Tedim need more breathing room because local MMS-TTS work is staggered
// and Suno customMode renders can take longer than text generation.
const COUNTDOWN_BY_SOURCE = { suno: 150, musicgen: 480, youtube: 35, hymn: 35, hymn_sung: 35 };
const MULTILINGUAL_COUNTDOWN_BY_SOURCE = { suno: 300, musicgen: 480, youtube: 90, hymn: 90, hymn_sung: 90 };
const isMultilingualVoice = computed(() => ["my", "td"].includes(props.service?.language));
const lockedMusicSource = computed(() => props.musicSource || props.service?.music_source || "");
const countdownFrom = computed(() => {
  const table = isMultilingualVoice.value ? MULTILINGUAL_COUNTDOWN_BY_SOURCE : COUNTDOWN_BY_SOURCE;
  return table[lockedMusicSource.value] ?? (isMultilingualVoice.value ? 90 : 35);
});
const remaining = ref(countdownFrom.value);
let timer = null;
let cardTimer = null;
let opened = false;

const hasService = computed(() => props.service != null);
const welcome = computed(() => props.service?.welcome || "");
const countdownDone = computed(() => remaining.value <= 0);
const countdownCards = ref([]);
const currentCardIndex = ref(0);
const currentCardContext = ref("");
const currentCard = computed(() => countdownCards.value[currentCardIndex.value] || null);
const cardContext = computed(() => {
  const language = props.service?.language || props.language || "";
  const mood = props.service?.mood || props.mood || "";
  return language + "|" + mood;
});

// Open only once the service is ready (all text composed + music if expected).
// The countdown is a visual experience — it no longer gates the doors by itself.
// App.vue's mediaReady includes a 15-min absolute failsafe so this never hangs.
const canOpen = computed(() => props.mediaReady && hasService.value);

function tick() {
  if (remaining.value > 0) remaining.value -= 1;
  if (canOpen.value && !opened) {
    opened = true;
    clearInterval(timer);
    timer = null;
    if (cardTimer) {
      clearInterval(cardTimer);
      cardTimer = null;
    }
    emit("ready");
  }
}

async function loadCountdownCards() {
  try {
    const language = props.service?.language || props.language || undefined;
    const mood = props.service?.mood || props.mood || undefined;
    const cfg = await api.getConfig({ language, mood });
    countdownCards.value = (Array.isArray(cfg.countdown_cards) ? cfg.countdown_cards : [])
      .filter((c) => c && typeof c.text === "string" && c.text.trim())
      .map((c) => ({
        type: ["testimony", "verse"].includes(c.type) ? c.type : "banner",
        text: c.text.trim(),
        source: typeof c.source === "string" ? c.source.trim() : "",
      }))
      .slice(0, 16);
    currentCardIndex.value = 0;
    currentCardContext.value = cardContext.value;
  } catch {
    countdownCards.value = [];
  }
}

watch(countdownFrom, (next, prev) => {
  // Only extend the countdown if it hasn't ticked below the previous target —
  // prevents a multilingual language arrival from restarting a near-finished countdown.
  if (!opened && remaining.value >= prev) remaining.value = next;
});

watch(cardContext, (next) => {
  if (next !== currentCardContext.value) loadCountdownCards();
});

// React immediately when the server signals readiness — don't wait for the next
// 1-second tick. This eliminates the "Opening the doors…" hang when mediaReady
// arrives between ticks or while the countdown is still running.
watch(canOpen, (val) => {
  if (val && !opened) {
    opened = true;
    if (timer) { clearInterval(timer); timer = null; }
    if (cardTimer) { clearInterval(cardTimer); cardTimer = null; }
    nextTick(() => emit("ready"));
  }
});

onMounted(() => {
  remaining.value = countdownFrom.value;
  loadCountdownCards();
  timer = setInterval(tick, 1000);
  cardTimer = setInterval(() => {
    if (countdownCards.value.length > 1) {
      currentCardIndex.value = (currentCardIndex.value + 1) % countdownCards.value.length;
    }
  }, 9000);
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
  if (cardTimer) clearInterval(cardTimer);
});
</script>

<template>
  <section class="preparing">
    <div class="mark" aria-hidden="true">✝</div>
    <h1 class="title">AI Virtual Church</h1>

    <!-- Personalized greeting for registered worshippers, the moment it arrives. -->
    <transition name="fade">
      <p v-if="welcome" class="welcome">{{ welcome }}</p>
    </transition>

    <div class="count" :class="{ holding: countdownDone && !hasService }">
      <template v-if="!countdownDone">
        <span class="num">{{ remaining }}</span>
        <span class="lead">Your service begins in…</span>
      </template>
      <template v-else-if="!hasService">
        <span class="spinner" aria-hidden="true"></span>
        <span class="lead">Connecting…</span>
      </template>
      <template v-else>
        <span class="lead">Opening the doors…</span>
      </template>
    </div>

    <transition name="fade" mode="out-in">
      <div v-if="currentCard" :key="currentCardIndex" class="wait-card">
        <span class="card-label">{{ currentCard.type === "testimony" ? "Testimony" : currentCard.type === "verse" ? "Scripture" : "While you wait" }}</span>
        <p>{{ currentCard.text }}</p>
        <small v-if="currentCard.source">{{ currentCard.source }}</small>
      </div>
    </transition>

    <p class="hint">Take a breath. Your worship will begin soon.</p>
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
.title { font-size: 1.5rem; margin: 0; letter-spacing: -0.02em; }
.welcome {
  max-width: 34rem;
  color: var(--text);
  line-height: 1.7;
  font-size: 1.05rem;
  margin: 0.25rem 0;
}
.count { display: flex; flex-direction: column; align-items: center; gap: 0.35rem; margin-top: 0.5rem; }
.num { font-size: 3.5rem; font-weight: 700; line-height: 1; color: var(--primary); letter-spacing: -0.03em; }
.lead { color: var(--text-muted); font-size: 0.95rem; }
.hint { color: var(--text-faint); font-size: 0.85rem; max-width: 28rem; margin: 0.5rem 0 0; }
.wait-card {
  width: min(100%, 34rem);
  border: 1px solid var(--border);
  background: var(--surface-2);
  border-radius: var(--radius-sm);
  padding: 0.95rem 1rem;
  text-align: left;
  box-shadow: var(--shadow-sm);
}
.wait-card p { margin: 0.35rem 0 0; color: var(--text); line-height: 1.6; font-size: 0.95rem; }
.wait-card small { display: block; margin-top: 0.5rem; color: var(--text-faint); font-size: 0.78rem; }
.card-label { color: var(--primary); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }

.spinner {
  width: 1.6rem; height: 1.6rem; border-radius: 50%;
  border: 3px solid var(--surface-3);
  border-top-color: var(--primary);
  animation: spin 0.9s linear infinite;
}

@keyframes pulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.06); opacity: 0.85; } }
@keyframes spin { to { transform: rotate(360deg); } }

.fade-enter-active { transition: opacity 0.6s ease; }
.fade-enter-from { opacity: 0; }
</style>
