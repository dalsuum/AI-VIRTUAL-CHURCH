<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";
import { normalizeLanguage } from "../i18n";

const localTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const emit = defineEmits(["started", "intercepted"]);

const { t, te, locale } = useI18n();

// ---- Service language ------------------------------------------------------
// Follows the one global language authority. There is no per-page picker and no
// stored service_language: the service language is the global UI language,
// clamped to the set the service backend actually supports
// (config.enabled_languages) so generation never receives a language
// it cannot produce. Mirrors how Bible Reader/Study clamp to their capabilities.
const enabledLangs = ref(["en"]); // filled from config; English is always the fallback
const language = computed(() => {
  const appLang = normalizeLanguage(locale.value);
  return enabledLangs.value.includes(appLang) ? appLang : (enabledLangs.value[0] || "en");
});
const usesMyanmarFont = computed(() => ["my", "td"].includes(language.value));

// ---- Special-Sunday highlight ---------------------------------------------
// The active observance (localized title + short brief), or null outside any
// window. Best-effort: a failure simply hides the card. Re-fetched whenever the
// global language changes so the highlight matches the service language.
const specialSunday = ref(null);
async function loadSpecialSunday() {
  try {
    const res = await api.getCurrentSpecialSunday(language.value);
    specialSunday.value = res && res.active ? res.observance : null;
  } catch {
    specialSunday.value = null;
  }
}
watch(language, loadSpecialSunday);

// Localized mood + music-source labels come from vue-i18n (the canonical string
// store) — the VALUE sent to the backend stays the English key. An admin-added
// mood outside the default set falls back to its raw label.
const moodLabel = (m) => (te(`intake.moods.${m}`) ? t(`intake.moods.${m}`) : m);
const srcTitle = (v) => t(`intake.sources.${v}.title`);
const srcDesc = (v) => t(`intake.sources.${v}.desc`);

// Canonical music-source order; the admin's config.music_sources picks the
// subset that actually appears, rendered in this order.
const MUSIC_SOURCE_ORDER = ["hymn_sung", "hymn", "hymn_youtube", "suno", "musicgen", "local_ai", "youtube"];

// Intake options come from the backend so an admin can curate moods, music sources,
// and scheduling without a redeploy. Sensible defaults keep the form usable if the
// config request is slow or fails.
const moods = ref(["Grateful", "Anxious", "Grieving", "Joyful", "Seeking", "Hopeful"]);
const enabledSources = ref(MUSIC_SOURCE_ORDER.slice());
const schedulingEnabled = ref(true);

// Only the admin-enabled sources, in canonical order.
const musicSources = computed(() =>
  MUSIC_SOURCE_ORDER.filter((v) => enabledSources.value.includes(v)),
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
      musicSource.value = musicSources.value[0] || musicSource.value;
    }
    schedulingEnabled.value = cfg.scheduling_enabled !== false;
    if (!schedulingEnabled.value) when.value = "now";
    if (Array.isArray(cfg.enabled_languages) && cfg.enabled_languages.length) {
      // `language` is a computed clamp over this set, so just record it — no need
      // to select anything; the global language drives the choice.
      enabledLangs.value = cfg.enabled_languages;
    }
  } catch {
    // Keep the defaults above — the worshipper can still begin a service.
  }
  loadSpecialSunday();
});

// Identity is optional: a worshipper may give their name and/or email, or stay
// anonymous (the backend assigns a friendly visitor name). A returning visitor is
// remembered via localStorage so we can greet them back and pre-fill their name.
const returningName = api.rememberedName();
const expanded = ref(!!returningName);
function setScheduleLater() {
  when.value = "later";
  expanded.value = true;
}
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
  return d.toLocaleDateString(locale.value, { month: "short", day: "numeric", year: "numeric" });
}
const email = ref("");

async function begin() {
  error.value = "";

  // When scheduling, require a future time and send it as ISO so the backend holds
  // the service until then.
  let scheduledIso = null;
  if (when.value === "later") {
    if (!email.value.trim()) {
      error.value = t("intake.errors.emailForReminder");
      return;
    }
    if (!scheduledAt.value) {
      error.value = t("intake.errors.chooseTime");
      return;
    }
    const dt = new Date(scheduledAt.value);
    if (isNaN(dt) || dt <= new Date()) {
      error.value = t("intake.errors.futureTime");
      return;
    }
    scheduledIso = dt.toISOString();
  }

  const trimmedCustomMood = customMood.value.trim();
  if (trimmedCustomMood && !/^\p{L}+$/u.test(trimmedCustomMood)) {
    error.value = t("intake.errors.customMoodLetters");
    return;
  }

  async function submitOnce() {
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

    const res = await api.submitIntake(session_token, {
      mood: selectedMood.value,
      language: language.value,
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
      emit("started", { token: session_token, musicSource: musicSource.value, language: language.value, mood: selectedMood.value });
    }
  }

  loading.value = true;
  try {
    await submitOnce();
  } catch (e) {
    // Stale/expired token in localStorage can throw 401 here for returning guests.
    // Reset guest auth once and retry automatically so the worshipper is not blocked.
    if (e?.status === 401) {
      try {
        api.clearSession();
        // The server session is gone so the old XSRF-TOKEN cookie is stale.
        // Force a fresh CSRF fetch before retrying or the next POST gets 419.
        await api.refreshCsrf();
        await submitOnce();
        return;
      } catch (retryErr) {
        error.value = retryErr.data?.message || t("intake.errors.generic");
        return;
      }
    }
    error.value = e.data?.message || t("intake.errors.generic");
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="intake" :class="{ 'lang-my': usesMyanmarFont }">
    <!-- AI Bible Study entry — a live multi-pastor discussion. -->
    <a href="#bible-study" class="study-cta">
      <span class="study-cta-icon" aria-hidden="true">💬</span>
      <span class="study-cta-text">
        <strong>{{ t("intake.studyCtaTitle") }}</strong>
        <small>{{ t("intake.studyCtaDesc") }}</small>
      </span>
    </a>

    <!-- Special-Sunday highlight: only shown during the observance's active
         window (Fri–Sun). Title + short brief localized to the service language;
         Myanmar Unicode font only for my/td (see .lang-my below + lang-my-text). -->
    <div
      v-if="specialSunday"
      class="special-sunday"
      :class="{ 'lang-my-text': usesMyanmarFont }"
      dir="auto"
    >
      <span class="special-sunday-badge">✦</span>
      <div class="special-sunday-body">
        <h2 class="special-sunday-title">{{ specialSunday.title }}</h2>
        <p class="special-sunday-brief">{{ specialSunday.brief }}</p>
      </div>
    </div>

    <h1>{{ returningName ? t("intake.welcomeBack", { name: returningName }) : t("intake.welcome") }}</h1>
    <p class="sub">{{ returningName ? t("intake.subBack") : t("intake.sub") }}</p>

    <!-- Previous services for returning worshippers -->
    <template v-if="returningName && historyLoaded && history.length">
      <div class="history">
        <p class="history-label">{{ t("intake.historyLabel") }}</p>
        <div v-for="s in history" :key="s.session_token" class="history-row">
          <span class="history-date">{{ fmtHistoryDate(s.date) }}</span>
          <span class="badge pending history-mood">{{ s.mood }}</span>
          <span class="history-sermon bidi-text" dir="auto">{{ s.sermon_topic || "—" }}</span>
        </div>
      </div>
    </template>

    <!-- Mood picker is first: the only question a first-time visitor must answer -->
    <label class="field-label">{{ t("intake.moodLabel") }}</label>
    <div class="mood-grid">
      <button
        v-for="m in moods"
        :key="m"
        type="button"
        class="mood"
        :class="{ active: selectedMood === m }"
        @click="selectedMood = m"
      >
        {{ moodLabel(m) }}
      </button>
    </div>

    <!-- Optional details collapsed by default for first-time visitors -->
    <button
      v-if="!returningName"
      type="button"
      class="customize-toggle"
      :aria-expanded="String(expanded)"
      @click="expanded = !expanded"
    >
      {{ t("intake.customize") }}
      <span class="toggle-chevron" :class="{ open: expanded }">▼</span>
    </button>

    <div v-show="expanded || returningName" class="customize-body">
      <template v-if="!returningName">
        <label class="field-label" for="name">{{ t("intake.nameLabel") }}</label>
        <input
          id="name"
          v-model="name"
          type="text"
          class="text-input"
          :placeholder="t('intake.namePh')"
          autocomplete="name"
          dir="auto"
        />
      </template>

      <label class="field-label" for="email">
        {{ t("intake.emailLabel") }} <span v-if="when === 'later'">{{ t("intake.emailReq") }}</span><span v-else>{{ t("intake.emailOpt") }}</span>
      </label>
      <input
        id="email"
        v-model="email"
        type="email"
        class="text-input"
        :placeholder="when === 'later' ? t('intake.emailPhLater') : t('intake.emailPhNow')"
        autocomplete="email"
        :required="when === 'later'"
      />

      <label class="field-label" for="custom-mood">{{ t("intake.customMoodLabel") }}</label>
      <input
        id="custom-mood"
        v-model="customMood"
        type="text"
        class="text-input"
        :placeholder="t('intake.customMoodPh')"
        maxlength="50"
        autocomplete="off"
        dir="auto"
      />

      <label class="field-label" for="prayer">{{ t("intake.prayerLabel") }}</label>
      <textarea
        id="prayer"
        v-model="prayerText"
        rows="3"
        :placeholder="t('intake.prayerPh')"
        dir="auto"
      ></textarea>

      <label class="field-label">{{ t("intake.musicLabel") }}</label>
      <div class="source-row">
        <button
          v-for="v in musicSources"
          :key="v"
          type="button"
          class="source"
          :class="{ active: musicSource === v }"
          @click="musicSource = v"
        >
          <strong>{{ srcTitle(v) }}</strong>
          <span>{{ srcDesc(v) }}</span>
        </button>
      </div>

      <template v-if="schedulingEnabled">
        <label class="field-label">{{ t("intake.whenLabel") }}</label>
        <div class="source-row">
          <button
            type="button"
            class="source"
            :class="{ active: when === 'now' }"
            @click="when = 'now'"
          >
            <strong>{{ t("intake.now") }}</strong>
            <span>{{ t("intake.nowDesc") }}</span>
          </button>
          <button
            type="button"
            class="source"
            :class="{ active: when === 'later' }"
            @click="setScheduleLater"
          >
            <strong>{{ t("intake.later") }}</strong>
            <span>{{ t("intake.laterDesc") }}</span>
          </button>
        </div>
        <input
          v-if="when === 'later'"
          v-model="scheduledAt"
          type="datetime-local"
          class="schedule-input"
          :aria-label="t('intake.scheduleAria')"
        />
        <small v-if="when === 'later'" class="tz-hint">{{ t("intake.localTime") }} · {{ localTimezone }}</small>
      </template>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <button class="begin" :disabled="loading" @click="begin">
      {{ loading ? t("intake.preparing") : (when === "later" ? t("intake.schedule") : t("intake.begin")) }}
    </button>

    <p v-if="!returningName" class="no-account-hint">{{ t("intake.noAccount") }}</p>
  </div>
</template>

<style scoped>
/* Myanmar Unicode fonts only (Padauk / Noto Sans Myanmar) — never Zawgyi. */
@import url("https://fonts.googleapis.com/css2?family=Padauk:wght@400;700&family=Noto+Sans+Myanmar:wght@400;600&display=swap");

.intake { max-width: 460px; margin: 0 auto; }
.intake.lang-my, .intake.lang-my input, .intake.lang-my textarea, .intake.lang-my button {
  font-family: "Padauk", "Noto Sans Myanmar", "Myanmar Text", sans-serif;
  line-height: 1.9; /* Myanmar script needs taller lines for stacked diacritics */
}
.study-cta {
  display: flex; align-items: center; gap: 0.75rem;
  margin-bottom: 1rem; padding: 0.85rem 1rem;
  background: var(--primary-soft); border: 1px solid var(--primary);
  border-radius: var(--radius); text-decoration: none; color: var(--text);
  transition: background 0.15s ease;
}
.study-cta:hover { background: var(--primary); }
.study-cta:hover .study-cta-text strong,
.study-cta:hover .study-cta-text small { color: var(--on-primary); }
.study-cta-icon { font-size: 1.5rem; }
.study-cta-text { display: flex; flex-direction: column; }
.study-cta-text strong { color: var(--text); font-size: 1rem; }
.study-cta-text small { color: var(--text-muted); font-size: 0.85rem; }

/* Special-Sunday highlight card */
.special-sunday {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  margin: 0 0 1.25rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--primary);
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--primary) 8%, var(--surface));
}
.special-sunday-badge { color: var(--primary); font-size: 1.1rem; line-height: 1.4; }
.special-sunday-title { margin: 0 0 0.2rem; font-size: 1.1rem; color: var(--primary); }
.special-sunday-brief { margin: 0; color: var(--text-muted); line-height: 1.55; font-size: 0.92rem; }
/* Myanmar Unicode for my/td brief + title, regardless of the .intake lang class. */
.special-sunday.lang-my-text { font-family: "Pyidaungsu", "Padauk", "Noto Sans Myanmar", "Myanmar Text", sans-serif; }
.special-sunday.lang-my-text .special-sunday-brief { line-height: 1.9; }

h1 { font-size: 1.55rem; margin: 0 0 0.35rem; letter-spacing: 0; }
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
.source { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); cursor: pointer; text-align: start; transition: border-color 0.12s ease, background 0.12s ease; }
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

.customize-toggle {
  width: 100%;
  margin-top: 1.1rem;
  padding: 0.6rem 0.85rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border: 1px dashed var(--border);
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-muted);
  font: inherit;
  font-size: 0.875rem;
  cursor: pointer;
  transition: border-color 0.15s ease, color 0.15s ease;
}
.customize-toggle:hover { border-color: var(--primary); color: var(--primary); }
.toggle-chevron { font-size: 0.65rem; transition: transform 0.2s ease; display: inline-block; line-height: 1; }
.toggle-chevron.open { transform: rotate(180deg); }
.customize-body { margin-top: 0; }
.no-account-hint {
  text-align: center;
  margin-top: 0.6rem;
  font-size: 0.8rem;
  color: var(--text-faint);
  letter-spacing: 0.01em;
}

.history { margin: 1rem 0 1.25rem; padding: 0.85rem 1rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); }
.history-label { font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 0.6rem; }
.history-row { display: flex; align-items: center; gap: 0.55rem; padding: 0.3rem 0; border-top: 1px solid var(--border); font-size: 0.82rem; }
.history-row:first-of-type { border-top: none; }
.history-date { color: var(--text-muted); white-space: nowrap; min-width: 4.5rem; }
.history-mood { flex-shrink: 0; }
.history-sermon { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
