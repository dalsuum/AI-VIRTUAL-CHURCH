<script setup>
import { computed, ref, onMounted, onUnmounted } from "vue";
import IntakeForm from "./components/IntakeForm.vue";
import PreparingView from "./components/PreparingView.vue";
import ServicePlayer from "./components/ServicePlayer.vue";
import AdminConsole from "./components/AdminConsole.vue";
import PasswordReset from "./components/PasswordReset.vue";
import ThemeToggle from "./components/ThemeToggle.vue";
import { api } from "./composables/useApi";

// The admin console lives at #admin so it never collides with the worship flow.
const isAdminRoute = ref(window.location.hash === "#admin");
window.addEventListener("hashchange", () => {
  isAdminRoute.value = window.location.hash === "#admin";
});

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
const displayName = ref(api.rememberedName() || "");
const resumeError = ref("");

let pollTimer = null;

// The service is reported "complete" the moment the spoken segments land (the
// benediction is the last one generated). Music and server narration attach after
// that, and either can be slow or absent. Do not open on text alone: for sung
// modes, wait for worship music; for server-narrated modes, wait for the opening
// prayer audio too so late narration does not start over a page the worshipper is
// already reading.
const MEDIA_GRACE_POLLS = 75;
const OPEN_FAILSAFE_POLLS = 120;
let mediaGracePolls = 0;
let openWaitPolls = 0;

const textComposed = computed(() => service.value?.status === "complete");
const musicLanded = computed(() => service.value?.music_asset != null);
const lockedMusicSource = computed(() => musicSource.value || service.value?.music_source || null);
const musicExpected = computed(() => ["suno", "youtube", "hymn", "hymn_sung"].includes(lockedMusicSource.value));
// Server-voice modes attach mp3s after the text; 'browser'/'off' never do, so only
// these gate the open on narration audio. (Kept in sync with Setting::NARRATION_MODES.)
const SERVER_VOICE_MODES = ["openai", "kokoro", "edge_tts"];
const serverNarrationExpected = computed(() => {
  const s = service.value;
  return Boolean(s && s.narration_enabled !== false && SERVER_VOICE_MODES.includes(s.narration_mode));
});
const openingPrayerTextReady = computed(() => Boolean(service.value?.segments?.opening_prayer));
const openingPrayerVoiceReady = computed(() => {
  if (!serverNarrationExpected.value) return true;
  return Boolean(service.value?.audios?.opening_prayer);
});
const narrationSettled = computed(() => {
  const s = service.value;
  if (!s || !serverNarrationExpected.value) return true;
  const segs = s.segments || {};
  const auds = s.audios || {};
  return Object.keys(segs).every((k) => auds[k]);
});
const requiredOpeningMediaReady = computed(() => {
  if (!textComposed.value || !openingPrayerTextReady.value || !openingPrayerVoiceReady.value) return false;
  return !musicExpected.value || musicLanded.value;
});
// Enough is composed to begin worship. This intentionally waits longer for
// Myanmar/Tedim server narration so users are not surprised by a delayed first
// prayer voice while they are already reading later pages. The failsafe preserves
// the app's degrade-not-block behavior if an external media provider never returns.
const mediaReady = computed(() => requiredOpeningMediaReady.value || (textComposed.value && openWaitPolls >= OPEN_FAILSAFE_POLLS));

const isScheduled = computed(() => service.value?.status === "scheduled");
const scheduledFor = computed(() => {
  const at = service.value?.scheduled_at;
  return at ? new Date(at).toLocaleString() : null;
});

function onStarted({ token, musicSource: source }) {
  sessionToken.value = token;
  musicSource.value = source;
  view.value = "preparing";
  service.value = null;
  displayName.value = api.rememberedName() || "";
  mediaGracePolls = 0;
  openWaitPolls = 0;
  // Assets are produced asynchronously by the AI pipeline; poll until they land.
  // (A later phase replaces this with the WebSocket push described in the README.)
  poll();
  pollTimer = setInterval(poll, 4000);
}

async function poll() {
  try {
    service.value = await api.getService(sessionToken.value);
    if (textComposed.value && !requiredOpeningMediaReady.value) {
      openWaitPolls += 1;
    }
    // Once the player can safely open, keep polling until both the worship music
    // and (server-voice) narration have landed — or the grace window runs out (counted
    // only after opening is allowed). narrationSettled covers the late-arriving server
    // mp3s (the sermon especially) that attach after their text in OpenAI/Kokoro mode.
    const mediaSettled = (!musicExpected.value || musicLanded.value) && narrationSettled.value;
    if (mediaReady.value && pollTimer && (mediaSettled || ++mediaGracePolls >= MEDIA_GRACE_POLLS)) {
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

  <div v-else class="page">
    <header class="topbar">
      <a class="brand" href="#">
        <span class="brand-mark" aria-hidden="true">✝</span>
        <span class="brand-name">AI Virtual Church</span>
      </a>
      <div class="topbar-right">
        <ThemeToggle />
      </div>
    </header>

    <main class="shell">
      <div class="card">
        <p v-if="resumeError" style="color:var(--danger);font-size:0.85rem;margin-bottom:1rem;">{{ resumeError }}</p>
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
.brand { display: inline-flex; align-items: center; gap: 0.55rem; text-decoration: none; color: var(--text); font-weight: 600; }
.brand-mark {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: 9px;
  background: var(--primary); color: var(--on-primary); font-size: 1rem;
}
.brand-name { font-size: 0.98rem; letter-spacing: -0.01em; }

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
