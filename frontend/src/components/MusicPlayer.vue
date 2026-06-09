<script setup>
// Renders whichever music asset the pipeline produced. The dual-source design
// surfaces here: an "audio" asset is a stored file (pre-signed URL), a "youtube"
// asset is an embedded clip referenced by video id.
//
// Emits `ended` when the track finishes so the service player can auto-advance to
// the opening prayer. For stored audio this is the native <audio> ended event; for
// YouTube we load the IFrame API and watch for the ENDED player state.
import { onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
  asset: { type: Object, required: true }, // { asset_type, url?, provider_ref?, title? }
});
const emit = defineEmits(["ended"]);

const ytEl = ref(null);
let ytPlayer = null;

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
    <audio
      v-if="asset.asset_type === 'audio'"
      :src="asset.url"
      controls
      autoplay
      @ended="emit('ended')"
    ></audio>

    <!-- YouTube: the IFrame API replaces this div with the player iframe so we can
         observe playback state and emit `ended`. -->
    <div v-else-if="asset.asset_type === 'youtube'" class="yt-wrap">
      <div ref="ytEl"></div>
    </div>

    <p v-if="asset.title" class="title">{{ asset.title }}</p>
  </div>
</template>

<style scoped>
.player { width: 100%; }
audio { width: 100%; }
.yt-wrap { width: 100%; aspect-ratio: 16 / 9; border-radius: var(--radius-sm); overflow: hidden; }
.yt-wrap :deep(iframe) { width: 100%; height: 100%; border: 0; }
.title { font-size: 0.85rem; color: var(--text-muted); margin: 0.5rem 0 0; }
</style>
