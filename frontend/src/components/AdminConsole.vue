<script setup>
// Admin console, reached at #admin. Logs in with an admin account, then exposes the
// dashboard plus moderation (testimonies), donor insight, user management, service
// retry, and CSV exports.
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import { api } from "../composables/useApi";
import ThemeToggle from "./ThemeToggle.vue";
import VoiceStudio from "./VoiceStudio.vue";
import PermissionsMatrix from "./PermissionsMatrix.vue";

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

// First tab the current user is allowed to see — determines landing tab after login.
function firstAllowedTab() {
  const candidates = [
    { name: "dashboard",   check: () => can("dashboard.view") },
    { name: "services",    check: () => can("services.view") },
    { name: "donors",      check: () => can("donors.view") },
    { name: "testimonies", check: () => can("testimonies.view") },
    { name: "users",       check: () => isAdminUser.value },
    { name: "prayer",      check: () => can("prayer_requests.view") },
    { name: "settings",    check: () => isAdminUser.value },
    { name: "music-pool",  check: () => isAdminUser.value },
    { name: "voice-studio",check: () => can("voice_studio.view") },
    { name: "permissions", check: () => isAdminUser.value },
    { name: "system",      check: () => isAdminUser.value },
  ];
  return (candidates.find((c) => c.check()) ?? candidates[0]).name;
}

const tab = ref("dashboard"); // dashboard | services | donors | testimonies | users | prayer | settings | music-pool | voice-studio | permissions
const stats = ref(null);
const services = ref([]);
const donors = ref([]);
const testimonies = ref([]);
const users = ref([]);
const prayerRequests = ref([]);
const settings        = ref(null);
const savingSettings  = ref(false);
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

// How spoken segments are voiced across all services. Mirrors the backend's
// Setting::NARRATION_MODES; surfaced as a single-choice selector.
const narrationModes = [
  { value: "edge_tts", label: "Edge TTS (free)", hint: "Microsoft neural voice — free, no API key, high quality. Recommended." },
  { value: "browser", label: "Browser voice", hint: "The worshipper's browser reads each segment aloud — free, no API key." },
  { value: "openai", label: "OpenAI voice", hint: "Segments are narrated with OpenAI text-to-speech. Requires a TTS key." },
  { value: "kokoro", label: "OpenRouter Kokoro", hint: "Segments are narrated with the open hexgrad/kokoro-82m voice via OpenRouter. Uses the OpenRouter key." },
  { value: "off", label: "Off", hint: "Segments stay as silent text — nothing is read aloud." },
];

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

const narrationLanguages = [
  { key: "narration_en", label: "English", hint: "Use the selected narration provider for English services." },
  { key: "narration_my", label: "Myanmar", hint: "Uses my-MM-NilarNeural (female) / my-MM-ThihaNeural (male) via Edge TTS. Requires narration mode set to Edge TTS." },
  { key: "narration_td", label: "Tedim (Zolai)", hint: "Enable only when EDGE_TTS_VOICE_TD is configured." },
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
const countdownSourceOptions = [
  { value: "both", label: "Banners + testimonies", hint: "Rotate admin banners and approved testimonies." },
  { value: "all", label: "All sources", hint: "Rotate banners, approved testimonies, and an online Bible verse." },
  { value: "banners", label: "Custom banners", hint: "Show only the admin-managed messages below." },
  { value: "testimonies", label: "Testimonies", hint: "Show only approved testimonies from the moderation queue." },
  { value: "online", label: "Online Bible verse", hint: "Show a cached public-domain verse from a fixed online provider." },
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

    // Load dashboard stats in the background if accessible.
    if (can("dashboard.view")) {
      api.adminDashboard().then((res) => { stats.value = res; }).catch(() => {});
    }

    const startTab = firstAllowedTab();
    tab.value = startTab;
    // Load data for the start tab (if it's not dashboard — dashboard loads above).
    if (startTab !== "dashboard") show(startTab);
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
  email.value       = "";
  password.value    = "";
}

async function loadServices() {
  services.value = (await api.adminServices()).services || [];
}
async function loadDonors() {
  donors.value = (await api.adminDonors()).donors || [];
}
async function loadTestimonies() {
  testimonies.value = (await api.adminTestimonies()).testimonies || [];
}
async function loadUsers() {
  users.value = (await api.adminUsers()).users || [];
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
    notice.value = e?.data?.message || "Could not load Suno pool.";
  } finally {
    musicPoolBusy.value = false;
  }
}

function show(name) {
  if (name !== "system") clearInterval(updateTimer);
  tab.value    = name;
  notice.value = "";
  if (name === "services")    loadServices();
  if (name === "donors")      loadDonors();
  if (name === "testimonies") loadTestimonies();
  if (name === "users")       loadUsers();
  if (name === "prayer")      loadPrayerRequests();
  if (name === "settings")    loadSettings();
  if (name === "music-pool")  loadMusicTracks();
  if (name === "permissions") loadPermissions();
  if (name === "system")      { loadUpdateStatus(); scheduleUpdatePoll(); }
  // Dashboard stats loaded on demand if not yet fetched.
  if (name === "dashboard" && can("dashboard.view")) {
    api.adminDashboard().then((res) => { stats.value = res; }).catch(() => {});
  }
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

const setNarrationMode = (mode) => saveSetting("narration_mode", mode, "Narration voice updated.");
const setLanguageNarration = (key, on) => saveSetting(key, on, "Language narration updated.");

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
const setAvatarEnabled = (on) => saveSetting("avatar_enabled", on, "Avatar rendering updated.");
const setTextHighlightEnabled = (on) => saveSetting("text_highlight_enabled", on, "Text highlighting updated.");
const setStorageBackend = (backend) => saveSetting("storage_backend", backend, "Storage backend updated.");
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
    source: "suno",
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
    source: "suno",
  };
  if (!payload.mood || !payload.provider_ref || !payload.storage_key) {
    notice.value = "Mood, provider ref, and storage key are required.";
    return;
  }

  musicPoolBusy.value = true;
  try {
    if (musicTrackEditingId.value) {
      await api.adminUpdateMusicTrack(musicTrackEditingId.value, payload);
      notice.value = "Suno pool track updated.";
    } else {
      await api.adminCreateMusicTrack(payload);
      notice.value = "Suno pool track added.";
    }
    resetMusicTrackForm();
    await loadMusicTracks();
  } catch (e) {
    notice.value = e?.data?.message || "Could not save Suno pool track.";
  } finally {
    musicPoolBusy.value = false;
  }
}

async function removeMusicTrack(track) {
  if (!confirm(`Delete Suno pool row #${track.id}?`)) return;
  musicPoolBusy.value = true;
  try {
    await api.adminDeleteMusicTrack(track.id);
    notice.value = "Suno pool track deleted.";
    if (musicTrackEditingId.value === track.id) resetMusicTrackForm();
    await loadMusicTracks();
  } catch (e) {
    notice.value = e?.data?.message || "Could not delete Suno pool track.";
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

// ─── System Monitor ────────────────────────────────────────────────────────────

const updateStatus   = ref(null);   // { checked_at, checking, packages, services, git }
const updateBusy     = ref(false);  // true while an action is in flight
const updateNotice   = ref("");
let   updateTimer    = null;

const SERVICE_LABELS = {
  "aivc-workers"       : "Workers (sermon/avatar)",
  "aivc-workers-music" : "Workers (music)",
  "aivc-bridge"        : "Bridge consumer",
  "aivc-queue"         : "Laravel queue",
  "aivc-scheduler"     : "Laravel scheduler",
  "aivc-tedim-api"     : "Tedim LLM API",
  "aivc-burmese-api"   : "Burmese LLM API",
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

// If a token is already stored (e.g. a returning admin), try to enter directly.
onMounted(() => { if (api.hasToken()) enter(); });
onUnmounted(() => clearInterval(updateTimer));
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
        <button v-if="can('dashboard.view')"        :class="{ active: tab === 'dashboard' }"   @click="show('dashboard')">Dashboard</button>
        <button v-if="can('services.view')"         :class="{ active: tab === 'services' }"    @click="show('services')">Services</button>
        <button v-if="can('donors.view')"           :class="{ active: tab === 'donors' }"      @click="show('donors')">Donors</button>
        <button v-if="can('testimonies.view')"      :class="{ active: tab === 'testimonies' }" @click="show('testimonies')">Testimonies</button>
        <button v-if="isAdminUser"                  :class="{ active: tab === 'users' }"       @click="show('users')">Users</button>
        <button v-if="can('prayer_requests.view')"  :class="{ active: tab === 'prayer' }"      @click="show('prayer')">Prayer Requests</button>
        <button v-if="isAdminUser"                  :class="{ active: tab === 'settings' }"    @click="show('settings')">Settings</button>
        <button v-if="isAdminUser"                  :class="{ active: tab === 'music-pool' }"  @click="show('music-pool')">Suno Pool</button>
        <button v-if="can('voice_studio.view')"     :class="{ active: tab === 'voice-studio'}" @click="show('voice-studio')">Voice Studio</button>
        <button v-if="isAdminUser"                  :class="{ active: tab === 'permissions' }" @click="show('permissions')">Permissions</button>
        <button v-if="isAdminUser"                  :class="{ active: tab === 'system' }"      @click="show('system')">System</button>
        <span v-if="currentUser" class="staff-role-badge" :class="'role-' + currentUser.role">
          {{ currentUser.role }}
        </span>
      </nav>

      <p v-if="notice" class="notice">{{ notice }}</p>

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
        <table class="grid">
          <thead><tr><th>#</th><th>User</th><th>Mood</th><th>Sermon topic</th><th>Music</th><th>Status</th><th>Segments</th><th></th></tr></thead>
          <tbody>
            <template v-for="s in services" :key="s.id">
              <tr>
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
                <td colspan="8">
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
            <button class="chip primary-chip" @click="openCreateUser">+ Add User</button>
            <button class="chip" @click="exportReport('users')">Export CSV</button>
          </div>
        </div>

        <!-- Create user form -->
        <div v-if="showCreateUser" class="create-user-panel">
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

        <table class="grid">
          <thead>
            <tr>
              <th>Name</th><th>Email</th><th>Role</th><th>Last mood</th>
              <th>Visits</th><th>Last seen</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in users" :key="u.id" :class="{ 'row-blocked': u.is_blocked }">
              <td>
                {{ u.name }}
                <span v-if="u.is_guest" class="tag">visitor</span>
                <span v-if="u.is_blocked" class="tag tag-blocked">blocked</span>
              </td>
              <td><small>{{ u.email || "—" }}</small></td>
              <td>
                <select
                  v-if="!u.is_guest"
                  class="role-select"
                  :value="u.role"
                  @change="assignRole(u, $event.target.value)"
                >
                  <option value="member">Member</option>
                  <option value="presenter">Presenter</option>
                  <option value="moderator">Moderator</option>
                  <option value="admin">Admin</option>
                </select>
                <span v-else class="tag">guest</span>
              </td>
              <td>
                <span v-if="u.last_mood" class="badge pending">{{ u.last_mood }}</span>
                <span v-else class="muted-cell">—</span>
              </td>
              <td>{{ u.visits }}</td>
              <td><small>{{ fmtDate(u.last_seen) }}</small></td>
              <td class="actions">
                <button v-if="!u.is_guest" class="link" @click="forceReset(u)">Reset pw</button>
                <button class="link" @click="toggleBlock(u)">{{ u.is_blocked ? "Unblock" : "Block" }}</button>
                <button class="link danger" @click="deleteUser(u)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

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

      <!-- Suno Pool -->
      <div v-else-if="tab === 'music-pool'" class="table-wrap">
        <div class="table-head">
          <h2>Suno Song Pool (music_tracks)</h2>
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

        <div class="pool-editor">
          <h3>{{ musicTrackEditingId ? `Edit Track #${musicTrackEditingId}` : 'Add Track' }}</h3>
          <div class="pool-grid">
            <input v-model="musicTrackForm.mood" class="pool-input" placeholder="Mood (required)" />
            <select v-model="musicTrackForm.language" class="pool-input">
              <option value="en">English</option>
              <option value="my">Myanmar</option>
              <option value="td">Tedim</option>
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
          <thead><tr><th>#</th><th>Mood</th><th>Lang</th><th>Title</th><th>Provider Ref</th><th>Storage Key</th><th>Lyrics</th><th>Created</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in musicTracks" :key="t.id">
              <td>{{ t.id }}</td>
              <td><span class="badge pending">{{ t.mood }}</span></td>
              <td>{{ t.language }}</td>
              <td>{{ t.title || '—' }}</td>
              <td><small>{{ t.provider_ref }}</small></td>
              <td><small>{{ t.storage_key }}</small></td>
              <td class="content">{{ t.lyrics || '—' }}</td>
              <td><small>{{ fmtDate(t.created_at) }}</small></td>
              <td class="actions">
                <button class="link" @click="editMusicTrack(t)">Edit</button>
                <button class="link danger" @click="removeMusicTrack(t)">Delete</button>
              </td>
            </tr>
            <tr v-if="!musicTracks.length"><td colspan="9" class="empty">No Suno pool tracks found.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Settings -->
      <section v-else-if="tab === 'settings'" class="settings">
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
                :disabled="savingSettings || settings.moods.length <= 1"
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
              :disabled="savingSettings"
              @keyup.enter="addMood"
            />
            <button type="button" class="primary add-btn" :disabled="savingSettings || !newMood.trim()" @click="addMood">
              Add
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
              :disabled="savingSettings"
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
                :disabled="savingSettings"
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
              :disabled="savingSettings"
              @click="setScheduling(true)"
            >
              <strong>Allow scheduling</strong>
              <span>Show the "schedule it" option at intake.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.scheduling_enabled === false }"
              :disabled="savingSettings"
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
            Online verses use a fixed public-domain provider and are cached by the server.
          </p>
          <template v-if="settings">
            <div class="choice-row">
              <button
                type="button"
                class="choice"
                :class="{ active: settings.countdown_content_enabled === true }"
                :disabled="savingSettings"
                @click="setCountdownEnabled(true)"
              >
                <strong>Show cards</strong>
                <span>Use the countdown space for testimony and encouragement.</span>
              </button>
              <button
                type="button"
                class="choice"
                :class="{ active: settings.countdown_content_enabled === false }"
                :disabled="savingSettings"
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
                :disabled="savingSettings || settings.countdown_content_enabled === false"
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
                  :disabled="savingSettings"
                  @change="updateCountdownBanner(i, 'text', $event.target.value)"
                ></textarea>
                <div class="banner-meta-row">
                  <input
                    :value="banner.source"
                    class="banner-source"
                    maxlength="80"
                    placeholder="Source label, e.g. Psalm 46:10"
                    :disabled="savingSettings"
                    @change="updateCountdownBanner(i, 'source', $event.target.value)"
                  />
                  <button
                    type="button"
                    class="chip danger-chip"
                    :disabled="savingSettings || settings.countdown_banners.length <= 1"
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
                :disabled="savingSettings"
              ></textarea>
              <div class="banner-meta-row">
                <input
                  v-model="newCountdownBanner.source"
                  class="banner-source"
                  maxlength="80"
                  placeholder="Optional source"
                  :disabled="savingSettings"
                  @keyup.enter="addCountdownBanner"
                />
                <button
                  type="button"
                  class="primary add-btn"
                  :disabled="savingSettings || !newCountdownBanner.text.trim()"
                  @click="addCountdownBanner"
                >Add banner</button>
              </div>
            </div>
          </template>
          <p v-else class="setting-desc">Loading…</p>
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
              :disabled="savingSettings"
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
            are read aloud across every service.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              v-for="m in narrationModes"
              :key="m.value"
              type="button"
              class="choice"
              :class="{ active: settings.narration_mode === m.value }"
              :disabled="savingSettings"
              @click="setNarrationMode(m.value)"
            >
              <strong>{{ m.label }}</strong>
              <span>{{ m.hint }}</span>
            </button>
          </div>
          <template v-if="settings && settings.narration_mode === 'edge_tts'">
            <p class="setting-desc" style="margin-top:1rem">Voice</p>
            <div class="choice-row">
              <button
                v-for="v in edgeTtsVoices"
                :key="v.value"
                type="button"
                class="choice"
                :class="{ active: (settings.edge_tts_voice || 'en-US-AriaNeural') === v.value }"
                :disabled="savingSettings"
                @click="setEdgeTtsVoice(v.value)"
              >
                <strong>{{ v.label }}</strong>
                <span>{{ v.gender }} · {{ v.accent }}</span>
              </button>
            </div>
          </template>
          <p v-else-if="!settings" class="setting-desc">Loading…</p>
          <template v-if="settings">
            <p class="setting-desc" style="margin-top:1rem">Languages</p>
            <div class="choice-row">
              <button
                v-for="lang in narrationLanguages"
                :key="lang.key"
                type="button"
                class="choice"
                :class="{ active: settings[lang.key] === true }"
                :disabled="savingSettings"
                @click="setLanguageNarration(lang.key, !settings[lang.key])"
              >
                <strong>{{ lang.label }}<span class="state">{{ settings[lang.key] ? "on" : "off" }}</span></strong>
                <span>{{ lang.hint }}</span>
              </button>
            </div>
          </template>
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
              :disabled="savingSettings"
              @click="setMusicReuse(true)"
            >
              <strong>Reuse from pool</strong>
              <span>Serve an existing mood song when one exists.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.music_reuse === false }"
              :disabled="savingSettings"
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
              :disabled="savingSettings"
              @click="setTextHighlightEnabled(true)"
            >
              <strong>Enabled</strong>
              <span>Highlight each word as narration plays.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.text_highlight_enabled === false }"
              :disabled="savingSettings"
              @click="setTextHighlightEnabled(false)"
            >
              <strong>Disabled</strong>
              <span>Show plain text without moving highlights.</span>
            </button>
          </div>
          <p v-else class="setting-desc">Loading…</p>
        </div>

        <div class="setting-block">
          <h2>Avatar videos</h2>
          <p class="setting-desc">
            Talking-head avatar videos (powered by D-ID) for the sermon, opening prayer,
            and benediction. Disable when your D-ID subscription is inactive to fall back
            to text and TTS narration without touching any config files.
          </p>
          <div v-if="settings" class="choice-row">
            <button
              type="button"
              class="choice"
              :class="{ active: settings.avatar_enabled === true }"
              :disabled="savingSettings"
              @click="setAvatarEnabled(true)"
            >
              <strong>Enabled</strong>
              <span>Render talking-head videos via D-ID for each spoken segment.</span>
            </button>
            <button
              type="button"
              class="choice"
              :class="{ active: settings.avatar_enabled === false }"
              :disabled="savingSettings"
              @click="setAvatarEnabled(false)"
            >
              <strong>Disabled</strong>
              <span>Skip avatar rendering — segments stay as text and audio only.</span>
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
              :disabled="savingSettings"
              @click="setStorageBackend(b.value)"
            >
              <strong>{{ b.label }}</strong>
              <span>{{ b.hint }}</span>
            </button>
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

      <section v-else-if="tab === 'voice-studio'" class="settings">
        <VoiceStudio />
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
          <div class="sys-header-actions">
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
                v-if="['aivc-workers','aivc-workers-music','aivc-bridge','aivc-queue','aivc-scheduler','aivc-tedim-api','aivc-burmese-api'].includes(name)"
                class="chip svc-restart-btn"
                :disabled="updateBusy"
                @click="restartSvc(name)"
              >Restart</button>
            </div>
          </div>
          <p v-else class="sys-empty">Run a check to see service statuses.</p>
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
            <button class="chip primary-chip git-pull-btn" :disabled="updateBusy" @click="triggerGitPull">
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
                      v-if="pkgHasUpdate(info)"
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

.exports { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 1.75rem; }
.exports-label { color: var(--text-muted); font-size: 0.85rem; margin-right: 0.25rem; }
.chip { border: 1px solid var(--border); background: var(--surface); color: var(--text); border-radius: 999px; padding: 0.4rem 0.85rem; font-size: 0.85rem; cursor: pointer; }
.chip:hover { border-color: var(--primary); color: var(--primary-hover); }
.pool-filters { display: grid; grid-template-columns: 1fr 180px 1.3fr auto; gap: 0.6rem; margin: 0.9rem 0 1rem; }
.pool-editor { background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 0.85rem; margin-bottom: 1rem; }
.pool-editor h3 { margin: 0 0 0.65rem; font-size: 0.95rem; }
.pool-grid { display: grid; grid-template-columns: 1fr 180px 1.4fr 1.4fr 1fr; gap: 0.55rem; }
.pool-input { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); font: inherit; }
.pool-input:focus, .pool-lyrics:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-soft); }
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
.mood-add { display: flex; gap: 0.5rem; }
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
