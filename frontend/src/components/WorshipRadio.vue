<script setup>
/**
 * AI Worship Radio — worshippers state a mood and the app continuously plays
 * mood-matched worship songs in the selected content language until they press Stop.
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
import { getRegistry, isRtlLocale, normalizeLanguage } from "../i18n";
import AppIcon from "./AppIcon.vue";

const { t, locale } = useI18n();

// Worship language follows the one global language authority — no per-page
// language state. The canonical resolver maps the UI locale to its content code.
const language = computed(() => normalizeLanguage(locale.value));
const MOOD_ICONS = {
  energy: "mdi:flash",
  feel_good: "mdi:emoticon-happy-outline",
  focus: "mdi:target",
  love: "mdi:heart",
  relax: "mdi:leaf",
  heartbreak: "mdi:heart-broken",
};
const RECENT_CAP = 50;     // no-repeat window
const REFILL_AT = 2;       // tracks remaining before we fetch the next playlist

const moods = ref([]);          // [{ key, label, emoji }]
const selectedMood = ref("");
const freeText = ref("");

const queue = ref([]);          // upcoming WorshipTrack[]
const current = ref(null);      // now playing
const reason = ref("");
const searchLabel = ref("");
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
function moodIcon(m) {
  return MOOD_ICONS[m.key] || null;
}

const activeMoodLabel = computed(() => {
  if (freeText.value.trim()) return freeText.value.trim();
  const m = moods.value.find((x) => x.key === selectedMood.value);
  return m ? moodLabel(m) : "";
});

// Display name for the active language comes from the backend registry (native
// name), never a hardcoded label.
const selectedLanguageLabel = computed(() => (
  getRegistry()[locale.value]?.native_name || language.value
));
const isRtl = computed(() => isRtlLocale(language.value));

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
    // Language is global now (not restored from the hash); only mood is reused.
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
  const nextSearchLabel = t("worship.worshipQuery", { language: selectedLanguageLabel.value });
  recentIds.length = 0;
  queue.value = [];
  current.value = null;
  try {
    const res = await api.musicRecommend(payload());
    reason.value = res.reason || "";
    searchLabel.value = nextSearchLabel;
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
      <a class="wr-back" href="#">{{ isRtl ? "→" : "←" }} {{ t("worship.back") }}</a>
      <span class="wr-icon" aria-hidden="true">
        <AppIcon name="mdi:radio" size="30px" />
      </span>
      <h1>{{ t("worship.title") }}</h1>
      <p class="wr-sub">{{ t("worship.subtitle") }}</p>
    </header>

    <section class="wr-controls">
      <div class="wr-moods">
        <button
          v-for="m in moods" :key="m.key"
          class="wr-mood" :class="{ active: selectedMood === m.key && !freeText }"
          @click="pickMood(m.key)"
        >
          <AppIcon v-if="moodIcon(m)" class="wr-emoji" :name="moodIcon(m)" size="20px" />
          <span v-else class="wr-emoji">{{ m.emoji }}</span>
          {{ moodLabel(m) }}
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

    <section v-if="reason && !error" class="wr-reason" :title="reason" aria-live="polite">
      <strong class="wr-reason-title">
        <AppIcon name="mdi:music-note" size="20px" />
        {{ t("worship.foundTitle") }}
      </strong>
      <span class="wr-reason-label">{{ t("worship.searchingLabel") }}</span>
      <span class="wr-reason-query">{{ searchLabel }}</span>
      <span class="wr-reason-note">{{ t("worship.showingMatches") }}</span>
    </section>

    <section v-if="current || queue.length" class="wr-list">
      <article v-if="current" class="wr-card now">
        <img v-if="current.cover_image" :src="current.cover_image" alt="" class="wr-cover" />
        <span v-else class="wr-cover wr-cover-fallback" aria-hidden="true">
          <AppIcon name="mdi:music-note" size="34px" />
        </span>
        <div class="wr-meta">
          <span class="wr-badge">{{ t("worship.nowPlaying") }}</span>
          <strong class="bidi-text" dir="auto">{{ current.title }}</strong>
          <span class="wr-artist bidi-text" dir="auto">{{ current.artist }} · {{ fmtDuration(current.duration) }}</span>
          <span class="wr-genre">{{ t("worship.genreLabel") }}</span>
        </div>
        <button
          type="button"
          class="wr-card-action"
          :aria-label="playing ? t('worship.pause') : t('worship.play')"
          :title="playing ? t('worship.pause') : t('worship.play')"
          @click="togglePlay"
        >
          <AppIcon :name="playing ? 'mdi:pause' : 'mdi:play'" size="24px" />
        </button>
      </article>

      <h2 v-if="queue.length" class="wr-list-title">{{ t("worship.upNext") }}</h2>
      <article v-for="track in queue" :key="track.id" class="wr-card">
        <img v-if="track.cover_image" :src="track.cover_image" alt="" class="wr-cover" />
        <span v-else class="wr-cover wr-cover-fallback" aria-hidden="true">
          <AppIcon name="mdi:music-note" size="34px" />
        </span>
        <div class="wr-meta">
          <strong class="bidi-text" dir="auto">{{ track.title }}</strong>
          <span class="wr-artist bidi-text" dir="auto">{{ track.artist }} · {{ fmtDuration(track.duration) }}</span>
          <span class="wr-genre">{{ t("worship.genreLabel") }}</span>
        </div>
        <a
          v-if="streamLink(track)"
          :href="streamLink(track)"
          target="_blank"
          rel="noopener"
          class="wr-card-action"
          :aria-label="t('worship.openTrack', { title: track.title })"
          :title="t('worship.openTrack', { title: track.title })"
        >
          <AppIcon name="mdi:play" size="24px" />
        </a>
      </article>
    </section>

    <section v-if="current" class="wr-stage">
      <div v-show="!noEmbed" class="wr-video"><div id="worship-yt"></div></div>
      <div v-if="noEmbed" class="wr-noembed">
        <strong class="bidi-text" dir="auto">{{ current.title }}</strong>
        <span class="bidi-text" dir="auto">{{ current.artist }}</span>
        <p>{{ t("worship.noEmbed") }}</p>
        <a v-if="streamLink(current)" :href="streamLink(current)" target="_blank" rel="noopener" class="wr-open">
          {{ t("worship.openExternally") }}
        </a>
        <small>{{ t("worship.autoAdvancing") }}</small>
      </div>
    </section>

    <footer v-if="current" class="wr-player">
      <span class="wr-current bidi-text" dir="auto">{{ current.title }} — {{ current.artist }}</span>
      <div class="wr-buttons">
        <button
          type="button"
          :aria-label="playing ? t('worship.pause') : t('worship.play')"
          :title="playing ? t('worship.pause') : t('worship.play')"
          @click="togglePlay"
        >
          <AppIcon :name="playing ? 'mdi:pause' : 'mdi:play'" size="22px" />
          <span class="wr-sr">{{ playing ? t("worship.pause") : t("worship.play") }}</span>
        </button>
        <button type="button" :aria-label="t('worship.next')" :title="t('worship.next')" @click="skip">
          <AppIcon name="mdi:skip-next" class="rtl-flip" size="22px" />
          <span class="wr-sr">{{ t("worship.next") }}</span>
        </button>
        <button type="button" class="wr-stop" :aria-label="t('worship.stop')" :title="t('worship.stop')" @click="stop">
          <AppIcon name="mdi:stop" size="22px" />
          <span class="wr-sr">{{ t("worship.stop") }}</span>
        </button>
      </div>
      <span class="wr-mood-tag" v-if="activeMoodLabel">{{ t("worship.moodTag", { mood: activeMoodLabel }) }}</span>
    </footer>
  </div>
</template>

<style scoped>
.worship {
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
  padding: 1rem 0.875rem 1.5rem;
  color: var(--text);
}
.wr-head {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.35rem;
  margin-bottom: 1rem;
}
.wr-back {
  display: inline-block;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 0.88rem;
}
.wr-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 50px;
  height: 50px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--primary-soft);
  color: var(--primary);
}
.wr-head h1 {
  margin: 0;
  font-size: 1.75rem;
  line-height: 1.1;
  letter-spacing: 0;
}
.wr-sub {
  color: var(--text-muted);
  margin: 0;
  line-height: 1.35;
}

.wr-controls {
  width: 100%;
  max-width: 620px;
  margin: 0 auto;
}
.wr-moods {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
  margin-bottom: 0.85rem;
}
.wr-mood {
  display: inline-flex;
  align-items: center;
	  justify-content: flex-start;
  gap: 0.45rem;
  min-width: 0;
  min-height: 46px;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  font: inherit;
  font-size: 0.92rem;
  line-height: 1.2;
}
.wr-mood.active {
  background: var(--primary-soft);
  border-color: var(--primary);
  color: var(--primary);
  font-weight: 600;
}
.wr-emoji {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  color: currentColor;
  font-size: 1.1rem;
}

.wr-free {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  width: 100%;
  margin: 0 auto;
}
.wr-free input {
  width: 100%;
  min-width: 0;
  min-height: 48px;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font: inherit;
}
.wr-free input:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-soft);
}
.wr-start {
  width: 100%;
  min-height: 48px;
  padding: 0.7rem 1rem;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--primary);
  color: var(--on-primary);
  cursor: pointer;
  font: inherit;
  font-weight: 600;
}
.wr-start:disabled {
  opacity: 0.6;
  cursor: default;
}

.wr-error {
  color: var(--danger);
  text-align: center;
  margin: 0.75rem 0 0;
  overflow-wrap: anywhere;
}

.wr-reason {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.2rem 0.65rem;
  background: var(--primary-soft);
  border: 1px solid var(--primary);
  border-radius: var(--radius-sm);
  padding: 0.85rem 0.95rem;
  margin: 1rem auto;
  max-width: 620px;
  color: var(--text);
  white-space: normal;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.wr-reason-title {
  grid-column: 1 / -1;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}
.wr-reason-label {
  color: var(--text-muted);
  font-size: 0.86rem;
}
.wr-reason-query {
  min-width: 0;
  font-weight: 600;
}
.wr-reason-note {
  grid-column: 1 / -1;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.wr-stage {
  margin: 1rem 0;
}
.wr-video {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  max-height: 34vh;
  margin: 0 auto;
  border-radius: var(--radius-sm);
  overflow: hidden;
  background: #000;
  box-shadow: var(--shadow-sm);
}
.wr-video > div,
.wr-video :deep(iframe) {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}
.wr-noembed {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  padding: 1.5rem 1rem;
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface);
  text-align: center;
}
.wr-noembed strong {
  font-size: 1.05rem;
}
.wr-noembed p {
  color: var(--text-muted);
  margin: 0.25rem 0;
}
.wr-noembed small {
  color: var(--text-faint);
}
.wr-open {
  color: var(--primary);
  text-decoration: none;
  font-weight: 600;
}

.wr-list {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  margin-top: 1rem;
}
.wr-list-title {
  margin: 0.35rem 0 0;
  font-size: 0.92rem;
  line-height: 1.2;
  color: var(--text-muted);
  letter-spacing: 0;
}
.wr-card {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  width: 100%;
  min-width: 0;
  padding: 0.7rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
}
.wr-card.now {
  border-color: var(--primary);
  background: var(--primary-soft);
}
.wr-cover {
  width: 90px;
  height: 90px;
  flex: 0 0 90px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  background: var(--surface-3);
}
.wr-cover-fallback {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--text-faint);
}
.wr-meta {
  display: flex;
  flex: 1 1 auto;
  min-width: 0;
  flex-direction: column;
  gap: 0.18rem;
}
.wr-meta strong {
  display: -webkit-box;
  overflow: hidden;
  white-space: normal;
  line-height: 1.25;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
.wr-artist,
.wr-genre {
  display: -webkit-box;
  overflow: hidden;
  color: var(--text-muted);
  font-size: 0.85rem;
  line-height: 1.25;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
}
.wr-genre {
  color: var(--text-faint);
}
.wr-badge {
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0;
  line-height: 1.15;
  text-transform: uppercase;
}
.wr-card-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  flex: 0 0 44px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--primary);
  cursor: pointer;
  text-decoration: none;
}
.wr-card-action:hover {
  border-color: var(--primary);
}

.wr-player {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  margin-top: 0.85rem;
  padding: 0.7rem 0.85rem calc(0.7rem + env(safe-area-inset-bottom, 0px));
  background: var(--surface);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-sm);
}
.wr-current {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
}
.wr-buttons {
  display: flex;
  flex: 0 0 auto;
  gap: 0.45rem;
}
.wr-buttons button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  padding: 0;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
}
.wr-buttons button:hover {
  border-color: var(--primary);
  color: var(--primary);
}
.wr-stop {
  color: var(--danger) !important;
  border-color: var(--danger) !important;
}
.wr-mood-tag {
  grid-column: 1 / -1;
  min-width: 0;
  color: var(--text-muted);
  font-size: 0.85rem;
  overflow-wrap: anywhere;
}
.wr-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

@media (max-width: 420px) {
  .worship {
    padding-top: 0.75rem;
  }
  .wr-back {
    display: none;
  }
  .wr-icon {
    width: 44px;
    height: 44px;
  }
  .wr-head h1 {
    font-size: 1.55rem;
  }
  .wr-sub {
    font-size: 0.95rem;
  }
  .wr-moods {
    gap: 0.6rem;
  }
  .wr-mood {
    min-height: 44px;
    padding: 0.5rem 0.55rem;
    font-size: 0.86rem;
  }
  .wr-cover {
    width: 82px;
    height: 82px;
    flex-basis: 82px;
  }
  .wr-card {
    gap: 0.7rem;
    padding: 0.65rem;
  }
  .wr-card-action {
    width: 40px;
    height: 40px;
    flex-basis: 40px;
  }
  .wr-mood-tag {
    display: none;
  }
}

@media (min-width: 641px) {
  .worship {
    padding: 1.5rem 1rem 6.5rem;
  }
  .wr-head {
    margin-bottom: 1.25rem;
  }
  .wr-moods {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .wr-free {
    flex-direction: row;
  }
  .wr-start {
    width: auto;
    white-space: nowrap;
  }
  .wr-video {
    max-height: 55vh;
    border-radius: var(--radius);
  }
  .wr-player {
    position: fixed;
    inset-inline: 0;
    bottom: 0;
    z-index: 41;
    grid-template-columns: minmax(0, 1fr) auto auto;
    max-width: 100vw;
    margin-top: 0;
    padding: 0.75rem 1rem;
    border-width: 1px 0 0;
    border-radius: 0;
    box-shadow: var(--shadow);
  }
  .wr-mood-tag {
    grid-column: auto;
    white-space: nowrap;
  }
}
</style>
