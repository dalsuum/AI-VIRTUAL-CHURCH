<script setup>
// Group Page (v1.3 Phase F) — the collaboration workspace, reached from the
// Church Dashboard's group cards (#group?id=…). Organized around workflows,
// participation before administration: header → Today's Status → Today's
// Reading → Members → Invitations → Join Requests → Recent Activity.
// The UI only decides what to OFFER; every action is re-authorized server-side.
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const props = defineProps({ currentHash: { type: String, default: "" } });
const { t } = useI18n();

const groupId = computed(() => {
  const q = (props.currentHash || window.location.hash).split("?")[1] || "";
  return Number(new URLSearchParams(q).get("id")) || null;
});

const loading = ref(true);
const error = ref("");
const group = ref(null);        // GET /groups/{id} — header + status extras
const members = ref([]);
const activity = ref([]);
const session = ref(null);      // open session detail (roster included)
const requests = ref([]);       // pending join requests (managers)
const plans = ref([]);          // for the create-session form

const isMember = computed(() => !!group.value?.my_role);
const canManage = computed(() => !!group.value?.can_manage);
const myUserId = ref(null);
const iAmReading = computed(() =>
  !!session.value?.participants?.some((p) => p.user?.id === myUserId.value));

// "Completed today" is judged against the viewer's local date — good enough
// for a status tile; each member's authoritative date lives server-side.
const todayStr = new Date().toLocaleDateString("sv");
const readToday = (p) => p.last_read_on === todayStr;
const completedToday = computed(() =>
  (session.value?.participants || []).filter(readToday).length);

async function load() {
  if (!groupId.value) return;
  loading.value = true;
  error.value = "";
  try {
    group.value = await api.group(groupId.value);
    const jobs = [
      api.groupMembers(groupId.value).then((r) => (members.value = r.members || [])),
      api.groupActivity(groupId.value).then((r) => (activity.value = r.activity || [])),
      api.groupService(groupId.value).then((r) => (gService.value = r.service)),
      api.me().then((r) => (myUserId.value = r.user?.id ?? r.id ?? null)).catch(() => {}),
    ];
    if (group.value.open_session) {
      jobs.push(api.readingSession(group.value.open_session.id).then((s) => (session.value = s)));
    } else {
      session.value = null;
    }
    if (group.value.can_manage) {
      jobs.push(api.groupJoinRequests(groupId.value).then((r) => (requests.value = r || [])));
      jobs.push(api.readingPlans().then((r) => (plans.value = r.plans || [])));
      jobs.push(api.myServices().then((r) => (myServices.value = r.services || [])).catch(() => {}));
    }
    await Promise.all(jobs);
  } catch (e) {
    error.value = e.status === 403 ? t("group.notFound") : e.message;
    group.value = null;
  } finally {
    loading.value = false;
  }
}
onMounted(load);
watch(groupId, load);

const gService = ref(null);     // the group's shared service (v1.4)
const myServices = ref([]);     // manager's own services for the share-picker
const shareToken = ref(null);

const busy = ref(false);
const actionError = ref("");
async function run(fn, reload = true) {
  busy.value = true;
  actionError.value = "";
  try {
    await fn();
    if (reload) await load();
  } catch (e) {
    actionError.value = e.message;
  } finally {
    busy.value = false;
  }
}

// ── Membership (join requests, requester side) ──────────────────────────────
const askToJoin = () => run(() => api.requestToJoin(groupId.value));
const withdraw = () => run(() => api.invitationCancel(group.value.my_pending_request));

// ── Reading session ──────────────────────────────────────────────────────────
const newPlanId = ref(null);
const createSession = () => run(() => api.createReadingSession(groupId.value, newPlanId.value));
const joinReading = () => run(() => api.joinReadingSession(session.value.id));
const sessionDo = (action) => run(() => api.readingSessionAction(session.value.id, action));

// ── Invitations (managers) ───────────────────────────────────────────────────
const mint = ref({ max_uses: null, days: 7 });
const copiedId = ref(null);
const mintLink = () => run(() => api.mintGroupLink(groupId.value, {
  max_uses: mint.value.max_uses || null,
  expires_at: new Date(Date.now() + mint.value.days * 864e5).toISOString(),
}));
const revokeLink = (id) => run(() => api.invitationCancel(id));
const inviteEmail = ref("");
const emailSentTo = ref("");
async function emailInvite() {
  const addr = inviteEmail.value;
  await run(() => api.emailGroupInvite(groupId.value, { email: addr }));
  if (!actionError.value) {
    emailSentTo.value = addr;
    inviteEmail.value = "";
    setTimeout(() => { if (emailSentTo.value === addr) emailSentTo.value = ""; }, 4000);
  }
}
async function copyLink(l) {
  try {
    await navigator.clipboard.writeText(l.join_url);
    copiedId.value = l.id;
    setTimeout(() => { if (copiedId.value === l.id) copiedId.value = null; }, 2000);
  } catch { /* URL stays visible for manual copy */ }
}

// ── Group service (v1.4): share one of MY generated services with the group ──
const shareService = () => run(() => api.shareGroupService(groupId.value, shareToken.value));
const unshareService = () => run(() => api.unshareGroupService(groupId.value));

// ── Join requests (manager side) ─────────────────────────────────────────────
const approve = (id) => run(() => api.invitationAccept(id));
const decline = (id) => run(() => api.invitationDecline(id));

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : "");
</script>

<template>
  <div class="group-page">
    <p v-if="loading" class="muted">{{ t("group.loading") }}</p>
    <p v-else-if="!group" class="gp-error">{{ error || t("group.notFound") }}</p>

    <template v-else>
      <!-- Header -->
      <section class="card">
        <div class="gp-head">
          <div>
            <h1>{{ group.name }}</h1>
            <p class="gp-meta">
              <span class="badge">{{ t(`church.types.${group.type}`) }}</span>
              <a class="badge" href="#church">⛪ {{ group.church?.name }}</a>
              <span v-if="group.my_role" class="badge role">{{ t(`church.role.${group.my_role}`) }}</span>
            </p>
            <p v-if="group.description" class="muted">{{ group.description }}</p>
            <p v-if="group.leaders?.length" class="muted small">
              {{ t("group.leaders") }}: {{ group.leaders.join(", ") }}
            </p>
          </div>
          <div v-if="!isMember" class="gp-join">
            <button v-if="!group.my_pending_request" class="btn" :disabled="busy" @click="askToJoin">
              {{ t("group.requests.ask") }}
            </button>
            <template v-else>
              <span class="badge">{{ t("group.requests.pending") }}</span>
              <button class="btn ghost" :disabled="busy" @click="withdraw">{{ t("group.requests.withdraw") }}</button>
            </template>
          </div>
        </div>
        <p v-if="actionError" class="gp-error">{{ actionError }}</p>
      </section>

      <!-- Today's Status -->
      <section class="card gp-status">
        <h2>{{ t("group.status.title") }}</h2>
        <ul>
          <li>{{ session ? "✓ " + t("group.status.sessionActive") : t("group.status.noSession") }}</li>
          <li>{{ t("group.status.members", { n: group.member_count }) }}</li>
          <li v-if="session">{{ t("group.status.completedToday", { n: completedToday }) }}</li>
          <li v-if="canManage">{{ t("group.status.pendingRequests", { n: group.pending_request_count ?? 0 }) }}</li>
          <li v-if="canManage">{{ t("group.status.activeLinks", { n: group.links?.length ?? 0 }) }}</li>
        </ul>
      </section>

      <!-- Today's Reading — participation before administration -->
      <section class="card">
        <h2>{{ t("group.reading.title") }}</h2>

        <template v-if="session">
          <p class="gp-meta">
            <strong>{{ session.plan?.title }}</strong>
            <span class="badge live">{{ t(`group.reading.state.${session.status}`) }}</span>
            <span class="badge">{{ t("group.reading.participants", { n: session.participants?.length ?? 0 }) }}</span>
          </p>

          <p v-if="isMember && !iAmReading">
            <button class="btn" :disabled="busy" @click="joinReading">{{ t("group.reading.join") }}</button>
          </p>
          <p v-else-if="iAmReading" class="muted small">✓ {{ t("group.reading.joined") }} —
            <a href="#bible">📖</a>
          </p>

          <ul class="gp-roster">
            <li v-for="p in session.participants" :key="p.user?.id">
              <span :class="{ done: readToday(p) }">{{ readToday(p) ? "✓" : "·" }}</span>
              {{ p.user?.name }}
              <span class="muted small">{{ t("group.reading.day", { n: p.current_sequence }) }}</span>
              <span v-if="readToday(p)" class="badge live small">{{ t("group.reading.completedToday") }}</span>
            </li>
          </ul>

          <p v-if="canManage" class="gp-controls">
            <button v-if="session.status === 'planned'" class="btn" :disabled="busy" @click="sessionDo('start')">{{ t("group.reading.start") }}</button>
            <button v-if="session.status === 'active'" class="btn ghost" :disabled="busy" @click="sessionDo('pause')">{{ t("group.reading.pause") }}</button>
            <button v-if="session.status === 'paused'" class="btn" :disabled="busy" @click="sessionDo('resume')">{{ t("group.reading.resume") }}</button>
            <button v-if="session.status !== 'planned'" class="btn ghost" :disabled="busy" @click="sessionDo('complete')">{{ t("group.reading.complete") }}</button>
          </p>
        </template>

        <template v-else>
          <p class="muted">{{ t("group.reading.none") }}</p>
          <form v-if="canManage && plans.length" class="gp-mint" @submit.prevent="createSession">
            <select v-model="newPlanId" required>
              <option :value="null" disabled>{{ t("group.reading.choosePlan") }}</option>
              <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.title }} ({{ p.day_count }}d)</option>
            </select>
            <button class="btn" type="submit" :disabled="busy || !newPlanId">{{ t("group.reading.createBtn") }}</button>
          </form>
        </template>
      </section>

      <!-- Group Service (v1.4): one shared generated service, opened by everyone -->
      <section class="card">
        <h2>{{ t("group.service.title") }}</h2>

        <template v-if="gService">
          <p class="gp-meta">
            <span class="badge live">🙏 {{ t("group.service.sharedBy", { name: gService.shared_by ?? "…" }) }}</span>
            <span class="muted small">{{ fmtDate(gService.created_at) }} · {{ (gService.language || "").toUpperCase() }}</span>
          </p>
          <p class="gp-controls">
            <a class="btn" :href="`#service?token=${gService.session_token}`">{{ t("group.service.open") }}</a>
            <button v-if="canManage" class="btn ghost" :disabled="busy" @click="unshareService">
              {{ t("group.service.unshare") }}
            </button>
          </p>
        </template>

        <template v-else>
          <p class="muted">{{ t("group.service.none") }}</p>
          <form v-if="canManage && myServices.length" class="gp-mint" @submit.prevent="shareService">
            <select v-model="shareToken" required>
              <option :value="null" disabled>{{ t("group.service.pick") }}</option>
              <option v-for="s in myServices" :key="s.session_token" :value="s.session_token">
                {{ fmtDate(s.created_at) }} · {{ (s.language || "").toUpperCase() }} · {{ s.status }}
              </option>
            </select>
            <button class="btn" type="submit" :disabled="busy || !shareToken">{{ t("group.service.share") }}</button>
          </form>
        </template>
      </section>

      <!-- Members -->
      <section class="card">
        <h2>{{ t("group.members.title") }} ({{ members.length }})</h2>
        <p class="gp-meta">
          <span v-for="m in members" :key="m.id" class="badge">
            {{ m.name }}<template v-if="m.role !== 'member'"> · {{ t(`church.role.${m.role}`) }}</template>
          </span>
        </p>
      </section>

      <!-- Invitations (managers) -->
      <section v-if="canManage" class="card">
        <h2>{{ t("group.invites.title") }}</h2>
        <form class="gp-mint" @submit.prevent="mintLink">
          <input v-model.number="mint.max_uses" type="number" min="1" max="10000" :placeholder="t('group.invites.maxUses')" />
          <label class="muted small">{{ t("group.invites.expiresDays") }}
            <select v-model.number="mint.days">
              <option :value="1">1</option><option :value="7">7</option>
              <option :value="30">30</option><option :value="90">90</option>
            </select>
          </label>
          <button class="btn" type="submit" :disabled="busy">{{ t("group.invites.mintBtn") }}</button>
        </form>
        <!-- Personal email invitation: delivers a single-use link; the join page
             handles register → auto-return → join for people with no account. -->
        <form class="gp-mint" @submit.prevent="emailInvite">
          <input v-model.trim="inviteEmail" type="email" :placeholder="t('group.invites.emailPlaceholder')" required />
          <button class="btn" type="submit" :disabled="busy || !inviteEmail">{{ t("group.invites.sendEmail") }}</button>
          <span v-if="emailSentTo" class="badge live">✓ {{ t("group.invites.sent", { email: emailSentTo }) }}</span>
        </form>
        <p v-if="!group.links?.length" class="muted">{{ t("group.invites.none") }}</p>
        <ul v-else class="gp-links">
          <li v-for="l in group.links" :key="l.id">
            <code class="gp-url">{{ l.join_url }}</code>
            <span class="muted small">
              {{ l.max_uses ? t("group.invites.uses", { used: l.use_count, max: l.max_uses })
                            : t("group.invites.unlimited", { used: l.use_count }) }}
              · {{ fmtDate(l.expires_at) }}
            </span>
            <button class="btn small" @click="copyLink(l)">{{ copiedId === l.id ? t("church.copied") : t("church.copyLink") }}</button>
            <button class="btn small ghost" :disabled="busy" @click="revokeLink(l.id)">{{ t("group.invites.revoke") }}</button>
          </li>
        </ul>
      </section>

      <!-- Join Requests (managers) -->
      <section v-if="canManage" class="card">
        <h2>{{ t("group.requests.title") }} ({{ requests.length }})</h2>
        <p v-if="!requests.length" class="muted">{{ t("group.requests.none") }}</p>
        <ul v-else class="gp-requests">
          <li v-for="r in requests" :key="r.id">
            <strong>{{ r.inviter?.name }}</strong>
            <span v-if="r.message" class="muted">“{{ r.message }}”</span>
            <button class="btn small" :disabled="busy" @click="approve(r.id)">{{ t("group.requests.approve") }}</button>
            <button class="btn small ghost" :disabled="busy" @click="decline(r.id)">{{ t("group.requests.decline") }}</button>
          </li>
        </ul>
      </section>

      <!-- Recent Activity — human sentences, never raw event names -->
      <section class="card">
        <h2>{{ t("group.activity.title") }}</h2>
        <p v-if="!activity.length" class="muted">{{ t("group.activity.none") }}</p>
        <ul v-else class="gp-activity">
          <li v-for="(a, i) in activity" :key="i">
            <span class="muted small">{{ fmtDate(a.at) }}</span>
            {{ t(`group.activity.${a.type}`, { actor: a.actor ?? "…", subject: a.subject ?? "…" }) }}
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.group-page { width: min(880px, 100%); margin: 0 auto; padding: 1rem; display: flex; flex-direction: column; gap: 1rem; }
.group-page .card { margin: 0; }
.muted { color: var(--text-muted, #888); }
.small { font-size: 0.8rem; }
.gp-error { color: var(--danger, #c0392b); font-size: 0.9rem; }
.gp-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
.gp-meta { display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; margin: 0.4rem 0; }
.badge { font-size: 0.75rem; padding: 0.15rem 0.6rem; border-radius: 999px; background: var(--surface-2, rgba(128,128,128,.15)); text-decoration: none; color: inherit; }
.badge.role { background: var(--accent, #3b82f6); color: #fff; }
.badge.live { background: var(--success, #16a34a); color: #fff; }
.btn { padding: 0.35rem 0.9rem; border-radius: 8px; border: 1px solid var(--border, #ccc); background: var(--accent, #3b82f6); color: #fff; cursor: pointer; }
.btn.ghost { background: transparent; color: inherit; }
.btn.small { padding: 0.2rem 0.6rem; font-size: 0.8rem; }
.btn:disabled { opacity: 0.6; cursor: default; }
.gp-status ul { list-style: none; padding: 0; margin: 0.5rem 0 0; display: flex; gap: 0.5rem 1.25rem; flex-wrap: wrap; }
.gp-roster, .gp-links, .gp-requests, .gp-activity { list-style: none; padding: 0; margin: 0.6rem 0 0; display: flex; flex-direction: column; gap: 0.4rem; }
.gp-roster .done { color: var(--success, #16a34a); font-weight: 700; }
.gp-controls { display: flex; gap: 0.5rem; margin-top: 0.6rem; }
.gp-mint { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin: 0.5rem 0; }
.gp-mint input, .gp-mint select { padding: 0.4rem 0.6rem; border-radius: 8px; border: 1px solid var(--border, #ccc); background: transparent; color: inherit; }
.gp-links li, .gp-requests li { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.gp-url { font-size: 0.75rem; overflow-wrap: anywhere; flex: 1 1 12rem; opacity: 0.8; }
</style>
