<script setup>
import { computed, ref, onUnmounted } from "vue";
import IntakeForm from "./components/IntakeForm.vue";
import PreparingView from "./components/PreparingView.vue";
import ServicePlayer from "./components/ServicePlayer.vue";
import AdminConsole from "./components/AdminConsole.vue";
import ThemeToggle from "./components/ThemeToggle.vue";
import { api } from "./composables/useApi";

// The admin console lives at #admin so it never collides with the worship flow.
const isAdminRoute = ref(window.location.hash === "#admin");
window.addEventListener("hashchange", () => {
  isAdminRoute.value = window.location.hash === "#admin";
});

// view: "intake" | "preparing" | "service" | "intercepted"
//   intake    — the form
//   preparing — countdown + welcome while the AI composes (PreparingView)
//   service   — the full-screen, one-stage-at-a-time player (ServicePlayer)
const view = ref("intake");
const sessionToken = ref(null);
const resource = ref(null);
const service = ref(null);
const musicSource = ref(null);
const displayName = ref(api.rememberedName() || "");

let pollTimer = null;

// The service is reported "complete" the moment the spoken segments land (the
// benediction is the last one generated). But two kinds of media attach AFTER that:
//   - worship music composes in parallel and, for AI-composed (Suno), can finish
//     well after text (its generation poll runs up to ~240s); YouTube returns in ~1s.
//   - in a server-voice narration mode (OpenAI or Kokoro), each spoken segment's TTS
//     mp3 is attached after its text, and the long sermon frequently lands after the
//     music does.
// So we must not freeze the snapshot on text-complete (or even music) alone, or that
// late media never reaches the client. Keep polling until both have settled, but cap
// the extra wait so a genuinely failed/absent asset doesn't poll forever (~5 min at
// the 4s interval).
const MEDIA_GRACE_POLLS = 75;
let mediaGracePolls = 0;

// The spoken service is "complete" once the benediction lands; worship music
// composes in parallel (AI-composed runs ~2 min, YouTube ~1s). Both must be present
// before we call the service composed enough to open the doors — these reactive
// checks drive both the poll-stop below and the preparing screen's early open.
const textComposed = computed(() => service.value?.status === "complete");
const musicLanded = computed(() => service.value?.music_asset != null);
// Server-voice modes attach mp3s after the text; 'browser'/'off' never do, so only
// these gate the open on narration audio. (Kept in sync with Setting::NARRATION_MODES.)
const SERVER_VOICE_MODES = ["openai", "kokoro"];
const narrationSettled = computed(() => {
  const s = service.value;
  if (!s || !SERVER_VOICE_MODES.includes(s.narration_mode)) return true;
  const segs = s.segments || {};
  const auds = s.audios || {};
  return Object.keys(segs).every((k) => auds[k]);
});
// Enough is composed to begin worship: spoken text and the worship music are in.
// (Late server-voice narration fills in during playback, since the player reads the
// still-polling service — so it doesn't gate the open.)
const mediaReady = computed(() => textComposed.value && musicLanded.value);

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
  // Assets are produced asynchronously by the AI pipeline; poll until they land.
  // (A later phase replaces this with the WebSocket push described in the README.)
  poll();
  pollTimer = setInterval(poll, 4000);
}

async function poll() {
  try {
    service.value = await api.getService(sessionToken.value);
    // Once the spoken service is composed, keep polling until both the worship music
    // and (server-voice) narration have landed — or the grace window runs out (counted
    // only from text-complete on). narrationSettled covers the late-arriving server mp3s
    // (the sermon especially) that attach after their text in OpenAI/Kokoro mode.
    const mediaSettled = musicLanded.value && narrationSettled.value;
    if (textComposed.value && pollTimer && (mediaSettled || ++mediaGracePolls >= MEDIA_GRACE_POLLS)) {
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
      <ThemeToggle />
    </header>

    <main class="shell">
      <div class="card">
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
      </div>
    </main>
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
</style>
