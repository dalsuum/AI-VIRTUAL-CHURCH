<script setup>
// The service, presented like a guided liturgy: one full-screen stage at a time,
// in order — worship music, then each spoken segment, then the closing where the
// worshipper can leave a testimony and give. Media-bearing stages auto-advance when
// their audio/video finishes; a manual Previous/Next is always available so the
// worshipper stays in control of the pace.
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import MusicPlayer from "./MusicPlayer.vue";
import TestimonyWall from "./TestimonyWall.vue";
import OfferingForm from "./OfferingForm.vue";
import AdCarousel from "./AdCarousel.vue";
import { api } from "../composables/useApi.js";
import { isRtlLocale } from "../i18n";

const { t } = useI18n();

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
const serviceDir = computed(() => isRtlLocale(props.service?.language) ? "rtl" : "auto");
const musicFallbackNotice = computed(() => {
  const asset = props.service?.music_asset;
  if (!asset || asset.asset_type !== "text") return "";
  return asset.title || t("player.musicUnavailable");
});

// Spoken segments, in the order they're read during the service. Labels resolve
// through i18n at render time via `labelKey`.
const SEGMENTS = [
  { key: "opening_prayer", labelKey: "player.openingPrayer" },
  { key: "scripture", labelKey: "player.scripture" },
  { key: "sermon", labelKey: "player.message" },
  { key: "benediction", labelKey: "player.benediction" },
];

// Build the ordered list of stages from whatever the service produced. Worship leads
// (when music composed), then each spoken segment that has text, then the closing.
const stages = computed(() => {
  const s = props.service;
  const list = [];
  if (s?.music_asset && ["audio", "youtube"].includes(s.music_asset.asset_type)) {
    list.push({ kind: "worship", key: "worship", labelKey: "player.worship" });
  }

  for (const seg of SEGMENTS) {
    const text = s?.segments?.[seg.key];
    // A segment can be a sourced YouTube clip instead of text — the preaching
    // message in YouTube mode. Embedded clips have no text body.
    const embed = s?.embeds?.[seg.key] || null;

    // Audio/video can arrive after the text. Never keep a finished text segment
    // hidden behind a loading screen just because narration is late or failed.
    const audio = s?.narration_enabled === false ? null : (s?.audios?.[seg.key] || null);

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
        labelKey: seg.labelKey,
        text: text || "",
        embed,
        videoParts,                          // array of part URLs, or null
        video: videoParts?.[0] || null,      // first part for initial render
        audio,
      });
    } else if (s?.status !== "complete") {
      list.push({ kind: "loading", key: seg.key, labelKey: seg.labelKey });
    }
  }
  list.push({ kind: "closing", key: "closing", labelKey: "player.beforeYouGo" });
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
function speakSequence(pieces, i, wordOffset = 0) {
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

  const wordsInPiece = pieces[i].split(/\s+/).filter(Boolean).length;

  // Timeout fallback: Chrome sometimes never fires onend (15-second cutoff bug).
  // If the utterance hasn't finished within 30s per 200-char chunk, skip ahead.
  const chunkTimeout = setTimeout(() => {
    if (activeUtterance !== u) return;
    speakSequence(pieces, i + 1, wordOffset + wordsInPiece);
  }, 30000);

  const advance = () => {
    clearTimeout(chunkTimeout);
    speakSequence(pieces, i + 1, wordOffset + wordsInPiece);
  };

  u.onboundary = (e) => {
    if (!textHighlightEnabled.value || e.name !== 'word' || activeUtterance !== u) return;
    const pieceTextToCurrent = pieces[i].substring(0, e.charIndex);
    const wordsInPieceSoFar = pieceTextToCurrent.split(/\s+/).filter(Boolean).length;
    highlightedWordIndex.value = wordOffset + wordsInPieceSoFar;
  };
  u.onend = () => {
    if (activeUtterance !== u) { clearTimeout(chunkTimeout); return; }
    advance();
  };
  // Skip failed pieces rather than freezing — Chrome fires 'interrupted' for
  // non-English scripts when no matching voice is installed.
  u.onerror = (e) => {
    if (activeUtterance !== u) { clearTimeout(chunkTimeout); return; }
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
      1: t("player.errAborted"),
      2: t("player.errNetwork"),
      3: t("player.errDecode"),
      4: t("player.errSource"),
    }[code] || t("player.errGeneric")
  );
}

function onMediaError(ev) {
  mediaNote.value = `${describeMediaError(ev.target)} (${ev.target?.currentSrc || "no source"})`;
}

// Hard-stop the media element that's about to be replaced. Pausing it BEFORE the
// DOM swaps <audio> for <video> is essential: a detached media element keeps
// playing in the background, so without this the old narration audio echoes
// under the avatar video (which carries the same narration in its own track).
function stopMediaEl() {
  const el = mediaEl.value;
  if (!el) return;
  try { el.pause(); } catch { /* element may already be gone */ }
}

// Try to start the current stage's media. Runs after the DOM settles so we grab
// the freshly-mounted element (the stage subtree is keyed and remounts per stage).
// `seekTo` lets a late-arriving avatar video resume from where the narration audio
// had reached, so the speech isn't heard a second time on handoff.
async function playCurrentMedia(seekTo = 0) {
  mediaNote.value = "";
  await nextTick();
  const el = mediaEl.value;
  if (!el) return;
  if (seekTo > 0) {
    const applySeek = () => { try { el.currentTime = seekTo; } catch { /* unseekable */ } };
    if (el.readyState >= 1) applySeek();
    else el.addEventListener("loadedmetadata", applySeek, { once: true });
  }
  try {
    await el.play();
  } catch (err) {
    // NotAllowedError = the browser is gating autoplay behind a user gesture.
    mediaNote.value =
      err?.name === "NotAllowedError"
        ? t("player.tapToStartAudio")
        : `${t("player.errGeneric")} (${err?.name || err})`;
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
      stopMediaEl();           // silence the previous segment's media before it unmounts
      if (video || audio) playCurrentMedia();
      else if (usesBrowserSpeech.value) narrate(current.value.text);
    } else {
      // Same segment — only act when a URL just appeared for the first time.
      const newVideo = video && !prev.video;
      const newAudio = audio && !prev.audio;
      if (newVideo || newAudio) {
        stopNarration();
        // Avatar video just replaced the narration audio: hand off seamlessly by
        // resuming the video where the audio was, so the line isn't spoken twice.
        // Only for a single-part video (multi-part splits don't share one timeline).
        const singlePart = !current.value?.videoParts || current.value.videoParts.length <= 1;
        const handoffAt = newVideo && singlePart && mediaEl.value && !mediaEl.value.paused
          ? mediaEl.value.currentTime
          : 0;
        stopMediaEl();         // pause the now-stale audio before the DOM swaps in the video
        playCurrentMedia(handoffAt);
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

// ── Ad carousel integration ────────────────────────────────────────────────

const activeAds    = ref([]);
const showStartAd  = ref(false);
const showEndAd    = ref(false);

// A stable session token for impression tracking — use the service session token.
const sessionToken = computed(() => props.service?.session_token || '');

onMounted(async () => {
  window.addEventListener("keydown", onKey);
  // Fetch active ads — fire and forget so a failure never blocks the service.
  try {
    const res = await api.fetchActiveAds(props.service?.language, props.service?.mood);
    activeAds.value = res.ads || [];
    // Show start ad only when one is active for the 'start' location.
    if (activeAds.value.some(a => a.status === 'active' && (a.locations || []).includes('start'))) {
      showStartAd.value = true;
    }
  } catch { /* non-critical — ads must never block the service */ }
});

onUnmounted(() => {
  window.removeEventListener("keydown", onKey);
  stopNarration();
});

// When the worshipper clicks "End service", show the end ad first (if any).
function requestExit() {
  if (activeAds.value.some(a => a.status === 'active' && (a.locations || []).includes('end'))) {
    showEndAd.value = true;
  } else {
    emit('exit');
  }
}

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

    <p v-if="musicFallbackNotice && atStart && !showStartAd" class="stage-hint">{{ musicFallbackNotice }}</p>

    <!-- Start ad gate: shown before the first stage when an active start ad exists. -->
    <AdCarousel
      v-if="showStartAd"
      :ads="activeAds"
      location="start"
      :session-token="sessionToken"
      :language="service.language || ''"
      :mood="service.mood || ''"
      @done="showStartAd = false"
    />

    <!-- Normal stage content (shown after any start ad clears) -->
    <template v-else>
      <div class="stage" :key="current.key">
        <!-- Worship -->
        <template v-if="current.kind === 'worship'">
          <h2 class="stage-title">{{ t("player.worship") }}</h2>
          <MusicPlayer :asset="service.music_asset" @ended="onMediaEnded" />
          <p class="stage-hint">{{ t("player.worshipHint") }}</p>
        </template>

        <!-- Spoken segment -->
        <template v-else-if="current.kind === 'segment'">
          <h2 class="stage-title">{{ t(current.labelKey) }}</h2>

          <!-- A YouTube-sourced segment (the preaching message in YouTube mode):
               embed the clip and auto-advance when it ends, same as worship. -->
          <MusicPlayer
            v-if="current.embed"
            :asset="{ asset_type: 'youtube', provider_ref: current.embed.provider_ref, title: current.embed.title }"
            @ended="onMediaEnded"
          />

          <template v-else>
            <!-- Avatar/narration player stays pinned at the top while a long prayer
                 or message scrolls beneath it, so the presenter never scrolls away. -->
            <div v-if="current.video || current.audio" class="stage-media">
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
                v-else
                ref="mediaEl"
                class="narration"
                :src="current.audio"
                controls
                @ended="onMediaEnded"
                @error="onMediaError"
                @timeupdate="onMediaTimeUpdate"
              ></audio>
              <p v-if="mediaNote" class="media-note">{{ mediaNote }}</p>
            </div>
            <button
              v-else-if="usesBrowserSpeech"
              class="read-aloud"
              type="button"
              @click="toggleNarration"
            >
              {{ narrating ? t("player.stopReading") : t("player.readAloud") }}
            </button>
            <div class="stage-text bidi-text" :dir="serviceDir">
              <p v-for="(para, pi) in paragraphs" :key="pi">
                <template v-for="({ word, idx }) in para.words" :key="idx">
                  <span :class="{ highlight: textHighlightEnabled && idx === highlightedWordIndex }">{{ word }}</span>{{ ' ' }}
                </template>
              </p>
            </div>
          </template>
        </template>

        <!-- Loading stage (for incomplete segments when streaming service) -->
        <template v-else-if="current.kind === 'loading'">
          <h2 class="stage-title">{{ t(current.labelKey) }}</h2>
          <div class="loading-state" style="text-align: center; padding: 2rem 0; color: var(--text-muted);">
            <div class="spinner" aria-hidden="true" style="margin: 0 auto 1rem; width: 32px; height: 32px; border: 3px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p>{{ t("player.composingMessage") }}</p>
          </div>
        </template>

        <!-- Closing: testimony + offering -->
        <template v-else>
          <h2 class="stage-title">{{ t("player.beforeYouGo") }}</h2>
          <p class="stage-hint">{{ t("player.beforeYouGoHint") }}</p>
          <TestimonyWall />
          <OfferingForm />
        </template>
      </div>

      <!-- Between-stage ad slot: shown below stage content, above controls. -->
      <AdCarousel
        v-if="activeAds.length"
        :ads="activeAds"
        location="between"
        :session-token="sessionToken"
        :language="service.language || ''"
        :mood="service.mood || ''"
        @done="() => {}"
      />

      <nav class="controls">
        <button class="nav prev" :disabled="atStart" @click="prev">{{ t("player.previous") }}</button>
        <span class="pos">{{ index + 1 }} / {{ stages.length }}</span>
        <!-- On the last stage the forward action ends the service rather than dead-ending
             on a disabled button. -->
        <button v-if="!atEnd" class="nav next" @click="next">{{ t("player.next") }}</button>
        <button v-else class="nav end" @click="requestExit">{{ t("player.endService") }}</button>
      </nav>
    </template>

    <!-- End-of-service ad overlay: shown when worshipper clicks "End service". -->
    <div v-if="showEndAd" class="end-ad-overlay">
      <AdCarousel
        :ads="activeAds"
        location="end"
        :session-token="sessionToken"
        :language="service.language || ''"
        :mood="service.mood || ''"
        @done="emit('exit')"
      />
    </div>
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
.stage-title { font-size: 1.4rem; margin: 0 0 0.9rem; }
.stage-hint { color: var(--text-muted); font-size: 0.9rem; margin: 0.75rem 0 0; }

/* Pin the presenter while a long segment scrolls. The backdrop + blur keep the
   scrolling text from showing through the rounded corners and bottom gap. */
.stage-media {
  position: sticky;
  top: 0;
  z-index: 5;
	  margin-block-end: 0.9rem;
  padding: 0.5rem 0 0.75rem;
  background: var(--surface-2, var(--surface, #14141a));
  backdrop-filter: blur(8px);
  /* Soft fade so the text appears to slide under the player rather than collide. */
  box-shadow: 0 12px 16px -8px var(--surface-2, rgba(0, 0, 0, 0.55));
}
.avatar {
  width: 100%;
  max-height: 42vh;          /* keep the pinned video from eating the screen on tall portraits */
  object-fit: contain;
  border-radius: var(--radius-sm);
  background: #000;
  display: block;
}
.narration { width: 100%; display: block; }
.media-note { color: var(--text-muted); font-size: 0.85rem; margin: 0.5rem 0 0; }
.read-aloud {
  align-self: flex-start;
  margin-block-end: 0.9rem;
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
.stage-text p:last-child { margin-block-end: 0; }
.stage-text span { border-radius: 3px; transition: background 0.15s; }
.stage-text span.highlight { background: rgba(99, 179, 237, 0.35); }

.ad-slot {
  width: 100%;
  overflow: hidden;
  border-radius: var(--radius-sm);
  background: transparent;
  text-align: center;
}
.ad-slot :deep(ins),
.ad-slot :deep(iframe),
.ad-slot :deep(img) {
  max-width: 100%;
  height: auto;
}

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

/* End-of-service ad overlay */
.end-ad-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: 1.5rem;
}
.end-ad-overlay > * {
  max-width: 480px;
  width: 100%;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
