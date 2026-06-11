<script setup>
import { computed, onMounted, ref } from "vue";

const localTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
import { api } from "../composables/useApi";

const emit = defineEmits(["started", "intercepted"]);

// Every music source the app knows about, with its copy. The admin decides which of
// these actually appear (config.music_sources); we render that subset in this order.
const MUSIC_SOURCES = [
  { value: "hymn_sung", title: "Sung hymn", desc: "A classic hymn sung aloud, with the words on screen" },
  { value: "hymn", title: "Instrumental hymn", desc: "The hymn played, with the words to sing along" },
  { value: "hymn_youtube", title: "Sung Hymn (YouTube)", desc: "A traditional hymn sung by a choir, matched to your mood" },
  { value: "suno", title: "AI-composed", desc: "Original worship, generated for you" },
  { value: "youtube", title: "Modern Worship (YouTube)", desc: "An existing worship track and sermon" },
];

// Intake options come from the backend so an admin can curate moods, music sources,
// and scheduling without a redeploy. Sensible defaults keep the form usable if the
// config request is slow or fails.
const moods = ref(["Grateful", "Anxious", "Grieving", "Joyful", "Seeking", "Hopeful"]);
const enabledSources = ref(MUSIC_SOURCES.map((s) => s.value));
const schedulingEnabled = ref(true);

// Only the admin-enabled sources, in canonical order.
const musicSources = computed(() =>
  MUSIC_SOURCES.filter((s) => enabledSources.value.includes(s.value)),
);

const selectedMood = ref("Grateful");
const customMood = ref("");
const prayerText = ref("");
const musicSource = ref("youtube"); // "hymn_sung" | "hymn" | "suno" | "youtube"
const when = ref("now"); // "now" | "later"
const scheduledAt = ref(""); // datetime-local value when scheduling for later
const loading = ref(false);
const error = ref("");

onMounted(async () => {
  try {
    const cfg = await api.getConfig();
    if (Array.isArray(cfg.moods) && cfg.moods.length) {
      moods.value = cfg.moods;
      if (!moods.value.includes(selectedMood.value)) selectedMood.value = moods.value[0];
    }
    if (Array.isArray(cfg.music_sources) && cfg.music_sources.length) {
      enabledSources.value = cfg.music_sources;
    }
    if (cfg.default_music_source && enabledSources.value.includes(cfg.default_music_source)) {
      musicSource.value = cfg.default_music_source;
    } else if (!enabledSources.value.includes(musicSource.value)) {
      musicSource.value = musicSources.value[0]?.value || musicSource.value;
    }
    schedulingEnabled.value = cfg.scheduling_enabled !== false;
    if (!schedulingEnabled.value) when.value = "now";
  } catch {
    // Keep the defaults above — the worshipper can still begin a service.
  }
});

// Identity is optional: a worshipper may give their name and/or email, or stay
// anonymous (the backend assigns a friendly visitor name). A returning visitor is
// remembered via localStorage so we can greet them back and pre-fill their name.
const returningName = api.rememberedName();
const name = ref(returningName || "");

// Service history — loaded for returning users who already have an auth token.
const history = ref([]);
const historyLoaded = ref(false);
if (returningName && api.hasToken()) {
  api.getMyServices()
    .then((res) => { history.value = res.sessions || []; })
    .catch(() => {})
    .finally(() => { historyLoaded.value = true; });
}

function fmtHistoryDate(v) {
  if (!v) return "";
  const d = new Date(v);
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
}
const email = ref("");

async function begin() {
  error.value = "";

  // When scheduling, require a future time and send it as ISO so the backend holds
  // the service until then.
  let scheduledIso = null;
  if (when.value === "later") {
    if (!email.value.trim()) {
      error.value = "Please enter your email so we can send you a reminder when your service begins.";
      return;
    }
    if (!scheduledAt.value) {
      error.value = "Please choose a date and time for your service.";
      return;
    }
    const dt = new Date(scheduledAt.value);
    if (isNaN(dt) || dt <= new Date()) {
      error.value = "Please choose a time in the future.";
      return;
    }
    scheduledIso = dt.toISOString();
  }

  loading.value = true;
  try {
    // Walk-up worshippers have no account; provision a session first, carrying
    // their (optional) name/email and music choice, then open a service and submit
    // intake. A returning visitor already holds a token, so this is a no-op for them.
    await api.ensureSession({
      name: name.value.trim() || null,
      email: email.value.trim() || null,
      music_source: musicSource.value,
    });

    // If a token was already present (returning guest), ensureSession() is a no-op
    // and the email from the form never reached the server. Patch it now so the
    // scheduling reminder can be delivered to the right address.
    if (when.value === "later" && email.value.trim()) {
      try { await api.updateGuestEmail(email.value.trim()); } catch { /* already set or registered user */ }
    }

    await api.updateMusicSource(musicSource.value);
    const { session_token } = await api.startService();
    const trimmedCustomMood = customMood.value.trim();
    if (trimmedCustomMood && !/^[A-Za-z]+$/.test(trimmedCustomMood)) {
      error.value = "Your custom feeling must be a single word using only letters.";
      loading.value = false;
      return;
    }

    const res = await api.submitIntake(session_token, {
      mood: selectedMood.value,
      custom_mood: trimmedCustomMood || null,
      prayer_text: prayerText.value || null,
      scheduled_at: scheduledIso,
      // Always include contact_email when scheduling so the backend can
      // update a @guest.local account even if ensureSession was a no-op.
      contact_email: scheduledIso ? (email.value.trim() || null) : null,
    });

    if (res.intercepted) {
      emit("intercepted", res.resource);
    } else {
      // Carry the music choice so the preparing screen can size its countdown to the
      // mode — AI-composed music takes ~2 min, YouTube returns in seconds.
      emit("started", { token: session_token, musicSource: musicSource.value });
    }
  } catch (e) {
    error.value = e.data?.message || "Something went wrong. Please try again.";
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="intake">
    <h1>{{ returningName ? `Welcome back, ${returningName}` : "Welcome" }}</h1>
    <p class="sub">
      {{ returningName
        ? "It's good to see you again. Let us prepare a service just for you."
        : "Let us prepare a service just for you." }}
    </p>

    <!-- Previous services for returning worshippers -->
    <template v-if="returningName && historyLoaded && history.length">
      <div class="history">
        <p class="history-label">Your previous services</p>
        <div v-for="s in history" :key="s.session_token" class="history-row">
          <span class="history-date">{{ fmtHistoryDate(s.date) }}</span>
          <span class="badge pending history-mood">{{ s.mood }}</span>
          <span class="history-sermon">{{ s.sermon_topic || "—" }}</span>
        </div>
      </div>
    </template>

    <template v-if="!returningName">
      <label class="field-label" for="name">Your name (optional)</label>
      <input
        id="name"
        v-model="name"
        type="text"
        class="text-input"
        placeholder="We'll give you a visitor name if you'd rather not say"
        autocomplete="name"
      />
    </template>

    <label class="field-label" for="email">
      Email <span v-if="when === 'later'">(required — we'll send you a reminder)</span><span v-else>(optional)</span>
    </label>
    <input
      id="email"
      v-model="email"
      type="email"
      class="text-input"
      :placeholder="when === 'later' ? 'Enter your email to receive a reminder' : 'So we can welcome you back'"
      autocomplete="email"
      :required="when === 'later'"
    />

    <label class="field-label">How are you feeling today?</label>
    <div class="mood-grid">
      <button
        v-for="m in moods"
        :key="m"
        type="button"
        class="mood"
        :class="{ active: selectedMood === m }"
        @click="selectedMood = m"
      >
        {{ m }}
      </button>
    </div>

    <label class="field-label" for="custom-mood">Or describe your feeling in one word (optional)</label>
    <input
      id="custom-mood"
      v-model="customMood"
      type="text"
      class="text-input"
      placeholder="e.g. sad, hopeful, numb…"
      maxlength="50"
      autocomplete="off"
    />

    <label class="field-label" for="prayer">Prayer request (optional)</label>
    <textarea
      id="prayer"
      v-model="prayerText"
      rows="3"
      placeholder="Share what is on your heart…"
    ></textarea>

    <label class="field-label">Music for your service</label>
    <div class="source-row">
      <button
        v-for="s in musicSources"
        :key="s.value"
        type="button"
        class="source"
        :class="{ active: musicSource === s.value }"
        @click="musicSource = s.value"
      >
        <strong>{{ s.title }}</strong>
        <span>{{ s.desc }}</span>
      </button>
    </div>

    <template v-if="schedulingEnabled">
      <label class="field-label">When would you like your service?</label>
      <div class="source-row">
        <button
          type="button"
          class="source"
          :class="{ active: when === 'now' }"
          @click="when = 'now'"
        >
          <strong>Right now</strong>
          <span>Begin immediately</span>
        </button>
        <button
          type="button"
          class="source"
          :class="{ active: when === 'later' }"
          @click="when = 'later'"
        >
          <strong>Schedule it</strong>
          <span>Pick a future time</span>
        </button>
      </div>
      <input
        v-if="when === 'later'"
        v-model="scheduledAt"
        type="datetime-local"
        class="schedule-input"
        aria-label="Service date and time"
      />
      <small v-if="when === 'later'" class="tz-hint">Your local time · {{ localTimezone }}</small>
    </template>

    <p v-if="error" class="error">{{ error }}</p>

    <button class="begin" :disabled="loading" @click="begin">
      {{ loading ? "Preparing your service…" : (when === "later" ? "Schedule my service" : "Begin my service") }}
    </button>
  </div>
</template>

<style scoped>
.intake { max-width: 460px; margin: 0 auto; }
h1 { font-size: 1.55rem; margin: 0 0 0.35rem; letter-spacing: -0.02em; }
.sub { color: var(--text-muted); margin: 0 0 1.5rem; line-height: 1.55; }
.field-label { display: block; font-size: 0.85rem; color: var(--text-muted); margin: 1rem 0 0.5rem; }
.text-input { width: 100%; padding: 0.65rem 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font: inherit; }
.text-input::placeholder { color: var(--text-faint); }
.text-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.mood-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
.mood { padding: 0.65rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); cursor: pointer; transition: border-color 0.12s ease, background 0.12s ease; }
.mood:hover { border-color: var(--border-strong); }
.mood.active { border-color: var(--primary); background: var(--primary-soft); color: var(--primary-hover); font-weight: 500; }
textarea { width: 100%; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.65rem 0.75rem; font: inherit; resize: vertical; background: var(--surface); color: var(--text); }
textarea::placeholder { color: var(--text-faint); }
textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.source-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.source { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); cursor: pointer; text-align: left; transition: border-color 0.12s ease, background 0.12s ease; }
.source:hover { border-color: var(--border-strong); }
.source span { font-size: 0.75rem; color: var(--text-muted); }
.source.active { border-color: var(--primary); background: var(--primary-soft); }
.schedule-input { width: 100%; margin-top: 0.5rem; padding: 0.65rem 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; background: var(--surface); color: var(--text); }
.schedule-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.tz-hint { display: block; margin-top: 0.35rem; color: var(--text-muted, #888); font-size: 0.78rem; }
.begin { width: 100%; margin-top: 1.5rem; padding: 0.8rem; border: none; border-radius: var(--radius-sm); background: var(--primary); color: var(--on-primary); font-weight: 600; cursor: pointer; transition: background 0.12s ease; }
.begin:hover:not(:disabled) { background: var(--primary-hover); }
.begin:disabled { opacity: 0.6; cursor: default; }
.error { color: var(--danger); font-size: 0.85rem; }

.history { margin: 1rem 0 1.25rem; padding: 0.85rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); }
.history-label { font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 0.6rem; }
.history-row { display: flex; align-items: center; gap: 0.55rem; padding: 0.3rem 0; border-top: 1px solid var(--border); font-size: 0.82rem; }
.history-row:first-of-type { border-top: none; }
.history-date { color: var(--text-muted); white-space: nowrap; min-width: 4.5rem; }
.history-mood { flex-shrink: 0; }
.history-sermon { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
