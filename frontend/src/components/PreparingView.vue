<script setup>
// The gate between intake and the service. Shows the church mark and a gentle
// countdown while the AI pipeline composes the service in the background. For a
// registered worshipper, the mood-aware "welcome back" greeting fades in as soon as
// it lands. The doors open the moment the countdown elapses (a hard cap on the
// wait); whatever the pipeline has composed by then plays, and any remaining
// segments fill in afterwards since the player reads the still-polling service.
import { computed, onMounted, onUnmounted, ref } from "vue";

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
  // True once the spoken service and worship music have both landed — open early.
  mediaReady: { type: Boolean, default: false },
});
const emit = defineEmits(["ready"]);

// The countdown is a hard cap on the wait, sized to how long the chosen music mode
// takes to compose so the doors don't open on silence before the worship music
// lands. AI-composed (Suno) runs ~2 min; YouTube and the pre-rendered hymn
// return in seconds.
const COUNTDOWN_BY_SOURCE = { suno: 150, youtube: 35, hymn: 35, hymn_sung: 35 };
const countdownFrom = computed(() => COUNTDOWN_BY_SOURCE[props.musicSource] ?? 35);
const remaining = ref(countdownFrom.value);
let timer = null;
let opened = false;

const hasService = computed(() => props.service != null);
const welcome = computed(() => props.service?.welcome || "");
const countdownDone = computed(() => remaining.value <= 0);

// Open as soon as the service is composed (mediaReady), or when the countdown
// elapses — whichever comes first. The countdown is the upper bound: whatever the
// pipeline has composed by then plays, and any remaining segments fill in afterwards
// since the player builds its stages reactively from the still-polling service.
const canOpen = computed(() => (props.mediaReady || countdownDone.value) && hasService.value);

function tick() {
  if (remaining.value > 0) remaining.value -= 1;
  if (canOpen.value && !opened) {
    opened = true;
    clearInterval(timer);
    timer = null;
    emit("ready");
  }
}

onMounted(() => {
  remaining.value = countdownFrom.value;
  timer = setInterval(tick, 1000);
});
onUnmounted(() => timer && clearInterval(timer));
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
        <span class="lead">Almost ready — opening your service…</span>
      </template>
      <template v-else>
        <span class="lead">Opening the doors…</span>
      </template>
    </div>

    <p class="hint">Take a breath. When everything is ready, your worship will begin.</p>
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
