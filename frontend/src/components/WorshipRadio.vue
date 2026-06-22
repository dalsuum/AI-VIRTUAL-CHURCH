<script setup>
/**
 * AI Worship Radio — worshippers state a mood and the app continuously plays
 * mood-matched worship songs (English / Burmese / Zolai) until they press Stop.
 *
 * Selection is server-side (POST /music/recommend); this component only drives
 * the queue + player. Continuous autoplay keeps a rolling window of the last 50
 * played track ids and asks the server for a fresh playlist (excluding them)
 * when the queue runs low, so songs never repeat soon and the radio loops
 * forever. Playback uses the YouTube IFrame API so we can auto-advance when a
 * track ends.
 */
import { ref, computed, onMounted, onUnmounted } from "vue";
import { api } from "../composables/useApi";

const LANGUAGES = [
  { code: "en", label: "English" },
  { code: "my", label: "Burmese" },
  { code: "td", label: "Zolai" },
];
const RECENT_CAP = 50;     // no-repeat window
const REFILL_AT = 2;       // tracks remaining before we fetch the next playlist

const language = ref("en");
const moods = ref([]);          // [{ key, label, emoji }]
const selectedMood = ref("");
const freeText = ref("");

const queue = ref([]);          // upcoming WorshipTrack[]
const current = ref(null);      // now playing
const reason = ref("");
const themes = ref([]);
const loading = ref(false);
const error = ref("");
const playing = ref(false);
const recentIds = [];           // rolling last-50 ids

let player = null;              // YT.Player instance
let ytReady = null;            // Promise resolving when the IFrame API is loaded

const activeMoodLabel = computed(() => {
  if (freeText.value.trim()) return freeText.value.trim();
  const m = moods.value.find((x) => x.key === selectedMood.value);
  return m ? m.label : "";
});

onMounted(async () => {
  try {
    const res = await api.musicMoods();
    moods.value = res.moods || [];
  } catch {
    error.value = "Could not load moods.";
  }
});

onUnmounted(() => {
  try { player && player.destroy(); } catch {}
});

/** Load the YouTube IFrame API once; resolve when window.YT is ready. */
function loadYouTubeApi() {
  if (ytReady) return ytReady;
  ytReady = new Promise((resolve) => {
    if (window.YT && window.YT.Player) return resolve();
    const tag = document.createElement("script");
    tag.src = "https://www.youtube.com/iframe_api";
    const prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => { prev && prev(); resolve(); };
    document.head.appendChild(tag);
  });
  return ytReady;
}

/** Extract the 11-char video id from a youtube watch/share URL. */
function youtubeId(url) {
  if (!url) return null;
  const m = String(url).match(/(?:v=|youtu\.be\/|embed\/)([\w-]{11})/);
  return m ? m[1] : null;
}

function payload(extra = {}) {
  const mood = freeText.value.trim() || activeMoodLabel.value || selectedMood.value;
  return { language: language.value, mood, ...extra };
}

/** Start a brand-new radio session for the chosen mood. */
async function start() {
  if (!activeMoodLabel.value) { error.value = "Pick a mood or describe how you feel."; return; }
  error.value = "";
  loading.value = true;
  recentIds.length = 0;
  queue.value = [];
  current.value = null;
  try {
    const res = await api.musicRecommend(payload());
    reason.value = res.reason || "";
    themes.value = res.themes || [];
    queue.value = res.playlist || [];
    if (!queue.value.length) { error.value = "No songs matched yet — try another mood."; return; }
    await playNext();
  } catch (e) {
    error.value = "Could not build a playlist. Please try again.";
  } finally {
    loading.value = false;
  }
}

/** Fetch another playlist (excluding recently played) and append it. */
async function refill() {
  try {
    const res = await api.musicRecommend(payload({ exclude: recentIds.slice(-RECENT_CAP) }));
    const fresh = (res.playlist || []).filter(
      (t) => !recentIds.includes(t.id) && !queue.value.some((q) => q.id === t.id),
    );
    queue.value.push(...fresh);
  } catch { /* keep playing what we have */ }
}

/** Advance to the next track in the queue, refilling when it runs low. */
async function playNext() {
  if (queue.value.length <= REFILL_AT) await refill();
  const next = queue.value.shift();
  if (!next) { error.value = "Radio stopped — no more songs."; stop(); return; }
  current.value = next;
  recentIds.push(next.id);
  if (recentIds.length > RECENT_CAP) recentIds.shift();
  await playCurrent();
}

async function playCurrent() {
  const id = youtubeId(current.value?.youtube_url);
  if (!id) { /* no embeddable source — skip ahead */ await playNext(); return; }
  await loadYouTubeApi();
  if (!player) {
    player = new window.YT.Player("worship-yt", {
      height: "100%", width: "100%", videoId: id,
      playerVars: { autoplay: 1, rel: 0, modestbranding: 1 },
      events: {
        onReady: () => { playing.value = true; },
        onStateChange: (e) => {
          if (e.data === window.YT.PlayerState.ENDED) playNext();
          if (e.data === window.YT.PlayerState.PLAYING) playing.value = true;
          if (e.data === window.YT.PlayerState.PAUSED) playing.value = false;
        },
      },
    });
  } else {
    player.loadVideoById(id);
    playing.value = true;
  }
}

function togglePlay() {
  if (!player) return;
  playing.value ? player.pauseVideo() : player.playVideo();
}
function skip() { playNext(); }
function stop() {
  try { player && player.stopVideo(); } catch {}
  playing.value = false;
  current.value = null;
  queue.value = [];
}

function pickMood(key) { selectedMood.value = key; freeText.value = ""; }

function fmtDuration(s) {
  if (!s) return "";
  const m = Math.floor(s / 60), sec = String(s % 60).padStart(2, "0");
  return `${m}:${sec}`;
}
function streamLink(t) {
  return t.youtube_url || t.spotify_url || t.apple_music_url || null;
}
</script>

<template>
  <div class="worship">
    <header class="wr-head">
      <a class="wr-back" href="#">← Back</a>
      <h1>🎶 AI Worship Radio</h1>
      <p class="wr-sub">Tell us how you feel — we'll keep the worship playing.</p>
    </header>

    <section class="wr-controls">
      <div class="wr-langs">
        <button
          v-for="l in LANGUAGES" :key="l.code"
          class="wr-lang" :class="{ active: language === l.code }"
          @click="language = l.code"
        >{{ l.label }}</button>
      </div>

      <div class="wr-moods">
        <button
          v-for="m in moods" :key="m.key"
          class="wr-mood" :class="{ active: selectedMood === m.key && !freeText }"
          @click="pickMood(m.key)"
        >
          <span class="wr-emoji">{{ m.emoji }}</span>{{ m.label }}
        </button>
      </div>

      <div class="wr-free">
        <input
          v-model="freeText" type="text" maxlength="100"
          placeholder="…or describe it: “I feel anxious and tired”"
          @keyup.enter="start"
        />
        <button class="wr-start" :disabled="loading" @click="start">
          {{ loading ? "Finding songs…" : "Start Worship" }}
        </button>
      </div>

      <p v-if="error" class="wr-error">{{ error }}</p>
    </section>

    <section v-if="reason" class="wr-reason">{{ reason }}</section>

    <section v-if="current" class="wr-stage">
      <div class="wr-video"><div id="worship-yt"></div></div>
    </section>

    <section v-if="current || queue.length" class="wr-list">
      <article v-if="current" class="wr-card now">
        <img v-if="current.cover_image" :src="current.cover_image" alt="" class="wr-cover" />
        <div class="wr-meta">
          <span class="wr-badge">Now playing</span>
          <strong>{{ current.title }}</strong>
          <span class="wr-artist">{{ current.artist }} · {{ fmtDuration(current.duration) }}</span>
        </div>
      </article>

      <article v-for="t in queue" :key="t.id" class="wr-card">
        <img v-if="t.cover_image" :src="t.cover_image" alt="" class="wr-cover" />
        <div class="wr-meta">
          <strong>{{ t.title }}</strong>
          <span class="wr-artist">{{ t.artist }} · {{ fmtDuration(t.duration) }}</span>
        </div>
        <a v-if="streamLink(t)" :href="streamLink(t)" target="_blank" rel="noopener" class="wr-ext">↗</a>
      </article>
    </section>

    <footer v-if="current" class="wr-player">
      <span class="wr-current">{{ current.title }} — {{ current.artist }}</span>
      <div class="wr-buttons">
        <button @click="togglePlay">{{ playing ? "⏸ Pause" : "▶ Play" }}</button>
        <button @click="skip">⏭ Next</button>
        <button class="wr-stop" @click="stop">⏹ Stop</button>
      </div>
      <span class="wr-mood-tag" v-if="activeMoodLabel">Mood: {{ activeMoodLabel }}</span>
    </footer>
  </div>
</template>

<style scoped>
.worship { max-width: 880px; margin: 0 auto; padding: 1.5rem 1rem 7rem; color: var(--text); }
.wr-head { text-align: center; margin-bottom: 1.25rem; }
.wr-back { display: inline-block; margin-bottom: .5rem; color: var(--text-muted); text-decoration: none; }
.wr-head h1 { margin: 0; font-size: 1.6rem; }
.wr-sub { color: var(--text-muted); margin: .25rem 0 0; }

.wr-langs { display: flex; gap: .5rem; justify-content: center; margin-bottom: 1rem; }
.wr-lang { padding: .4rem .9rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--text); cursor: pointer; }
.wr-lang.active { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); font-weight: 600; }

.wr-moods { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; margin-bottom: 1rem; }
.wr-mood { display: inline-flex; align-items: center; gap: .35rem; padding: .45rem .8rem;
  border: 1px solid var(--border); border-radius: 999px; background: var(--surface);
  color: var(--text); cursor: pointer; font-size: .9rem; }
.wr-mood.active { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); font-weight: 600; }
.wr-emoji { font-size: 1.1rem; }

.wr-free { display: flex; gap: .5rem; max-width: 600px; margin: 0 auto; }
.wr-free input { flex: 1; padding: .6rem .8rem; border: 1px solid var(--border);
  border-radius: var(--radius-sm); background: var(--surface); color: var(--text); }
.wr-start { padding: .6rem 1.2rem; border: none; border-radius: var(--radius-sm);
  background: var(--primary); color: var(--on-primary); cursor: pointer; font-weight: 600; white-space: nowrap; }
.wr-start:disabled { opacity: .6; cursor: default; }

.wr-error { color: var(--danger); text-align: center; margin-top: .75rem; }

.wr-reason { background: var(--primary-soft); border: 1px solid var(--primary);
  border-radius: var(--radius); padding: .8rem 1rem; margin: 1.25rem 0; color: var(--text); }

.wr-stage { margin: 1rem 0; }
.wr-video { position: relative; padding-top: 56.25%; border-radius: var(--radius); overflow: hidden;
  background: #000; box-shadow: var(--shadow-sm); }
.wr-video > div { position: absolute; inset: 0; }

.wr-list { display: flex; flex-direction: column; gap: .5rem; margin-top: 1rem; }
.wr-card { display: flex; align-items: center; gap: .75rem; padding: .6rem .8rem;
  border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); }
.wr-card.now { border-color: var(--primary); background: var(--primary-soft); }
.wr-cover { width: 48px; height: 48px; object-fit: cover; border-radius: var(--radius-sm); }
.wr-meta { display: flex; flex-direction: column; gap: .15rem; flex: 1; min-width: 0; }
.wr-meta strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wr-artist { color: var(--text-muted); font-size: .85rem; }
.wr-badge { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: var(--primary); font-weight: 700; }
.wr-ext { color: var(--text-muted); text-decoration: none; font-size: 1.1rem; }

.wr-player { position: fixed; left: 0; right: 0; bottom: 0; display: flex; align-items: center;
  justify-content: space-between; gap: 1rem; padding: .75rem 1rem; background: var(--surface);
  border-top: 1px solid var(--border-strong); box-shadow: var(--shadow); }
.wr-current { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.wr-buttons { display: flex; gap: .5rem; }
.wr-buttons button { padding: .45rem .8rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--text); cursor: pointer; }
.wr-stop { color: var(--danger) !important; border-color: var(--danger) !important; }
.wr-mood-tag { color: var(--text-muted); font-size: .85rem; white-space: nowrap; }

@media (max-width: 640px) {
  .wr-free { flex-direction: column; }
  .wr-player { flex-wrap: wrap; }
  .wr-mood-tag { display: none; }
}
</style>
