<script setup>
// Admin console, reached at #admin. Logs in with an admin account, then exposes the
// dashboard plus moderation (testimonies), donor insight, user management, service
// retry, and CSV exports.
import { ref, onMounted } from "vue";
import { api } from "../composables/useApi";
import ThemeToggle from "./ThemeToggle.vue";

const authed = ref(false);
const email = ref("");
const password = ref("");
const loginError = ref("");

const tab = ref("dashboard"); // dashboard | services | donors | testimonies | users | settings
const stats = ref(null);
const services = ref([]);
const donors = ref([]);
const testimonies = ref([]);
const users = ref([]);
const settings = ref(null);
const savingSettings = ref(false);
const notice = ref("");

// How spoken segments are voiced across all services. Mirrors the backend's
// Setting::NARRATION_MODES; surfaced as a single-choice selector.
const narrationModes = [
  { value: "browser", label: "Browser voice", hint: "The worshipper's browser reads each segment aloud — free, no API key." },
  { value: "openai", label: "OpenAI voice", hint: "Segments are narrated with OpenAI text-to-speech. Requires a TTS key." },
  { value: "kokoro", label: "OpenRouter Kokoro", hint: "Segments are narrated with the open hexgrad/kokoro-82m voice via OpenRouter. Uses the OpenRouter key." },
  { value: "off", label: "Off", hint: "Segments stay as silent text — nothing is read aloud." },
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
  { value: "suno", label: "AI-composed", hint: "Original worship composed for the worshipper." },
  { value: "youtube", label: "From YouTube", hint: "An existing worship track and sermon clip." },
];

const newMood = ref(""); // the mood being typed in the add-mood field

async function login() {
  loginError.value = "";
  try {
    await api.login({ email: email.value, password: password.value });
    await enter();
  } catch (e) {
    loginError.value = e?.data?.message || "Login failed.";
  }
}

// Verify the logged-in account actually has admin rights by loading the dashboard.
async function enter() {
  try {
    stats.value = await api.adminDashboard();
    authed.value = true;
  } catch (e) {
    loginError.value = e?.status === 403 ? "This account is not an admin." : "Could not load admin.";
    authed.value = false;
  }
}

function logout() {
  api.logout();
  authed.value = false;
  email.value = "";
  password.value = "";
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
async function loadSettings() {
  settings.value = await api.adminSettings();
}

function show(name) {
  tab.value = name;
  notice.value = "";
  if (name === "services") loadServices();
  if (name === "donors") loadDonors();
  if (name === "testimonies") loadTestimonies();
  if (name === "users") loadUsers();
  if (name === "settings") loadSettings();
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
const setMusicReuse = (on) => saveSetting("music_reuse", on, "Music reuse updated.");
const setStorageBackend = (backend) => saveSetting("storage_backend", backend, "Storage backend updated.");
const setScheduling = (on) => saveSetting("scheduling_enabled", on, "Scheduling updated.");

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

async function retry(s) {
  try {
    await api.adminRetryService(s.id);
    notice.value = `Re-dispatched service #${s.id}.`;
    loadServices();
  } catch (e) {
    notice.value = e?.data?.message || "Retry failed.";
  }
}
async function approve(t) {
  await api.adminApproveTestimony(t.id);
  loadTestimonies();
}
async function remove(t) {
  await api.adminDeleteTestimony(t.id);
  loadTestimonies();
}
async function toggleAdmin(u) {
  try {
    await api.adminSetAdmin(u.id, !u.is_admin);
    loadUsers();
  } catch (e) {
    notice.value = e?.data?.message || "Could not update.";
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

function fmtDate(v) {
  return v ? new Date(v).toLocaleString() : "—";
}
function fmtMoney(amount, currency) {
  return `${Number(amount).toFixed(2)} ${(currency || "usd").toUpperCase()}`;
}

// If a token is already stored (e.g. a returning admin), try to enter directly.
onMounted(() => { if (api.hasToken()) enter(); });
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
        <button :class="{ active: tab === 'dashboard' }" @click="show('dashboard')">Dashboard</button>
        <button :class="{ active: tab === 'services' }" @click="show('services')">Services</button>
        <button :class="{ active: tab === 'donors' }" @click="show('donors')">Donors</button>
        <button :class="{ active: tab === 'testimonies' }" @click="show('testimonies')">Testimonies</button>
        <button :class="{ active: tab === 'users' }" @click="show('users')">Users</button>
        <button :class="{ active: tab === 'settings' }" @click="show('settings')">Settings</button>
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
            <span class="n">{{ stats.services.completed }}</span>
            <span class="lbl">Completed</span>
            <small>{{ stats.services.active }} active · {{ stats.services.scheduled }} scheduled</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.intercepts.total }}</span>
            <span class="lbl">Crisis intercepts</span>
            <small>{{ stats.intercepts.today }} today</small>
          </div>
          <div class="card">
            <span class="n">{{ stats.users.admins }}</span>
            <span class="lbl">Admins</span>
            <small>of {{ stats.users.total }} accounts</small>
          </div>
        </div>

        <div class="exports">
          <span class="exports-label">Export report</span>
          <button class="chip" @click="exportReport('donations')">Donations CSV</button>
          <button class="chip" @click="exportReport('users')">Users CSV</button>
          <button class="chip" @click="exportReport('testimonies')">Testimonies CSV</button>
        </div>
      </section>

      <!-- Services -->
      <div v-else-if="tab === 'services'" class="table-wrap">
        <table class="grid">
          <thead><tr><th>#</th><th>User</th><th>Status</th><th>Segments</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in services" :key="s.id">
              <td>{{ s.id }}</td>
              <td>{{ s.user?.name }}<br /><small>{{ s.user?.email }}</small></td>
              <td><span class="badge" :class="s.status">{{ s.status }}</span></td>
              <td>{{ s.assets_count }}</td>
              <td><button class="link" @click="retry(s)">Retry</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Donors -->
      <div v-else-if="tab === 'donors'" class="table-wrap">
        <div class="table-head">
          <h2>Donors</h2>
          <button class="chip" @click="exportReport('donations')">Export CSV</button>
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
          <button class="chip" @click="exportReport('testimonies')">Export CSV</button>
        </div>
        <table class="grid">
          <thead><tr><th>By</th><th>Content</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in testimonies" :key="t.id">
              <td>{{ t.user?.name || "—" }}<br /><small>{{ t.source }}</small></td>
              <td class="content">{{ t.content }}</td>
              <td><span class="badge" :class="t.approved ? 'ready' : 'pending'">{{ t.approved ? "approved" : "pending" }}</span></td>
              <td>
                <button v-if="!t.approved" class="link" @click="approve(t)">Approve</button>
                <button class="link danger" @click="remove(t)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Users -->
      <div v-else-if="tab === 'users'" class="table-wrap">
        <div class="table-head">
          <h2>Users &amp; visitors</h2>
          <button class="chip" @click="exportReport('users')">Export CSV</button>
        </div>
        <table class="grid">
          <thead><tr><th>Name</th><th>Email</th><th>Visits</th><th>Last seen</th><th>Admin</th><th></th></tr></thead>
          <tbody>
            <tr v-for="u in users" :key="u.id">
              <td>
                {{ u.name }}
                <span v-if="u.is_guest" class="tag">visitor</span>
              </td>
              <td><small>{{ u.email || "—" }}</small></td>
              <td>{{ u.visits }}</td>
              <td><small>{{ fmtDate(u.last_seen) }}</small></td>
              <td>{{ u.is_admin ? "yes" : "no" }}</td>
              <td><button class="link" @click="toggleAdmin(u)">{{ u.is_admin ? "Revoke" : "Make admin" }}</button></td>
            </tr>
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

.tabs { display: flex; gap: 0.25rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.tabs button { padding: 0.6rem 0.9rem; border: 0; background: transparent; cursor: pointer; color: var(--text-muted); border-bottom: 2px solid transparent; font: inherit; }
.tabs button:hover { color: var(--text); }
.tabs button.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 500; }

.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.15rem; box-shadow: var(--shadow-sm); }
.card .n { font-size: 1.7rem; font-weight: 700; color: var(--text); letter-spacing: -0.02em; line-height: 1.1; }
.card .lbl { font-size: 0.9rem; color: var(--text); font-weight: 500; margin-top: 0.2rem; }
.card small { color: var(--text-muted); font-size: 0.78rem; }

.exports { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 1.75rem; }
.exports-label { color: var(--text-muted); font-size: 0.85rem; margin-right: 0.25rem; }
.chip { border: 1px solid var(--border); background: var(--surface); color: var(--text); border-radius: 999px; padding: 0.4rem 0.85rem; font-size: 0.85rem; cursor: pointer; }
.chip:hover { border-color: var(--primary); color: var(--primary-hover); }

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
.badge { display: inline-block; font-size: 0.78rem; padding: 0.15rem 0.55rem; border-radius: 999px; background: var(--surface-3); color: var(--text-muted); text-transform: capitalize; }
.badge.active, .badge.ready { background: var(--success-soft); color: var(--success); }
.badge.pending, .badge.scheduled { background: var(--primary-soft); color: var(--primary-hover); }

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
.mood-input { flex: 1; padding: 0.6rem 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; background: var(--surface); color: var(--text); }
.mood-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.add-btn { padding: 0.6rem 1.1rem; }
</style>
