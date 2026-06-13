<script setup>
import { computed, onMounted, ref } from "vue";

const localTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
import { api } from "../composables/useApi";

const emit = defineEmits(["started", "intercepted"]);

// ---- Service language ------------------------------------------------------
// 'en' | 'my' | 'td'. Drives (a) this form's own labels, (b) the language sent
// with the intake so the whole service — prayers, sermon, scripture (Judson
// 1835 Burmese / Lai Siangtho 1932 Tedim), hymns (dalsuum/myanmar-hymns / ZBC
// Labu Lui), narration voice — is conducted in that language.
// Persisted so a returning worshipper lands back on their tab.
// NOTE: the Tedim (Zolai) strings below are best-effort — please have a native
// speaker review them (you wrote the Zolai dictionary; corrections welcome).
const LANGS = ["en", "my", "td"];
const enabledLangs = ref(["en"]); // filled from config; English always the fallback
const stored = localStorage.getItem("service_language");
const language = ref(LANGS.includes(stored) ? stored : "en");
function setLanguage(l) {
  language.value = l;
  localStorage.setItem("service_language", l);
}

// UI strings per language. Myanmar text is Myanmar Unicode (rendered with
// Padauk / Noto Sans Myanmar below) — never Zawgyi.
const STRINGS = {
  en: {
    welcome: "Welcome",
    welcomeBack: (n) => `Welcome back, ${n}`,
    sub: "Let us prepare a service just for you.",
    subBack: "It's good to see you again. Let us prepare a service just for you.",
    nameLabel: "Your name (optional)",
    namePh: "We'll give you a visitor name if you'd rather not say",
    emailLabel: "Email",
    emailReq: "(required — we'll send you a reminder)",
    emailOpt: "(optional)",
    emailPhLater: "Enter your email to receive a reminder",
    emailPhNow: "So we can welcome you back",
    moodLabel: "How are you feeling today?",
    customMoodLabel: "Or describe your feeling in one word (optional)",
    customMoodPh: "e.g. sad, hopeful, numb…",
    prayerLabel: "Prayer request (optional)",
    prayerPh: "Share what is on your heart…",
    musicLabel: "Music for your service",
    whenLabel: "When would you like your service?",
    now: "Right now", nowDesc: "Begin immediately",
    later: "Schedule it", laterDesc: "Pick a future time",
    historyLabel: "Your previous services",
    begin: "Begin my service", schedule: "Schedule my service",
    preparing: "Preparing your service…",
    localTime: "Your local time",
  },
  my: {
    welcome: "ကြိုဆိုပါသည်",
    welcomeBack: (n) => `ပြန်လည်ကြိုဆိုပါသည်၊ ${n}`,
    sub: "သင့်အတွက် ဝတ်ပြုကိုးကွယ်ခြင်းအစီအစဉ်ကို ပြင်ဆင်ပေးပါမည်။",
    subBack: "ပြန်လည်တွေ့ရသည့်အတွက် ဝမ်းမြောက်ပါသည်။ သင့်အတွက် ဝတ်ပြုကိုးကွယ်ခြင်းကို ပြင်ဆင်ပေးပါမည်။",
    nameLabel: "သင့်အမည် (ရွေးချယ်နိုင်သည်)",
    namePh: "မဖော်ပြလိုပါက ဧည့်သည်အမည် သတ်မှတ်ပေးပါမည်",
    emailLabel: "အီးမေးလ်",
    emailReq: "(လိုအပ်သည် — သတိပေးချက် ပေးပို့ပါမည်)",
    emailOpt: "(ရွေးချယ်နိုင်သည်)",
    emailPhLater: "သတိပေးချက်ရရန် သင့်အီးမေးလ်ကို ရိုက်ထည့်ပါ",
    emailPhNow: "နောက်တစ်ကြိမ် ကြိုဆိုနိုင်ရန်",
    moodLabel: "ယနေ့ မည်သို့ ခံစားနေရပါသနည်း။",
    customMoodLabel: "သို့မဟုတ် သင့်ခံစားချက်ကို စကားလုံးတစ်လုံးဖြင့် ဖော်ပြပါ (ရွေးချယ်နိုင်သည်)",
    customMoodPh: "ဥပမာ — ဝမ်းနည်း၊ မျှော်လင့်…",
    prayerLabel: "ဆုတောင်းချက် (ရွေးချယ်နိုင်သည်)",
    prayerPh: "သင့်စိတ်နှလုံးထဲမှ အရာများကို မျှဝေပါ…",
    musicLabel: "ဝတ်ပြုခြင်းအတွက် တေးဂီတ",
    whenLabel: "ဝတ်ပြုခြင်းကို မည်သည့်အချိန်တွင် ပြုလုပ်လိုပါသနည်း။",
    now: "ယခုချက်ချင်း", nowDesc: "ချက်ချင်း စတင်မည်",
    later: "အချိန်ဇယားသတ်မှတ်မည်", laterDesc: "နောင်အချိန်တစ်ခု ရွေးပါ",
    historyLabel: "ယခင်ဝတ်ပြုခြင်းများ",
    begin: "ဝတ်ပြုခြင်း စတင်မည်", schedule: "ဝတ်ပြုခြင်း စီစဉ်မည်",
    preparing: "သင့်ဝတ်ပြုခြင်းကို ပြင်ဆင်နေပါသည်…",
    localTime: "သင့်ဒေသစံတော်ချိန်",
  },
  td: {
    welcome: "Hong kipahpih ung",
    welcomeBack: (n) => `Hong kipahpih kik ung, ${n}`,
    sub: "Nangma adingin Pasian biakna hun ka bawlsak ding uh hi.",
    subBack: "Na hong pai kik ka kipak mahmah uh hi. Nangma adingin biakna hun ka bawlsak ding uh hi.",
    nameLabel: "Na min (na deih leh)",
    namePh: "Na gen nop kei leh khualpa min kong pia ding uh hi",
    emailLabel: "Email",
    emailReq: "(kisam hi — theihsakna kong khak ding uh hi)",
    emailOpt: "(na deih leh)",
    emailPhLater: "Theihsakna na muh nadingin na email gelh in",
    emailPhNow: "Nang hong kipahpih kik thei nadingin",
    moodLabel: "Tuni in na lungsim bangci hiam?",
    customMoodLabel: "A hih kei leh na lungsim thuakna kammal khat tawh gen in (na deih leh)",
    customMoodPh: "gentehna — dah, lametna…",
    prayerLabel: "Thungetna (na deih leh)",
    prayerPh: "Na lungsim sunga om thute hong gen in…",
    musicLabel: "Na biakna ading lasa",
    whenLabel: "Bang hun in na biakna na deih hiam?",
    now: "Tu in", nowDesc: "Tu in kipan pah ding",
    later: "Hun khen ding", laterDesc: "Mailam hun khat teel in",
    historyLabel: "Na biakna masate",
    begin: "Ka biakna kipan ding", schedule: "Ka biakna hun khen ding",
    preparing: "Na biakna ka bawl laitak uh hi…",
    localTime: "Na omna mun hun",
  },
};
const t = computed(() => STRINGS[language.value]);

// Display labels for the default mood set (the VALUE sent to the backend stays
// English — the admin's moods config and the worker's mood matching are English).
const MOOD_MY = {
  Grateful: "ကျေးဇူးတင်ခြင်း", Anxious: "စိုးရိမ်ပူပန်ခြင်း", Grieving: "ဝမ်းနည်းကြေကွဲခြင်း",
  Joyful: "ဝမ်းမြောက်ခြင်း", Seeking: "ရှာဖွေခြင်း", Hopeful: "မျှော်လင့်ခြင်း",
};
const MOOD_TD = {
  Grateful: "Lungdam", Anxious: "Lunghimawh", Grieving: "Dahna",
  Joyful: "Nopna", Seeking: "Pasian zon", Hopeful: "Lametna",
};
const moodLabel = (m) =>
  (language.value === "my" && MOOD_MY[m]) ||
  (language.value === "td" && MOOD_TD[m]) || m;

// Music-source copy per language (values unchanged).
const SOURCE_MY = {
  hymn_sung:    { title: "ဓမ္မသီချင်း (သီဆို)", desc: "မြန်မာဓမ္မသီချင်းကို သီဆိုပြီး စာသားများကို ဖန်သားပြင်တွင် ပြသပေးမည်" },
  hymn:         { title: "ဓမ္မသီချင်း", desc: "မြန်မာဓမ္မသီချင်း — စာသားနှင့်အတူ လိုက်ဆိုနိုင်သည်" },
  hymn_youtube: { title: "ဓမ္မသီချင်း (YouTube)", desc: "သင့်ခံစားချက်နှင့် ကိုက်ညီသော ဓမ္မသီချင်း" },
  suno:         { title: "AI ရေးစပ်တေးဂီတ", desc: "သင့်အတွက် အသစ်ဖန်တီးထားသော ဝတ်ပြုတေးဂီတ" },
  youtube:      { title: "ခေတ်သစ်ဝတ်ပြုတေးဂီတ (YouTube)", desc: "ရှိပြီးသား ဝတ်ပြုတေးဂီတနှင့် တရားဒေသနာ" },
};
const SOURCE_TD = {
  hymn_sung:    { title: "ZBC Labu la (sak)", desc: "Tedim labu la khat, a kammal te screen ah kilang ding" },
  hymn:         { title: "ZBC Labu la", desc: "Labu la — a kammal te tawh sak khawm thei" },
  hymn_youtube: { title: "Labu la (YouTube)", desc: "Na lungsim tawh kituak labu la" },
  suno:         { title: "AI phuah lasa", desc: "Nangma ading a kiphuah biakna lasa thak" },
  youtube:      { title: "Tulai biakna lasa (YouTube)", desc: "Om sa biakna lasa leh thugenna" },
};
const sourceLabel = (s_) =>
  (language.value === "my" && SOURCE_MY[s_.value]) ||
  (language.value === "td" && SOURCE_TD[s_.value]) || s_;


// Every music source the app knows about, with its copy. The admin decides which of
// these actually appear (config.music_sources); we render that subset in this order.
const MUSIC_SOURCES = [
  { value: "hymn_sung", title: "Sung hymn", desc: "A classic hymn sung aloud, with the words on screen" },
  { value: "hymn", title: "Instrumental hymn", desc: "The hymn played, with the words to sing along" },
  { value: "hymn_youtube", title: "Sung Hymn (YouTube)", desc: "A traditional hymn sung by a choir, matched to your mood" },
  { value: "suno", title: "AI-composed (Suno)", desc: "Original worship, generated for you" },
  { value: "musicgen", title: "AI-composed (Local)", desc: "Worship music created on-server — no account needed" },
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
    if (Array.isArray(cfg.enabled_languages) && cfg.enabled_languages.length) {
      enabledLangs.value = cfg.enabled_languages;
      if (!enabledLangs.value.includes(language.value)) {
        setLanguage(enabledLangs.value[0]);
      }
    }
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

  const trimmedCustomMood = customMood.value.trim();
  if (trimmedCustomMood && !/^[A-Za-z]+$/.test(trimmedCustomMood)) {
    error.value = "Your custom feeling must be a single word using only letters.";
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
      emit("started", { token: session_token, musicSource: musicSource.value });
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
        await submitOnce();
        return;
      } catch (retryErr) {
        error.value = retryErr.data?.message || "Something went wrong. Please try again.";
        return;
      }
    }
    error.value = e.data?.message || "Something went wrong. Please try again.";
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="intake" :class="{ 'lang-my': language === 'my' }">
    <!-- Service language tabs: the whole service — UI, prayers, sermon,
         scripture (Judson 1835), hymns, narration voice — follows this choice. -->
    <div v-if="enabledLangs.length > 1" class="lang-tabs" role="tablist" aria-label="Service language">
      <button v-if="enabledLangs.includes('en')" type="button" role="tab" class="lang-tab"
              :aria-selected="language === 'en'" :class="{ active: language === 'en' }"
              @click="setLanguage('en')">English</button>
      <button v-if="enabledLangs.includes('my')" type="button" role="tab" class="lang-tab lang-tab-my"
              :aria-selected="language === 'my'" :class="{ active: language === 'my' }"
              @click="setLanguage('my')">မြန်မာ</button>
      <button v-if="enabledLangs.includes('td')" type="button" role="tab" class="lang-tab"
              :aria-selected="language === 'td'" :class="{ active: language === 'td' }"
              @click="setLanguage('td')">Zolai</button>
    </div>

    <h1>{{ returningName ? t.welcomeBack(returningName) : t.welcome }}</h1>
    <p class="sub">{{ returningName ? t.subBack : t.sub }}</p>

    <!-- Previous services for returning worshippers -->
    <template v-if="returningName && historyLoaded && history.length">
      <div class="history">
        <p class="history-label">{{ t.historyLabel }}</p>
        <div v-for="s in history" :key="s.session_token" class="history-row">
          <span class="history-date">{{ fmtHistoryDate(s.date) }}</span>
          <span class="badge pending history-mood">{{ s.mood }}</span>
          <span class="history-sermon">{{ s.sermon_topic || "—" }}</span>
        </div>
      </div>
    </template>

    <template v-if="!returningName">
      <label class="field-label" for="name">{{ t.nameLabel }}</label>
      <input
        id="name"
        v-model="name"
        type="text"
        class="text-input"
        :placeholder="t.namePh"
        autocomplete="name"
      />
    </template>

    <label class="field-label" for="email">
      {{ t.emailLabel }} <span v-if="when === 'later'">{{ t.emailReq }}</span><span v-else>{{ t.emailOpt }}</span>
    </label>
    <input
      id="email"
      v-model="email"
      type="email"
      class="text-input"
      :placeholder="when === 'later' ? t.emailPhLater : t.emailPhNow"
      autocomplete="email"
      :required="when === 'later'"
    />

    <label class="field-label">{{ t.moodLabel }}</label>
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

    <label class="field-label" for="custom-mood">{{ t.customMoodLabel }}</label>
    <input
      id="custom-mood"
      v-model="customMood"
      type="text"
      class="text-input"
      :placeholder="t.customMoodPh"
      maxlength="50"
      autocomplete="off"
    />

    <label class="field-label" for="prayer">{{ t.prayerLabel }}</label>
    <textarea
      id="prayer"
      v-model="prayerText"
      rows="3"
      :placeholder="t.prayerPh"
    ></textarea>

    <label class="field-label">{{ t.musicLabel }}</label>
    <div class="source-row">
      <button
        v-for="s in musicSources"
        :key="s.value"
        type="button"
        class="source"
        :class="{ active: musicSource === s.value }"
        @click="musicSource = s.value"
      >
        <strong>{{ sourceLabel(s).title }}</strong>
        <span>{{ sourceLabel(s).desc }}</span>
      </button>
    </div>

    <template v-if="schedulingEnabled">
      <label class="field-label">{{ t.whenLabel }}</label>
      <div class="source-row">
        <button
          type="button"
          class="source"
          :class="{ active: when === 'now' }"
          @click="when = 'now'"
        >
          <strong>{{ t.now }}</strong>
          <span>{{ t.nowDesc }}</span>
        </button>
        <button
          type="button"
          class="source"
          :class="{ active: when === 'later' }"
          @click="when = 'later'"
        >
          <strong>{{ t.later }}</strong>
          <span>{{ t.laterDesc }}</span>
        </button>
      </div>
      <input
        v-if="when === 'later'"
        v-model="scheduledAt"
        type="datetime-local"
        class="schedule-input"
        aria-label="Service date and time"
      />
      <small v-if="when === 'later'" class="tz-hint">{{ t.localTime }} · {{ localTimezone }}</small>
    </template>

    <p v-if="error" class="error">{{ error }}</p>

    <button class="begin" :disabled="loading" @click="begin">
      {{ loading ? t.preparing : (when === "later" ? t.schedule : t.begin) }}
    </button>
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
.lang-tabs { display: flex; gap: 0; margin-bottom: 1.25rem; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
.lang-tab { flex: 1; padding: 0.55rem; border: none; background: var(--surface); color: var(--text-muted); cursor: pointer; font: inherit; transition: background 0.12s ease, color 0.12s ease; }
.lang-tab + .lang-tab { border-left: 1px solid var(--border); }
.lang-tab.active { background: var(--primary); color: var(--on-primary); font-weight: 600; }
.lang-tab-my { font-family: "Padauk", "Noto Sans Myanmar", "Myanmar Text", sans-serif; }
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
