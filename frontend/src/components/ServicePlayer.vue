<script setup>
// The service, presented like a guided liturgy: one full-screen stage at a time,
// in order — worship music, then each spoken segment, then the closing where the
// worshipper can leave a testimony and give. Media-bearing stages auto-advance when
// their audio/video finishes; a manual Previous/Next is always available so the
// worshipper stays in control of the pace.
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import MusicPlayer from "./MusicPlayer.vue";
import TestimonyWall from "./TestimonyWall.vue";
import OfferingForm from "./OfferingForm.vue";

const props = defineProps({
  service: { type: Object, required: true },
  // The worshipper's display name — their own, or the friendly visitor name the
  // backend assigned an anonymous guest. Shown alongside their mood in a strip that
  // stays up for the whole service so the worship always feels addressed to them.
  displayName: { type: String, default: "" },
});
const emit = defineEmits(["exit"]);

// The mood the worshipper chose at intake, carried through on the service object.
const mood = computed(() => props.service?.mood || "");
const musicFallbackNotice = computed(() => {
  const asset = props.service?.music_asset;
  if (!asset || asset.asset_type !== "text") return "";
  return asset.title || "Worship music is unavailable right now. We will continue with scripture and prayer.";
});

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
  if (s?.music_asset && ["audio", "youtube"].includes(s.music_asset.asset_type)) {
    list.push({ kind: "worship", key: "worship", label: "Worship" });
  }
  for (const seg of SEGMENTS) {
    const text = s?.segments?.[seg.key];
    // A segment can be a sourced YouTube clip instead of text — the preaching
    // message in YouTube mode. Embedded clips have no text body.
    const embed = s?.embeds?.[seg.key] || null;
    if (text || embed) {
      // video may be a single URL or a JSON array of part URLs (long segments).
      const rawVideo = s.videos?.[seg.key] || null;
      let videoParts = null;
      if (rawVideo) {
        try { videoParts = JSON.parse(rawVideo); } catch { videoParts = [rawVideo]; }
      }
      list.push({
        kind: "segment",
        key: seg.key,
        label: seg.label,
        text: text || "",
        embed,
        videoParts,                          // array of part URLs, or null
        video: videoParts?.[0] || null,      // first part for initial render
        audio: s.narration_enabled === false ? null : (s.audios?.[seg.key] || null),
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
// For multi-part avatar videos, advance to the next part first.
function onMediaEnded() {
  const parts = current.value?.videoParts;
  if (parts && videoPartIndex.value < parts.length - 1) {
    videoPartIndex.value += 1;
    nextTick(() => mediaEl.value?.play());
    return;
  }
  videoPartIndex.value = 0;
  next();
}

// --- Narration via the browser's built-in speech synthesis ---------------
// When a spoken segment arrives without server-generated audio/video, the
// browser reads its text aloud. No API key, no storage — it just works.
const speechSupported = typeof window !== "undefined" && "speechSynthesis" in window;
const narrating = ref(false);

// The global narration voice mode the admin chose (see Admin Console → Settings):
//   'openai'/'kokoro'/'edge_tts' — the server synthesized audio; we play it (handled below).
//   'browser'         — no server audio; the browser reads each segment aloud.
//   'off'             — segments stay silent text.
// Default to 'browser' so a service without the field still reads aloud for free.
const narrationMode = computed(() => props.service?.narration_mode || "browser");
const narrationEnabled = computed(() => props.service?.narration_enabled !== false);
const textHighlightEnabled = computed(() => props.service?.text_highlight_enabled !== false);

// The browser reads a segment when narration is enabled and either (a) 'browser'
// mode is chosen, or (b) a server voice was chosen but no audio arrived.
function pickVoice() {
  const voices = window.speechSynthesis.getVoices();
  const lang = props.service?.language || "en";
  if (lang === "my") {
    return voices.find((v) => /^my[-_]/i.test(v.lang)) || null;
  }
  if (lang === "td") {
    // Tedim has no standard browser voice. Do not fall back to English; it will
    // skip/mangle Tedim words. Use Edge TTS with EDGE_TTS_VOICE_TD if desired.
    return null;
  }
  return voices.find((v) => /^en[-_]/i.test(v.lang)) || voices[0] || null;
}

const browserVoice = computed(() => (speechSupported ? pickVoice() : null));

const usesBrowserSpeech = computed(
  () =>
    speechSupported &&
    browserVoice.value &&
    narrationEnabled.value &&
    narrationMode.value !== "off" &&
    current.value?.kind === "segment" &&
    !current.value.embed &&
    !current.value.video &&
    !current.value.audio,
);

// Chrome silently cuts off any single utterance after ~15s, so a long segment
// (the sermon especially) would stop partway. We split the text into short,
// sentence-aligned pieces and speak them back-to-back — no one utterance runs
// long enough to trip the cutoff, and the segment reads start to finish.
function splitForSpeech(text) {
  const clean = text.replace(/\s+/g, " ").trim();
  const lang = props.service?.language || "en";
  // Burmese uses ။ (full stop) and ၊ (comma) as sentence delimiters, not .!?
  const sentenceRe = lang === "my"
    ? /[^။၊]+[။၊]*\s*/g
    : /[^.!?]+[.!?]*\s*/g;
  const sentences = clean.match(sentenceRe) || [clean];
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
    activeUtterance.onboundary = null;
    activeUtterance = null;
  }
  window.speechSynthesis.cancel();
  narrating.value = false;
  highlightedWordIndex.value = -1;
}

// Speak `pieces` from index `i` onward, one after the next. Only the final
// piece finishing counts as a natural end (and advances the service) — the
// same contract <audio>/<video> have via @ended.
function speakSequence(pieces, i, charOffset = 0) {
  if (i >= pieces.length) {
    activeUtterance = null;
    narrating.value = false;
    highlightedWordIndex.value = -1;
    onMediaEnded();
    return;
  }
  const u = new SpeechSynthesisUtterance(pieces[i]);
  u.rate = 0.96;
  const lang = props.service?.language || "en";
  if (lang === "my") u.lang = "my-MM";
  const voice = browserVoice.value;
  if (voice) u.voice = voice;

  // Timeout fallback: Chrome sometimes never fires onend (15-second cutoff bug).
  // If the utterance hasn't finished within 30s per 200-char chunk, skip ahead.
  const chunkTimeout = setTimeout(() => {
    if (activeUtterance !== u) return;
    speakSequence(pieces, i + 1, charOffset + pieces[i].length + 1);
  }, 30000);

  const advance = () => {
    clearTimeout(chunkTimeout);
    speakSequence(pieces, i + 1, charOffset + pieces[i].length + 1);
  };

  u.onboundary = (e) => {
    if (!textHighlightEnabled.value || e.name !== 'word' || activeUtterance !== u) return;
    highlightedWordIndex.value = charToWordIndex(charOffset + e.charIndex);
  };
  u.onend = () => {
    if (activeUtterance !== u) { clearTimeout(chunkTimeout); return; }
    advance();
  };
  // Skip failed pieces rather than freezing — Chrome fires 'interrupted' for
  // non-English scripts when no matching voice is installed.
  u.onerror = (e) => {
    if (activeUtterance !== u) { clearTimeout(chunkTimeout); return; }
    if (e.error === "interrupted") return; // another utterance replaced us — ignore
    advance();
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

// --- Server-narrated media (audio/avatar) ---------------------------------
// When a segment carries an <audio>/<video> URL we drive it explicitly rather
// than relying on the `autoplay` attribute alone: a bare autoplay that the
// browser blocks (no user gesture yet) or a URL that fails to load leaves a
// silent control bar with no explanation. Here we attempt play, and on any
// failure say *why* — and a tap on the bar recovers it.
const mediaEl = ref(null);
const mediaNote = ref("");
const videoPartIndex = ref(0);
const currentVideoSrc = computed(() => {
  const parts = current.value?.videoParts;
  if (!parts) return current.value?.video || null;
  return parts[videoPartIndex.value] || null;
});

// --- Word highlighting ---------------------------------------------------
const highlightedWordIndex = ref(-1);

// Character start positions of every word in the current segment, used to
// map a char offset (from onboundary or timeupdate) to a word index.
const wordPositions = computed(() => {
  const text = current.value?.text || '';
  const positions = [];
  const regex = /\S+/g;
  let m;
  while ((m = regex.exec(text)) !== null) positions.push(m.index);
  return positions;
});

// Paragraphs split into words with global indices, for rendering spans.
const paragraphs = computed(() => {
  if (!current.value?.text) return [];
  let idx = 0;
  return current.value.text.split(/\n+/).filter(Boolean).map(para => ({
    words: para.split(/\s+/).filter(Boolean).map(word => ({ word, idx: idx++ })),
  }));
});

function charToWordIndex(charIndex) {
  const pos = wordPositions.value;
  let result = 0;
  for (let i = 0; i < pos.length; i++) {
    if (pos[i] <= charIndex) result = i;
    else break;
  }
  return result;
}

function onMediaTimeUpdate() {
  if (!textHighlightEnabled.value) return;
  const el = mediaEl.value;
  const total = wordPositions.value.length;
  if (!el || !el.duration || !total) return;
  highlightedWordIndex.value = Math.min(
    Math.floor((el.currentTime / el.duration) * total),
    total - 1,
  );
}

// Map an HTMLMediaElement error code to plain words.
function describeMediaError(el) {
  const code = el?.error?.code;
  return (
    {
      1: "Playback was aborted.",
      2: "Network error while fetching the audio.",
      3: "The audio file could not be decoded.",
      4: "Audio source not supported or unreachable.",
    }[code] || "Audio failed to play."
  );
}

function onMediaError(ev) {
  mediaNote.value = `${describeMediaError(ev.target)} (${ev.target?.currentSrc || "no source"})`;
}

// Try to start the current stage's media. Runs after the DOM settles so we grab
// the freshly-mounted element (the stage subtree is keyed and remounts per stage).
async function playCurrentMedia() {
  mediaNote.value = "";
  await nextTick();
  const el = mediaEl.value;
  if (!el) return;
  try {
    await el.play();
  } catch (err) {
    // NotAllowedError = the browser is gating autoplay behind a user gesture.
    mediaNote.value =
      err?.name === "NotAllowedError"
        ? "Tap ▶ on the bar to start the audio."
        : `Couldn't start audio: ${err?.name || err}`;
  }
}

// Single watcher handles two distinct cases:
//   1. Segment navigation (key changes): reset state and start appropriate audio.
//   2. Late-arriving media on the same segment (video/audio goes null → URL):
//      stop any browser speech fallback and switch to the media element.
watch(
  () => ({
    key:   current.value?.key,
    video: current.value?.video,
    audio: current.value?.audio,
  }),
  ({ key, video, audio }, prev) => {
    const keyChanged = !prev || key !== prev.key;
    if (keyChanged) {
      highlightedWordIndex.value = -1;
      videoPartIndex.value = 0;
      stopNarration();
      if (video || audio) playCurrentMedia();
      else if (usesBrowserSpeech.value) narrate(current.value.text);
    } else {
      // Same segment — only act when a URL just appeared for the first time.
      const newVideo = video && !prev.video;
      const newAudio = audio && !prev.audio;
      if (newVideo || newAudio) {
        stopNarration();
        playCurrentMedia();
      }
    }
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
    <!-- Who this service is for and the mood they came in with — kept up for the
         whole service, from the first stage to "End service". -->
    <div v-if="displayName || mood" class="identity">
      <span v-if="displayName" class="who">{{ displayName }}</span>
      <span v-if="displayName && mood" class="dot" aria-hidden="true">·</span>
      <span v-if="mood" class="mood-chip">{{ mood }}</span>
    </div>

    <!-- Progress: which stage of how many. -->
    <div class="rail">
      <span
        v-for="(st, i) in stages"
        :key="st.key"
        class="pip"
        :class="{ done: i < index, active: i === index }"
      ></span>
    </div>

    <p v-if="musicFallbackNotice" class="stage-hint">{{ musicFallbackNotice }}</p>

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

        <!-- A YouTube-sourced segment (the preaching message in YouTube mode):
             embed the clip and auto-advance when it ends, same as worship. -->
        <MusicPlayer
          v-if="current.embed"
          :asset="{ asset_type: 'youtube', provider_ref: current.embed.provider_ref, title: current.embed.title }"
          @ended="onMediaEnded"
        />

        <template v-else>
          <video
            v-if="current.video"
            ref="mediaEl"
            class="avatar"
            :src="currentVideoSrc"
            controls
            playsinline
            @ended="onMediaEnded"
            @error="onMediaError"
            @timeupdate="onMediaTimeUpdate"
          ></video>
          <audio
            v-else-if="current.audio"
            ref="mediaEl"
            class="narration"
            :src="current.audio"
            controls
            @ended="onMediaEnded"
            @error="onMediaError"
            @timeupdate="onMediaTimeUpdate"
          ></audio>
          <p v-if="mediaNote && (current.audio || current.video)" class="media-note">{{ mediaNote }}</p>
          <button
            v-else-if="usesBrowserSpeech"
            class="read-aloud"
            type="button"
            @click="toggleNarration"
          >
            {{ narrating ? "⏸ Stop reading" : "🔊 Read aloud" }}
          </button>
          <div class="stage-text">
            <p v-for="(para, pi) in paragraphs" :key="pi">
              <template v-for="({ word, idx }) in para.words" :key="idx">
                <span :class="{ highlight: textHighlightEnabled && idx === highlightedWordIndex }">{{ word }}</span>{{ ' ' }}
              </template>
            </p>
          </div>
        </template>
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

.identity {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  color: var(--text-muted);
  font-size: 0.9rem;
}
.identity .who { font-weight: 600; color: var(--text); }
.identity .dot { color: var(--text-faint); }
.identity .mood-chip {
  padding: 0.15rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary-hover);
  font-weight: 500;
  font-size: 0.8rem;
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
.media-note { color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.9rem; }
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
.stage-text { line-height: 1.75; color: var(--text); margin: 0; font-size: 1.05rem; }
.stage-text p { margin: 0 0 0.75rem; }
.stage-text p:last-child { margin-bottom: 0; }
.stage-text span { border-radius: 3px; transition: background 0.15s; }
.stage-text span.highlight { background: rgba(99, 179, 237, 0.35); }

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
