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

const tab = ref("dashboard"); // dashboard | services | donors | testimonies | users
const stats = ref(null);
const services = ref([]);
const donors = ref([]);
const testimonies = ref([]);
const users = ref([]);
const notice = ref("");

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

function show(name) {
  tab.value = name;
  notice.value = "";
  if (name === "services") loadServices();
  if (name === "donors") loadDonors();
  if (name === "testimonies") loadTestimonies();
  if (name === "users") loadUsers();
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
</style>
