<script setup>
// Renders whichever music asset the pipeline produced. The dual-source design
// surfaces here: an "audio" asset is a stored file (pre-signed URL), a "youtube"
// asset is an embedded clip referenced by video id.
//
// Emits `ended` when the track finishes so the service player can auto-advance to
// the opening prayer. For stored audio this is the native <audio> ended event; for
// YouTube we load the IFrame API and watch for the ENDED player state.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

const { t } = useI18n();
const props = defineProps({
  // { asset_type, url?, provider_ref?, title?, lyrics?, timings? }
  asset: { type: Object, required: true },
});
const emit = defineEmits(["ended"]);

// --- LRC line-synced lyrics (static sung hymns) ---------------------------
// When the asset carries a `timings` array ([{time, line_index}], ascending by
// time — authored by workers/tools/tap_lyrics.py) we highlight whole lyric
// lines off real timestamps as the <audio> plays, driven by its native
// `timeupdate` event (no polling). Without `timings` we keep the plain <pre>
// verses below. See docs/lrc-static-sync-spike.md.
const hasTimings = computed(() =>
  Array.isArray(props.asset.timings) && props.asset.timings.length > 0);

// Non-empty lyric lines, matching how tap_lyrics.py indexes line_index.
const lyricLines = computed(() =>
  (props.asset.lyrics || "").split(/\n/).map((l) => l.trim()).filter(Boolean));

const activeLineIndex = ref(-1);
const lineEls = ref([]);

// Largest line_index whose timing.time <= t, via binary search; -1 before the
// first cue. Pure + side-effect free so the logic is easy to reason about/test.
function lineIndexAtTime(timings, t) {
  let lo = 0, hi = timings.length - 1, ans = -1;
  while (lo <= hi) {
    const mid = (lo + hi) >> 1;
    if (timings[mid].time <= t) { ans = mid; lo = mid + 1; }
    else { hi = mid - 1; }
  }
  return ans === -1 ? -1 : timings[ans].line_index;
}

function onTimeUpdate(ev) {
  if (!hasTimings.value) return;
  activeLineIndex.value = lineIndexAtTime(props.asset.timings, ev.target.currentTime);
}

// Center the active line *within the scrollable lyrics box only*. We scroll the
// box itself rather than el.scrollIntoView(), which would bubble up and also
// scroll the whole page whenever the box isn't already centered in the viewport.
watch(activeLineIndex, (i) => {
  const el = lineEls.value[i];
  const box = el?.parentElement; // the .lyrics.lrc container
  if (!el || !box) return;
  const delta = (el.getBoundingClientRect().top - box.getBoundingClientRect().top)
              - (box.clientHeight - el.clientHeight) / 2;
  box.scrollBy({ top: delta, behavior: "smooth" });
});

const ytEl = ref(null);
let ytPlayer = null;

// --- Stored audio (AI-composed / Suno) ------------------------------------
// The worship track is driven explicitly rather than trusting the bare
// `autoplay` attribute: a blocked autoplay (no user gesture yet) or a URL that
// fails to load otherwise leaves a silent control bar with no explanation. We
// attempt play, and on any failure say *why* — a tap on the bar then recovers it.
const audioEl = ref(null);
const audioNote = ref("");

// Map an HTMLMediaElement error code to plain words.
function describeMediaError(el) {
  const code = el?.error?.code;
  return (
    {
      1: t("player.errAborted"),
      2: t("player.errMusicNetwork"),
      3: t("player.errMusicDecode"),
      4: t("player.errMusicSource"),
    }[code] || t("player.errMusicGeneric")
  );
}

function onAudioError(ev) {
  audioNote.value = `${describeMediaError(ev.target)} ${t("player.tapRetry")}`;
}

async function playAudio() {
  audioNote.value = "";
  await nextTick();
  const el = audioEl.value;
  if (!el) return;
  try {
    await el.play();
  } catch (err) {
    audioNote.value =
      err?.name === "NotAllowedError"
        ? t("player.tapToStartMusic")
        : `${t("player.errMusicGeneric")} (${err?.name || err})`;
  }
}

// Load the YouTube IFrame API once, then resolve. Subsequent callers reuse the
// in-flight/loaded promise so we never inject the script twice.
function loadYouTubeApi() {
  if (window.YT && window.YT.Player) return Promise.resolve();
  if (!window.__ytApiPromise) {
    window.__ytApiPromise = new Promise((resolve) => {
      const prev = window.onYouTubeIframeAPIReady;
      window.onYouTubeIframeAPIReady = () => {
        prev && prev();
        resolve();
      };
      const tag = document.createElement("script");
      tag.src = "https://www.youtube.com/iframe_api";
      document.head.appendChild(tag);
    });
  }
  return window.__ytApiPromise;
}

onMounted(async () => {
  if (props.asset.asset_type === "audio") {
    playAudio();
    return;
  }
  if (props.asset.asset_type !== "youtube" || !ytEl.value) return;
  await loadYouTubeApi();
  if (!ytEl.value) return; // unmounted while the API loaded
  ytPlayer = new window.YT.Player(ytEl.value, {
    host: 'https://www.youtube.com',
    videoId: props.asset.provider_ref,
    playerVars: { autoplay: 1, rel: 0, playsinline: 1, enablejsapi: 1, origin: window.location.origin },
    events: {
      onStateChange: (e) => {
        if (e.data === window.YT.PlayerState.ENDED) emit("ended");
      },
    },
  });
});

onBeforeUnmount(() => {
  if (ytPlayer) {
    try {
      ytPlayer.destroy();
    } catch (err) {
      // ignore destroy errors if iframe is already detached
    }
    ytPlayer = null;
  }
  // A detached <audio> keeps playing in the background, so the worship song would
  // echo under the next stage (prayer/sermon). Pause it explicitly on unmount.
  if (audioEl.value) {
    try { audioEl.value.pause(); } catch (err) { /* already gone */ }
  }
});
</script>

<template>
  <div class="player">
    <template v-if="asset.asset_type === 'audio'">
      <audio
        ref="audioEl"
        :src="asset.url"
        controls
        autoplay
        @ended="emit('ended')"
        @error="onAudioError"
        @timeupdate="onTimeUpdate"
      ></audio>
      <p v-if="audioNote" class="note">{{ audioNote }}</p>
    </template>

    <!-- YouTube: the IFrame API replaces this div with the player iframe so we can
         observe playback state and emit `ended`. -->
    <div v-else-if="asset.asset_type === 'youtube'" class="yt-wrap">
      <div ref="ytEl"></div>
    </div>

    <p v-if="asset.title" class="title bidi-text" dir="auto">{{ asset.title }}</p>

    <!-- LRC line-synced lyrics: real per-line timings drive a whole-line
         highlight that scrolls into view as the hymn plays. Falls back to the
         plain verses block when the asset has no `timings`. -->
    <div v-if="hasTimings" class="lyrics lrc bidi-text" dir="auto">
      <p
        v-for="(line, li) in lyricLines"
        :key="li"
        :ref="el => { if (el) lineEls[li] = el; }"
        :class="{ 'lrc-active': li === activeLineIndex }"
      >{{ line }}</p>
    </div>
    <!-- Public-domain hymn verses, shown to read/sing along (hymn sources). -->
    <pre v-else-if="asset.lyrics" class="lyrics bidi-text" dir="auto">{{ asset.lyrics }}</pre>
  </div>
</template>

<style scoped>
.player { width: 100%; }
audio { width: 100%; }
.yt-wrap { width: 100%; aspect-ratio: 16 / 9; border-radius: var(--radius-sm); overflow: hidden; }
.yt-wrap :deep(iframe) { width: 100%; height: 100%; border: 0; }
.title { font-size: 0.85rem; color: var(--text-muted); margin: 0.5rem 0 0; }
.note { font-size: 0.85rem; color: var(--text-muted); margin: 0.5rem 0 0; }
.lyrics {
  margin: 1rem 0 0;
  padding: 1rem 1.1rem;
  max-height: 16rem;
  overflow-y: auto;
  white-space: pre-wrap;
  font: inherit;
  line-height: 1.6;
  color: var(--text);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  text-align: start;
}
/* LRC line-synced lyrics: dim inactive lines, lift the active one. */
.lyrics.lrc p { margin: 0 0 0.4rem; transition: color 0.2s, opacity 0.2s; color: var(--text-muted); opacity: 0.55; }
.lyrics.lrc p:last-child { margin-bottom: 0; }
.lyrics.lrc p.lrc-active { color: var(--text); opacity: 1; font-weight: 600; }
</style>
