<script setup>
// Renders whichever music asset the pipeline produced. The dual-source design
// surfaces here: an "audio" asset is a stored file (pre-signed URL), a "youtube"
// asset is an embedded clip referenced by video id.
//
// Emits `ended` when the track finishes so the service player can auto-advance to
// the opening prayer. For stored audio this is the native <audio> ended event; for
// YouTube we load the IFrame API and watch for the ENDED player state.
import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
  asset: { type: Object, required: true }, // { asset_type, url?, provider_ref?, title?, lyrics? }
});
const emit = defineEmits(["ended"]);

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
      1: "Playback was aborted.",
      2: "Network error while fetching the music.",
      3: "The music file could not be decoded.",
      4: "Music source unavailable — the track may have expired.",
    }[code] || "The worship music failed to play."
  );
}

function onAudioError(ev) {
  audioNote.value = `${describeMediaError(ev.target)} Tap ▶ to retry.`;
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
        ? "Tap ▶ on the bar to start the worship music."
        : `Couldn't start the music: ${err?.name || err}`;
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
    videoId: props.asset.provider_ref,
    playerVars: { autoplay: 1, rel: 0, playsinline: 1 },
    events: {
      onStateChange: (e) => {
        if (e.data === window.YT.PlayerState.ENDED) emit("ended");
      },
    },
  });
});

onBeforeUnmount(() => {
  if (ytPlayer) {
    ytPlayer.destroy();
    ytPlayer = null;
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
      ></audio>
      <p v-if="audioNote" class="note">{{ audioNote }}</p>
    </template>

    <!-- YouTube: the IFrame API replaces this div with the player iframe so we can
         observe playback state and emit `ended`. -->
    <div v-else-if="asset.asset_type === 'youtube'" class="yt-wrap">
      <div ref="ytEl"></div>
    </div>

    <p v-if="asset.title" class="title">{{ asset.title }}</p>

    <!-- Public-domain hymn verses, shown to read/sing along (hymn sources). -->
    <pre v-if="asset.lyrics" class="lyrics">{{ asset.lyrics }}</pre>
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
  text-align: left;
}
</style>
