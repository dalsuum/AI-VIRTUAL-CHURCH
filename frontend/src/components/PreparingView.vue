<script setup>
// The gate between intake and the service. Shows the church mark and a gentle
// countdown while the AI pipeline composes the service in the background. For a
// registered worshipper, the mood-aware "welcome back" greeting fades in as soon as
// it lands. We only open the doors once BOTH the countdown has elapsed AND the
// service is fully composed — so the worship video can play uninterrupted.
import { computed, onMounted, onUnmounted, ref } from "vue";

const props = defineProps({
  // The polled service object (null until the first poll returns). We read its
  // `status` and `welcome` fields.
  service: { type: Object, default: null },
  // The worshipper's remembered display name, for the fallback greeting.
  displayName: { type: String, default: "" },
});
const emit = defineEmits(["ready"]);

const COUNTDOWN_FROM = 12; // seconds of held breath before the doors open
const remaining = ref(COUNTDOWN_FROM);
let timer = null;
let opened = false;

const composed = computed(() => props.service?.status === "complete");
const welcome = computed(() => props.service?.welcome || "");
const countdownDone = computed(() => remaining.value <= 0);

// Open only when the countdown has finished AND the service is composed. If the
// countdown beats the pipeline, we hold on "Almost ready…" until composition lands.
const canOpen = computed(() => countdownDone.value && composed.value);

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

    <div class="count" :class="{ holding: countdownDone && !composed }">
      <template v-if="!countdownDone">
        <span class="num">{{ remaining }}</span>
        <span class="lead">Your service begins in…</span>
      </template>
      <template v-else-if="!composed">
        <span class="spinner" aria-hidden="true"></span>
        <span class="lead">Almost ready — composing the final touches…</span>
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
