<script setup>
// Member Directory (v1.3 Phase F) — deliberately lightweight: search, role
// filter, group badges. Rosters are small, so filtering is client-side over
// the single directory payload. This is a directory, not an admin console —
// role changes and removals stay with future administration work.
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
    churchName.value = churches[0].name;
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
            </div>
            <div class="dir-meta">
              <span v-for="g in m.groups" :key="g" class="badge">{{ g }}</span>
              <span v-if="m.joined_at" class="muted small">
                {{ t("church.directory.joined", { date: fmtDate(m.joined_at) }) }}
              </span>
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
.dir-filters input, .dir-filters select { padding: 0.45rem 0.6rem; border-radius: 8px; border: 1px solid var(--border, #ccc); background: transparent; color: inherit; }
.dir-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem; }
.dir-list li { padding: 0.6rem 0; border-bottom: 1px solid var(--border, rgba(128,128,128,.2)); }
.dir-name { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.dir-meta { display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap; margin-top: 0.3rem; }
.badge { font-size: 0.75rem; padding: 0.15rem 0.6rem; border-radius: 999px; background: var(--surface-2, rgba(128,128,128,.15)); }
.badge.role { background: var(--accent, #3b82f6); color: #fff; }
</style>
