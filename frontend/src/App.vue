<script setup>
import { computed, ref, onMounted, onUnmounted } from "vue";
import IntakeForm from "./components/IntakeForm.vue";
import PreparingView from "./components/PreparingView.vue";
import ServicePlayer from "./components/ServicePlayer.vue";
import AdminConsole from "./components/AdminConsole.vue";
import PasswordReset from "./components/PasswordReset.vue";
import ThemeToggle from "./components/ThemeToggle.vue";
import ZolaiVocabulary from "./components/ZolaiVocabulary.vue";
import MyanmarLyrics from "./components/MyanmarLyrics.vue";
import FathersDay from "./components/FathersDay.vue";
import LiveSticker from "./components/LiveSticker.vue";
import BibleReader from "./components/BibleReader.vue";
import BibleStudy from "./components/BibleStudy.vue";
import AuthPanel from "./components/AuthPanel.vue";
import AccountSettings from "./components/AccountSettings.vue";
import { api } from "./composables/useApi";

// The admin console lives at #admin so it never collides with the worship flow.
const isAdminRoute  = ref(window.location.hash === "#admin");
const isVocabRoute  = ref(window.location.hash === "#vocabulary");
const isLyricsRoute = ref(window.location.hash === "#lyrics");
const isBibleRoute  = ref(window.location.hash === "#bible");
const isStudyRoute  = ref(window.location.hash === "#bible-study");
// Account + auth entry points (hash-routed like the rest of the app).
const isLoginRoute    = ref(window.location.hash === "#login");
const isRegisterRoute = ref(window.location.hash === "#register");
const isAccountRoute  = ref(window.location.hash === "#account");
// Father's Day (Special Day) MV — standalone, removable page.
const isFathersDayRoute = ref(window.location.hash === "#fathers-day");
const fathersDayEnabled = ref(false);
// Banner/nav text is admin-driven so any special day can re-theme it from the console.
const fdTitle    = ref("Happy Father's Day");
const fdSubtitle = ref("Make a music video for your father");
api.fdPublicConfig().then((c) => {
  fathersDayEnabled.value = !!c?.enabled;
  if (c?.title) fdTitle.value = c.title;
  if (c?.subtitle) fdSubtitle.value = c.subtitle;
}).catch(() => {});
// Live Sticker maker — standalone, removable page at #stickers.
const isStickerRoute = ref(window.location.hash === "#stickers");
const stickersEnabled = ref(false);
const stickersTitle = ref("Make a Live Sticker");
api.stickerConfig().then((c) => {
  stickersEnabled.value = !!c?.enabled;
  if (c?.title) stickersTitle.value = c.title;
}).catch(() => {});
window.addEventListener("hashchange", () => {
  isAdminRoute.value  = window.location.hash === "#admin";
  isVocabRoute.value  = window.location.hash === "#vocabulary";
  isLyricsRoute.value = window.location.hash === "#lyrics";
  isBibleRoute.value  = window.location.hash === "#bible";
  isStudyRoute.value  = window.location.hash === "#bible-study";
  isFathersDayRoute.value = window.location.hash === "#fathers-day";
  isStickerRoute.value = window.location.hash === "#stickers";
  isLoginRoute.value    = window.location.hash === "#login";
  isRegisterRoute.value = window.location.hash === "#register";
  isAccountRoute.value  = window.location.hash === "#account";
  enforceGuards();
});

// ── Auth state + route guards ────────────────────────────────────────────────
// currentUser is the /me payload (or null when no session). A guest session is
// authenticated but anonymous, so it counts as "logged out" for the account UI.
const currentUser = ref(null);
const isAuthed = computed(() => !!currentUser.value && !currentUser.value.is_guest);
const isAdmin  = computed(() =>
  !!currentUser.value && (currentUser.value.is_admin || currentUser.value.role === "admin"));

async function loadMe() {
  try {
    // Public probe: 200 with user:null when logged out (no expected-error 401 noise).
    const res = await api.session();
    currentUser.value = res?.user || null;
  } catch {
    currentUser.value = null; // network failure → treat as logged out
  }
  enforceGuards();
}

// Hash-route access control. Mirrors requireAuth / requireGuest / requireAdmin:
//  • #account needs a registered login;
//  • #login / #register are for logged-out users only;
//  • #admin is barred to logged-in non-staff (the console keeps its own login
//    form for the not-yet-authenticated case, so we don't bounce those away).
function enforceGuards() {
  const hash = window.location.hash;
  if (hash === "#account" && !isAuthed.value) {
    window.location.hash = "#login";
  } else if ((hash === "#login" || hash === "#register") && isAuthed.value) {
    window.location.hash = "#account";
  } else if (hash === "#admin" && currentUser.value && !currentUser.value.is_guest && !isAdmin.value) {
    window.location.hash = "";
  }
}

// Called by AuthPanel after a successful login/register: refresh identity, then
// send the user into the app (account page for the freshly registered).
async function onAuthed() {
  await loadMe();
  window.location.hash = "#account";
}

async function logout() {
  try { await api.logout(); } catch { /* clear locally regardless */ }
  currentUser.value = null;
  window.location.hash = "";
}

// Resolve identity once on load so nav + guards reflect the real session.
loadMe();

function goHome() { window.location.hash = ""; }

// view: "intake" | "preparing" | "service" | "intercepted" | "reset"
const view = ref("intake");

// Pre-fill reset token from URL hash: #reset?token=XXX
const resetToken = ref("");
function checkResetHash() {
  const hash = window.location.hash;
  if (hash.startsWith("#reset")) {
    const params = new URLSearchParams(hash.split("?")[1] || "");
    resetToken.value = params.get("token") || "";
    view.value = "reset";
  }
}
checkResetHash();
window.addEventListener("hashchange", checkResetHash);
const sessionToken = ref(null);
const resource = ref(null);
const service = ref(null);
const musicSource = ref(null);
const intakeLanguage = ref("en");
const intakeMood = ref("");
const displayName = ref(api.rememberedName() || "");
const resumeError = ref("");

let pollTimer = null;

// The service is reported "complete" the moment the spoken segments land (the
// benediction is the last one generated). Music and server narration attach after
// that, and either can be slow or absent. Do not open on text alone: for sung
// modes, wait for worship music; for server-narrated modes, wait for the opening
// prayer audio too so late narration does not start over a page the worshipper is
// already reading.
// Multilingual (Tedim/Myanmar) MMS-TTS runs sequentially on CPU and takes
// longer to finish all segments — extend the polling window so late-arriving
// sermon narration still attaches before we stop polling.
const MEDIA_GRACE_POLLS = computed(() =>
  (["my", "td"].includes(service.value?.language) && serverNarrationExpected.value) ? 150 : 75
);
// Fallback open after ~140s (35 polls x 4s) once the opening prayer TEXT is
// ready, so a slow or failed narration callback (e.g. edge_tts/MMS-TTS for
// Myanmar/Tedim, which is the door's gating asset) does not trap worshippers
// on the preparing screen. This starts counting from the prayer text — not the
// full service "complete" — so a stuck narration opens within a bounded time
// even if later segments (benediction) are also slow.
const OPEN_FAILSAFE_POLLS = 35;
// Absolute ceiling: open after 15 min of polling even if the service never
// reaches "complete" (e.g. worker outage, LLM silent failure). Prevents the
// preparing screen from becoming a permanent dead-end.
const TOTAL_FAILSAFE_POLLS = 225; // 225 × 4s = 15 min
let mediaGracePolls = 0;
let openWaitPolls = 0;
let totalPolls = 0;

const textComposed = computed(() => service.value?.status === "complete");
const musicLanded = computed(() => service.value?.music_asset != null);
const musicFallbackToText = computed(() => service.value?.music_asset?.asset_type === "text");
const lockedMusicSource = computed(() => musicSource.value || service.value?.music_source || null);
const musicExpected = computed(() => ["suno", "youtube", "hymn", "hymn_sung"].includes(lockedMusicSource.value) && !musicFallbackToText.value);
// Server-voice modes attach mp3s after the text; we keep polling after entry so
// late narration can still attach without blocking worship start. (Kept in sync
// with Setting::NARRATION_MODES.)
const SERVER_VOICE_MODES = ["openai", "kokoro", "edge_tts", "mms_tts", "voicebox"];
const serverNarrationExpected = computed(() => {
  const s = service.value;
  return Boolean(s && s.narration_enabled !== false && SERVER_VOICE_MODES.includes(s.narration_mode));
});
const openingPrayerTextReady = computed(() => Boolean(service.value?.segments?.opening_prayer));
const openingPrayerAudioReady = computed(() => {
  if (!serverNarrationExpected.value) return true;
  return Boolean(service.value?.audios?.opening_prayer);
});
// Music segments (worship / closing_hymn) are never narrated — exclude them so
// narrationSettled doesn't get stuck waiting for audio that will never arrive.
const NARRATED_SEGMENTS = new Set(["opening_prayer", "scripture", "sermon", "benediction"]);
const narrationSettled = computed(() => {
  const s = service.value;
  if (!s || !serverNarrationExpected.value) return true;
  const segs = s.segments || {};
  const auds = s.audios || {};
  return Object.keys(segs)
    .filter((k) => NARRATED_SEGMENTS.has(k))
    .every((k) => auds[k]);
});
// Avatar videos attach after their text/audio, and the benediction (rendered last)
// can land minutes after narration has settled. Without this the player stops polling
// once audio is in and never picks up the late benediction video — it shows as
// audio/text only. Keep polling (within the grace window) until every narrated segment
// that has audio also has its video. Segments whose avatar render failed and fell back
// to text never get a video, so this intentionally relies on the bounded grace window
// rather than blocking forever.
const avatarExpected = computed(() => Boolean(service.value?.avatar_enabled));
const avatarSettled = computed(() => {
  const s = service.value;
  if (!s || !avatarExpected.value) return true;
  const auds = s.audios || {};
  const vids = s.videos || {};
  return Object.keys(auds)
    .filter((k) => NARRATED_SEGMENTS.has(k))
    .every((k) => vids[k]);
});
const requiredOpeningMediaReady = computed(() => {
  if (!openingPrayerTextReady.value || !openingPrayerAudioReady.value) return false;
  return !musicExpected.value || musicLanded.value;
});
// Enough is composed to begin worship. This intentionally waits longer for
// Myanmar/Tedim server narration so users are not surprised by a delayed first
// prayer voice while they are already reading later pages. The failsafe preserves
// the app's degrade-not-block behavior if an external media provider never returns.
const mediaReady = computed(() =>
  requiredOpeningMediaReady.value ||
  (openingPrayerTextReady.value && openWaitPolls >= OPEN_FAILSAFE_POLLS) ||
  totalPolls >= TOTAL_FAILSAFE_POLLS
);

const isScheduled = computed(() => service.value?.status === "scheduled");
const scheduledFor = computed(() => {
  const at = service.value?.scheduled_at;
  return at ? new Date(at).toLocaleString() : null;
});

function onStarted({ token, musicSource: source, language, mood }) {
  sessionToken.value = token;
  musicSource.value = source;
  intakeLanguage.value = language || "en";
  intakeMood.value = mood || "";
  view.value = "preparing";
  service.value = null;
  displayName.value = api.rememberedName() || "";
  mediaGracePolls = 0;
  openWaitPolls = 0;
  totalPolls = 0;
  // Assets are produced asynchronously by the AI pipeline; poll until they land.
  // (A later phase replaces this with the WebSocket push described in the README.)
  poll();
  pollTimer = setInterval(poll, 4000);
}

async function poll() {
  try {
    service.value = await api.getService(sessionToken.value);
    totalPolls += 1;
    if (openingPrayerTextReady.value && !requiredOpeningMediaReady.value) {
      openWaitPolls += 1;
    }
    // Once the player can safely open, keep polling until both the worship music
    // and (server-voice) narration have landed — or the grace window runs out (counted
    // only after opening is allowed). narrationSettled covers the late-arriving server
    // mp3s (the sermon especially) that attach after their text in OpenAI/Kokoro mode.
    const mediaSettled = textComposed.value && (!musicExpected.value || musicLanded.value) && narrationSettled.value && avatarSettled.value;
    if (mediaReady.value && pollTimer && (mediaSettled || ++mediaGracePolls >= MEDIA_GRACE_POLLS.value)) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  } catch (e) {
    // Keep polling on transient errors; surface nothing to the worshipper.
  }
}

// The countdown screen opens the doors once the service is composed.
function onReady() {
  view.value = "service";
}

// The worshipper ended the service from the final stage. Tear down any polling and
// return to the start so they can begin another service. Clear the session too:
// on a shared/walk-up device the visitor won't get another chance to identify
// themselves, so we drop the guest token and remembered name. The next person then
// starts fresh — the intake form shows the name field, and a new guest is provisioned.
function onExit() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
  api.clearSession();
  service.value = null;
  sessionToken.value = null;
  displayName.value = "";
  view.value = "intake";
}

function onIntercepted(res) {
  resource.value = res;
  view.value = "intercepted";
}

// If the user arrived via an email link (?session=TOKEN), restore their session
// automatically — even on a different device or after browser storage was cleared.
onMounted(async () => {
  const params = new URLSearchParams(window.location.search);
  const token  = params.get("session");
  if (!token) return;

  // Clean the token from the URL bar without a page reload.
  window.history.replaceState({}, "", window.location.pathname + window.location.hash);

  try {
    const { session_token, status } = await api.resumeSession(token);
    sessionToken.value = session_token;
    displayName.value  = api.rememberedName() || "";
    mediaGracePolls    = 0;
    openWaitPolls      = 0;
    totalPolls         = 0;

    if (status === "scheduled") {
      // Time hasn't arrived yet — show the "your service is scheduled" screen.
      await poll();
      view.value = "preparing";
    } else {
      // Service is active or complete — go to the preparing/player screen and poll.
      view.value = "preparing";
      await poll();
      pollTimer = setInterval(poll, 4000);
    }
  } catch (e) {
    resumeError.value = "This service link has expired or is no longer available. Please start a new service below.";
  }
});

onUnmounted(() => pollTimer && clearInterval(pollTimer));
</script>

<template>
  <AdminConsole v-if="isAdminRoute" />
  <ZolaiVocabulary v-else-if="isVocabRoute" />
  <MyanmarLyrics v-else-if="isLyricsRoute" />
  <FathersDay v-else-if="isFathersDayRoute" />
  <LiveSticker v-else-if="isStickerRoute" />
  <BibleReader v-else-if="isBibleRoute" />
  <BibleStudy v-else-if="isStudyRoute" />

  <div v-else class="page">
    <header class="topbar">
      <a class="brand" href="#">
        <span class="brand-mark" aria-hidden="true">✝</span>
        <span class="brand-name">AI Virtual Church</span>
      </a>
      <div class="topbar-right">
        <nav class="topbar-nav">
          <a href="#lyrics" class="nav-link" :class="{ active: isLyricsRoute }">🎵 သီချင်း</a>
          <a href="#bible" class="nav-link" :class="{ active: isBibleRoute }">📖 Bible</a>
          <a href="#bible-study" class="nav-link" :class="{ active: isStudyRoute }">💬 Bible Study</a>
          <a href="#vocabulary" class="nav-link" :class="{ active: isVocabRoute }">📖 Vocabulary</a>
          <a v-if="fathersDayEnabled" href="#fathers-day" class="nav-link" :class="{ active: isFathersDayRoute }">💙 <span class="nav-label-full">{{ fdTitle }}</span><span class="nav-label-short">MV</span></a>
          <a v-if="stickersEnabled" href="#stickers" class="nav-link" :class="{ active: isStickerRoute }">🎨 Stickers</a>
          <a v-if="isAdmin" href="#admin" class="nav-link" :class="{ active: isAdminRoute }">🛠 Admin</a>
          <a v-if="isAuthed" href="#account" class="nav-link" :class="{ active: isAccountRoute }">👤 <span class="nav-label-full">Account</span></a>
          <button v-if="isAuthed" class="nav-link nav-btn" @click="logout">Logout</button>
          <template v-else>
            <a href="#login" class="nav-link" :class="{ active: isLoginRoute }">Login</a>
            <a href="#register" class="nav-link" :class="{ active: isRegisterRoute }">Register</a>
          </template>
        </nav>
        <ThemeToggle />
      </div>
    </header>

    <main class="shell">
      <!-- Public auth entry: #login / #register. -->
      <div v-if="isLoginRoute || isRegisterRoute" class="card">
        <AuthPanel :mode="isRegisterRoute ? 'register' : 'login'" @authed="onAuthed" />
      </div>

      <!-- Authenticated account dashboard: token balance, plan, password. -->
      <div v-else-if="isAccountRoute && isAuthed" class="card">
        <AccountSettings @close="goHome" @nameChanged="loadMe" />
      </div>

      <div v-else class="card">
        <p v-if="resumeError" style="color:var(--danger);font-size:0.85rem;margin-bottom:1rem;">{{ resumeError }}</p>

        <!-- Father's Day (Special Day) MV — promo banner, only when enabled. -->
        <a v-if="view === 'intake' && fathersDayEnabled" href="#fathers-day" class="fd-banner">
          <span class="fd-banner-emoji">💙</span>
          <span class="fd-banner-text">
            <strong>{{ fdTitle }}</strong>
            {{ fdSubtitle }} →
          </span>
        </a>

        <!-- Live Sticker maker — promo banner on the intake page. -->
        <a v-if="view === 'intake' && stickersEnabled" href="#stickers" class="sk-banner">
          <span class="sk-banner-emoji">🎨</span>
          <span class="sk-banner-text">
            <strong>{{ stickersTitle }}</strong>
            Upload a photo → get a fun art sticker →
          </span>
        </a>

        <IntakeForm
          v-if="view === 'intake'"
          @started="onStarted"
          @intercepted="onIntercepted"
        />

        <!-- Scheduled service: nothing is generated until its time arrives. -->
        <section v-else-if="view === 'preparing' && isScheduled" class="service">
          <h1>Your service is scheduled</h1>
          <p class="sub">
            We'll prepare it for <strong>{{ scheduledFor }}</strong>. Come back then —
            your worship will be waiting.
          </p>
        </section>

        <!-- Countdown + welcome while the AI composes. -->
        <PreparingView
          v-else-if="view === 'preparing'"
          :service="service"
          :display-name="displayName"
          :music-source="musicSource"
          :media-ready="mediaReady"
          :language="intakeLanguage"
          :mood="intakeMood"
          @ready="onReady"
        />

        <!-- The guided, full-screen service. -->
        <ServicePlayer
          v-else-if="view === 'service' && service"
          :service="service"
          :display-name="displayName"
          @exit="onExit"
        />

        <section v-else-if="view === 'intercepted'" class="intercepted">
          <h1>We're here for you</h1>
          <p class="sub">{{ resource?.message }}</p>
          <a v-if="resource?.url" :href="resource.url" target="_blank" rel="noopener">
            {{ resource.label || "Get support" }}
          </a>
        </section>

        <PasswordReset
          v-else-if="view === 'reset'"
          :initial-token="resetToken"
          @back="view = 'intake'"
        />
      </div>
    </main>

    <footer class="site-footer">
      <span class="ai-disclaimer">AI can make mistakes. Please verify important information.</span>
      <a
        href="https://www.paypal.com/donate/?hosted_button_id=WETP5RQ7ZGJ6U"
        target="_blank"
        rel="noopener noreferrer"
        class="donate-link"
      >
        ☕ Buy me a coffee
      </a>
    </footer>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; }

.topbar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1.25rem;
  background: color-mix(in srgb, var(--bg) 80%, transparent);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--border);
}
.topbar-right { display: flex; align-items: center; gap: 0.75rem; }
.topbar-nav { display: flex; align-items: center; gap: 0.25rem; }
.nav-link {
  display: inline-flex; align-items: center; gap: 0.25rem;
  padding: 0.35rem 0.65rem;
  font-size: 0.8rem; font-family: "Padauk", "Noto Sans Myanmar", sans-serif;
  color: var(--text-muted); text-decoration: none;
  border: 1px solid transparent; border-radius: var(--radius-sm);
  transition: color 0.12s, border-color 0.12s, background 0.12s;
}
.nav-link:hover { color: var(--primary); border-color: var(--border); }
.nav-btn { background: none; cursor: pointer; font-family: inherit; }
.nav-link.active { color: var(--primary); background: var(--primary-soft); border-color: var(--primary); font-weight: 600; }
.nav-label-short { display: none; }
@media (max-width: 640px) {
  .nav-label-full { display: none; }
  .nav-label-short { display: inline; }
  /* Logo-only brand to free up width for the nav + theme toggle. */
  .brand-name { display: none; }
  .topbar { gap: 0.5rem; padding: 0.7rem 0.85rem; }
  /* Keep the nav from squeezing the theme toggle off-screen: let it scroll. */
  .topbar-right { gap: 0.5rem; min-width: 0; }
  .topbar-nav { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .topbar-nav::-webkit-scrollbar { display: none; }
  .nav-link { flex: 0 0 auto; }
  /* The theme toggle must always stay visible. */
  .theme-toggle, .topbar-right > :last-child { flex: 0 0 auto; }
}
.brand { display: inline-flex; align-items: center; gap: 0.55rem; text-decoration: none; color: var(--text); font-weight: 600; }
.brand-mark {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: 9px;
  background: var(--primary); color: var(--on-primary); font-size: 1rem;
}
.brand-name { font-size: 0.98rem; letter-spacing: -0.01em; }

/* Father's Day (Special Day) MV promo banner on the intake card. */
.fd-banner {
  display: flex; align-items: center; gap: 0.7rem;
  margin-bottom: 1.25rem; padding: 0.85rem 1rem;
  border-radius: var(--radius-sm); text-decoration: none;
  background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 70%, #8b5cf6));
  color: var(--on-primary);
  box-shadow: var(--shadow); transition: transform 0.12s, filter 0.12s;
}
.fd-banner:hover { transform: translateY(-1px); filter: brightness(1.05); }
.fd-banner-emoji { font-size: 1.5rem; line-height: 1; }
.fd-banner-text { font-size: 0.9rem; line-height: 1.4; }
.fd-banner-text strong { display: block; font-size: 1rem; }

.sk-banner {
  display: flex; align-items: center; gap: 0.7rem;
  margin-bottom: 1.25rem; padding: 0.85rem 1rem;
  border-radius: var(--radius-sm); text-decoration: none;
  background: linear-gradient(135deg, #ef4444, #dc2626);
  color: #fff;
  box-shadow: var(--shadow); transition: transform 0.12s, filter 0.12s;
}
.sk-banner:hover { transform: translateY(-1px); filter: brightness(1.05); }
.sk-banner-emoji { font-size: 1.5rem; line-height: 1; }
.sk-banner-text { font-size: 0.9rem; line-height: 1.4; }
.sk-banner-text strong { display: block; font-size: 1rem; }

.shell { max-width: 600px; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 2rem 1.75rem;
}

.service h1, .intercepted h1 { font-size: 1.55rem; margin: 0 0 0.35rem; letter-spacing: -0.02em; }
.sub { color: var(--text-muted); margin: 0 0 1.5rem; line-height: 1.55; }
.intercepted a { color: var(--primary); font-weight: 500; }

.site-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 1.25rem;
  padding: 1.25rem;
  border-top: 1px solid var(--border);
}
.ai-disclaimer {
  width: 100%;
  text-align: center;
  font-size: 0.78rem;
  color: var(--text-muted);
  opacity: 0.7;
}
.donate-link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--text-muted);
  text-decoration: none;
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  transition: color 0.15s, border-color 0.15s;
}
.donate-link:hover {
  color: var(--primary);
  border-color: var(--primary);
}
</style>
