<script setup>
// Member Directory (v1.3 Phase F) — deliberately lightweight: search, role
// filter, group badges. Rosters are small, so filtering is client-side over
// the single directory payload. v1.4 adds CHURCH ROLE GOVERNANCE for elders+:
// an explicit change-role flow (choose role → optional reason → confirm), with
// strict-dominance rules enforced server-side — the UI only offers what the
// backend would allow (roles strictly below your own; never yourself/owner).
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const { t } = useI18n();

const loading = ref(true);
const error = ref("");
const churchName = ref("");
const members = ref([]);
const search = ref("");
const roleFilter = ref("");

const ROLES = ["guest", "member", "leader", "deacon", "elder", "pastor", "owner"];
const LEVEL = Object.fromEntries(ROLES.map((r, i) => [r, i]));

// Governance context (mirrors the server rules; the server stays authoritative).
const churchId = ref(null);
const myRole = ref("");
const myId = ref(null);
const canGovern = computed(() => LEVEL[myRole.value] >= LEVEL.elder);
const assignable = computed(() =>
  ROLES.filter((r) => r !== "owner" && LEVEL[r] < LEVEL[myRole.value]));
const canEdit = (m) => canGovern.value && m.id !== myId.value && LEVEL[m.role] < LEVEL[myRole.value];

const editing = ref(null);   // { id, role, reason }
const busy = ref(false);
async function confirmRole() {
  busy.value = true;
  error.value = "";
  try {
    await api.setChurchMemberRole(churchId.value, editing.value.id, {
      role: editing.value.role, reason: editing.value.reason || null,
    });
    const res = await api.churchMembers(churchId.value);
    members.value = res.members || [];
    editing.value = null;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return members.value.filter((m) =>
    (!q || (m.name || "").toLowerCase().includes(q)
        || (m.groups || []).some((g) => g.toLowerCase().includes(q)))
    && (!roleFilter.value || m.role === roleFilter.value));
});

onMounted(async () => {
  try {
    const { churches } = await api.myChurches();
    if (!churches?.length) return;
    churchId.value = churches[0].id;
    churchName.value = churches[0].name;
    myRole.value = churches[0].role || "";
    api.me().then((r) => (myId.value = r.user?.id ?? r.id ?? null)).catch(() => {});
    const res = await api.churchMembers(churches[0].id);
    members.value = res.members || [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : "");
</script>

<template>
  <div class="dir-page">
    <div class="card">
      <p><a href="#church" class="muted">{{ t("church.directory.back") }}</a></p>
      <h1>{{ t("church.directory.title") }}<template v-if="churchName"> · {{ churchName }}</template></h1>

      <p v-if="loading" class="muted">{{ t("church.loading") }}</p>
      <p v-else-if="error" class="dir-error">{{ error }}</p>

      <template v-else>
        <div class="dir-filters">
          <input v-model="search" type="search" :placeholder="t('church.directory.search')" />
          <select v-model="roleFilter">
            <option value="">{{ t("church.directory.allRoles") }}</option>
            <option v-for="r in ROLES" :key="r" :value="r">{{ t(`church.role.${r}`) }}</option>
          </select>
        </div>

        <p v-if="!filtered.length" class="muted">{{ t("church.directory.empty") }}</p>
        <ul v-else class="dir-list">
          <li v-for="m in filtered" :key="m.id">
            <div class="dir-name">
              <strong>{{ m.name }}</strong>
              <span class="badge role">{{ t(`church.role.${m.role}`) }}</span>
              <span v-if="m.status !== 'active'" class="badge">{{ m.status }}</span>
              <button
                v-if="canEdit(m) && editing?.id !== m.id"
                class="btn-small ghost" @click="editing = { id: m.id, role: m.role, reason: '' }"
              >{{ t("church.directory.changeRole") }}</button>
            </div>
            <div class="dir-meta">
              <span v-for="g in m.groups" :key="g" class="badge">{{ g }}</span>
              <span v-if="m.joined_at" class="muted small">
                {{ t("church.directory.joined", { date: fmtDate(m.joined_at) }) }}
              </span>
            </div>
            <!-- Explicit governance flow: choose role → optional reason → confirm. -->
            <div v-if="editing?.id === m.id" class="dir-govern">
              <select v-model="editing.role">
                <option v-for="r in assignable" :key="r" :value="r">{{ t(`church.role.${r}`) }}</option>
              </select>
              <input v-model.trim="editing.reason" :placeholder="t('church.directory.reason')" maxlength="300" />
              <button class="btn-small" :disabled="busy || editing.role === m.role" @click="confirmRole">
                {{ t("church.directory.confirm") }}
              </button>
              <button class="btn-small ghost" @click="editing = null">{{ t("church.cancel") }}</button>
            </div>
          </li>
        </ul>
      </template>
    </div>
  </div>
</template>

<style scoped>
.dir-page { width: min(720px, 100%); margin: 0 auto; padding: 1rem; }
.muted { color: var(--text-muted, #888); }
.small { font-size: 0.8rem; }
.dir-error { color: var(--danger, #c0392b); font-size: 0.9rem; }
.dir-filters { display: flex; gap: 0.5rem; margin: 0.75rem 0; flex-wrap: wrap; }
.dir-filters input { flex: 1 1 12rem; }
.dir-filters input, .dir-filters select { padding: 0.5rem 0.75rem; border-radius: var(--radius-sm, 8px); border: 1px solid var(--border, #ccc); background: var(--surface, transparent); color: var(--text, inherit); min-height: 2.5rem; font-size: 0.9rem; }
.dir-filters input:focus-visible, .dir-filters select:focus-visible { outline: 2px solid var(--accent, #3b82f6); outline-offset: 1px; border-color: var(--accent, #3b82f6); }
.dir-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem; }
.dir-list li { padding: 0.6rem 0; border-bottom: 1px solid var(--border, rgba(128,128,128,.2)); }
.dir-name { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.dir-meta { display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; margin-top: 0.3rem; }
.badge { font-size: 0.75rem; padding: 0.15rem 0.6rem; border-radius: 999px; background: var(--surface-2, rgba(128,128,128,.15)); }
.badge.role { background: var(--accent, #3b82f6); color: #fff; }
.btn-small { padding: 0.2rem 0.7rem; border-radius: 8px; border: 1px solid var(--border, #ccc); background: var(--accent, #3b82f6); color: #fff; cursor: pointer; font-size: 0.8rem; }
.btn-small.ghost { background: transparent; color: inherit; }
.btn-small:disabled { opacity: 0.6; cursor: default; }
.dir-govern { display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; margin-top: 0.5rem; }
.dir-govern select, .dir-govern input { padding: 0.45rem 0.65rem; border-radius: var(--radius-sm, 8px); border: 1px solid var(--border, #ccc); background: var(--surface, transparent); color: var(--text, inherit); font-size: 0.85rem; }
.dir-govern input { flex: 1 1 10rem; }
</style>
