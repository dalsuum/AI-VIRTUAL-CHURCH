<script setup>
// Admin console, reached at #admin. Logs in with an admin account, then exposes the
// dashboard plus moderation (testimonies), donor insight, user management, service
// retry, and CSV exports.
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import { api } from "../composables/useApi";
import ThemeToggle from "./ThemeToggle.vue";
import VoiceStudio from "./VoiceStudio.vue";
import PermissionsMatrix from "./PermissionsMatrix.vue";
import AdsManager from "./AdsManager.vue";
import AdminLyricsManager from "./AdminLyricsManager.vue";
import VocabularyManager from "./VocabularyManager.vue";
import SpecialSundaysManager from "./SpecialSundaysManager.vue";
import FathersDayManager from "./FathersDayManager.vue";
import StickerManager from "./StickerManager.vue";

const authed      = ref(false);
const currentUser = ref(null); // { id, name, role, permissions: string[] }
const email       = ref("");
const password    = ref("");
const loginError  = ref("");

// Returns true if the current user has the given "feature.action" permission.
// Admin role bypasses the permissions check.
function can(permission) {
  if (!currentUser.value) return false;
  if (currentUser.value.role === "admin") return true;
  return (currentUser.value.permissions ?? []).includes(permission);
}
const isAdminUser = computed(() => currentUser.value?.role === "admin");

// Single source of truth for all admin tabs.
// Adding a new tab: add ONE entry here — nav button, permission, and data load are all derived from it.
// name: route key  |  label: nav text  |  can: () => bool  |  load: fn called on tab open (null = nothing to fetch)
const TABS = [
  { name: "dashboard",      label: "Dashboard",       can: () => can("dashboard.view"),       load: () => { if (can("dashboard.view")) api.adminDashboard().then(r => { stats.value = r; }).catch(() => {}); } },
  { name: "services",       label: "Services",         can: () => can("services.view"),        load: loadServices },
  { name: "donors",         label: "Donors",           can: () => can("donors.view"),          load: loadDonors },
  { name: "testimonies",    label: "Testimonies",      can: () => can("testimonies.view"),     load: loadTestimonies },
  { name: "users",          label: "Users",            can: () => can("users.view"),           load: loadUsers },
  { name: "lyrics",         label: "Lyrics",           can: () => can("lyrics.manage"),        load: null },
  { name: "vocabulary",     label: "Vocabulary",       can: () => can("vocabulary.manage"),    load: null },
  { name: "prayer",         label: "Prayer Requests",  can: () => can("prayer_requests.view"), load: loadPrayerRequests },
  { name: "settings",       label: "Settings",         can: () => can("settings.view"),        load: loadSettings },
  { name: "bible",          label: "Bible",            can: () => can("settings.view"),        load: loadSettings },
  { name: "content-filter", label: "Content Filter",   can: () => isAdminUser.value,           load: loadContentFilter },
  { name: "music-pool",     label: "AI Music Pool",    can: () => can("music_pool.view"),      load: loadMusicTracks },
  { name: "voice-studio",   label: "Voice Studio",     can: () => can("voice_studio.view"),    load: null },
  { name: "voice-training", label: "Voice Training",   can: () => can("voice_training.view"),  load: () => { loadVoiceTrainingStatus(); scheduleVoiceTrainingPoll(); } },
  { name: "ads",            label: "Ads",              can: () => can("ads.view"),             load: null },
  { name: "special-sundays",label: "Special Sundays",  can: () => can("special_sundays.view"), load: null },
  { name: "special-day-mv", label: "Special Day MV",   can: () => isAdminUser.value,           load: null },
  { name: "live-sticker",   label: "Live Sticker",     can: () => isAdminUser.value,           load: null },
  { name: "permissions",    label: "Permissions",      can: () => can("permissions.view"),     load: loadPermissions },
  { name: "grammar-review", label: "Language Review",  can: () => can("language_review.view"), load: () => { grData.value = null; loadGrammarReview(); } },
  { name: "system",         label: "System",           can: () => can("system.view"),          load: () => { loadUpdateStatus(); scheduleUpdatePoll(); loadVoiceboxStatus(); scheduleVoiceboxPoll(); } },
];

function firstAllowedTab() {
  return (TABS.find(t => t.can()) ?? TABS[0]).name;
}

const tab = ref("dashboard");
const stats = ref(null);
const services = ref([]);
const donors = ref([]);
const testimonies = ref([]);
const users = ref([]);
const prayerRequests = ref([]);
const settings        = ref(null);
const savingSettings  = ref(false);
// Settings writes are admin-only; non-admins can view but not change.
const settingsReadOnly = computed(() => !isAdminUser.value);
const notice          = ref("");
const permissionsData = ref(null); // { permissions, available, roles }
const musicTracks = ref([]);
const musicPoolBusy = ref(false);
const musicPoolFilters = ref({ mood: "", language: "", search: "", limit: 100 });
const musicTrackEditingId = ref(null);
const musicTrackForm = ref({
  mood: "",
  language: "en",
  provider_ref: "",
  storage_key: "",
  title: "",
  lyrics: "",
  source: "suno",
});
const musicPoolLanguages = [
  { value: "", label: "All languages" },
  { value: "en", label: "English" },
  { value: "my", label: "Myanmar" },
  { value: "td", label: "Tedim (Zolai)" },
];

const voiceTraining = ref(null);
const voiceTrainingBusy = ref(false);
const voiceTrainingError = ref("");
let voiceTrainingTimer = null;

// How spoken segments are voiced across all services. Mirrors the backend's
// Setting::NARRATION_MODES; surfaced as a single-choice selector.
const narrationModes = [
  { value: "edge_tts", label: "Edge TTS (free)", hint: "Microsoft neural voice — free, no API key, high quality. Recommended." },
  { value: "voicebox", label: "Voicebox (local)", hint: "Voice-cloned narration via the local Voicebox container. Requires Docker setup and VOICEBOX_PROFILE_ID_FEMALE / _MALE set in the worker env." },
  { value: "browser", label: "Browser voice", hint: "The worshipper's browser reads each segment aloud — free, no API key." },
  { value: "openai", label: "OpenAI voice", hint: "Segments are narrated with OpenAI text-to-speech. Requires a TTS key." },
  { value: "kokoro", label: "OpenRouter Kokoro", hint: "Segments are narrated with the open hexgrad/kokoro-82m voice via OpenRouter. Uses the OpenRouter key." },
  { value: "off", label: "Off", hint: "Segments stay as silent text — nothing is read aloud." },
];

const voiceboxEngines = [
  { value: "qwen",       label: "Qwen3-TTS 0.6B", hint: "CPU-friendly model used by the local Docker image. Recommended for this server." },
  { value: "qwen_1_7b",  label: "Qwen3-TTS 1.7B", hint: "Higher quality, much heavier. Use only with enough RAM or GPU headroom." },
];
const setVoiceboxEngine = (v) => saveSetting("voicebox_engine", v, "Voicebox engine updated.");

// Edge TTS voice options (shown when narration_mode === 'edge_tts').
const edgeTtsVoices = [
  { value: "en-US-AriaNeural",       label: "Aria",       gender: "Female", accent: "US" },
  { value: "en-US-JennyNeural",      label: "Jenny",      gender: "Female", accent: "US" },
  { value: "en-GB-SoniaNeural",      label: "Sonia",      gender: "Female", accent: "UK" },
  { value: "en-AU-NatashaNeural",    label: "Natasha",    gender: "Female", accent: "AU" },
  { value: "en-US-GuyNeural",        label: "Guy",        gender: "Male",   accent: "US" },
  { value: "en-US-ChristopherNeural",label: "Christopher",gender: "Male",   accent: "US" },
  { value: "en-GB-RyanNeural",       label: "Ryan",       gender: "Male",   accent: "UK" },
  { value: "en-AU-WilliamNeural",    label: "William",    gender: "Male",   accent: "AU" },
];
const setEdgeTtsVoice = (v) => saveSetting("edge_tts_voice", v, "Voice updated.");

// Myanmar narration modes: real Edge TTS (cloud) or local MMS-TTS.
const narrationModesMY = [
  { value: "edge_tts", label: "Edge TTS (cloud, free)", hint: "Microsoft my-MM-NilarNeural (female) / my-MM-ThihaNeural (male) — high-quality neural Burmese, no server needed. Configure EDGE_TTS_VOICE_MY_FEMALE / _MALE in workers/.env to override." },
  { value: "mms_tts",  label: "MMS-TTS (local, free)",  hint: "Local facebook/mms-tts-mya via the aivc-mms-tts service. Best offline quality; requires MMS speech on port 8003." },
  { value: "off",      label: "Off",                    hint: "Segments stay as silent text — nothing is read aloud." },
];

// Tedim/Zolai narration modes: local MMS-TTS (native) or Edge TTS (no native Zolai voice).
const narrationModesTD = [
  { value: "mms_tts",  label: "MMS-TTS (local, free)",  hint: "Local facebook/mms-tts-ctd — the only native Zolai TTS. Requires MMS speech on port 8003." },
  { value: "edge_tts", label: "Edge TTS (cloud, free)", hint: "Microsoft cloud TTS — no native Zolai voice; reads Tedim text phonetically using EDGE_TTS_VOICE_TD (default en-US-AriaNeural). Free but accent will be English." },
  { value: "off",      label: "Off",                    hint: "Segments stay as silent text — nothing is read aloud." },
];

// Compact per-version "Listen" voice rows for the Bible page. English & KJV get
// the full English provider set; Myanmar & Tedim reuse their native-aware lists;
// Hebrew uses its he-IL Edge voice; the Chin/Zo Bibles have no native voice so
// they read phonetically via the English Edge voice (or Off).
const bibleVoiceEN = narrationModes.filter((x) => x.value !== "browser");
const bibleVoiceHE = [
  { value: "edge_tts", label: "Edge TTS (cloud, free)", hint: "Microsoft he-IL neural voice (Avri / Hila) — native Hebrew, no server needed." },
  { value: "off",      label: "Off",                    hint: "No narration for this translation." },
];
const bibleVoicePhonetic = [
  { value: "edge_tts", label: "Edge TTS (cloud, free)", hint: "No native voice — reads the Latin-script text phonetically with the English Edge voice. Free, but the accent is English." },
  { value: "off",      label: "Off",                    hint: "No narration for this translation." },
];
const bibleVoiceLangs = [
  { code: "kjv", label: "KJV (English)",  modes: bibleVoiceEN },
  { code: "en",  label: "English (BSB)",  modes: bibleVoiceEN },
  { code: "he",  label: "Hebrew (עברית)", modes: bibleVoiceHE },
  { code: "my",  label: "Burmese (ဗမာ)",  modes: narrationModesMY },
  { code: "td",  label: "Tedim (Zolai)",  modes: narrationModesTD },
  { code: "cfm", label: "Falam",          modes: bibleVoicePhonetic },
  { code: "cnh", label: "Hakha",          modes: bibleVoicePhonetic },
  { code: "mrh", label: "Mara",           modes: bibleVoicePhonetic },
  { code: "hlt", label: "Matu",           modes: bibleVoicePhonetic },
  { code: "lus", label: "Mizo",           modes: bibleVoicePhonetic },
  { code: "pck", label: "Paite",          modes: bibleVoicePhonetic },
  { code: "csy", label: "Sizang",         modes: bibleVoicePhonetic },
];

const serviceLanguages = [
  { key: "lang_en", label: "English", hint: "Show the English tab in the intake form. Keep at least one language on." },
  { key: "lang_my", label: "Myanmar (မြန်မာ)", hint: "Show the Myanmar/Burmese tab. Enable once the Burmese LLM is running." },
  { key: "lang_td", label: "Zolai (Tedim)", hint: "Show the Zolai/Tedim tab. Enable once the Tedim LLM is running." },
];

// Where the worker stores generated audio. Mirrors Setting::STORAGE_BACKENDS.
const storageBackends = [
  { value: "local", label: "Local disk", hint: "Served from the app's own storage. Best for a single-machine setup." },
  { value: "s3", label: "S3 object storage", hint: "Durable cloud storage — the reusable song library survives restarts. Needs S3 keys in the worker env." },
];

// Every music source the app can offer worshippers. Mirrors Setting::MUSIC_SOURCES;
// the admin enables a subset, and only those appear in the intake form.
const musicSourceOptions = [
  { value: "hymn_sung", label: "Sung hymn", hint: "A classic hymn sung aloud, words on screen." },
  { value: "hymn", label: "Instrumental hymn", hint: "The hymn played, with words to sing along." },
  { value: "hymn_youtube", label: "Vocal hymn (YouTube)", hint: "A traditional hymn sung by a choir, mood-matched and embedded from YouTube." },
  { value: "suno", label: "AI-composed (Suno)", hint: "Original worship composed for the worshipper via Suno API." },
  { value: "musicgen", label: "AI-composed (Local)", hint: "Worship music generated locally using Meta MusicGen — no API key needed, runs on-server (5–8 min on CPU)." },
  { value: "youtube", label: "From YouTube", hint: "An existing worship track and sermon clip." },
];

const newMood = ref("");
const newCountdownBanner = ref({ text: "", source: "" });

// ── Content Filter tab state ──────────────────────────────────────────────────
const cfCategories = ref([]);      // [{ id, label, description, scope, type, keywords }]
const cfScopes = ref(["both", "music", "sermon"]);
const cfTypes = ref(["block", "allow"]);
const cfBusy = ref(false);
const cfNewKeyword = ref({});      // keyed by category id
const cfNewCategory = ref({ label: "", scope: "both", type: "block", description: "" });
const cfFileInput = ref(null);
const cfScopeLabels = { both: "Worship + Sermon", music: "Worship only", sermon: "Sermon only" };
const cfTypeLabels = { block: "Block", allow: "Allow" };
const countdownSourceOptions = [
  { value: "all", label: "All sources", hint: "Rotate banners, approved testimonies, and mood-matched Scripture from the local Bible." },
  { value: "both", label: "Banners + testimonies", hint: "Rotate admin banners and approved testimonies." },
  { value: "verses", label: "Scripture verses", hint: "Show mood-matched verses from the local Bible (English / Burmese / Tedim)." },
  { value: "banners", label: "Custom banners", hint: "Show only the admin-managed messages below." },
  { value: "testimonies", label: "Testimonies", hint: "Show only approved testimonies from the moderation queue." },
  { value: "off", label: "Off", hint: "Show the normal countdown without cards." },
];

// Password change
const pwCurrent = ref("");
const pwNew = ref("");
const pwConfirm = ref("");
const pwSaving = ref(false);
const pwError = ref("");
const pwOk = ref("");

async function changePassword() {
  pwError.value = "";
  pwOk.value = "";
  if (pwNew.value !== pwConfirm.value) { pwError.value = "New passwords don't match."; return; }
  if (pwNew.value.length < 8) { pwError.value = "New password must be at least 8 characters."; return; }
  pwSaving.value = true;
  try {
    await api.changePassword(pwCurrent.value, pwNew.value);
    pwOk.value = "Password updated.";
    pwCurrent.value = "";
    pwNew.value = "";
    pwConfirm.value = "";
  } catch (e) {
    pwError.value = e?.data?.message || "Could not update password.";
  } finally {
    pwSaving.value = false;
  }
}

async function login() {
  loginError.value = "";
  try {
    await api.login({ email: email.value, password: password.value });
    await enter();
  } catch (e) {
    loginError.value = e?.data?.message || "Login failed.";
  }
}

// Verify the account has staff access (admin/moderator/presenter) via /me,
// then navigate to the first tab that this user's permissions allow.
async function enter() {
  try {
    const meRes = await api.me();
    const user  = meRes.user;
    const staffRoles = ["admin", "moderator", "presenter"];
    if (!staffRoles.includes(user.role)) {
      loginError.value = "This account does not have staff access.";
      return;
    }
    currentUser.value = user;
    authed.value      = true;
    show(firstAllowedTab());
  } catch (e) {
    loginError.value = e?.data?.message || "Could not authenticate.";
    authed.value = false;
  }
}

function logout() {
  api.logout();
  authed.value      = false;
  currentUser.value = null;
  stats.value       = null;
  voiceTraining.value = null;
  voiceTrainingError.value = "";
  clearInterval(voiceTrainingTimer);
  email.value       = "";
  password.value    = "";
}

async function loadServices() {
  services.value = (await api.adminServices()).services || [];
  selectedServiceIds.value = [];
}
async function loadDonors() {
  donors.value = (await api.adminDonors()).donors || [];
}
async function loadTestimonies() {
  testimonies.value = (await api.adminTestimonies()).testimonies || [];
}
async function loadUsers() {
  users.value = (await api.adminUsers()).users || [];
  selectedUserIds.value = [];
}
async function loadPrayerRequests() {
  prayerRequests.value = (await api.adminPrayerRequests()).prayer_requests || [];
}
async function loadSettings() {
  settings.value = await api.adminSettings();
}
async function loadPermissions() {
  permissionsData.value = await api.adminGetPermissions();
}
async function loadMusicTracks() {
  musicPoolBusy.value = true;
  try {
    const res = await api.adminMusicTracks(musicPoolFilters.value);
    musicTracks.value = res.tracks || [];
  } catch (e) {
    notice.value = e?.data?.message || "Could not load AI music pool.";
  } finally {
    musicPoolBusy.value = false;
  }
}

function show(name) {
  if (name !== "system")         { clearInterval(updateTimer); clearInterval(vbTimer); }
  if (name !== "voice-training") { clearInterval(voiceTrainingTimer); }
  tab.value    = name;
  notice.value = "";
  const t = TABS.find(t => t.name === name);
  if (t?.load) t.load();
}

// Optimistically apply one setting, persist it, and roll back the local value if the
// write fails. `key` is the settings field; `value` the new value; `ok` the toast.
async function saveSetting(key, value, ok) {
  if (!settings.value || settings.value[key] === value) return;
  savingSettings.value = true;
  const prev = settings.value[key];
  settings.value[key] = value; // optimistic
  try {
    await api.adminUpdateSettings({ [key]: value });
    notice.value = ok;
  } catch (e) {
    settings.value[key] = prev; // roll back on failure
    notice.value = e?.data?.message || "Could not update setting.";
  } finally {
    savingSettings.value = false;
  }
}

const setNarrationMode = (lang, mode) => saveSetting(`narration_mode_${lang}`, mode, "Narration voice updated.");

function toggleServiceLanguage(key) {
  if (!settings.value) return;
  const willDisable = settings.value[key] === true;
  const enabledCount = serviceLanguages.filter((l) => settings.value[l.key] === true).length;
  if (willDisable && enabledCount <= 1) {
    notice.value = "Keep at least one service language enabled.";
    return;
  }
  saveSetting(key, !settings.value[key], "Service language updated.");
}
const setMusicReuse = (on) => saveSetting("music_reuse", on, "Music reuse updated.");
const setAvatarEnabled = (on) => saveSetting("avatar_enabled", on, "D-ID avatar rendering updated.");
const setLocalAvatarEnabled = (on) => saveSetting("local_avatar_enabled", on, "Local avatar rendering updated.");
const setOrchestrationMode = (mode) => saveSetting("orchestration_mode", mode, "Orchestration mode updated.");
const setAgentProvider = (provider) => saveSetting("agent_provider", provider, "Agent provider updated.");
const setTextHighlightEnabled = (on) => saveSetting("text_highlight_enabled", on, "Text highlighting updated.");

// Online Bible reader ("Listen" button) — its own per-language voice + highlight.
const setBibleNarrationMode = (lang, mode) => saveSetting(`bible_narration_mode_${lang}`, mode, "Bible narration voice updated.");
const setBibleTextHighlight = (on) => saveSetting("bible_text_highlight_enabled", on, "Bible highlighting updated.");
const setBibleBgMusicMode = (mode) => saveSetting("bible_bg_music_mode", mode, "Bible background music mode updated.");
const setBibleBgMusicEngine = (engine) => saveSetting("bible_bg_music_engine", engine, "Bible AI music engine updated.");
const bibleBgStatus = ref(null);   // { ready, total } of the theme x tod matrix
const bibleBgPregenLoading = ref(false);
const refreshBibleBgStatus = async () => {
  try { bibleBgStatus.value = await api.adminBibleBgMusicStatus(); }
  catch (e) { bibleBgStatus.value = null; }
};
// Load the matrix status whenever the admin views/enables AI background mode.
watch(
  () => settings.value?.bible_bg_music_mode,
  (mode) => { if (mode === "ai" && !bibleBgStatus.value) refreshBibleBgStatus(); }
);
const pregenerateBibleBg = async () => {
  if (bibleBgPregenLoading.value) return;
  bibleBgPregenLoading.value = true;
  try {
    const res = await api.adminBibleBgMusicPregenerate();
    bibleBgStatus.value = { ready: res.ready, total: res.total };
    notice.value = `Queued ${res.queued} track(s). ${res.ready}/${res.total} ready — generation runs in the background.`;
  } catch (e) {
    notice.value = e?.data?.message || "Could not queue background music.";
  } finally {
    bibleBgPregenLoading.value = false;
  }
};
const saveBibleBgMusicUrl = () =>
  saveSetting("bible_bg_music_url", (settings.value.bible_bg_music_url || "").trim(), "Bible background music updated.");
const setBibleBgMusicVolume = (v) =>
  saveSetting("bible_bg_music_volume", Number(v), "Bible background music volume updated.");

// Per-version Bible reader feature matrix (show/hide a translation tab + enable
// or disable each reader feature button). Mirrors Setting::BIBLE_VERSIONS /
// BIBLE_FEATURES and the controls in BibleReader.vue.
const BIBLE_VERSIONS = [
  { code: "kjv", label: "KJV" },
  { code: "en",  label: "English (BSB)" },
  { code: "he",  label: "Hebrew (עברית)" },
  { code: "my",  label: "Burmese (ဗမာ)" },
  { code: "cfm", label: "Falam" },
  { code: "cnh", label: "Hakha" },
  { code: "mrh", label: "Mara" },
  { code: "hlt", label: "Matu" },
  { code: "lus", label: "Mizo" },
  { code: "pck", label: "Paite" },
  { code: "csy", label: "Sizang" },
  { code: "td",  label: "Tedim" },
];
const BIBLE_FEATURE_COLS = [
  { key: "enabled",    label: "Show tab" },
  { key: "listen",     label: "Listen" },
  { key: "highlight",  label: "Highlight" },
  { key: "continuous", label: "Continuous" },
  { key: "music",      label: "Music" },
  { key: "select",     label: "Select" },
  { key: "speed",      label: "Speed" },
  { key: "textsize",   label: "Size" },
  { key: "color",      label: "Color" },
];
// Read a cell, defaulting to enabled when the matrix hasn't loaded a value yet.
const bibleFeature = (code, key) => settings.value?.bible_features?.[code]?.[key] !== false;
// Toggle one cell and persist the whole matrix (objects don't suit saveSetting's
// value-equality check), with optimistic update + rollback on failure.
async function toggleBibleFeature(code, key) {
  if (!settings.value || settingsReadOnly.value || savingSettings.value) return;
  const prev = settings.value.bible_features || {};
  const next = JSON.parse(JSON.stringify(prev));
  next[code] = next[code] || {};
  next[code][key] = !(next[code][key] !== false);
  settings.value.bible_features = next; // optimistic
  savingSettings.value = true;
  try {
    await api.adminUpdateSettings({ bible_features: next });
    notice.value = "Bible feature updated.";
  } catch (e) {
    settings.value.bible_features = prev; // roll back
    notice.value = e?.data?.message || "Could not update setting.";
  } finally {
    savingSettings.value = false;
  }
}
const setRunpodEnabled = (on) => saveSetting("runpod_enabled", on ? "1" : "0", "Premium GPU usage updated.");
const setStorageBackend = (backend) => saveSetting("storage_backend", backend, "Storage backend updated.");
const setAiChordsEnabled = (on) => saveSetting("ai_chords_enabled", on, "AI chord detection updated.");
const setAiChordsModel = (v) => saveSetting("ai_chords_model", v.trim(), "AI chord model updated.");
const setScheduling = (on) => saveSetting("scheduling_enabled", on, "Scheduling updated.");
const setDefaultMusicSource = (src) => saveSetting("default_music_source", src, "Default music source updated.");
const setCountdownEnabled = (on) => saveSetting("countdown_content_enabled", on, "Countdown content updated.");
const setCountdownSource = (source) => saveSetting("countdown_content_source", source, "Countdown content source updated.");

// Persist a list-valued setting (moods, music_sources), rolling back on failure.
// Unlike saveSetting, this always writes — callers pass a freshly built array.
async function saveListSetting(key, value, ok) {
  if (!settings.value) return;
  savingSettings.value = true;
  const prev = settings.value[key];
  settings.value[key] = value; // optimistic
  try {
    await api.adminUpdateSettings({ [key]: value });
    notice.value = ok;
  } catch (e) {
    settings.value[key] = prev; // roll back on failure
    notice.value = e?.data?.message || "Could not update setting.";
  } finally {
    savingSettings.value = false;
  }
}

function addMood() {
  const m = newMood.value.trim();
  if (!m || !settings.value) return;
  if (settings.value.moods.some((x) => x.toLowerCase() === m.toLowerCase())) {
    notice.value = `"${m}" is already a mood.`;
    newMood.value = "";
    return;
  }
  saveListSetting("moods", [...settings.value.moods, m], `Added mood "${m}".`);
  newMood.value = "";
}

function removeMood(m) {
  if (!settings.value) return;
  if (settings.value.moods.length <= 1) {
    notice.value = "Keep at least one mood.";
    return;
  }
  saveListSetting("moods", settings.value.moods.filter((x) => x !== m), `Removed mood "${m}".`);
}

// ── Content Filter tab ────────────────────────────────────────────────────────
async function loadContentFilter() {
  try {
    const res = await api.cfList();
    cfCategories.value = res.categories || [];
    cfScopes.value = res.scopes || cfScopes.value;
    cfTypes.value = res.types || cfTypes.value;
  } catch (e) {
    notice.value = e?.data?.message || "Could not load content filter.";
  }
}

// Wrap a content-filter mutation: run it, refresh state from the response, surface a notice.
async function cfRun(fn, ok) {
  cfBusy.value = true;
  try {
    const res = await fn();
    if (res?.categories) cfCategories.value = res.categories;
    notice.value = ok;
  } catch (e) {
    notice.value = e?.data?.message || "Content filter update failed.";
  } finally {
    cfBusy.value = false;
  }
}

function cfAddKeyword(cat) {
  const kw = (cfNewKeyword.value[cat.id] || "").trim().toLowerCase();
  if (!kw) return;
  cfNewKeyword.value = { ...cfNewKeyword.value, [cat.id]: "" };
  cfRun(() => api.cfAddKeyword(cat.id, kw), `Added "${kw}" to ${cat.label}.`);
}

function cfRemoveKeyword(cat, kw) {
  cfRun(() => api.cfDeleteKeyword(cat.id, kw), `Removed "${kw}" from ${cat.label}.`);
}

function cfChangeScope(cat, scope) {
  cfRun(() => api.cfUpdateCategory(cat.id, { scope }), `${cat.label} now applies to: ${cfScopeLabels[scope]}.`);
}

function cfChangeType(cat, type) {
  cfRun(() => api.cfUpdateCategory(cat.id, { type }),
    `${cat.label} is now an ${type === "allow" ? "allow (override)" : "block"} list.`);
}

function cfAddCategory() {
  const label = cfNewCategory.value.label.trim();
  if (!label) return;
  const payload = { ...cfNewCategory.value, label };
  cfNewCategory.value = { label: "", scope: "both", type: "block", description: "" };
  cfRun(() => api.cfAddCategory(payload), `Added category "${label}".`);
}

function cfDeleteCategory(cat) {
  if (!window.confirm(`Delete the "${cat.label}" category and its ${cat.keywords.length} keyword(s)?`)) return;
  cfRun(() => api.cfDeleteCategory(cat.id), `Deleted category "${cat.label}".`);
}

function cfDownload(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

async function cfExport(format) {
  try {
    const stamp = new Date().toISOString().slice(0, 10);
    if (format === "csv") {
      cfDownload(await api.cfExportCsv(), `content-filter-${stamp}.csv`);
    } else {
      cfDownload(await api.cfExportJson(), `content-filter-${stamp}.json`);
    }
    notice.value = `Exported content filter as ${format.toUpperCase()}.`;
  } catch (e) {
    notice.value = e?.data?.message || "Export failed.";
  }
}

async function cfImport(event) {
  const file = event.target.files?.[0];
  event.target.value = "";  // allow re-importing the same file
  if (!file) return;
  if (!window.confirm("Restore from this JSON file? This REPLACES the entire current filter.")) return;
  try {
    const text = await file.text();
    const parsed = JSON.parse(text);
    const categories = Array.isArray(parsed) ? parsed : parsed.categories;
    if (!Array.isArray(categories)) throw new Error("No categories array found in file.");
    await cfRun(() => api.cfReplace(categories), "Content filter restored from file.");
  } catch (e) {
    notice.value = e?.message ? `Import failed: ${e.message}` : "Import failed.";
  }
}

function addCountdownBanner() {
  if (!settings.value) return;
  const text = newCountdownBanner.value.text.trim();
  const source = newCountdownBanner.value.source.trim();
  if (!text) return;
  const current = Array.isArray(settings.value.countdown_banners) ? settings.value.countdown_banners : [];
  if (current.length >= 12) {
    notice.value = "Keep countdown banners to 12 or fewer.";
    return;
  }
  saveListSetting("countdown_banners", [...current, { text, source }], "Countdown banner added.");
  newCountdownBanner.value = { text: "", source: "" };
}

function updateCountdownBanner(index, field, value) {
  if (!settings.value) return;
  const current = Array.isArray(settings.value.countdown_banners) ? settings.value.countdown_banners : [];
  const trimmed = value.trim();
  if (field === "text" && !trimmed) {
    notice.value = "Countdown banner text cannot be blank.";
    return;
  }
  const next = current.map((b, i) => i === index ? { ...b, [field]: trimmed } : b);
  saveListSetting("countdown_banners", next, "Countdown banner updated.");
}

function removeCountdownBanner(index) {
  if (!settings.value) return;
  const current = Array.isArray(settings.value.countdown_banners) ? settings.value.countdown_banners : [];
  if (current.length <= 1) {
    notice.value = "Keep at least one countdown banner.";
    return;
  }
  saveListSetting("countdown_banners", current.filter((_, i) => i !== index), "Countdown banner removed.");
}

// Flip a music source on/off, preserving canonical order and never emptying the set.
function toggleMusicSource(value) {
  if (!settings.value) return;
  const on = settings.value.music_sources.includes(value);
  if (on && settings.value.music_sources.length <= 1) {
    notice.value = "Keep at least one music source.";
    return;
  }
  const next = musicSourceOptions
    .map((o) => o.value)
    .filter((v) => (v === value ? !on : settings.value.music_sources.includes(v)));
  saveListSetting("music_sources", next, "Music sources updated.");
}

function resetMusicTrackForm() {
  musicTrackEditingId.value = null;
  musicTrackForm.value = {
    mood: "",
    language: "en",
    provider_ref: "",
    storage_key: "",
    title: "",
    lyrics: "",
    source: "suno",
  };
}

function editMusicTrack(track) {
  musicTrackEditingId.value = track.id;
  musicTrackForm.value = {
    mood: track.mood || "",
    language: track.language || "en",
    provider_ref: track.provider_ref || "",
    storage_key: track.storage_key || "",
    title: track.title || "",
    lyrics: track.lyrics || "",
    source: track.source || "suno",
  };
}

async function saveMusicTrack() {
  const payload = {
    mood: musicTrackForm.value.mood.trim(),
    language: musicTrackForm.value.language,
    provider_ref: musicTrackForm.value.provider_ref.trim(),
    storage_key: musicTrackForm.value.storage_key.trim(),
    title: musicTrackForm.value.title.trim() || null,
    lyrics: musicTrackForm.value.lyrics.trim() || null,
    source: musicTrackForm.value.source,
  };
  if (!payload.mood || !payload.provider_ref || !payload.storage_key) {
    notice.value = "Mood, provider ref, and storage key are required.";
    return;
  }

  musicPoolBusy.value = true;
  try {
    if (musicTrackEditingId.value) {
      await api.adminUpdateMusicTrack(musicTrackEditingId.value, payload);
      notice.value = "AI music pool track updated.";
    } else {
      await api.adminCreateMusicTrack(payload);
      notice.value = "AI music pool track added.";
    }
    resetMusicTrackForm();
    await loadMusicTracks();
  } catch (e) {
    notice.value = e?.data?.message || "Could not save AI music pool track.";
  } finally {
    musicPoolBusy.value = false;
  }
}

async function removeMusicTrack(track) {
  if (!confirm(`Delete AI music pool row #${track.id}?`)) return;
  musicPoolBusy.value = true;
  try {
    await api.adminDeleteMusicTrack(track.id);
    notice.value = "AI music pool track deleted.";
    if (musicTrackEditingId.value === track.id) resetMusicTrackForm();
    await loadMusicTracks();
  } catch (e) {
    notice.value = e?.data?.message || "Could not delete AI music pool track.";
  } finally {
    musicPoolBusy.value = false;
  }
}

async function applyMusicPoolFilters() {
  await loadMusicTracks();
}

async function retry(s) {
  try {
    await api.adminRetryService(s.id);
    notice.value = `Re-dispatched service #${s.id}.`;
    loadServices();
  } catch (e) {
    notice.value = e?.data?.message || "Retry failed.";
  }
}

async function deleteService(s) {
  if (!confirm(`Delete service #${s.id}? This cannot be undone.`)) return;
  try {
    await api.adminDeleteService(s.id);
    notice.value = `Service #${s.id} deleted.`;
    loadServices();
  } catch (e) {
    notice.value = e?.data?.message || "Delete failed.";
  }
}

// ── Bulk selection + delete (Services & Users tabs) ──────────────────────────
const selectedServiceIds = ref([]);
const selectedUserIds     = ref([]);
const bulkDelete          = ref(null); // { type: 'services'|'users', count } or null

const allServicesSelected = computed(() =>
  services.value.length > 0 && selectedServiceIds.value.length === services.value.length);
const allUsersSelected = computed(() =>
  users.value.length > 0 && selectedUserIds.value.length === users.value.length);

function toggleAllServices(checked) {
  selectedServiceIds.value = checked ? services.value.map(s => s.id) : [];
}
function toggleAllUsers(checked) {
  selectedUserIds.value = checked ? users.value.map(u => u.id) : [];
}

function askBulkDeleteServices() {
  if (!selectedServiceIds.value.length) return;
  bulkDelete.value = { type: "services", count: selectedServiceIds.value.length };
}
function askBulkDeleteUsers() {
  if (!selectedUserIds.value.length) return;
  bulkDelete.value = { type: "users", count: selectedUserIds.value.length };
}

async function confirmBulkDelete() {
  const job = bulkDelete.value;
  if (!job) return;
  bulkDelete.value = null;
  try {
    if (job.type === "services") {
      await api.adminBulkDeleteServices(selectedServiceIds.value);
      notice.value = `${job.count} service(s) deleted.`;
      loadServices();
    } else {
      await api.adminBulkDeleteUsers(selectedUserIds.value);
      notice.value = `${job.count} user(s) deleted.`;
      loadUsers();
    }
  } catch (e) {
    notice.value = e?.data?.message || "Bulk delete failed.";
  }
}

// Share a service resume link. On mobile/modern browsers the native share sheet
// covers WhatsApp, Messenger, TikTok, etc. On desktop we fall back to a small
// popover with copy-link, WhatsApp, Email, and Telegram buttons.
const sharePopover = ref(null); // { token, link } or null

function serviceLink(token) {
  return `${window.location.origin}/?session=${token}`;
}

async function shareService(s) {
  const link = serviceLink(s.session_token);
  const title = "Your AI Virtual Church service";
  const text  = s.mood ? `A personalized worship service (${s.mood})` : "A personalized worship service";

  if (navigator.share) {
    try {
      await navigator.share({ title, text, url: link });
    } catch {
      // user cancelled — do nothing
    }
    return;
  }
  // Desktop fallback: show the inline popover.
  sharePopover.value = sharePopover.value?.token === s.session_token
    ? null
    : { token: s.session_token, link };
}

async function copyLink(link) {
  try {
    await navigator.clipboard.writeText(link);
    notice.value = "Link copied to clipboard.";
  } catch {
    notice.value = "Could not copy — please copy the link manually.";
  }
  sharePopover.value = null;
}

function shareVia(platform, link, mood) {
  const text = mood ? `A personalized worship service (${mood})` : "A personalized worship service";
  const urls = {
    whatsapp : `https://wa.me/?text=${encodeURIComponent(text + "\n" + link)}`,
    telegram : `https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`,
    email    : `mailto:?subject=${encodeURIComponent("Your AI Virtual Church service")}&body=${encodeURIComponent(text + "\n\n" + link)}`,
    twitter  : `https://twitter.com/intent/tweet?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`,
  };
  window.open(urls[platform], "_blank", "noopener,noreferrer");
  sharePopover.value = null;
}
async function approve(t) {
  await api.adminApproveTestimony(t.id);
  loadTestimonies();
}
async function remove(t) {
  await api.adminDeleteTestimony(t.id);
  loadTestimonies();
}
async function assignRole(u, role) {
  try {
    await api.adminAssignRole(u.id, role);
    loadUsers();
  } catch (e) {
    notice.value = e?.data?.message || "Could not update role.";
  }
}
async function toggleBlock(u) {
  try {
    await api.adminBlockUser(u.id, !u.is_blocked);
    loadUsers();
  } catch (e) {
    notice.value = e?.data?.message || "Could not update.";
  }
}
async function deleteUser(u) {
  if (!confirm(`Delete "${u.name}" and all their data? This cannot be undone.`)) return;
  try {
    await api.adminDeleteUser(u.id);
    loadUsers();
  } catch (e) {
    notice.value = e?.data?.message || "Could not delete user.";
  }
}

const resetLink = ref(null); // { name, url, expires_at }
async function forceReset(u) {
  try {
    const res = await api.adminForcePasswordReset(u.id);
    resetLink.value = { name: u.name, url: res.reset_url, expires_at: res.expires_at };
  } catch (e) {
    notice.value = e?.data?.message || "Could not generate reset link.";
  }
}

const showCreateUser  = ref(false);
const createUserForm  = ref({ name: "", email: "", role: "member", password: "" });
const creatingUser    = ref(false);
const createUserError = ref("");
const createUserLink  = ref(null); // reset URL when no password given

function openCreateUser() {
  createUserForm.value  = { name: "", email: "", role: "member", password: "" };
  createUserError.value = "";
  createUserLink.value  = null;
  showCreateUser.value  = true;
}

async function submitCreateUser() {
  createUserError.value = "";
  createUserLink.value  = null;
  creatingUser.value    = true;
  try {
    const payload = { ...createUserForm.value };
    if (!payload.password) delete payload.password;
    const res = await api.adminCreateUser(payload);
    if (res.reset_url) {
      createUserLink.value = { url: res.reset_url, expires_at: res.expires_at };
    } else {
      showCreateUser.value = false;
    }
    loadUsers();
  } catch (e) {
    createUserError.value = e?.data?.message || e?.data?.errors
      ? Object.values(e.data.errors).flat().join(" ")
      : "Could not create user.";
  } finally {
    creatingUser.value = false;
  }
}

// Download a CSV report. We fetch with the auth header, then save the Blob.
async function exportReport(type) {
  try {
    const blob = await api.adminExport(type);
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${type}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    notice.value = "Export failed.";
  }
}

const MUSIC_SOURCE_LABELS = {
  hymn_sung: "Sung hymn",
  hymn: "Instrumental",
  hymn_youtube: "Vocal hymn",
  suno: "AI-composed",
  youtube: "YouTube",
};
function musicSourceLabel(v) {
  return MUSIC_SOURCE_LABELS[v] || v;
}

function fmtDate(v) {
  return v ? new Date(v).toLocaleString() : "—";
}
function fmtMoney(amount, currency) {
  return `${Number(amount).toFixed(2)} ${(currency || "usd").toUpperCase()}`;
}

async function loadVoiceTrainingStatus() {
  if (!isAdminUser.value) return;
  try {
    voiceTraining.value = await api.adminVoiceTrainingStatus();
    voiceTrainingError.value = "";
  } catch (e) {
    voiceTrainingError.value = e?.data?.message || "Could not load voice training status.";
  }
}

function scheduleVoiceTrainingPoll() {
  clearInterval(voiceTrainingTimer);
  voiceTrainingTimer = setInterval(loadVoiceTrainingStatus, 30000);
}

async function startVoiceTraining(row) {
  if (!row?.can_start) return;
  voiceTrainingBusy.value = true;
  try {
    await api.adminVoiceTrainingStart({ user_id: row.user_id, lang: row.lang });
    notice.value = "Voice training queued. Status will update when the worker starts it.";
    setTimeout(loadVoiceTrainingStatus, 1200);
  } catch (e) {
    notice.value = e?.data?.message || "Could not start voice training.";
    await loadVoiceTrainingStatus();
  } finally {
    voiceTrainingBusy.value = false;
  }
}

function trainingBadgeClass(status) {
  if (status === "succeeded") return "ready";
  if (status === "running") return "active";
  if (status === "failed") return "failed";
  if (status === "stale") return "scheduled";
  return "pending";
}

function shortPath(path) {
  if (!path) return "—";
  const parts = String(path).split("/");
  return parts.slice(-3).join("/");
}

// ─── System Monitor ────────────────────────────────────────────────────────────

const updateStatus   = ref(null);   // { checked_at, checking, packages, services, git }
const updateBusy     = ref(false);  // true while an action is in flight
const updateNotice   = ref("");
let   updateTimer    = null;

// ─── Voicebox TTS Monitor ──────────────────────────────────────────────────────

const vbHealth   = ref(null);   // { status, model_loaded, gpu_type, vram_used_mb, ... }
const vbProfiles = ref([]);     // [{ id, name, voice_type, language, sample_count }]
const vbQueue    = ref(null);   // { status, generations, downloads }
const vbCopied   = ref("");     // id of the profile whose UUID was just copied
let   vbTimer    = null;

async function loadVoiceboxStatus() {
  try {
    const [h, p, q] = await Promise.all([
      api.adminVoiceboxHealth(),
      api.adminVoiceboxProfiles(),
      api.adminVoiceboxQueue(),
    ]);
    vbHealth.value   = h;
    vbProfiles.value = p.profiles || [];
    vbQueue.value    = q;
  } catch { /* silent — Voicebox may not be running yet */ }
}

function scheduleVoiceboxPoll() {
  clearInterval(vbTimer);
  vbTimer = setInterval(loadVoiceboxStatus, 30000);
}

async function copyProfileId(id) {
  try {
    await navigator.clipboard.writeText(id);
    vbCopied.value = id;
    setTimeout(() => { if (vbCopied.value === id) vbCopied.value = ""; }, 2000);
  } catch { /* clipboard permission denied */ }
}

function vbDotClass(status) {
  if (!status) return "svc-unknown";
  if (status === "ok" || status === "healthy") return "svc-active";
  if (status === "unreachable") return "svc-inactive";
  return "svc-unknown";
}

const SERVICE_LABELS = {
  "aivc-workers"       : "Workers (sermon/avatar)",
  "aivc-workers-music" : "Workers (music)",
  "aivc-bridge"        : "Bridge consumer",
  "aivc-queue"         : "Laravel queue",
  "aivc-scheduler"     : "Laravel scheduler",
  "aivc-tedim-api"     : "Tedim LLM API",
  "aivc-burmese-api"   : "Burmese LLM API",
  "aivc-mms-tts"       : "MMS TTS (Tedim/Burmese)",
  "redis-server"       : "Redis",
  "nginx"              : "Nginx",
};

function svcBadgeClass(status) {
  if (status === "active")   return "svc-active";
  if (status === "inactive") return "svc-inactive";
  return "svc-unknown";
}

function pkgHasUpdate(pkg) {
  return pkg?.update_available === true;
}

async function loadUpdateStatus() {
  try {
    updateStatus.value = await api.adminUpdateStatus();
  } catch { /* silent — status tab may not be open */ }
}

function scheduleUpdatePoll() {
  clearInterval(updateTimer);
  const interval = updateStatus.value?.checking ? 4000 : 30000;
  updateTimer = setInterval(async () => {
    await loadUpdateStatus();
    // Once checking finishes, switch to the slow 30-second polling cadence.
    if (!updateStatus.value?.checking) scheduleUpdatePoll();
  }, interval);
}

async function triggerCheck() {
  updateBusy.value = true;
  updateNotice.value = "";
  try {
    await api.adminUpdateCheck();
    await loadUpdateStatus();
    scheduleUpdatePoll();
    updateNotice.value = "Check queued — results will appear in a few seconds.";
  } catch (e) {
    updateNotice.value = e?.data?.message || "Check failed.";
  } finally {
    updateBusy.value = false;
  }
}

async function triggerGitPull() {
  if (!confirm("Pull the latest code from origin? The app will keep running while pulling.")) return;
  updateBusy.value = true;
  updateNotice.value = "";
  try {
    await api.adminGitPull();
    await loadUpdateStatus();
    scheduleUpdatePoll();
    updateNotice.value = "Git pull queued — this may take a few seconds.";
  } catch (e) {
    updateNotice.value = e?.data?.message || "Git pull failed.";
  } finally {
    updateBusy.value = false;
  }
}

async function installPackage(pkgName) {
  if (!confirm(`Upgrade ${pkgName} to the latest version?`)) return;
  updateBusy.value = true;
  updateNotice.value = "";
  try {
    await api.adminInstallPackage(pkgName);
    await loadUpdateStatus();
    scheduleUpdatePoll();
    updateNotice.value = `${pkgName} upgrade queued.`;
  } catch (e) {
    updateNotice.value = e?.data?.message || "Upgrade failed.";
  } finally {
    updateBusy.value = false;
  }
}

async function restartSvc(svcName) {
  if (!confirm(`Restart ${svcName}? It will be briefly unavailable.`)) return;
  updateBusy.value = true;
  updateNotice.value = "";
  try {
    await api.adminRestartService(svcName);
    updateNotice.value = `${svcName} restart queued — may take a few seconds.`;
    setTimeout(loadUpdateStatus, 5000);
  } catch (e) {
    updateNotice.value = e?.data?.message || "Restart failed.";
  } finally {
    updateBusy.value = false;
  }
}

// ─── Language Grammar Review ───────────────────────────────────────────────────

const grLang       = ref('td');
const grType       = ref('hymn_titles');
const grStatus     = ref('all');
const grPage       = ref(1);
const grData       = ref(null);
const grBusy       = ref(false);
const grError      = ref('');
const grEditing    = ref(null);
const grCorrection = ref('');

const grTypeOptions = {
  td: [
    { value: 'hymn_titles', label: 'Hymn Titles' },
    { value: 'hymn_lyrics', label: 'Hymn Lyrics' },
    { value: 'sermons',     label: 'Sermon Topics' },
  ],
  my: [
    { value: 'hymn_titles', label: 'Hymn Titles' },
    { value: 'hymn_lyrics', label: 'Hymn Lyrics' },
    { value: 'prayers',     label: 'Prayers' },
  ],
};

async function loadGrammarReview() {
  grBusy.value  = true;
  grError.value = '';
  try {
    grData.value = await api.adminGrammarReview({
      lang: grLang.value, type: grType.value, status: grStatus.value, page: grPage.value,
    });
  } catch (e) {
    grError.value = e?.data?.message || 'Could not load sentences.';
  } finally {
    grBusy.value = false;
  }
}

async function grApprove(key) {
  grBusy.value = true;
  try {
    await api.adminGrammarReviewSave({ key, action: 'approve' });
    await loadGrammarReview();
  } catch (e) {
    grError.value = e?.data?.error || e?.data?.message || 'Could not save.';
  } finally {
    grBusy.value = false;
  }
}

async function grSaveCorrection(key) {
  if (!grCorrection.value.trim()) return;
  grBusy.value = true;
  try {
    await api.adminGrammarReviewSave({ key, action: 'correct', correction: grCorrection.value });
    grEditing.value    = null;
    grCorrection.value = '';
    await loadGrammarReview();
  } catch (e) {
    grError.value = e?.data?.error || e?.data?.message || 'Could not save.';
  } finally {
    grBusy.value = false;
  }
}

async function grReset(key) {
  grBusy.value = true;
  try {
    await api.adminGrammarReviewSave({ key, action: 'reset' });
    await loadGrammarReview();
  } catch (e) {
    grError.value = e?.data?.error || e?.data?.message || 'Could not reset.';
  } finally {
    grBusy.value = false;
  }
}

function grToggleEdit(key, existing) {
  if (grEditing.value === key) {
    grEditing.value    = null;
    grCorrection.value = '';
  } else {
    grEditing.value    = key;
    grCorrection.value = existing || '';
  }
}

function grChangeLang(lang) {
  grLang.value = lang;
  grType.value = grTypeOptions[lang][0].value;
  grPage.value = 1;
  loadGrammarReview();
}

// If a token is already stored (e.g. a returning admin), try to enter directly.
onMounted(() => { if (api.hasToken()) enter(); });
onUnmounted(() => {
  clearInterval(updateTimer);
  clearInterval(vbTimer);
  clearInterval(voiceTrainingTimer);
});
</script>

<template>
  <main class="admin-shell">
    <header class="admin-head">
      <h1>Admin Console</h1>
      <div class="head-actions">
        <button v-if="authed" class="ghost" @click="logout">Sign out</button>
        <ThemeToggle />
      </div>
    </header>

    <!-- Login -->
    <section v-if="!authed" class="login">
      <input v-model="email" type="email" placeholder="Admin email" autocomplete="username" />
      <input v-model="password" type="password" placeholder="Password" autocomplete="current-password" @keyup.enter="login" />
      <button class="primary" @click="login">Sign in</button>
      <p v-if="loginError" class="error">{{ loginError }}</p>
    </section>

    <template v-else>
      <nav class="tabs">
        <template v-for="t in TABS" :key="t.name">
          <button v-if="t.can()" :class="{ active: tab === t.name }" @click="show(t.name)">{{ t.label }}</button>
        </template>
        <span v-if="currentUser" class="staff-role-badge" :class="'role-' + currentUser.role">
          {{ currentUser.role }}
        </span>
      </nav>

      <p v-if="notice" class="notice">{{ notice }}</p>

      <!-- Bulk-delete confirmation modal (shared by Services & Users tabs) -->
      <div v-if="bulkDelete" class="reset-modal">
        <p v-if="bulkDelete.type === 'services'">
          Are you sure you want to delete the {{ bulkDelete.count }} selected services?
          This action cannot be undone.
        </p>
        <p v-else>
          Are you sure you want to delete the {{ bulkDelete.count }} selected users?
          This will also delete all their associated data. This action cannot be undone.
        </p>
        <div style="display:flex;gap:.5rem;margin-top:.5rem;">
          <button class="chip danger" @click="confirmBulkDelete">Delete</button>
          <button class="chip" @click="bulkDelete = null">Cancel</button>
        </div>
      </div>

      <!-- Dashboard -->
      <section v-if="tab === 'dashboard' && stats" class="dash">
        <div class="cards">
          <div class="card">
            <span class="n">{{ stats.users.total }}</span>
            <span class="lbl">Total visitors</span>
            <small>{{ stats.users.registered }} registered · {{ stats.users.visitors }} anonymous</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.usage.active_now }}</span>
            <span class="lbl">Active now</span>
            <small>{{ stats.usage.today }} services today</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.usage.hours }}</span>
            <span class="lbl">Use hours</span>
            <small>across {{ stats.services.total }} services</small>
          </div>
          <div class="card">
            <span class="n">{{ fmtMoney(stats.offerings.total, stats.offerings.currency) }}</span>
            <span class="lbl">Given</span>
            <small>{{ stats.offerings.count }} gifts · {{ stats.offerings.donors }} donors</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.testimonies.pending }}</span>
            <span class="lbl">Testimonies pending</span>
            <small>{{ stats.testimonies.approved }} approved</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.prayer_requests.total }}</span>
            <span class="lbl">Prayer requests</span>
            <small>{{ stats.prayer_requests.today }} today</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.services.completed }}</span>
            <span class="lbl">Completed</span>
            <small>{{ stats.services.active }} active · {{ stats.services.scheduled }} scheduled</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.intercepts.total }}</span>
            <span class="lbl">Crisis intercepts</span>
            <small>{{ stats.intercepts.today }} today</small>
          </div>
          <div v-if="stats.musicgen?.total > 0 || stats.musicgen?.today > 0" class="card">
            <span class="n">{{ stats.musicgen?.total ?? 0 }}</span>
            <span class="lbl">MusicGen generations</span>
            <small>{{ stats.musicgen?.today ?? 0 }} today · ~{{ stats.musicgen?.audio_minutes ?? 0 }} min audio</small>
          </div>
          <div v-if="stats.features?.special_day" class="card">
            <span class="n">{{ stats.features.special_day.total }}</span>
            <span class="lbl">Special Day MV</span>
            <small>{{ stats.features.special_day.today }} today</small>
          </div>
          <div v-if="stats.features?.live_sticker" class="card">
            <span class="n">{{ stats.features.live_sticker.total }}</span>
            <span class="lbl">Live Stickers</span>
            <small>{{ stats.features.live_sticker.today }} today</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.users.admins }}</span>
            <span class="lbl">Admins</span>
            <small>of {{ stats.users.total }} accounts</small>
          </div>
        </div>

        <div v-if="isAdminUser" class="exports">
          <span class="exports-label">Export report</span>
          <button class="chip" @click="exportReport('donations')">Donations CSV</button>
          <button class="chip" @click="exportReport('users')">Users CSV</button>
          <button class="chip" @click="exportReport('testimonies')">Testimonies CSV</button>
        </div>
      </section>

      <!-- Services -->
      <div v-else-if="tab === 'services'" class="table-wrap">
        <div v-if="can('services.delete')" class="table-head">
          <button class="chip danger" :disabled="!selectedServiceIds.length" @click="askBulkDeleteServices">
            Delete Selected ({{ selectedServiceIds.length }})
          </button>
        </div>
        <table class="grid">
          <thead><tr>
            <th><input type="checkbox" :checked="allServicesSelected" @change="toggleAllServices($event.target.checked)" /></th>
            <th>#</th><th>User</th><th>Mood</th><th>Sermon topic</th><th>Music</th><th>Status</th><th>Segments</th><th></th>
          </tr></thead>
          <tbody>
            <template v-for="s in services" :key="s.id">
              <tr>
                <td><input type="checkbox" :value="s.id" v-model="selectedServiceIds" /></td>
                <td>{{ s.id }}</td>
                <td>{{ s.user?.name }}<br /><small>{{ s.user?.email }}</small></td>
                <td>
                  <span v-if="s.mood" class="badge pending">{{ s.mood }}</span>
                  <span v-else class="muted-cell">—</span>
                </td>
                <td><small>{{ s.sermon_topic || "—" }}</small></td>
                <td>
                  <span v-if="s.music_source" class="badge music-source">{{ musicSourceLabel(s.music_source) }}</span>
                  <span v-else class="muted-cell">—</span>
                </td>
                <td><span class="badge" :class="s.status">{{ s.status }}</span></td>
                <td>{{ s.assets_count }}</td>
                <td>
                  <button class="link" @click="shareService(s)">Share</button>
                  <button v-if="can('services.retry')"  class="link" @click="retry(s)">Retry</button>
                  <button v-if="can('services.delete')" class="link danger" @click="deleteService(s)">Delete</button>
                </td>
              </tr>
              <!-- Inline share popover — appears below the row when native share is unavailable -->
              <tr v-if="sharePopover && sharePopover.token === s.session_token" class="share-row">
                <td colspan="9">
                  <div class="share-popover">
                    <span class="share-link-text">{{ sharePopover.link }}</span>
                    <div class="share-btns">
                      <button class="chip" @click="copyLink(sharePopover.link)">Copy link</button>
                      <button class="chip" @click="shareVia('whatsapp', sharePopover.link, s.mood)">WhatsApp</button>
                      <button class="chip" @click="shareVia('telegram', sharePopover.link, s.mood)">Telegram</button>
                      <button class="chip" @click="shareVia('email', sharePopover.link, s.mood)">Email</button>
                      <button class="chip" @click="shareVia('twitter', sharePopover.link, s.mood)">X / Twitter</button>
                      <button class="chip ghost" @click="sharePopover = null">Close</button>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Donors -->
      <div v-else-if="tab === 'donors'" class="table-wrap">
        <div class="table-head">
          <h2>Donors</h2>
          <button v-if="isAdminUser" class="chip" @click="exportReport('donations')">Export CSV</button>
        </div>
        <table class="grid">
          <thead><tr><th>Donor</th><th>Given</th><th>Gifts</th><th>Testimony</th><th>Prayer note</th></tr></thead>
          <tbody>
            <tr v-for="d in donors" :key="d.user_id">
              <td>{{ d.name }}<br /><small>{{ d.email || "anonymous" }}</small></td>
              <td class="strong">{{ fmtMoney(d.total, d.currency) }}</td>
              <td>{{ d.gifts }}</td>
              <td class="content">{{ d.testimony || "—" }}</td>
              <td class="content muted">{{ d.prayer || "—" }}</td>
            </tr>
            <tr v-if="!donors.length"><td colspan="5" class="empty">No donations yet.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Testimonies -->
      <div v-else-if="tab === 'testimonies'" class="table-wrap">
        <div class="table-head">
          <h2>Testimonies</h2>
          <button v-if="isAdminUser" class="chip" @click="exportReport('testimonies')">Export CSV</button>
        </div>
        <table class="grid">
          <thead><tr><th>By</th><th>Mood</th><th>Content</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in testimonies" :key="t.id">
              <td>{{ t.user?.name || "—" }}<br /><small>{{ t.source }}</small></td>
              <td>
                <template v-if="t.custom_moods && t.custom_moods.length">
                  <span v-for="w in t.custom_moods" :key="w" class="badge custom-mood">{{ w }}</span>
                </template>
                <span v-else class="muted-cell">—</span>
              </td>
              <td class="content">{{ t.content }}</td>
              <td><span class="badge" :class="t.approved ? 'ready' : 'pending'">{{ t.approved ? "approved" : "pending" }}</span></td>
              <td>
                <button v-if="!t.approved && can('testimonies.approve')" class="link" @click="approve(t)">Approve</button>
                <button v-if="can('testimonies.delete')" class="link danger" @click="remove(t)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Users -->
      <div v-else-if="tab === 'users'" class="table-wrap">
        <div class="table-head">
          <h2>Users &amp; visitors</h2>
          <div style="display:flex;gap:.5rem;">
            <button v-if="isAdminUser" class="chip primary-chip" @click="openCreateUser">+ Add User</button>
            <button v-if="isAdminUser" class="chip" @click="exportReport('users')">Export CSV</button>
          </div>
        </div>

        <!-- Create user form -->
        <div v-if="isAdminUser && showCreateUser" class="create-user-panel">
          <h3 class="cu-title">Add New User</h3>

          <!-- First-login link (shown after successful create without password) -->
          <div v-if="createUserLink" class="cu-success">
            <p>User created. Share this first-login link — it expires {{ fmtDate(createUserLink.expires_at) }}.</p>
            <input class="reset-url-input" :value="createUserLink.url" readonly @click="$event.target.select()" />
            <div style="display:flex;gap:.5rem;margin-top:.5rem;">
              <button class="chip" @click="navigator.clipboard?.writeText(createUserLink.url)">Copy link</button>
              <button class="chip" @click="showCreateUser = false; createUserLink = null">Done</button>
            </div>
          </div>

          <form v-else @submit.prevent="submitCreateUser" class="cu-form">
            <div class="cu-row">
              <label class="cu-label">Name</label>
              <input v-model="createUserForm.name" type="text" class="cu-input" placeholder="Full name" required />
            </div>
            <div class="cu-row">
              <label class="cu-label">Email</label>
              <input v-model="createUserForm.email" type="email" class="cu-input" placeholder="email@example.com" required />
            </div>
            <div class="cu-row">
              <label class="cu-label">Role</label>
              <select v-model="createUserForm.role" class="cu-input">
                <option value="member">Member</option>
                <option value="presenter">Presenter</option>
                <option value="moderator">Moderator</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="cu-row">
              <label class="cu-label">Password</label>
              <input v-model="createUserForm.password" type="password" class="cu-input"
                     placeholder="Leave blank to send a first-login link" autocomplete="new-password" />
            </div>
            <p v-if="createUserError" class="cu-error">{{ createUserError }}</p>
            <div class="cu-actions">
              <button type="submit" class="chip primary-chip" :disabled="creatingUser">
                {{ creatingUser ? "Creating…" : "Create User" }}
              </button>
              <button type="button" class="chip" @click="showCreateUser = false">Cancel</button>
            </div>
          </form>
        </div>

        <!-- Reset-link modal -->
        <div v-if="resetLink" class="reset-modal">
          <p><strong>Reset link for {{ resetLink.name }}</strong></p>
          <p class="reset-note">Share this link with the user. Expires {{ fmtDate(resetLink.expires_at) }}.</p>
          <input class="reset-url-input" :value="resetLink.url" readonly @click="$event.target.select()" />
          <div style="display:flex;gap:.5rem;margin-top:.5rem;">
            <button class="chip" @click="navigator.clipboard?.writeText(resetLink.url)">Copy</button>
            <button class="chip" @click="resetLink = null">Dismiss</button>
          </div>
        </div>

        <div v-if="isAdminUser" class="table-head">
          <button class="chip danger" :disabled="!selectedUserIds.length" @click="askBulkDeleteUsers">
            Delete Selected ({{ selectedUserIds.length }})
          </button>
        </div>
        <table class="grid">
          <thead>
            <tr>
              <th><input type="checkbox" :checked="allUsersSelected" @change="toggleAllUsers($event.target.checked)" /></th>
              <th>Name</th><th>Email</th><th>Role</th><th>Last mood</th>
              <th>Visits</th><th>Last seen</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in users" :key="u.id" :class="{ 'row-blocked': u.is_blocked }">
              <td><input type="checkbox" :value="u.id" v-model="selectedUserIds" /></td>
              <td>
                {{ u.name }}
                <span v-if="u.is_guest" class="tag">visitor</span>
                <span v-if="u.is_blocked" class="tag tag-blocked">blocked</span>
              </td>
              <td><small>{{ u.email || "—" }}</small></td>
              <td>
                <select
                  v-if="isAdminUser && !u.is_guest"
                  class="role-select"
                  :value="u.role"
                  @change="assignRole(u, $event.target.value)"
                >
                  <option value="member">Member</option>
                  <option value="presenter">Presenter</option>
                  <option value="moderator">Moderator</option>
                  <option value="admin">Admin</option>
                </select>
                <span v-else class="tag">{{ u.is_guest ? 'guest' : u.role }}</span>
              </td>
              <td>
                <span v-if="u.last_mood" class="badge pending">{{ u.last_mood }}</span>
                <span v-else class="muted-cell">—</span>
              </td>
              <td>{{ u.visits }}</td>
              <td><small>{{ fmtDate(u.last_seen) }}</small></td>
              <td class="actions">
                <button v-if="isAdminUser && !u.is_guest" class="link" @click="forceReset(u)">Reset pw</button>
                <button v-if="isAdminUser" class="link" @click="toggleBlock(u)">{{ u.is_blocked ? "Unblock" : "Block" }}</button>
                <button v-if="isAdminUser" class="link danger" @click="deleteUser(u)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Lyrics Manager -->
      <section v-else-if="tab === 'lyrics' && can('lyrics.manage')">
        <AdminLyricsManager />
      </section>

      <!-- Vocabulary Manager -->
      <section v-else-if="tab === 'vocabulary' && can('vocabulary.manage')">
        <VocabularyManager />
      </section>

      <!-- Prayer Requests -->
      <div v-else-if="tab === 'prayer'" class="table-wrap">
        <div class="table-head">
          <h2>Prayer Requests</h2>
        </div>
        <table class="grid">
          <thead><tr><th>From</th><th>Mood</th><th>Their word</th><th>Prayer</th><th>Submitted</th></tr></thead>
          <tbody>
            <tr v-for="p in prayerRequests" :key="p.id">
              <td>
                {{ p.user_name }}
                <span v-if="p.is_guest" class="tag">visitor</span>
                <br /><small>{{ p.user_email || "—" }}</small>
              </td>
              <td><span class="badge pending">{{ p.mood }}</span></td>
              <td><span v-if="p.custom_mood" class="badge custom-mood">{{ p.custom_mood }}</span><span v-else class="muted-cell">—</span></td>
              <td class="content">{{ p.prayer }}</td>
              <td><small>{{ fmtDate(p.submitted) }}</small></td>
            </tr>
            <tr v-if="!prayerRequests.length"><td colspan="5" class="empty">No prayer requests yet.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- AI Music Pool -->
      <div v-else-if="tab === 'music-pool'" class="table-wrap">
        <div class="table-head">
          <h2>AI Music Pool (music_tracks)</h2>
          <button class="chip" :disabled="musicPoolBusy" @click="loadMusicTracks">Refresh</button>
        </div>

        <div class="pool-filters">
          <input v-model="musicPoolFilters.mood" class="pool-input" placeholder="Filter mood" @keyup.enter="applyMusicPoolFilters" />
          <select v-model="musicPoolFilters.language" class="pool-input" @change="applyMusicPoolFilters">
            <option v-for="lang in musicPoolLanguages" :key="lang.value || 'all'" :value="lang.value">{{ lang.label }}</option>
          </select>
          <input v-model="musicPoolFilters.search" class="pool-input" placeholder="Search title/provider/storage" @keyup.enter="applyMusicPoolFilters" />
          <button class="chip" :disabled="musicPoolBusy" @click="applyMusicPoolFilters">Apply</button>
        </div>

        <div v-if="isAdminUser" class="pool-editor">
          <h3>{{ musicTrackEditingId ? `Edit Track #${musicTrackEditingId}` : 'Add Track' }}</h3>
          <div class="pool-grid">
            <input v-model="musicTrackForm.mood" class="pool-input" placeholder="Mood (required)" />
            <select v-model="musicTrackForm.language" class="pool-input">
              <option value="en">English</option>
              <option value="my">Myanmar</option>
              <option value="td">Tedim</option>
            </select>
            <select v-model="musicTrackForm.source" class="pool-input">
              <option value="suno">Suno (Vocal)</option>
              <option value="musicgen">MusicGen (Inst)</option>
            </select>
            <input v-model="musicTrackForm.provider_ref" class="pool-input" placeholder="Provider ref / Suno task id (required)" />
            <input v-model="musicTrackForm.storage_key" class="pool-input" placeholder="Storage key (required)" />
            <input v-model="musicTrackForm.title" class="pool-input" placeholder="Title (optional)" />
          </div>
          <textarea v-model="musicTrackForm.lyrics" class="pool-lyrics" rows="5" placeholder="Lyrics (optional)"></textarea>
          <div class="pool-actions">
            <button class="chip primary-chip" :disabled="musicPoolBusy" @click="saveMusicTrack">{{ musicTrackEditingId ? 'Update' : 'Create' }}</button>
            <button class="chip" :disabled="musicPoolBusy" @click="resetMusicTrackForm">Clear</button>
          </div>
        </div>

        <table class="grid">
          <thead><tr><th>#</th><th>Source</th><th>Mood</th><th>Lang</th><th>Title</th><th>Provider Ref</th><th>Storage Key</th><th>Lyrics</th><th>Created</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in musicTracks" :key="t.id">
              <td>{{ t.id }}</td>
              <td><span class="badge" :class="t.source === 'musicgen' ? 'custom-mood' : 'music-source'">{{ t.source || 'suno' }}</span></td>
              <td><span class="badge pending">{{ t.mood }}</span></td>
              <td>{{ t.language }}</td>
              <td>{{ t.title || '—' }}</td>
              <td><small>{{ t.provider_ref }}</small></td>
              <td><small>{{ t.storage_key }}</small></td>
              <td class="content">{{ t.lyrics || '—' }}</td>
              <td><small>{{ fmtDate(t.created_at) }}</small></td>
              <td class="actions">
                <button v-if="isAdminUser" class="link" @click="editMusicTrack(t)">Edit</button>
                <button v-if="isAdminUser" class="link danger" @click="removeMusicTrack(t)">Delete</button>
              </td>
            </tr>
            <tr v-if="!musicTracks.length"><td colspan="10" class="empty">No AI music pool tracks found.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Settings -->
      <section v-else-if="tab === 'settings'" class="settings">
        <p v-if="!isAdminUser" class="notice">View only — contact an admin to change settings.</p>
        <div class="setting-block">
          <h2>Moods</h2>
          <p class="setting-desc">
            The feelings a worshipper can choose at intake. Each one shapes the whole
            service — the prayer and message tone, the music, and the hymn chosen.
            Add your own; new moods take effect immediately.
          </p>
          <div v-if="settings" class="mood-editor">
            <span v-for="m in settings.moods" :key="m" class="mood-chip">
              {{ m }}
              <button
                type="button"
                class="chip-x"
                :disabled="savingSettings || settingsReadOnly || settings.moods.length <= 1"
                aria-label="Remove mood"
                @click="removeMood(m)"
              >×</button>
            </span>
          </div>
          <div v-if="settings" class="mood-add">
            <input
              v-model="newMood"
              type="text"
              class="mood-input"
              placeholder="Add a mood (e.g. Lonely)"
              :disabled="savingSettings || settingsReadOnly"
              @keyup.enter="addMood"
            />
            <button type="button" class="primary add-btn" :disabled="savingSettings || settingsReadOnly || !newMood.trim()" @click="addMood">
              Add
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
      </div>

      <div class="setting-block">
        <h2>Premium GPU (RunPod)</h2>
        <p class="setting-desc">
          Route AI text generation to your dedicated RunPod GPU endpoint instead of the standard tier. Requires <code>RUNPOD_BASE_URL</code>, <code>RUNPOD_API_KEY</code>, and <code>RUNPOD_LLM_MODEL</code> in <code>workers/.env</code>.
        </p>
        <div v-if="settings" class="choice-row">
          <button
            type="button"
            class="choice"
            :class="{ active: settings.runpod_enabled === '1' || settings.runpod_enabled === true }"
            :disabled="savingSettings || settingsReadOnly"
            @click="setRunpodEnabled(true)"
          >
            <strong>Enabled</strong>
            <span>Use premium RunPod GPU.</span>
          </button>
          <button
            type="button"
            class="choice"
            :class="{ active: settings.runpod_enabled !== '1' && settings.runpod_enabled !== true }"
            :disabled="savingSettings || settingsReadOnly"
            @click="setRunpodEnabled(false)"
          >
            <strong>Disabled</strong>
            <span>Use standard OpenRouter tier.</span>
          </button>
        </div>
        <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Music sources</h2>
          <p class="setting-desc">
            Which music options appear in the intake form. Turn one off to hide it from
            worshippers. At least one must stay on.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              v-for="s in musicSourceOptions"
              :key="s.value"
              type="button"
              class="choice"
              :class="{ active: settings.music_sources.includes(s.value) }"
              :disabled="savingSettings || settingsReadOnly"
              @click="toggleMusicSource(s.value)"
            >
              <strong>{{ s.label }} <span class="state">{{ settings.music_sources.includes(s.value) ? "On" : "Off" }}</span></strong>
              <span>{{ s.hint }}</span>
            </button>
          </div>
          <template v-if="settings">
            <p class="setting-desc" style="margin-top:1.1rem">Default selection in the intake form</p>
            <div class="choice-row">
              <button
                v-for="s in musicSourceOptions.filter(o => settings.music_sources.includes(o.value))"
                :key="s.value"
                type="button"
                class="choice"
                :class="{ active: settings.default_music_source === s.value }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setDefaultMusicSource(s.value)"
              >
                <strong>{{ s.label }}</strong>
                <span>{{ settings.default_music_source === s.value ? "Pre-selected ✓" : "Set as default" }}</span>
              </button>
            </div>
          </template>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Scheduling</h2>
          <p class="setting-desc">
            When on, worshippers can pick a future time for their service. When off, every
            service begins right away.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: settings.scheduling_enabled === true }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setScheduling(true)"
            >
              <strong>Allow scheduling</strong>
              <span>Show the "schedule it" option at intake.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.scheduling_enabled === false }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setScheduling(false)"
            >
              <strong>Begin now only</strong>
              <span>Hide scheduling; services start immediately.</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Countdown content</h2>
          <p class="setting-desc">
            Short cards shown while the service is preparing. Testimonies come only from
            approved moderation; custom banners are plain text with an optional source.
          </p>
          <template v-if="settings">
            <div class="choice-row">
              <button
                type="button"
                class="choice"
                :class="{ active: settings.countdown_content_enabled === true }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setCountdownEnabled(true)"
              >
                <strong>Show cards</strong>
                <span>Use the countdown space for testimony and encouragement.</span>
              </button>
              <button
                type="button"
                class="choice"
                :class="{ active: settings.countdown_content_enabled === false }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setCountdownEnabled(false)"
              >
                <strong>Hide cards</strong>
                <span>Keep the countdown screen minimal.</span>
              </button>
            </div>

            <p class="setting-desc" style="margin-top:1rem">Source</p>
            <div class="choice-row">
              <button
                v-for="source in countdownSourceOptions"
                :key="source.value"
                type="button"
                class="choice"
                :class="{ active: settings.countdown_content_source === source.value }"
                :disabled="savingSettings || settingsReadOnly || settings.countdown_content_enabled === false"
                @click="setCountdownSource(source.value)"
              >
                <strong>{{ source.label }}</strong>
                <span>{{ source.hint }}</span>
              </button>
            </div>

            <p class="setting-desc" style="margin-top:1rem">Custom banners</p>
            <div class="banner-list">
              <div v-for="(banner, i) in settings.countdown_banners" :key="i" class="banner-row">
                <textarea
                  :value="banner.text"
                  class="banner-text"
                  maxlength="300"
                  rows="2"
                  :disabled="savingSettings || settingsReadOnly"
                  @change="updateCountdownBanner(i, 'text', $event.target.value)"
                ></textarea>
                <div class="banner-meta-row">
                  <input
                    :value="banner.source"
                    class="banner-source"
                    maxlength="80"
                    placeholder="Source label, e.g. Psalm 46:10"
                    :disabled="savingSettings || settingsReadOnly"
                    @change="updateCountdownBanner(i, 'source', $event.target.value)"
                  />
                  <button
                    type="button"
                    class="chip danger-chip"
                    :disabled="savingSettings || settingsReadOnly || settings.countdown_banners.length <= 1"
                    @click="removeCountdownBanner(i)"
                  >Remove</button>
                </div>
              </div>
            </div>
            <div class="banner-add">
              <textarea
                v-model="newCountdownBanner.text"
                class="banner-text"
                maxlength="300"
                rows="2"
                placeholder="Add a short Christian encouragement or church announcement"
                :disabled="savingSettings || settingsReadOnly"
              ></textarea>
              <div class="banner-meta-row">
                <input
                  v-model="newCountdownBanner.source"
                  class="banner-source"
                  maxlength="80"
                  placeholder="Optional source"
                  :disabled="savingSettings || settingsReadOnly"
                  @keyup.enter="addCountdownBanner"
                />
                <button
                  type="button"
                  class="primary add-btn"
                  :disabled="savingSettings || settingsReadOnly || !newCountdownBanner.text.trim()"
                  @click="addCountdownBanner"
                >Add banner</button>
              </div>
            </div>
          </template>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Content Filter</h2>
          <p class="setting-desc">
            The YouTube content filter now has its own
            <button type="button" class="link-btn" @click="show('content-filter')">Content Filter</button>
            tab, where keywords are grouped into categories (Other Religions, Occult, Secular Music,
            Sermon-exclude, …) and you can export/import the whole list.
          </p>
        </div>

        <div class="setting-block">
          <h2>Service languages</h2>
          <p class="setting-desc">
            Which language tabs appear in the intake form. Worshippers can only pick a
            language whose tab is shown. English is on by default; enable Myanmar and
            Zolai once the corresponding LLM workers are running. Keep at least one on.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              v-for="lang in serviceLanguages"
              :key="lang.key"
              type="button"
              class="choice"
              :class="{ active: settings[lang.key] === true }"
              :disabled="savingSettings || settingsReadOnly"
              @click="toggleServiceLanguage(lang.key)"
            >
              <strong>{{ lang.label }} <span class="state">{{ settings[lang.key] ? "On" : "Off" }}</span></strong>
              <span>{{ lang.hint }}</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Narration voice</h2>
          <p class="setting-desc">
            How the spoken segments — opening prayer, scripture, message, benediction —
            are read aloud. Set a separate voice for each service language.
          </p>
          <template v-if="settings">
            <!-- English -->
            <p class="setting-desc" style="margin-top:1rem"><strong>English</strong></p>
            <div class="choice-row">
              <button
                v-for="m in narrationModes"
                :key="m.value"
                type="button"
                class="choice"
                :class="{ active: (settings.narration_mode_en || 'edge_tts') === m.value }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setNarrationMode('en', m.value)"
              >
                <strong>{{ m.label }}</strong>
                <span>{{ m.hint }}</span>
              </button>
            </div>
            <template v-if="settings.narration_mode_en === 'edge_tts'">
              <p class="setting-desc" style="margin-top:0.75rem">Voice</p>
              <div class="choice-row">
                <button
                  v-for="v in edgeTtsVoices"
                  :key="v.value"
                  type="button"
                  class="choice"
                  :class="{ active: (settings.edge_tts_voice || 'en-US-AriaNeural') === v.value }"
                  :disabled="savingSettings || settingsReadOnly"
                  @click="setEdgeTtsVoice(v.value)"
                >
                  <strong>{{ v.label }}</strong>
                  <span>{{ v.gender }} · {{ v.accent }}</span>
                </button>
              </div>
            </template>
            <template v-if="settings.narration_mode_en === 'voicebox'">
              <p class="setting-desc" style="margin-top:0.75rem">Engine</p>
              <div class="choice-row">
                <button
                  v-for="e in voiceboxEngines"
                  :key="e.value"
                  type="button"
                  class="choice"
                  :class="{ active: (settings.voicebox_engine || 'qwen') === e.value }"
                  :disabled="savingSettings || settingsReadOnly"
                  @click="setVoiceboxEngine(e.value)"
                >
                  <strong>{{ e.label }}</strong>
                  <span>{{ e.hint }}</span>
                </button>
              </div>
              <p class="setting-desc" style="margin-top:0.75rem">
                Set <code>VOICEBOX_PROFILE_ID_FEMALE</code> and <code>VOICEBOX_PROFILE_ID_MALE</code>
                in <code>workers/.env</code> with the profile UUIDs shown in the Voicebox panel below.
              </p>
            </template>

            <!-- Myanmar -->
            <p class="setting-desc" style="margin-top:1.5rem"><strong>Myanmar (မြန်မာ)</strong></p>
            <div class="choice-row">
              <button
                v-for="m in narrationModesMY"
                :key="m.value"
                type="button"
                class="choice"
                :class="{ active: (settings.narration_mode_my || 'mms_tts') === m.value }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setNarrationMode('my', m.value)"
              >
                <strong>{{ m.label }}</strong>
                <span>{{ m.hint }}</span>
              </button>
            </div>

            <!-- Tedim (Zolai) -->
            <p class="setting-desc" style="margin-top:1.5rem"><strong>Tedim (Zolai)</strong></p>
            <div class="choice-row">
              <button
                v-for="m in narrationModesTD"
                :key="m.value"
                type="button"
                class="choice"
                :class="{ active: (settings.narration_mode_td || 'mms_tts') === m.value }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setNarrationMode('td', m.value)"
              >
                <strong>{{ m.label }}</strong>
                <span>{{ m.hint }}</span>
              </button>
            </div>
          </template>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Music reuse</h2>
          <p class="setting-desc">
            When on, a worshipper who is new to a mood hears a song already composed
            for it — instant and free. Returning worshippers always get a fresh song
            for a mood they've had before. When off, every service composes anew.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: settings.music_reuse === true }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setMusicReuse(true)"
            >
              <strong>Reuse from pool</strong>
              <span>Serve an existing mood song when one exists.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.music_reuse === false }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setMusicReuse(false)"
            >
              <strong>Always compose</strong>
              <span>Generate a brand-new song for every service.</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Text highlighting</h2>
          <p class="setting-desc">
            Word-by-word highlighting while narration plays in the service player.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: settings.text_highlight_enabled === true }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setTextHighlightEnabled(true)"
            >
              <strong>Enabled</strong>
              <span>Highlight each word as narration plays.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.text_highlight_enabled === false }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setTextHighlightEnabled(false)"
            >
              <strong>Disabled</strong>
              <span>Show plain text without moving highlights.</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Orchestration mode</h2>
          <p class="setting-desc">
            <strong>Pipeline</strong> runs each service through a fixed, hard-coded
            sequence of steps — fast, predictable, and cheap.<br>
            <strong>AI Agent</strong> hands the conductor role to Claude: it decides
            which segments to generate, in what order, and whether to retry poor output.
            Agent mode uses more LLM tokens per service but can adapt to context.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: (settings.orchestration_mode || 'pipeline') === 'pipeline' }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setOrchestrationMode('pipeline')"
            >
              <strong>Pipeline <span class="state">{{ (settings.orchestration_mode || 'pipeline') === 'pipeline' ? 'Active ✓' : '' }}</span></strong>
              <span>Hard-coded flow — fast, low cost, always consistent.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.orchestration_mode === 'agent' }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setOrchestrationMode('agent')"
            >
              <strong>AI Agent <span class="state">{{ settings.orchestration_mode === 'agent' ? 'Active ✓' : '' }}</span></strong>
              <span>An LLM reasons about segment order, retries bad output, adapts to context.</span>
            </button>
          </div>

          <!-- Agent provider selector — only shown when agent mode is active -->
          <template v-if="settings && settings.orchestration_mode === 'agent'">
            <p class="setting-desc" style="margin-top:1.1rem">
              <strong>Agent model</strong> — which AI conducts the service in agent mode.
              Both use your existing OpenRouter key; no extra API key needed.
            </p>
            <div class="choice-row">
              <button
                type="button"
                class="choice"
                :class="{ active: (settings.agent_provider || 'claude') === 'claude' }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setAgentProvider('claude')"
              >
                <strong>Claude <span class="state">{{ (settings.agent_provider || 'claude') === 'claude' ? 'Active ✓' : '' }}</span></strong>
                <span>Anthropic claude-sonnet-4-6 via OpenRouter — best reasoning and instruction-following.</span>
              </button>
              <button
                type="button"
                class="choice"
                :class="{ active: settings.agent_provider === 'gemini' }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setAgentProvider('gemini')"
              >
                <strong>Gemini <span class="state">{{ settings.agent_provider === 'gemini' ? 'Active ✓' : '' }}</span></strong>
                <span>Google Gemini 2.5 Flash via OpenRouter — fast and cost-efficient.</span>
              </button>
              <button
                type="button"
                class="choice"
                :class="{ active: settings.agent_provider === 'chatgpt' }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setAgentProvider('chatgpt')"
              >
                <strong>ChatGPT <span class="state">{{ settings.agent_provider === 'chatgpt' ? 'Active ✓' : '' }}</span></strong>
                <span>OpenAI GPT-4o via OpenRouter — strong general reasoning.</span>
              </button>
            </div>
          </template>

          <p v-else-if="!settings" class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Avatar videos (D-ID)</h2>
          <p class="setting-desc">
            Talking-head avatar videos (powered by the D-ID cloud API) for the sermon,
            opening prayer, and benediction. Disable when your D-ID subscription is inactive
            to fall back to text and TTS narration without touching any config files.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: settings.avatar_enabled === true }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setAvatarEnabled(true)"
            >
              <strong>Enabled</strong>
              <span>Render talking-head videos via D-ID for each spoken segment.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.avatar_enabled === false }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setAvatarEnabled(false)"
            >
              <strong>Disabled</strong>
              <span>Skip D-ID avatar rendering — segments stay as text and audio only.</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Avatar videos (local engine)</h2>
          <p class="setting-desc">
            Self-hosted open-source avatar engine (e.g. LivePortrait) that lip-syncs to the
            generated narration audio — no cloud subscription needed. Requires
            LOCAL_AVATAR_URL and base images configured on the worker. When enabled
            alongside D-ID, the local engine takes priority.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: settings.local_avatar_enabled === true }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setLocalAvatarEnabled(true)"
            >
              <strong>Enabled</strong>
              <span>Render talking-head videos via the local engine, lip-synced to TTS audio.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.local_avatar_enabled === false }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setLocalAvatarEnabled(false)"
            >
              <strong>Disabled</strong>
              <span>Skip local avatar rendering.</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Audio storage</h2>
          <p class="setting-desc">
            Where the worker keeps generated songs and narration. S3 is recommended in
            production so the reusable song library outlives restarts.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              v-for="b in storageBackends"
              :key="b.value"
              type="button"
              class="choice"
              :class="{ active: settings.storage_backend === b.value }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setStorageBackend(b.value)"
            >
              <strong>{{ b.label }}</strong>
              <span>{{ b.hint }}</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>AI chord detection</h2>
          <p class="setting-desc">
            When enabled, the Lyrics editor can offer AI-assisted chord suggestions
            (manual ChordPro entry always works). Set the model id/endpoint below, or
            leave blank to fall back to the <code>AI_CHORD_MODEL</code> env var.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: settings.ai_chords_enabled === true }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setAiChordsEnabled(true)"
            >
              <strong>Enabled</strong>
              <span>Show the AI chord-detection action in the song editor.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.ai_chords_enabled === false }"
              :disabled="savingSettings || settingsReadOnly"
              @click="setAiChordsEnabled(false)"
            >
              <strong>Disabled</strong>
              <span>Manual chord entry only.</span>
            </button>
          </div>
          <div v-if="settings" class="ai-model-row">
            <input
              :value="settings.ai_chords_model"
              class="pool-input"
              type="text"
              placeholder="AI chord model id / endpoint (optional)"
              :disabled="savingSettings || settingsReadOnly"
              @change="setAiChordsModel($event.target.value)"
            />
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Change password</h2>
          <p class="setting-desc">Update the password for your admin account.</p>
          <div class="pw-form">
            <input v-model="pwCurrent" type="password" placeholder="Current password" autocomplete="current-password" :disabled="pwSaving" />
            <input v-model="pwNew" type="password" placeholder="New password" autocomplete="new-password" :disabled="pwSaving" />
            <input v-model="pwConfirm" type="password" placeholder="Confirm new password" autocomplete="new-password" :disabled="pwSaving" @keyup.enter="changePassword" />
            <p v-if="pwError" class="pw-error">{{ pwError }}</p>
            <p v-if="pwOk" class="pw-ok">{{ pwOk }}</p>
            <button class="primary pw-btn" :disabled="pwSaving || !pwCurrent || !pwNew || !pwConfirm" @click="changePassword">
              {{ pwSaving ? "Saving…" : "Update password" }}
            </button>
          </div>
        </div>
      </section>

      <!-- Dedicated Bible page: every Online Bible reader setting in one place —
           the per-version feature matrix (show/hide tabs + enable/disable each
           feature button) plus the reader voice / highlight / background music. -->
      <section v-else-if="tab === 'bible'" class="settings">
        <div class="setting-block">
          <h2>Bible reader features</h2>
          <p class="setting-desc">
            Control the online Bible reader per translation. <strong>Show tab</strong>
            removes a version from the reader entirely (and blocks its API access);
            the remaining columns enable or disable each feature button —
            🔊 Listen, ✨ Highlight, 📖 Continuous, 🎵 Music, 📋 Select, playback
            Speed, Aa text Size and Color themes. Everything is on by default.
          </p>
          <template v-if="settings && settings.bible_features">
            <div class="bible-matrix-wrap">
              <table class="bible-matrix">
                <thead>
                  <tr>
                    <th class="bm-ver">Version</th>
                    <th v-for="c in BIBLE_FEATURE_COLS" :key="c.key">{{ c.label }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="v in BIBLE_VERSIONS" :key="v.code">
                    <th class="bm-ver" scope="row">{{ v.label }}</th>
                    <td v-for="c in BIBLE_FEATURE_COLS" :key="c.key">
                      <label class="bm-toggle" :title="`${v.label} — ${c.label}`">
                        <input
                          type="checkbox"
                          :checked="bibleFeature(v.code, c.key)"
                          :disabled="savingSettings || settingsReadOnly"
                          @change="toggleBibleFeature(v.code, c.key)"
                        />
                      </label>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Bible reader voice</h2>
          <p class="setting-desc">
            The voice used by the online Bible reader's <strong>🔊 Listen</strong> button,
            per translation. Independent of the live-service narration. English &amp;
            KJV support every provider; Burmese &amp; Tedim add the native local
            MMS-TTS voice; Hebrew uses its he-IL voice; the Chin/Zo Bibles have no
            native voice, so they read phonetically with the English Edge voice — or
            set any translation to <strong>Off</strong> to disable narration there.
            Hover a button for details.
          </p>
          <template v-if="settings">
            <div class="voice-rows">
              <div v-for="l in bibleVoiceLangs" :key="l.code" class="voice-row">
                <span class="voice-row-lang">{{ l.label }}</span>
                <div class="voice-row-modes">
                  <button
                    v-for="m in l.modes"
                    :key="m.value"
                    type="button"
                    class="voice-chip"
                    :class="{ active: settings['bible_narration_mode_' + l.code] === m.value }"
                    :disabled="savingSettings || settingsReadOnly"
                    :title="m.hint"
                    @click="setBibleNarrationMode(l.code, m.value)"
                  >{{ m.label }}</button>
                </div>
              </div>
            </div>
            <p class="setting-desc" style="margin-top:0.6rem">
              The English Edge / Voicebox voice follows the live-service voice picked in Settings.
            </p>

            <!-- Highlight default -->
            <p class="setting-desc" style="margin-top:1.5rem"><strong>Verse highlighting default</strong></p>
            <p class="setting-desc">
              The starting value for verse highlighting. Each reader can flip it
              with the <strong>✨ Highlight</strong> switch in the Bible reader —
              their choice is remembered on their own device and overrides this default.
            </p>
            <div class="choice-row">
              <button
                type="button"
                class="choice"
                :class="{ active: settings.bible_text_highlight_enabled === true }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setBibleTextHighlight(true)"
              >
                <strong>On by default</strong>
                <span>New readers see verses highlighted as the chapter is read aloud.</span>
              </button>
              <button
                type="button"
                class="choice"
                :class="{ active: settings.bible_text_highlight_enabled === false }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setBibleTextHighlight(false)"
              >
                <strong>Off by default</strong>
                <span>New readers start without highlighting (they can still turn it on).</span>
              </button>
            </div>

            <!-- Background music during narration -->
            <p class="setting-desc" style="margin-top:1.5rem"><strong>Background music</strong></p>
            <p class="setting-desc">
              Play a soft instrumental track behind the spoken narration. Choose a
              fixed uploaded track, or let AI compose one per chapter — matched to
              the passage's mood and the reader's time of day (morning/evening/night).
            </p>
            <div class="choice-row">
              <button
                type="button"
                class="choice"
                :class="{ active: (settings.bible_bg_music_mode || 'off') === 'off' }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setBibleBgMusicMode('off')"
              >
                <strong>Off</strong>
                <span>Voice only — no background music.</span>
              </button>
              <button
                type="button"
                class="choice"
                :class="{ active: settings.bible_bg_music_mode === 'static' }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setBibleBgMusicMode('static')"
              >
                <strong>Static MP3</strong>
                <span>Loop one fixed track you provide below.</span>
              </button>
              <button
                type="button"
                class="choice"
                :class="{ active: settings.bible_bg_music_mode === 'ai' }"
                :disabled="savingSettings || settingsReadOnly"
                @click="setBibleBgMusicMode('ai')"
              >
                <strong>AI generated</strong>
                <span>Compose per chapter mood + time of day.</span>
              </button>
            </div>

            <!-- Static mode: the fixed track URL -->
            <template v-if="settings.bible_bg_music_mode === 'static'">
              <p class="setting-desc" style="margin-top:1rem">
                Direct audio URL (.mp3/.ogg) served over HTTPS with CORS allowed.
              </p>
              <div class="bgm-row">
                <input
                  v-model="settings.bible_bg_music_url"
                  type="url"
                  class="pool-input"
                  placeholder="https://example.com/ambient.mp3"
                  :disabled="savingSettings || settingsReadOnly"
                  @keyup.enter="saveBibleBgMusicUrl"
                />
                <button
                  type="button"
                  class="choice bgm-save"
                  :disabled="savingSettings || settingsReadOnly"
                  @click="saveBibleBgMusicUrl"
                >
                  Save
                </button>
              </div>
            </template>

            <!-- AI mode: which engine generates the loop -->
            <template v-if="settings.bible_bg_music_mode === 'ai'">
              <p class="setting-desc" style="margin-top:1rem">
                The first reader to open a chapter at a given time of day triggers a
                one-off generation (a few minutes on CPU); it's cached and reused for
                everyone after that, so they hear music on a later visit.
              </p>
              <div class="choice-row">
                <button
                  type="button"
                  class="choice"
                  :class="{ active: (settings.bible_bg_music_engine || 'musicgen') === 'musicgen' }"
                  :disabled="savingSettings || settingsReadOnly"
                  @click="setBibleBgMusicEngine('musicgen')"
                >
                  <strong>MusicGen (CPU)</strong>
                  <span>Local, free. Slower on a small box.</span>
                </button>
                <button
                  type="button"
                  class="choice"
                  :class="{ active: settings.bible_bg_music_engine === 'local_ai' }"
                  :disabled="savingSettings || settingsReadOnly"
                  @click="setBibleBgMusicEngine('local_ai')"
                >
                  <strong>Local AI (GPU)</strong>
                  <span>Same model, uses CUDA when available.</span>
                </button>
              </div>

              <!-- Pre-generate the whole matrix so readers never wait -->
              <p class="setting-desc" style="margin-top:1rem">
                Generate every track ahead of time (6 moods × 4 times of day) so
                readers always hear music instantly instead of waiting for the
                first on-demand build.
                <template v-if="bibleBgStatus">
                  <strong>{{ bibleBgStatus.ready }}/{{ bibleBgStatus.total }} ready.</strong>
                </template>
              </p>
              <div class="bgm-row">
                <button
                  type="button"
                  class="choice bgm-save"
                  :disabled="bibleBgPregenLoading || settingsReadOnly"
                  @click="pregenerateBibleBg"
                >
                  {{ bibleBgPregenLoading ? "Queuing…" : "Generate all tracks" }}
                </button>
                <button
                  type="button"
                  class="choice bgm-save"
                  :disabled="bibleBgPregenLoading"
                  @click="refreshBibleBgStatus"
                >
                  Refresh status
                </button>
              </div>
            </template>

            <!-- Volume applies to both static and AI modes -->
            <label
              v-if="settings.bible_bg_music_mode && settings.bible_bg_music_mode !== 'off'"
              class="setting-desc"
              style="display:block;margin-top:0.75rem"
            >
              Volume: {{ Math.round((settings.bible_bg_music_volume ?? 0.15) * 100) }}%
              <input
                type="range"
                min="0"
                max="1"
                step="0.05"
                style="width:100%"
                :value="settings.bible_bg_music_volume ?? 0.15"
                :disabled="savingSettings || settingsReadOnly"
                @change="setBibleBgMusicVolume($event.target.value)"
              />
            </label>
          </template>
          <p v-else class="setting-desc">Loading…</p>
        </div>
      </section>

      <section v-else-if="tab === 'content-filter'" class="settings">
        <div class="setting-block">
          <div class="cf-head">
            <div>
              <h2>Content Filter</h2>
              <p class="setting-desc">
                Keywords rejected from YouTube results so non-Christian or non-worship content stays
                out of services (Baptist / Assemblies of God context). Any video whose <strong>title</strong>
                or <strong>channel name</strong> contains a keyword is skipped — even if the search query
                already says "Christian". Works like a firewall: <strong>Block</strong> categories reject
                matching videos, while <strong>Allow</strong> categories rescue a video even if a block
                keyword also matches (allow wins over block — use it for trusted channels, artists, or
                ministries). Each category's <strong>scope</strong> controls whether it applies to
                the worship/music search, the sermon search, or both. Changes take effect within 5 minutes
                for running workers.
              </p>
            </div>
            <div class="cf-actions">
              <button type="button" class="chip" :disabled="cfBusy" @click="cfExport('json')">Export JSON</button>
              <button type="button" class="chip" :disabled="cfBusy" @click="cfExport('csv')">Export CSV</button>
              <button type="button" class="chip" :disabled="cfBusy" @click="cfFileInput?.click()">Restore JSON…</button>
              <input ref="cfFileInput" type="file" accept="application/json,.json" class="cf-file" @change="cfImport" />
            </div>
          </div>

          <p v-if="!cfCategories.length" class="setting-desc">Loading…</p>

          <div v-for="cat in cfCategories" :key="cat.id" class="cf-category"
               :class="{ 'cf-allow': (cat.type || 'block') === 'allow' }">
            <div class="cf-cat-head">
              <div class="cf-cat-title">
                <h3>{{ cat.label }}</h3>
                <span class="cf-badge" :class="(cat.type || 'block') === 'allow' ? 'cf-badge-allow' : 'cf-badge-block'">
                  {{ cfTypeLabels[cat.type || 'block'] }}
                </span>
                <span class="cf-count">{{ cat.keywords.length }}</span>
              </div>
              <div class="cf-cat-controls">
                <label class="cf-scope">
                  Mode:
                  <select :value="cat.type || 'block'" :disabled="cfBusy" @change="cfChangeType(cat, $event.target.value)">
                    <option v-for="t in cfTypes" :key="t" :value="t">{{ cfTypeLabels[t] || t }}</option>
                  </select>
                </label>
                <label class="cf-scope">
                  Applies to:
                  <select :value="cat.scope" :disabled="cfBusy" @change="cfChangeScope(cat, $event.target.value)">
                    <option v-for="s in cfScopes" :key="s" :value="s">{{ cfScopeLabels[s] || s }}</option>
                  </select>
                </label>
                <button type="button" class="chip-x cf-del-cat" :disabled="cfBusy"
                        title="Delete category" @click="cfDeleteCategory(cat)">Delete</button>
              </div>
            </div>
            <p v-if="cat.description" class="setting-desc cf-cat-desc">{{ cat.description }}</p>

            <div class="mood-editor">
              <span v-for="kw in cat.keywords" :key="kw" class="mood-chip"
                    :class="(cat.type || 'block') === 'allow' ? 'allow-chip' : 'filter-chip'">
                {{ kw }}
                <button type="button" class="chip-x" :disabled="cfBusy"
                        aria-label="Remove keyword" @click="cfRemoveKeyword(cat, kw)">×</button>
              </span>
              <span v-if="!cat.keywords.length" class="cf-empty">No keywords yet.</span>
            </div>

            <div class="mood-add">
              <input
                :value="cfNewKeyword[cat.id] || ''"
                type="text"
                class="mood-input"
                :placeholder="(cat.type || 'block') === 'allow' ? 'Add a trusted keyword to always allow (e.g. hillsong)' : 'Add a keyword to reject (e.g. shaman)'"
                :disabled="cfBusy"
                @input="cfNewKeyword = { ...cfNewKeyword, [cat.id]: $event.target.value }"
                @keyup.enter="cfAddKeyword(cat)"
              />
              <button type="button" class="primary add-btn"
                      :disabled="cfBusy || !(cfNewKeyword[cat.id] || '').trim()"
                      @click="cfAddKeyword(cat)">Add</button>
            </div>
          </div>

          <div class="cf-new-category">
            <h3>Add a category</h3>
            <div class="cf-new-row">
              <input v-model="cfNewCategory.label" type="text" class="mood-input"
                     placeholder="Category name (e.g. Profanity)" :disabled="cfBusy" />
              <select v-model="cfNewCategory.type" :disabled="cfBusy" title="Block or Allow">
                <option v-for="t in cfTypes" :key="t" :value="t">{{ cfTypeLabels[t] || t }}</option>
              </select>
              <select v-model="cfNewCategory.scope" :disabled="cfBusy">
                <option v-for="s in cfScopes" :key="s" :value="s">{{ cfScopeLabels[s] || s }}</option>
              </select>
              <button type="button" class="primary add-btn"
                      :disabled="cfBusy || !cfNewCategory.label.trim()" @click="cfAddCategory">Add category</button>
            </div>
            <input v-model="cfNewCategory.description" type="text" class="mood-input cf-desc-input"
                   placeholder="Optional description" :disabled="cfBusy" />
          </div>
        </div>
      </section>

      <section v-else-if="tab === 'voice-studio'" class="settings">
        <VoiceStudio />
      </section>

      <section v-else-if="tab === 'voice-training' && isAdminUser" class="voice-training-page">
        <div class="voice-train-panel">
          <div class="voice-train-head">
            <div>
              <h2>Voice Training</h2>
              <p v-if="voiceTraining?.checked_at" class="sys-meta">
                Last checked: {{ fmtDate(voiceTraining.checked_at) }}
              </p>
              <p v-else class="sys-meta">Loading training status.</p>
            </div>
            <button class="chip" :disabled="voiceTrainingBusy" @click="loadVoiceTrainingStatus">Refresh</button>
          </div>
          <p v-if="voiceTrainingError" class="sys-notice">{{ voiceTrainingError }}</p>

          <template v-if="voiceTraining">
            <div class="voice-train-summary">
              <span class="svc-status" :class="voiceTraining.load?.server_free ? 'svc-active' : 'svc-inactive'">
                Load {{ voiceTraining.load?.current ?? "n/a" }} / {{ voiceTraining.load?.max }}
              </span>
              <span class="badge" :class="voiceTraining.window?.inside ? 'ready' : 'scheduled'">
                {{ voiceTraining.window?.start }}-{{ voiceTraining.window?.end }} server
              </span>
              <span class="badge" :class="voiceTraining.enabled ? 'ready' : 'failed'">
                {{ voiceTraining.enabled ? "enabled" : "disabled" }}
              </span>
              <span class="badge" :class="voiceTraining.running_jobs?.length ? 'active' : 'pending'">
                {{ voiceTraining.running_jobs?.length || 0 }} running
              </span>
            </div>

            <div v-if="voiceTraining.active_models" class="voice-train-models">
              <span v-for="(model, lang) in voiceTraining.active_models" :key="lang">
                <strong>{{ lang.toUpperCase() }}</strong>
                {{ model.exists ? shortPath(model.path) : "stock MMS" }}
              </span>
            </div>

            <div v-if="voiceTraining.datasets?.length" class="pkg-table-wrap">
              <table class="pkg-table voice-train-table">
                <thead>
                  <tr>
                    <th>Dataset</th>
                    <th>Clips</th>
                    <th>Status</th>
                    <th>Last Success</th>
                    <th>Model</th>
                    <th>Reason</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in voiceTraining.datasets" :key="`${row.user_id}-${row.lang}`">
                    <td>
                      <strong>{{ row.label }}</strong><br />
                      <small>{{ row.user_name || `User ${row.user_id}` }} · {{ row.user_email || "no email" }}</small>
                    </td>
                    <td>
                      {{ row.recordings }}
                      <small v-if="row.last_success_recordings"> · +{{ row.new_recordings_since_success }}</small>
                    </td>
                    <td>
                      <span class="badge" :class="trainingBadgeClass(row.status)">{{ row.status }}</span>
                      <small v-if="row.running"> pid {{ row.pid }}</small>
                    </td>
                    <td><small>{{ fmtDate(row.last_success_at) }}</small></td>
                    <td><code class="voice-train-path">{{ shortPath(row.model_dir) }}</code></td>
                    <td class="voice-train-reason">{{ row.reason }}</td>
                    <td>
                      <button
                        v-if="isAdminUser"
                        class="chip"
                        :class="row.can_start && voiceTraining.load?.server_free ? 'primary-chip' : 'disabled-chip'"
                        :disabled="voiceTrainingBusy || !row.can_start || !voiceTraining.load?.server_free"
                        :title="row.can_start ? 'Start training now' : row.reason"
                        @click="startVoiceTraining(row)"
                      >
                        {{ row.can_start && voiceTraining.load?.server_free ? (voiceTrainingBusy ? "Queued…" : "Start now") : "Waiting" }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="sys-empty">No Voice Studio datasets yet.</p>
          </template>
        </div>
      </section>

      <!-- Ads management -->
      <section v-else-if="tab === 'special-day-mv' && isAdminUser">
        <FathersDayManager />
      </section>

      <section v-else-if="tab === 'live-sticker' && isAdminUser">
        <StickerManager />
      </section>

      <section v-else-if="tab === 'special-sundays' && can('special_sundays.view')">
        <SpecialSundaysManager :can-manage="can('special_sundays.manage')" />
      </section>

      <section v-else-if="tab === 'ads'" class="settings">
        <AdsManager :settings="settings" :saving="savingSettings" :read-only="settingsReadOnly" @save-setting="saveSetting" />
      </section>

      <section v-else-if="tab === 'permissions' && isAdminUser" class="settings">
        <PermissionsMatrix :initial-data="permissionsData" />
      </section>

      <!-- System Monitor -->
      <section v-else-if="tab === 'system' && isAdminUser" class="sys-monitor">

        <!-- Header row -->
        <div class="sys-header">
          <div>
            <h2 class="sys-title">System Monitor</h2>
            <p v-if="updateStatus?.checked_at" class="sys-meta">
              Last checked: {{ new Date(updateStatus.checked_at).toLocaleString() }}
            </p>
            <p v-else class="sys-meta">No data yet — run a check to populate this panel.</p>
          </div>
          <div v-if="isAdminUser" class="sys-header-actions">
            <button class="chip" :disabled="updateBusy || updateStatus?.checking" @click="triggerCheck">
              {{ updateStatus?.checking ? "Checking…" : "Refresh now" }}
            </button>
          </div>
        </div>

        <p v-if="updateNotice" class="sys-notice">{{ updateNotice }}</p>

        <!-- Service health -->
        <div class="sys-block">
          <h3 class="sys-block-title">Service Health</h3>
          <p class="sys-block-desc">
            Live status of all AIVC systemd units and supporting services. Restart
            requires <code>sudo systemctl restart</code> — configure sudoers first
            (see <code>RestartService.php</code> docblock).
          </p>
          <div v-if="updateStatus?.services" class="svc-grid">
            <div
              v-for="(status, name) in updateStatus.services"
              :key="name"
              class="svc-card"
            >
              <span class="svc-dot" :class="svcBadgeClass(status)"></span>
              <div class="svc-info">
                <strong class="svc-name">{{ SERVICE_LABELS[name] || name }}</strong>
                <code class="svc-unit">{{ name }}</code>
              </div>
              <span class="svc-status" :class="svcBadgeClass(status)">{{ status }}</span>
              <button
                v-if="isAdminUser && ['aivc-workers','aivc-workers-music','aivc-bridge','aivc-queue','aivc-scheduler','aivc-tedim-api','aivc-burmese-api','aivc-mms-tts'].includes(name)"
                class="chip svc-restart-btn"
                :disabled="updateBusy"
                @click="restartSvc(name)"
              >Restart</button>
            </div>
          </div>
          <p v-else class="sys-empty">Run a check to see service statuses.</p>
        </div>

        <!-- Voicebox TTS container -->
        <div class="sys-block">
          <h3 class="sys-block-title">Voicebox TTS</h3>
          <p class="sys-block-desc">
            Local voice-cloning container at <code>127.0.0.1:17493</code>. Used when narration
            mode is set to Voicebox. Profiles listed here provide the UUIDs needed for
            <code>VOICEBOX_PROFILE_ID_FEMALE</code> / <code>_MALE</code> in <code>workers/.env</code>.
          </p>

          <!-- Health row -->
          <div class="svc-card" style="margin-bottom:0.75rem">
            <span class="svc-dot" :class="vbDotClass(vbHealth?.status)"></span>
            <div class="svc-info">
              <strong class="svc-name">Voicebox</strong>
              <code class="svc-unit">docker · port 17493</code>
            </div>
            <span class="svc-status" :class="vbDotClass(vbHealth?.status)">
              {{ vbHealth ? (vbHealth.status === 'unreachable' ? 'unreachable' : 'running') : '—' }}
            </span>
            <button class="chip" @click="loadVoiceboxStatus">Refresh</button>
          </div>

          <!-- Model / GPU details -->
          <div v-if="vbHealth && vbHealth.status !== 'unreachable'" class="git-panel" style="margin-bottom:0.75rem">
            <div class="git-row">
              <span class="git-label">Model loaded</span>
              <span class="git-val">{{ vbHealth.model_loaded ? '✓ loaded' : 'not loaded' }}</span>
            </div>
            <div class="git-row">
              <span class="git-label">GPU</span>
              <span class="git-val">{{ vbHealth.gpu_type || (vbHealth.gpu_available ? 'available' : 'CPU only') }}</span>
            </div>
            <div v-if="vbHealth.vram_used_mb" class="git-row">
              <span class="git-label">VRAM used</span>
              <span class="git-val">{{ Math.round(vbHealth.vram_used_mb) }} MB</span>
            </div>
            <div class="git-row">
              <span class="git-label">Backend</span>
              <span class="git-val">{{ vbHealth.backend_type || '—' }} / {{ vbHealth.backend_variant || '—' }}</span>
            </div>
            <div v-if="vbQueue" class="git-row">
              <span class="git-label">Queue</span>
              <span class="git-val">{{ vbQueue.generations }} generating · {{ vbQueue.downloads }} downloading</span>
            </div>
          </div>

          <!-- Profile list -->
          <template v-if="vbProfiles.length">
            <p class="setting-desc" style="margin-bottom:0.4rem">Voice profiles</p>
            <p v-if="vbProfiles.some((p) => !p.sample_count)" class="sys-notice">
              One or more profiles has no sample recordings. Add at least one sample in Voicebox before enabling voice-cloned narration.
            </p>
            <table class="pkg-table" style="margin-bottom:0.5rem">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Lang</th>
                  <th>Samples</th>
                  <th>Profile ID</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in vbProfiles" :key="p.id">
                  <td class="pkg-name">{{ p.name }}</td>
                  <td><code>{{ p.voice_type }}</code></td>
                  <td><code>{{ p.language }}</code></td>
                  <td>{{ p.sample_count }}</td>
                  <td>
                    <code class="git-val" style="font-size:0.72rem">{{ p.id }}</code>
                    <button
                      class="chip"
                      style="margin-left:0.4rem;font-size:0.7rem;padding:0.1rem 0.45rem"
                      @click="copyProfileId(p.id)"
                    >{{ vbCopied === p.id ? 'Copied!' : 'Copy' }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>
          <p v-else-if="vbHealth && vbHealth.status !== 'unreachable'" class="sys-empty">
            No voice profiles yet. Open <code>http://localhost:17493</code> in a browser to create them.
          </p>
          <p v-else class="sys-empty">Start the Voicebox container to see profiles.</p>
        </div>

        <!-- Git / App version -->
        <div class="sys-block">
          <h3 class="sys-block-title">App Version (Git)</h3>
          <div v-if="updateStatus?.git" class="git-panel">
            <div class="git-row">
              <span class="git-label">Branch</span>
              <code class="git-val">{{ updateStatus.git.branch }}</code>
            </div>
            <div class="git-row">
              <span class="git-label">Commit</span>
              <code class="git-val">{{ updateStatus.git.commit }}</code>
            </div>
            <div class="git-row">
              <span class="git-label">Message</span>
              <span class="git-val">{{ updateStatus.git.message || "—" }}</span>
            </div>
            <div class="git-row">
              <span class="git-label">Behind origin</span>
              <span class="git-val">
                <span v-if="updateStatus.git.behind > 0" class="update-badge">
                  {{ updateStatus.git.behind }} commit{{ updateStatus.git.behind !== 1 ? 's' : '' }} behind
                </span>
                <span v-else class="up-to-date">Up to date</span>
              </span>
            </div>
            <div v-if="updateStatus.git.pull_output" class="git-row">
              <span class="git-label">Last pull</span>
              <code class="git-val git-output">{{ updateStatus.git.pull_output }}</code>
            </div>
            <button v-if="isAdminUser" class="chip primary-chip git-pull-btn" :disabled="updateBusy" @click="triggerGitPull">
              Pull latest from origin
            </button>
          </div>
          <p v-else class="sys-empty">Run a check to see git info.</p>
        </div>

        <!-- Python packages -->
        <div class="sys-block">
          <h3 class="sys-block-title">Python Packages</h3>
          <p class="sys-block-desc">
            Installed versions in <code>workers/.venv</code> versus the latest release
            on PyPI. Upgrade runs <code>pip install --upgrade</code> in the background.
          </p>
          <div v-if="updateStatus?.packages && Object.keys(updateStatus.packages).length" class="pkg-table-wrap">
            <table class="pkg-table">
              <thead>
                <tr>
                  <th>Package</th>
                  <th>Installed</th>
                  <th>Latest (PyPI)</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(info, name) in updateStatus.packages"
                  :key="name"
                  :class="{ 'pkg-row-update': pkgHasUpdate(info) }"
                >
                  <td class="pkg-name">{{ name }}</td>
                  <td><code>{{ info.current }}</code></td>
                  <td>
                    <code v-if="info.latest">{{ info.latest }}</code>
                    <span v-else class="sys-muted">—</span>
                    <span v-if="pkgHasUpdate(info)" class="update-badge pkg-badge">update</span>
                  </td>
                  <td>
                    <button
                      v-if="isAdminUser && pkgHasUpdate(info)"
                      class="chip primary-chip pkg-upgrade-btn"
                      :disabled="updateBusy"
                      @click="installPackage(name)"
                    >Upgrade</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="sys-empty">Run a check to see package versions.</p>
        </div>

      </section>

      <!-- Language Grammar Review -->
      <section v-else-if="tab === 'grammar-review'" class="gr-section">
        <div class="gr-header">
          <h2 class="gr-title">Language Grammar Review</h2>
          <p class="gr-desc">Review Tedim and Myanmar sentences used by the system. Approve correct text or supply a correction.</p>
        </div>

        <div class="gr-controls">
          <div class="gr-control-group">
            <span class="gr-label">Language</span>
            <div class="gr-pills">
              <button :class="['gr-pill', { active: grLang === 'td' }]" @click="grChangeLang('td')">Tedim (Zolai)</button>
              <button :class="['gr-pill', { active: grLang === 'my' }]" @click="grChangeLang('my')">Myanmar</button>
            </div>
          </div>
          <div class="gr-control-group">
            <span class="gr-label">Type</span>
            <div class="gr-pills">
              <button
                v-for="t in grTypeOptions[grLang]" :key="t.value"
                :class="['gr-pill', { active: grType === t.value }]"
                @click="grType = t.value; grPage = 1; loadGrammarReview()"
              >{{ t.label }}</button>
            </div>
          </div>
          <div class="gr-control-group">
            <span class="gr-label">Show</span>
            <div class="gr-pills">
              <button v-for="s in ['all','pending','approved','corrected']" :key="s"
                :class="['gr-pill', { active: grStatus === s }]"
                @click="grStatus = s; grPage = 1; loadGrammarReview()"
              >{{ s.charAt(0).toUpperCase() + s.slice(1) }}</button>
            </div>
          </div>
        </div>

        <p v-if="grError" class="error">{{ grError }}</p>
        <p v-if="grBusy && !grData" class="gr-loading">Loading…</p>

        <template v-if="grData">
          <p class="gr-stats">
            {{ grData.total }} sentence{{ grData.total !== 1 ? 's' : '' }}
            · page {{ grData.page }} of {{ Math.ceil(grData.total / grData.per_page) || 1 }}
          </p>

          <div class="gr-list">
            <div v-for="s in grData.sentences" :key="s.key" class="gr-item" :class="'gr-' + s.status">
              <div class="gr-item-head">
                <span class="gr-cat">{{ s.category }}</span>
                <span class="badge" :class="s.status === 'approved' ? 'ready' : s.status === 'corrected' ? 'active' : 'pending'">
                  {{ s.status }}
                </span>
              </div>
              <div class="gr-text">{{ s.text }}</div>
              <div v-if="s.text_en" class="gr-text-en">{{ s.text_en }}</div>
              <div v-if="s.extra" class="gr-extra">{{ s.extra }}</div>
              <div v-if="s.correction" class="gr-correction-display">
                <span class="gr-correction-label">Correction:</span> {{ s.correction }}
              </div>
              <div class="gr-actions">
                <button class="chip primary-chip" :disabled="grBusy" @click="grApprove(s.key)">✓ Approve</button>
                <button class="chip" :disabled="grBusy" @click="grToggleEdit(s.key, s.correction)">
                  {{ grEditing === s.key ? 'Cancel' : s.correction ? 'Edit correction' : 'Correct' }}
                </button>
                <button v-if="s.status !== 'pending'" class="chip danger-chip" :disabled="grBusy" @click="grReset(s.key)">Reset</button>
              </div>
              <div v-if="grEditing === s.key" class="gr-edit-row">
                <textarea
                  v-model="grCorrection"
                  class="gr-correction-input"
                  :rows="s.type === 'hymn_lyrics' ? 8 : 3"
                  placeholder="Enter the correct text…"
                  :disabled="grBusy"
                ></textarea>
                <button class="chip primary-chip" :disabled="grBusy || !grCorrection.trim()" @click="grSaveCorrection(s.key)">Save correction</button>
              </div>
            </div>
            <p v-if="!grData.sentences.length" class="gr-empty">No sentences match this filter.</p>
          </div>

          <div v-if="grData.total > grData.per_page" class="gr-pagination">
            <button class="chip" :disabled="grPage <= 1 || grBusy" @click="grPage--; loadGrammarReview()">← Prev</button>
            <span class="gr-page-info">Page {{ grPage }} of {{ Math.ceil(grData.total / grData.per_page) }}</span>
            <button class="chip" :disabled="grPage >= Math.ceil(grData.total / grData.per_page) || grBusy" @click="grPage++; loadGrammarReview()">Next →</button>
          </div>
        </template>
      </section>

    </template>
  </main>
</template>

<style scoped>
.admin-shell { max-width: 1000px; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
.admin-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.75rem; }
.admin-head h1 { font-size: 1.5rem; margin: 0; letter-spacing: -0.02em; }
.head-actions { display: flex; align-items: center; gap: 0.6rem; }
.ghost { border: 1px solid var(--border); background: var(--surface); color: var(--text-muted); border-radius: var(--radius-sm); padding: 0.5rem 0.8rem; cursor: pointer; }
.ghost:hover { color: var(--text); border-color: var(--border-strong); }

.login { max-width: 340px; display: flex; flex-direction: column; gap: 0.6rem; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; box-shadow: var(--shadow); }
.login input { padding: 0.65rem 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; background: var(--surface); color: var(--text); }
.login input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.primary { padding: 0.7rem; border: 0; border-radius: var(--radius-sm); background: var(--primary); color: var(--on-primary); font-weight: 600; cursor: pointer; }
.primary:hover { background: var(--primary-hover); }
.error { color: var(--danger); }
.notice { background: var(--primary-soft); color: var(--primary-hover); padding: 0.55rem 0.85rem; border-radius: var(--radius-sm); }

.tabs { display: flex; gap: 0.25rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; align-items: flex-end; }
.tabs button { padding: 0.6rem 0.9rem; border: 0; background: transparent; cursor: pointer; color: var(--text-muted); border-bottom: 2px solid transparent; font: inherit; }
.tabs button:hover { color: var(--text); }
.tabs button.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 500; }
.staff-role-badge {
  margin-left: auto; margin-bottom: 0.25rem; padding: 0.15rem 0.65rem;
  border-radius: 999px; font-size: 0.72rem; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.04em;
}
.role-admin     { background: #fee2e2; color: #b91c1c; }
.role-moderator { background: #fef3c7; color: #92400e; }
.role-presenter { background: #ede9fe; color: #5b21b6; }

.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.15rem; box-shadow: var(--shadow-sm); }
.card .n { font-size: 1.7rem; font-weight: 700; color: var(--text); letter-spacing: -0.02em; line-height: 1.1; }
.card .lbl { font-size: 0.9rem; color: var(--text); font-weight: 500; margin-top: 0.2rem; }
.card small { color: var(--text-muted); font-size: 0.78rem; }

.voice-train-panel {
  margin-top: 1.75rem; background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1rem 1.1rem; box-shadow: var(--shadow-sm);
}
.voice-training-page .voice-train-panel { margin-top: 0; }
.voice-train-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.75rem; }
.voice-train-head h2 { font-size: 1.05rem; margin: 0 0 0.2rem; }
.voice-train-summary, .voice-train-models { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; }
.voice-train-models { color: var(--text-muted); font-size: 0.82rem; }
.voice-train-models span { background: var(--surface-2); border: 1px solid var(--border); border-radius: 999px; padding: 0.2rem 0.55rem; }
.voice-train-table td { vertical-align: top; }
.voice-train-path { font-size: 0.72rem; color: var(--text-muted); }
.voice-train-reason { min-width: 180px; color: var(--text-muted); font-size: 0.82rem; line-height: 1.4; }

.exports { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 1.75rem; }
.exports-label { color: var(--text-muted); font-size: 0.85rem; margin-right: 0.25rem; }
.chip { border: 1px solid var(--border); background: var(--surface); color: var(--text); border-radius: 999px; padding: 0.4rem 0.85rem; font-size: 0.85rem; cursor: pointer; }
.chip:hover { border-color: var(--primary); color: var(--primary-hover); }
.chip:disabled { opacity: 0.55; cursor: default; pointer-events: none; }
.pool-filters { display: grid; grid-template-columns: 1fr 180px 1.3fr auto; gap: 0.6rem; margin: 0.9rem 0 1rem; }
.pool-editor { background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 0.85rem; margin-bottom: 1rem; }
.pool-editor h3 { margin: 0 0 0.65rem; font-size: 0.95rem; }
.pool-grid { display: grid; grid-template-columns: 1fr 120px 140px 1.4fr 1.4fr 1fr; gap: 0.55rem; }
.pool-input { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); font: inherit; }
.pool-input:focus, .pool-lyrics:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-soft); }
.bgm-row { display: flex; gap: 0.5rem; align-items: stretch; }
.bgm-row .pool-input { flex: 1; }
.bgm-save { flex: 0 0 auto; padding: 0.45rem 1.1rem; cursor: pointer; }

/* Per-version Bible feature matrix: a scrollable grid of show/enable checkboxes. */
.bible-matrix-wrap { overflow-x: auto; margin-top: 0.5rem; }
.bible-matrix { border-collapse: collapse; width: 100%; font-size: 0.85rem; }
.bible-matrix th, .bible-matrix td { padding: 0.4rem 0.6rem; text-align: center; border-bottom: 1px solid var(--border); }
.bible-matrix thead th { font-weight: 600; color: var(--text-muted); white-space: nowrap; }
.bible-matrix th.bm-ver { text-align: left; white-space: nowrap; font-weight: 600; }
.bible-matrix tbody tr:hover { background: var(--surface); }
.bm-toggle { display: inline-flex; cursor: pointer; }
.bm-toggle input { width: 1.05rem; height: 1.05rem; cursor: pointer; accent-color: var(--primary); }
.bm-toggle input:disabled { cursor: default; }

/* Compact per-translation "Listen" voice rows: label + a wrap of mode chips. */
.voice-rows { display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.6rem; }
.voice-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; padding: 0.35rem 0; border-bottom: 1px solid var(--border); }
.voice-row-lang { flex: 0 0 140px; font-weight: 600; font-size: 0.88rem; }
.voice-row-modes { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.voice-chip {
  padding: 0.3rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.8rem;
  cursor: pointer;
  white-space: nowrap;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.voice-chip:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.voice-chip.active { border-color: var(--primary); color: var(--primary); background: var(--primary-soft, rgba(99,179,237,0.12)); font-weight: 600; }
.voice-chip:disabled { opacity: 0.6; cursor: default; }
@media (max-width: 560px) { .voice-row-lang { flex-basis: 100%; } }
.pool-lyrics { width: 100%; margin-top: 0.55rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); padding: 0.55rem 0.6rem; font: inherit; resize: vertical; }
.pool-actions { display: flex; gap: 0.45rem; margin-top: 0.65rem; }

.table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 0.5rem 1.1rem 1rem; box-shadow: var(--shadow-sm); overflow-x: auto; }
.table-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 0 0.25rem; }
.table-head h2 { font-size: 1.05rem; margin: 0; }
.grid { width: 100%; border-collapse: collapse; }
.grid th, .grid td { text-align: left; padding: 0.65rem 0.5rem; border-bottom: 1px solid var(--border); vertical-align: top; font-size: 0.9rem; color: var(--text); }
.grid th { color: var(--text-muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; }
.grid tr:last-child td { border-bottom: 0; }
.grid small { color: var(--text-faint); }
.content { max-width: 320px; line-height: 1.5; }
.content.muted { color: var(--text-muted); }
.strong { font-weight: 600; }
.empty { color: var(--text-muted); text-align: center; padding: 1.5rem 0; }
.link { border: 0; background: transparent; color: var(--primary); cursor: pointer; padding: 0.2rem 0.4rem; font: inherit; }
.link:hover { color: var(--primary-hover); }
.link.danger { color: var(--danger); }

.tag { display: inline-block; margin-left: 0.4rem; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); background: var(--surface-3); border-radius: 999px; padding: 0.1rem 0.5rem; vertical-align: middle; }
.tag-blocked { background: #fee2e2; color: #b91c1c; }
.row-blocked td { opacity: 0.55; }
.actions { white-space: nowrap; display: flex; gap: 0.25rem; align-items: center; }
.role-select {
  font-size: 0.8rem; padding: 0.2rem 0.4rem; border: 1px solid var(--border);
  border-radius: 5px; background: var(--surface); color: var(--text); cursor: pointer;
}
.reset-modal {
  margin-bottom: 1rem; padding: 1rem; background: var(--surface-2);
  border: 1px solid var(--border); border-radius: 8px;
}
.reset-note { font-size: 0.82rem; color: var(--text-muted); margin: 0.2rem 0 0.6rem; }
.reset-url-input {
  width: 100%; padding: 0.4rem 0.6rem; font-size: 0.82rem;
  border: 1px solid var(--border); border-radius: 5px;
  background: var(--surface); color: var(--text); cursor: text;
}
.primary-chip {
  background: var(--primary, #2563eb); color: var(--on-primary, #fff);
  border-color: var(--primary, #2563eb);
}
.primary-chip:hover { background: var(--primary-hover, #1d4ed8); border-color: var(--primary-hover, #1d4ed8); }
.disabled-chip,
.disabled-chip:hover {
  background: var(--surface-3);
  color: var(--text-muted);
  border-color: var(--border);
}
.create-user-panel {
  margin-bottom: 1.25rem; padding: 1.25rem;
  background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px;
}
.cu-title { margin: 0 0 1rem; font-size: 1rem; }
.cu-form { display: flex; flex-direction: column; gap: .75rem; }
.cu-row { display: grid; grid-template-columns: 90px 1fr; align-items: center; gap: .5rem; }
.cu-label { font-size: .85rem; color: var(--text-muted); font-weight: 500; }
.cu-input {
  padding: .45rem .65rem; border: 1px solid var(--border); border-radius: 5px;
  background: var(--surface); color: var(--text); font-size: .875rem; width: 100%;
}
.cu-input:focus { outline: none; border-color: var(--primary); }
.cu-error { font-size: .82rem; color: #dc2626; margin: 0; }
.cu-actions { display: flex; gap: .5rem; padding-top: .25rem; }
.cu-success { font-size: .875rem; }
.cu-success p { margin: 0 0 .5rem; }
.badge { display: inline-block; font-size: 0.78rem; padding: 0.15rem 0.55rem; border-radius: 999px; background: var(--surface-3); color: var(--text-muted); text-transform: capitalize; }
.badge.active, .badge.ready { background: var(--success-soft); color: var(--success); }
.badge.pending, .badge.scheduled { background: var(--primary-soft); color: var(--primary-hover); }
.badge.failed { background: #fee2e2; color: #b91c1c; }
.badge.custom-mood { background: #fef3c7; color: #92400e; margin: 0.1rem 0.15rem 0.1rem 0; }
.badge.music-source { background: #ede9fe; color: #5b21b6; }
.muted-cell { color: var(--text-faint); font-size: 0.85rem; }

.settings { max-width: 640px; }
.setting-block { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem 1.4rem; box-shadow: var(--shadow-sm); }
.setting-block h2 { font-size: 1.05rem; margin: 0 0 0.3rem; }
.setting-desc { color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; margin: 0 0 1rem; }
.choice-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.6rem; }
.choice { display: flex; flex-direction: column; gap: 0.25rem; padding: 0.85rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); cursor: pointer; text-align: left; transition: border-color 0.12s ease, background 0.12s ease; }
.choice:hover:not(:disabled) { border-color: var(--border-strong); }
.choice span { font-size: 0.78rem; color: var(--text-muted); line-height: 1.4; }
.choice.active { border-color: var(--primary); background: var(--primary-soft); }
.choice.active span { color: var(--primary-hover); }
.choice:disabled { opacity: 0.6; cursor: default; }
.choice .state { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-left: 0.35rem; }
.choice.active .state { color: var(--primary-hover); }

.mood-editor { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.9rem; }
.mood-chip { display: inline-flex; align-items: center; gap: 0.35rem; background: var(--primary-soft); color: var(--primary-hover); border-radius: 999px; padding: 0.3rem 0.4rem 0.3rem 0.8rem; font-size: 0.85rem; font-weight: 500; }
.chip-x { border: 0; background: transparent; color: inherit; cursor: pointer; font-size: 1rem; line-height: 1; padding: 0 0.25rem; border-radius: 999px; }
.chip-x:hover:not(:disabled) { background: var(--primary); color: var(--on-primary); }
.chip-x:disabled { opacity: 0.4; cursor: default; }
.mood-chip.filter-chip { background: #fff0f0; color: #b00; }
.mood-chip.allow-chip { background: #e9f7ec; color: #18794e; }
.mood-add { display: flex; gap: 0.5rem; }

/* Content Filter tab */
.link-btn { border: 0; background: transparent; color: var(--primary); cursor: pointer; font: inherit; padding: 0; text-decoration: underline; }
.cf-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
.cf-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.cf-file { display: none; }
.cf-category { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 1rem; margin-top: 1rem; }
.cf-cat-head { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.cf-cat-title { display: flex; align-items: center; gap: 0.5rem; }
.cf-cat-title h3 { margin: 0; }
.cf-count { background: var(--primary-soft); color: var(--primary); border-radius: 999px; padding: 0.1rem 0.6rem; font-size: 0.8rem; }
.cf-badge { border-radius: 999px; padding: 0.1rem 0.55rem; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
.cf-badge-block { background: #fff0f0; color: #b00; }
.cf-badge-allow { background: #e9f7ec; color: #18794e; }
.cf-category.cf-allow { border-left: 3px solid #18794e; }
.cf-cat-controls { display: flex; align-items: center; gap: 0.75rem; }
.cf-scope { font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.4rem; }
.cf-scope select, .cf-new-row select { padding: 0.4rem 0.5rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font: inherit; }
.cf-del-cat { color: #b00; font-size: 0.8rem; }
.cf-cat-desc { margin: 0.4rem 0 0.6rem; }
.cf-empty { color: var(--text-muted); font-size: 0.85rem; }
.cf-new-category { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px dashed var(--border); }
.cf-new-category h3 { margin: 0 0 0.6rem; }
.cf-new-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.cf-desc-input { margin-top: 0.5rem; }
.banner-list { display: flex; flex-direction: column; gap: 0.75rem; }
.banner-row, .banner-add { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.75rem; background: var(--surface-2); }
.banner-add { margin-top: 0.8rem; }
.banner-text, .banner-source {
  width: 100%; padding: 0.55rem 0.65rem; border: 1px solid var(--border);
  border-radius: var(--radius-sm); font: inherit; background: var(--surface); color: var(--text);
}
.banner-text { resize: vertical; min-height: 4.2rem; line-height: 1.45; }
.banner-text:focus, .banner-source:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.banner-meta-row { display: grid; grid-template-columns: 1fr auto; gap: 0.5rem; align-items: center; margin-top: 0.5rem; }
.danger-chip { color: var(--danger); }
.danger-chip:hover { border-color: var(--danger); color: var(--danger); }

.pw-form { display: flex; flex-direction: column; gap: 0.6rem; max-width: 340px; }
.pw-form input { padding: 0.65rem 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; background: var(--surface); color: var(--text); }
.pw-form input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.pw-form input:disabled { opacity: 0.6; }
.pw-btn { align-self: flex-start; padding: 0.65rem 1.25rem; }
.pw-btn:disabled { opacity: 0.6; cursor: default; }
.pw-error { color: var(--danger); font-size: 0.88rem; margin: 0; }
.pw-ok { color: var(--success); font-size: 0.88rem; margin: 0; }
.mood-input { flex: 1; padding: 0.6rem 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; background: var(--surface); color: var(--text); }
.mood-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.add-btn { padding: 0.6rem 1.1rem; }

/* ── Language Grammar Review ─────────────────────────────────────────────────── */
.gr-section { display: flex; flex-direction: column; gap: 1rem; }
.gr-title { font-size: 1.1rem; margin: 0 0 0.3rem; }
.gr-desc { color: var(--text-muted); font-size: 0.88rem; margin: 0; line-height: 1.5; }
.gr-controls { display: flex; flex-direction: column; gap: 0.75rem; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem 1.2rem; box-shadow: var(--shadow-sm); }
.gr-control-group { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.gr-label { font-size: 0.78rem; font-weight: 600; color: var(--text-muted); min-width: 76px; text-transform: uppercase; letter-spacing: 0.04em; }
.gr-pills { display: flex; gap: 0.35rem; flex-wrap: wrap; }
.gr-pill { padding: 0.32rem 0.8rem; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text-muted); font: inherit; font-size: 0.82rem; cursor: pointer; }
.gr-pill:hover { border-color: var(--border-strong); color: var(--text); }
.gr-pill.active { border-color: var(--primary); background: var(--primary-soft); color: var(--primary); font-weight: 500; }
.gr-stats { color: var(--text-muted); font-size: 0.82rem; margin: 0; }
.gr-loading { color: var(--text-muted); font-size: 0.88rem; }
.gr-list { display: flex; flex-direction: column; gap: 0.65rem; }
.gr-item { background: var(--surface); border: 1px solid var(--border); border-left-width: 3px; border-radius: var(--radius); padding: 0.9rem 1.1rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 0.5rem; }
.gr-item.gr-approved  { border-left-color: var(--success, #16a34a); }
.gr-item.gr-corrected { border-left-color: var(--primary); }
.gr-item.gr-pending   { border-left-color: var(--border); }
.gr-item-head { display: flex; align-items: center; gap: 0.5rem; }
.gr-cat { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.gr-text { font-size: 0.92rem; line-height: 1.65; white-space: pre-wrap; }
.gr-text-en { font-size: 0.8rem; color: var(--text-muted); font-style: italic; }
.gr-extra { font-size: 0.8rem; color: var(--text-muted); line-height: 1.5; }
.gr-correction-display { font-size: 0.86rem; background: var(--primary-soft); color: var(--primary-hover); padding: 0.4rem 0.65rem; border-radius: var(--radius-sm); }
.gr-correction-label { font-weight: 600; }
.gr-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.gr-edit-row { display: flex; flex-direction: column; gap: 0.5rem; }
.gr-correction-input { width: 100%; padding: 0.55rem 0.65rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font: inherit; font-size: 0.9rem; resize: vertical; line-height: 1.55; box-sizing: border-box; }
.gr-correction-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.gr-empty { color: var(--text-muted); font-size: 0.88rem; text-align: center; padding: 2rem 0; margin: 0; }
.gr-pagination { display: flex; align-items: center; gap: 1rem; justify-content: center; padding-top: 0.25rem; }
.gr-page-info { font-size: 0.86rem; color: var(--text-muted); }

/* Share popover */
.share-row td { background: var(--surface-2, var(--surface)); padding: 0.6rem 0.75rem !important; }
.share-popover { display: flex; flex-direction: column; gap: 0.5rem; }
.share-link-text { font-size: 0.78rem; color: var(--text-muted); word-break: break-all; font-family: monospace; }
.share-btns { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.chip.ghost { background: transparent; border-color: var(--border); color: var(--text-muted); }
.chip.ghost:hover { border-color: var(--border-strong); color: var(--text); }

/* ── System Monitor ─────────────────────────────────────────────────────────── */
.sys-monitor { display: flex; flex-direction: column; gap: 1.25rem; }
.sys-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.sys-title { font-size: 1.1rem; margin: 0 0 0.2rem; }
.sys-meta { color: var(--text-muted); font-size: 0.82rem; margin: 0; }
.sys-header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.sys-notice { background: var(--primary-soft); color: var(--primary-hover); padding: 0.5rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.88rem; margin: 0; }
.sys-block { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.1rem 1.25rem; box-shadow: var(--shadow-sm); }
.sys-block-title { font-size: 1rem; margin: 0 0 0.25rem; }
.sys-block-desc { color: var(--text-muted); font-size: 0.83rem; margin: 0 0 0.9rem; line-height: 1.5; }
.sys-empty { color: var(--text-muted); font-size: 0.88rem; margin: 0; }
.sys-muted { color: var(--text-faint); font-size: 0.85rem; }

/* Service health grid */
.svc-grid { display: flex; flex-direction: column; gap: 0.45rem; }
.svc-card { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.8rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); }
.svc-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.svc-dot.svc-active   { background: var(--success, #16a34a); box-shadow: 0 0 0 3px rgba(22,163,74,.15); }
.svc-dot.svc-inactive { background: var(--danger, #dc2626); box-shadow: 0 0 0 3px rgba(220,38,38,.15); }
.svc-dot.svc-unknown  { background: var(--text-faint, #94a3b8); }
.svc-info { flex: 1; min-width: 0; }
.svc-name { display: block; font-size: 0.88rem; }
.svc-unit { font-size: 0.75rem; color: var(--text-muted); }
.svc-status { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.15rem 0.55rem; border-radius: 999px; }
.svc-status.svc-active   { background: rgba(22,163,74,.12);  color: #16a34a; }
.svc-status.svc-inactive { background: rgba(220,38,38,.12); color: #dc2626; }
.svc-status.svc-unknown  { background: var(--surface-3); color: var(--text-muted); }
.svc-restart-btn { font-size: 0.78rem; padding: 0.25rem 0.65rem; flex-shrink: 0; }

/* Git panel */
.git-panel { display: flex; flex-direction: column; gap: 0.55rem; }
.git-row { display: grid; grid-template-columns: 110px 1fr; gap: 0.5rem; align-items: baseline; font-size: 0.9rem; }
.git-label { color: var(--text-muted); font-size: 0.82rem; font-weight: 500; }
.git-val { word-break: break-all; }
.git-output { display: block; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; padding: 0.4rem 0.6rem; font-size: 0.78rem; white-space: pre-wrap; line-height: 1.5; }
.git-pull-btn { margin-top: 0.5rem; align-self: flex-start; }
.up-to-date { color: var(--success, #16a34a); font-size: 0.82rem; font-weight: 500; }
.update-badge { display: inline-block; background: #fef3c7; color: #92400e; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; border-radius: 999px; padding: 0.1rem 0.5rem; margin-left: 0.35rem; }

/* Package table */
.pkg-table-wrap { overflow-x: auto; }
.pkg-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.pkg-table th { text-align: left; color: var(--text-muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--border); }
.pkg-table td { padding: 0.55rem 0.6rem; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
.pkg-table tr:last-child td { border-bottom: 0; }
.pkg-name { font-weight: 500; }
.pkg-badge { vertical-align: middle; }
.pkg-row-update { background: rgba(234,179,8,.04); }
.pkg-upgrade-btn { font-size: 0.78rem; padding: 0.2rem 0.6rem; }
</style>
