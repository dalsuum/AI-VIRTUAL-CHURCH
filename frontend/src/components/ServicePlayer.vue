<script setup>
// The service, presented like a guided liturgy: one full-screen stage at a time,
// in order — worship music, then each spoken segment, then the closing where the
// worshipper can leave a testimony and give. Media-bearing stages auto-advance when
// their audio/video finishes; a manual Previous/Next is always available so the
// worshipper stays in control of the pace.
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import MusicPlayer from "./MusicPlayer.vue";
import TestimonyWall from "./TestimonyWall.vue";
import OfferingForm from "./OfferingForm.vue";

const props = defineProps({
  service: { type: Object, required: true },
});
const emit = defineEmits(["exit"]);

// Spoken segments, in the order they're read during the service.
const SEGMENTS = [
  { key: "opening_prayer", label: "Opening Prayer" },
  { key: "scripture", label: "Scripture" },
  { key: "sermon", label: "Message" },
  { key: "benediction", label: "Benediction" },
];

// Build the ordered list of stages from whatever the service produced. Worship leads
// (when music composed), then each spoken segment that has text, then the closing.
const stages = computed(() => {
  const s = props.service;
  const list = [];
  if (s?.music_asset) list.push({ kind: "worship", key: "worship", label: "Worship" });
  for (const seg of SEGMENTS) {
    if (s?.segments?.[seg.key]) {
      list.push({
        kind: "segment",
        key: seg.key,
        label: seg.label,
        text: s.segments[seg.key],
        video: s.videos?.[seg.key] || null,
        audio: s.audios?.[seg.key] || null,
      });
    }
  }
  list.push({ kind: "closing", key: "closing", label: "Testimony & Offering" });
  return list;
});

const index = ref(0);
const current = computed(() => stages.value[index.value]);
const atStart = computed(() => index.value === 0);
const atEnd = computed(() => index.value === stages.value.length - 1);

function next() {
  if (!atEnd.value) index.value += 1;
}
function prev() {
  if (!atStart.value) index.value -= 1;
}

// A media stage that finished playing flows naturally into the next one.
function onMediaEnded() {
  next();
}

// --- Narration via the browser's built-in speech synthesis ---------------
// When a spoken segment arrives without server-generated audio/video, the
// browser reads its text aloud. No API key, no storage — it just works.
const speechSupported = typeof window !== "undefined" && "speechSynthesis" in window;
const narrating = ref(false);

// A segment falls back to browser speech only when it has no richer media.
const usesBrowserSpeech = computed(
  () => speechSupported && current.value?.kind === "segment" && !current.value.video && !current.value.audio,
);

function pickVoice() {
  const voices = window.speechSynthesis.getVoices();
  return voices.find((v) => /^en[-_]/i.test(v.lang)) || voices[0] || null;
}

// Chrome silently cuts off any single utterance after ~15s, so a long segment
// (the sermon especially) would stop partway. We split the text into short,
// sentence-aligned pieces and speak them back-to-back — no one utterance runs
// long enough to trip the cutoff, and the segment reads start to finish.
function splitForSpeech(text) {
  const clean = text.replace(/\s+/g, " ").trim();
  const sentences = clean.match(/[^.!?]+[.!?]*\s*/g) || [clean];
  const pieces = [];
  let buf = "";
  for (const s of sentences) {
    if (buf && (buf + s).length > 200) {
      pieces.push(buf.trim());
      buf = "";
    }
    buf += s;
  }
  if (buf.trim()) pieces.push(buf.trim());
  return pieces;
}

// The utterance we're currently driving. We detach its handlers before any
// cancel so a stop we initiate never masquerades as a natural "ended".
let activeUtterance = null;

function stopNarration() {
  if (!speechSupported) return;
  if (activeUtterance) {
    activeUtterance.onend = null;
    activeUtterance.onerror = null;
    activeUtterance = null;
  }
  window.speechSynthesis.cancel();
  narrating.value = false;
}

// Speak `pieces` from index `i` onward, one after the next. Only the final
// piece finishing counts as a natural end (and advances the service) — the
// same contract <audio>/<video> have via @ended.
function speakSequence(pieces, i) {
  if (i >= pieces.length) {
    activeUtterance = null;
    narrating.value = false;
    onMediaEnded();
    return;
  }
  const u = new SpeechSynthesisUtterance(pieces[i]);
  u.rate = 0.96;
  const voice = pickVoice();
  if (voice) u.voice = voice;
  u.onend = () => {
    if (activeUtterance !== u) return; // stopped or replaced — don't continue
    speakSequence(pieces, i + 1);
  };
  u.onerror = () => {
    if (activeUtterance !== u) return;
    activeUtterance = null;
    narrating.value = false;
  };
  activeUtterance = u;
  window.speechSynthesis.speak(u);
}

function narrate(text) {
  if (!speechSupported || !text) return;
  stopNarration();
  const pieces = splitForSpeech(text);
  if (!pieces.length) return;
  narrating.value = true;
  speakSequence(pieces, 0);
}

function toggleNarration() {
  if (narrating.value) stopNarration();
  else if (current.value?.kind === "segment") narrate(current.value.text);
}

// Read each spoken segment as it becomes active. The first stage may stay
// silent until the worshipper interacts (browsers gate speech on a user
// gesture); the Read-aloud button and Next/Previous paging both satisfy that.
watch(
  () => current.value?.key,
  () => {
    stopNarration();
    if (usesBrowserSpeech.value) narrate(current.value.text);
  },
  { immediate: true },
);

// Keyboard paging — left/right arrows, the way you'd flip through slides.
function onKey(e) {
  if (e.key === "ArrowRight") next();
  else if (e.key === "ArrowLeft") prev();
}
onMounted(() => window.addEventListener("keydown", onKey));
onUnmounted(() => {
  window.removeEventListener("keydown", onKey);
  stopNarration();
});

// If the stage list grows after the service finishes composing (it shouldn't, since
// we only enter the player once complete) keep the index in range.
watch(stages, (list) => {
  if (index.value > list.length - 1) index.value = list.length - 1;
});
</script>

<template>
  <section class="player-shell">
    <!-- Progress: which stage of how many. -->
    <div class="rail">
      <span
        v-for="(st, i) in stages"
        :key="st.key"
        class="pip"
        :class="{ done: i < index, active: i === index }"
      ></span>
    </div>

    <div class="stage" :key="current.key">
      <!-- Worship -->
      <template v-if="current.kind === 'worship'">
        <h2 class="stage-title">Worship</h2>
        <MusicPlayer :asset="service.music_asset" @ended="onMediaEnded" />
        <p class="stage-hint">Let the music settle your heart. We'll continue when it ends.</p>
      </template>

      <!-- Spoken segment -->
      <template v-else-if="current.kind === 'segment'">
        <h2 class="stage-title">{{ current.label }}</h2>
        <video
          v-if="current.video"
          class="avatar"
          :src="current.video"
          autoplay
          controls
          playsinline
          @ended="onMediaEnded"
        ></video>
        <audio
          v-else-if="current.audio"
          class="narration"
          :src="current.audio"
          autoplay
          controls
          @ended="onMediaEnded"
        ></audio>
        <button
          v-else-if="usesBrowserSpeech"
          class="read-aloud"
          type="button"
          @click="toggleNarration"
        >
          {{ narrating ? "⏸ Stop reading" : "🔊 Read aloud" }}
        </button>
        <p class="stage-text">{{ current.text }}</p>
      </template>

      <!-- Closing: testimony + offering -->
      <template v-else>
        <h2 class="stage-title">Before you go</h2>
        <p class="stage-hint">Share what God has done, and give as you feel led.</p>
        <TestimonyWall />
        <OfferingForm />
      </template>
    </div>

    <nav class="controls">
      <button class="nav prev" :disabled="atStart" @click="prev">‹ Previous</button>
      <span class="pos">{{ index + 1 }} / {{ stages.length }}</span>
      <!-- On the last stage the forward action ends the service rather than dead-ending
           on a disabled button. -->
      <button v-if="!atEnd" class="nav next" @click="next">Next ›</button>
      <button v-else class="nav end" @click="emit('exit')">End service</button>
    </nav>
  </section>
</template>

<style scoped>
.player-shell {
  display: flex;
  flex-direction: column;
  min-height: 78vh;
  gap: 1.25rem;
}

.rail { display: flex; gap: 0.4rem; justify-content: center; }
.pip {
  width: 28px; height: 4px; border-radius: 999px;
  background: var(--surface-3);
  transition: background 0.2s ease;
}
.pip.done { background: var(--success); }
.pip.active { background: var(--primary); }

.stage { flex: 1; display: flex; flex-direction: column; }
.stage-title { font-size: 1.4rem; margin: 0 0 0.9rem; letter-spacing: -0.02em; }
.stage-hint { color: var(--text-muted); font-size: 0.9rem; margin: 0.75rem 0 0; }

.avatar { width: 100%; border-radius: var(--radius-sm); margin-bottom: 0.9rem; background: #000; }
.narration { width: 100%; margin-bottom: 0.9rem; }
.read-aloud {
  align-self: flex-start;
  margin-bottom: 0.9rem;
  padding: 0.55rem 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font-weight: 600;
  cursor: pointer;
  transition: border-color 0.12s ease, background 0.12s ease;
}
.read-aloud:hover { border-color: var(--primary); background: var(--primary-soft); }
.stage-text { white-space: pre-wrap; line-height: 1.75; color: var(--text); margin: 0; font-size: 1.05rem; }

.controls {
  position: sticky;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding-top: 1rem;
  margin-top: auto;
  border-top: 1px solid var(--border);
  background: color-mix(in srgb, var(--surface) 90%, transparent);
}
.nav {
  padding: 0.7rem 1.1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font-weight: 600;
  cursor: pointer;
  transition: border-color 0.12s ease, background 0.12s ease;
}
.nav:hover:not(:disabled) { border-color: var(--primary); background: var(--primary-soft); }
.nav:disabled { opacity: 0.4; cursor: default; }
.nav.next { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.nav.next:hover:not(:disabled) { background: var(--primary-hover); }
.nav.end { background: var(--success); color: var(--on-primary, #fff); border-color: var(--success); }
.nav.end:hover { filter: brightness(1.05); }
.pos { color: var(--text-muted); font-size: 0.85rem; font-variant-numeric: tabular-nums; }
</style>
