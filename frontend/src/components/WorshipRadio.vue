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
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const { t } = useI18n();

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
const noEmbed = ref(false);     // current track has no embeddable YouTube source
const recentIds = [];           // rolling last-50 ids

let player = null;              // YT.Player instance
let ytReady = null;            // Promise resolving when the IFrame API is loaded
let fallbackTimer = null;      // auto-advance timer for non-embeddable tracks
let watchdog = null;           // skips a track that never starts playing

function clearFallback() { if (fallbackTimer) { clearTimeout(fallbackTimer); fallbackTimer = null; } }
function clearWatchdog() { if (watchdog) { clearTimeout(watchdog); watchdog = null; } }
// Some YouTube videos (Shorts, embed/region-restricted) never fire onError —
// they loop buffering→unstarted and never reach PLAYING, hanging the radio. If a
// track hasn't actually started within the window, skip to the next one.
function armWatchdog() { clearWatchdog(); watchdog = setTimeout(() => playNext(), 12000); }

/** Mood chip text in the currently selected language (falls back to English). */
function moodLabel(m) {
  return (m.labels && m.labels[language.value]) || m.label;
}

const activeMoodLabel = computed(() => {
  if (freeText.value.trim()) return freeText.value.trim();
  const m = moods.value.find((x) => x.key === selectedMood.value);
  return m ? moodLabel(m) : "";
});

onMounted(async () => {
  try {
    const res = await api.musicMoods();
    moods.value = res.moods || [];
  } catch {
    error.value = t("worship.errors.loadMoods");
  }
  // Reuse a saved worship session: prefill mood + language from the hash and
  // auto-start. A stored mood that matches a chip key fills the chip; anything
  // else is treated as the worshipper's own free-text mood.
  const q = new URLSearchParams(window.location.hash.split("?")[1] || "");
  const mood = q.get("mood");
  if (mood) {
    language.value = q.get("language") || language.value;
    if (moods.value.some((m) => m.key === mood)) selectedMood.value = mood;
    else freeText.value = mood;
    start();
  }
});

onUnmounted(() => {
  clearFallback();
  clearWatchdog();
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
  // Send the canonical mood KEY (e.g. "relax"), not the localized label, so the
  // server's theme expansion + keyword search stay language-independent; the
  // server translates the key back to a native search term per language. Free
  // text (already in the worshipper's language) is sent verbatim.
  const mood = freeText.value.trim() || selectedMood.value;
  return { language: language.value, mood, ...extra };
}

/** Start a brand-new radio session for the chosen mood. */
async function start() {
  if (!activeMoodLabel.value) { error.value = t("worship.errors.pickMood"); return; }
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
    if (!queue.value.length) { error.value = t("worship.errors.noMatch"); return; }
    await playNext();
  } catch (e) {
    error.value = t("worship.errors.buildFailed");
  } finally {
    loading.value = false;
  }
}

/**
 * Fetch another batch in the SAME language and append it. The server only ever
 * returns same-language tracks, so when a small catalogue is exhausted the
 * excluded request comes back empty — at that point we recycle (clear the
 * no-repeat window except the current song) so the radio loops within the
 * chosen language instead of stopping or pulling another language.
 */
async function refill() {
  try {
    let res = await api.musicRecommend(payload({ exclude: recentIds.slice(-RECENT_CAP) }));
    let fresh = (res.playlist || []).filter(
      (t) => !recentIds.includes(t.id) && !queue.value.some((q) => q.id === t.id),
    );

    if (fresh.length === 0) {
      // Catalogue for this language is exhausted — recycle to keep looping.
      recentIds.length = 0;
      if (current.value) recentIds.push(current.value.id);
      res = await api.musicRecommend(payload({ exclude: current.value ? [current.value.id] : [] }));
      fresh = (res.playlist || []).filter((t) => !queue.value.some((q) => q.id === t.id));
    }

    queue.value.push(...fresh);
  } catch { /* keep playing what we have */ }
}

/** Advance to the next track in the queue, refilling when it runs low. */
async function playNext() {
  if (queue.value.length <= REFILL_AT) await refill();
  const next = queue.value.shift();
  if (!next) { error.value = t("worship.errors.stopped"); stop(); return; }
  current.value = next;
  recentIds.push(next.id);
  if (recentIds.length > RECENT_CAP) recentIds.shift();
  await playCurrent();
}

async function playCurrent() {
  clearFallback();
  clearWatchdog();
  const id = youtubeId(current.value?.youtube_url);

  // No embeddable video (e.g. a metadata-only track without a YouTube link):
  // keep the radio rolling — show the card with an external link and
  // auto-advance after the track's duration instead of silently skipping.
  if (!id) {
    try { player && player.pauseVideo(); } catch {}
    noEmbed.value = true;
    playing.value = true;
    const secs = Math.min(Number(current.value?.duration) || 45, 600);
    fallbackTimer = setTimeout(() => playNext(), secs * 1000);
    return;
  }

  noEmbed.value = false;
  await loadYouTubeApi();
  if (!player) {
    player = new window.YT.Player("worship-yt", {
      height: "100%", width: "100%", videoId: id,
      // playsinline keeps playback INSIDE the page on iOS/Android (without it
      // mobile Safari/Chrome refuse to start an embed or hijack to fullscreen);
      // origin is required by the IFrame API to avoid postMessage/embed errors.
      playerVars: {
        autoplay: 1, rel: 0, modestbranding: 1,
        playsinline: 1, origin: window.location.origin,
      },
      events: {
        onReady: () => { armWatchdog(); },
        onStateChange: (e) => {
          if (e.data === window.YT.PlayerState.ENDED) playNext();
          // Real playback started — cancel the no-start watchdog.
          if (e.data === window.YT.PlayerState.PLAYING) { playing.value = true; clearWatchdog(); }
          if (e.data === window.YT.PlayerState.PAUSED) playing.value = false;
        },
        // Dead/unavailable/embedding-disabled video → don't get stuck, skip on.
        onError: () => playNext(),
      },
    });
  } else {
    player.loadVideoById(id);
    armWatchdog();
  }
}

function togglePlay() {
  // Non-embeddable track: pause/resume the auto-advance timer.
  if (noEmbed.value) {
    if (playing.value) { clearFallback(); playing.value = false; }
    else {
      const secs = Math.min(Number(current.value?.duration) || 45, 600);
      fallbackTimer = setTimeout(() => playNext(), secs * 1000);
      playing.value = true;
    }
    return;
  }
  if (!player) return;
  playing.value ? player.pauseVideo() : player.playVideo();
}
function skip() { clearFallback(); playNext(); }
function stop() {
  clearFallback();
  clearWatchdog();
  try { player && player.stopVideo(); } catch {}
  playing.value = false;
  noEmbed.value = false;
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
      <a class="wr-back" href="#">← {{ t("worship.back") }}</a>
      <h1>🎶 {{ t("worship.title") }}</h1>
      <p class="wr-sub">{{ t("worship.subtitle") }}</p>
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
          <span class="wr-emoji">{{ m.emoji }}</span>{{ moodLabel(m) }}
        </button>
      </div>

      <div class="wr-free">
        <input
          v-model="freeText" type="text" maxlength="100"
          :placeholder="t('worship.describePlaceholder')"
          @keyup.enter="start"
        />
        <button class="wr-start" :disabled="loading" @click="start">
          {{ loading ? t("worship.finding") : t("worship.start") }}
        </button>
      </div>

      <p v-if="error" class="wr-error">{{ error }}</p>
    </section>

    <section v-if="reason" class="wr-reason">{{ reason }}</section>

    <section v-if="current" class="wr-stage">
      <div v-show="!noEmbed" class="wr-video"><div id="worship-yt"></div></div>
      <div v-if="noEmbed" class="wr-noembed">
        <strong>{{ current.title }}</strong>
        <span>{{ current.artist }}</span>
        <p>{{ t("worship.noEmbed") }}</p>
        <a v-if="streamLink(current)" :href="streamLink(current)" target="_blank" rel="noopener" class="wr-open">
          {{ t("worship.openExternally") }}
        </a>
        <small>{{ t("worship.autoAdvancing") }}</small>
      </div>
    </section>

    <section v-if="current || queue.length" class="wr-list">
      <article v-if="current" class="wr-card now">
        <img v-if="current.cover_image" :src="current.cover_image" alt="" class="wr-cover" />
        <div class="wr-meta">
          <span class="wr-badge">{{ t("worship.nowPlaying") }}</span>
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
        <button @click="togglePlay">{{ playing ? t("worship.pause") : t("worship.play") }}</button>
        <button @click="skip">{{ t("worship.next") }}</button>
        <button class="wr-stop" @click="stop">{{ t("worship.stop") }}</button>
      </div>
      <span class="wr-mood-tag" v-if="activeMoodLabel">{{ t("worship.moodTag", { mood: activeMoodLabel }) }}</span>
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
/* 16:9 player capped so it never dominates the viewport (esp. on phones where a
   full-width 56.25% box pushes the song list off-screen). max-height letterboxes
   the box instead of letting it grow with screen width. */
.wr-video { position: relative; width: 100%; aspect-ratio: 16 / 9; max-height: 55vh;
  margin: 0 auto; border-radius: var(--radius); overflow: hidden;
  background: #000; box-shadow: var(--shadow-sm); }
.wr-video > div, .wr-video :deep(iframe) { position: absolute; inset: 0; width: 100%; height: 100%; }
.wr-noembed { display: flex; flex-direction: column; align-items: center; gap: .35rem;
  padding: 2rem 1rem; border: 1px dashed var(--border-strong); border-radius: var(--radius);
  background: var(--surface); text-align: center; }
.wr-noembed strong { font-size: 1.1rem; }
.wr-noembed p { color: var(--text-muted); margin: .25rem 0; }
.wr-noembed small { color: var(--text-faint); }
.wr-open { color: var(--primary); text-decoration: none; font-weight: 600; }

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

.wr-player { position: fixed; left: 0; right: 0; bottom: 0; z-index: 41; display: flex; align-items: center;
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
  /* Keep the YouTube box compact on phones so the now-playing card and queue
     stay visible without scrolling past a giant player. */
  .wr-video { max-height: 34vh; }
  /* Sit the player bar directly above the fixed BottomNav so its
     Play/Pause/Next/Stop controls aren't hidden behind it on phones. */
  .wr-player { flex-wrap: wrap; bottom: var(--bottom-nav-h); }
  .wr-mood-tag { display: none; }
}
</style>
