<script setup>
// Church Dashboard (v1.3 Phase F) — the collaboration home. Composes the v1.3
// backend: church profile, ministry groups (with the viewer's own role and any
// open reading session), member roster, and the viewer's active invite links.
// Group cards link to the Group Page (#group?id=…), the collaboration workspace.
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const { t } = useI18n();

const loading = ref(true);
const error = ref("");
const churches = ref([]);      // [{id,name,role}] — my active memberships
const selectedId = ref(null);
const profile = ref(null);
const groups = ref([]);
const members = ref([]);
const inviteLinks = ref([]);
const feed = ref([]);

const myRole = computed(() => churches.value.find((c) => c.id === selectedId.value)?.role);
// Thresholds mirror the backend policies (GroupPolicy::create = leader+); the
// server remains the authority — these only decide what UI to offer.
const canCreateGroup = computed(() =>
  ["leader", "deacon", "elder", "pastor", "owner"].includes(myRole.value));

async function loadChurch(id) {
  selectedId.value = id;
  error.value = "";
  try {
    const [p, g, m, inv, act] = await Promise.all([
      api.church(id), api.churchGroups(id), api.churchMembers(id),
      api.myInvitations(), api.churchActivity(id),
    ]);
    profile.value = p;
    groups.value = g.groups || [];
    members.value = m.members || [];
    inviteLinks.value = (inv.sent || []).filter(
      (i) => i.kind === "link" && i.status === "pending" && i.group,
    );
    feed.value = act.activity || [];
  } catch (e) {
    error.value = e.message;
  }
}

onMounted(async () => {
  try {
    const res = await api.myChurches();
    churches.value = res.churches || [];
    if (churches.value.length) await loadChurch(churches.value[0].id);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});

// ── New group (leaders+) ─────────────────────────────────────────────────────
const GROUP_TYPES = ["bible_study", "youth", "children", "women", "men", "choir", "prayer", "custom"];
const showCreate = ref(false);
const creating = ref(false);
const createError = ref("");
const newGroup = ref({ name: "", type: "custom", description: "" });

async function createGroup() {
  creating.value = true;
  createError.value = "";
  try {
    const g = await api.createGroup(selectedId.value, newGroup.value);
    groups.value = [...groups.value, g].sort((a, b) => a.name.localeCompare(b.name));
    newGroup.value = { name: "", type: "custom", description: "" };
    showCreate.value = false;
  } catch (e) {
    createError.value = e.message;
  } finally {
    creating.value = false;
  }
}

// ── Invite links ─────────────────────────────────────────────────────────────
const copiedId = ref(null);
async function copyLink(link) {
  try {
    await navigator.clipboard.writeText(link.join_url);
    copiedId.value = link.id;
    setTimeout(() => { if (copiedId.value === link.id) copiedId.value = null; }, 2000);
  } catch { /* clipboard unavailable — the URL stays visible for manual copy */ }
}

const memberPreview = computed(() => members.value.slice(0, 8));
</script>

<template>
  <div class="church-page">
    <p v-if="loading" class="muted">{{ t("church.loading") }}</p>
    <p v-else-if="error" class="church-error">{{ error }}</p>

    <div v-else-if="!churches.length" class="card">
      <h1>{{ t("church.title") }}</h1>
      <p class="muted">{{ t("church.noChurch") }}</p>
    </div>

    <template v-else>
      <!-- Church picker — only when the user belongs to several. -->
      <div v-if="churches.length > 1" class="church-picker">
        <button
          v-for="c in churches" :key="c.id"
          class="picker-chip" :class="{ active: c.id === selectedId }"
          @click="loadChurch(c.id)"
        >{{ c.name }}</button>
      </div>

      <!-- Profile card -->
      <section v-if="profile" class="card church-profile">
        <div class="profile-head">
          <img v-if="profile.logo_url" :src="profile.logo_url" alt="" class="church-logo" />
          <div>
            <h1>{{ profile.name }}</h1>
            <p v-if="profile.description" class="muted">{{ profile.description }}</p>
            <p class="church-meta">
              <span v-if="myRole" class="badge">{{ t("church.myRole") }}: {{ t(`church.role.${myRole}`) }}</span>
              <span class="badge">{{ members.length }} {{ t("church.members") }}</span>
              <span class="badge">{{ groups.length }} {{ t("church.groups") }}</span>
            </p>
          </div>
        </div>
        <dl class="profile-facts">
          <template v-if="profile.address"><dt>📍</dt><dd style="white-space:pre-line">{{ profile.address }}</dd></template>
          <template v-if="profile.contact_email || profile.contact_phone">
            <dt>{{ t("church.contact") }}</dt>
            <dd>{{ [profile.contact_email, profile.contact_phone].filter(Boolean).join(" · ") }}</dd>
          </template>
          <template v-if="profile.website">
            <dt>{{ t("church.website") }}</dt>
            <dd><a :href="profile.website" target="_blank" rel="noopener noreferrer">{{ profile.website }}</a></dd>
          </template>
          <template v-if="profile.languages?.length">
            <dt>{{ t("church.languages") }}</dt><dd>{{ profile.languages.join(", ").toUpperCase() }}</dd>
          </template>
        </dl>
      </section>

      <!-- Groups -->
      <section class="card">
        <div class="section-head">
          <h2>{{ t("church.groups") }}</h2>
          <button v-if="canCreateGroup && !showCreate" class="btn-small" @click="showCreate = true">
            ＋ {{ t("church.newGroup") }}
          </button>
        </div>

        <form v-if="showCreate" class="create-form" @submit.prevent="createGroup">
          <input v-model.trim="newGroup.name" :placeholder="t('church.groupName')" required minlength="2" maxlength="120" />
          <select v-model="newGroup.type" :aria-label="t('church.groupType')">
            <option v-for="ty in GROUP_TYPES" :key="ty" :value="ty">{{ t(`church.types.${ty}`) }}</option>
          </select>
          <input v-model.trim="newGroup.description" :placeholder="t('church.groupDescription')" maxlength="500" />
          <div class="form-actions">
            <button type="submit" class="btn-small" :disabled="creating">{{ t("church.create") }}</button>
            <button type="button" class="btn-small ghost" @click="showCreate = false">{{ t("church.cancel") }}</button>
          </div>
          <p v-if="createError" class="church-error">{{ createError }}</p>
        </form>

        <p v-if="!groups.length" class="muted">{{ t("church.noGroups") }}</p>
        <div v-else class="group-grid">
          <a v-for="g in groups" :key="g.id" class="group-card" :href="`#group?id=${g.id}`">
            <div class="group-title">
              <strong>{{ g.name }}</strong>
              <span class="badge">{{ t(`church.types.${g.type}`) }}</span>
            </div>
            <p v-if="g.description" class="muted group-desc">{{ g.description }}</p>
            <p class="church-meta">
              <span class="badge">{{ g.member_count }} {{ t("church.members") }}</span>
              <span v-if="g.my_role" class="badge role">{{ t(`church.role.${g.my_role}`) }}</span>
              <span v-if="g.open_session" class="badge live">
                📖 {{ t("church.openSession") }} · {{ g.open_session.plan_title }} ({{ g.open_session.status }})
              </span>
            </p>
          </a>
        </div>
      </section>

      <!-- My active invite links -->
      <section v-if="inviteLinks.length" class="card">
        <h2>{{ t("church.inviteLinks") }}</h2>
        <ul class="invite-list">
          <li v-for="l in inviteLinks" :key="l.id">
            <span class="badge">{{ l.group?.name }}</span>
            <code class="invite-url">{{ l.join_url }}</code>
            <span v-if="l.max_uses" class="muted">{{ l.use_count }}/{{ l.max_uses }}</span>
            <button class="btn-small" @click="copyLink(l)">
              {{ copiedId === l.id ? t("church.copied") : t("church.copyLink") }}
            </button>
          </li>
        </ul>
      </section>

      <!-- Members preview → full directory -->
      <section class="card">
        <div class="section-head">
          <h2>{{ t("church.members") }} ({{ members.length }})</h2>
          <a href="#members" class="muted">{{ t("church.viewAll") }}</a>
        </div>
        <p class="church-meta">
          <span v-for="m in memberPreview" :key="m.id" class="badge">
            {{ m.name }} · {{ t(`church.role.${m.role}`) }}
          </span>
          <span v-if="members.length > memberPreview.length" class="muted">
            +{{ members.length - memberPreview.length }}
          </span>
        </p>
      </section>

      <!-- Recent activity — curated human sentences over the domain events -->
      <section class="card">
        <h2>{{ t("church.feed.title") }}</h2>
        <p v-if="!feed.length" class="muted">{{ t("church.feed.none") }}</p>
        <ul v-else class="feed-list">
          <li v-for="(a, i) in feed" :key="i">
            <span class="muted feed-date">{{ new Date(a.at).toLocaleDateString() }}</span>
            {{ t(`church.feed.${a.type}`, { actor: a.actor ?? "…", subject: a.subject ?? "…" }) }}
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.church-page { width: min(960px, 100%); margin: 0 auto; padding: 1rem; display: flex; flex-direction: column; gap: 1rem; }
.church-page .card { margin: 0; }
.muted { color: var(--text-muted, #888); }
.church-error { color: var(--danger, #c0392b); font-size: 0.9rem; }
.church-picker { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.picker-chip { padding: 0.35rem 0.9rem; border-radius: 999px; border: 1px solid var(--border, #ccc); background: transparent; cursor: pointer; }
.picker-chip.active { background: var(--accent, #3b82f6); color: #fff; border-color: transparent; }
.profile-head { display: flex; gap: 1rem; align-items: center; }
.church-logo { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; }
.church-meta { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.4rem; }
.badge { font-size: 0.75rem; padding: 0.15rem 0.6rem; border-radius: 999px; background: var(--surface-2, rgba(128,128,128,.15)); }
.badge.role { background: var(--accent, #3b82f6); color: #fff; }
.badge.live { background: var(--success, #16a34a); color: #fff; }
.profile-facts { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 0.75rem; margin-top: 0.75rem; font-size: 0.9rem; }
.profile-facts dt { opacity: 0.7; }
.section-head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.btn-small { padding: 0.3rem 0.8rem; border-radius: 8px; border: 1px solid var(--border, #ccc); background: var(--accent, #3b82f6); color: #fff; cursor: pointer; }
.btn-small.ghost { background: transparent; color: inherit; }
.btn-small:disabled { opacity: 0.6; cursor: default; }
.create-form { display: flex; flex-direction: column; gap: 0.5rem; margin: 0.75rem 0; }
.create-form input, .create-form select { padding: 0.45rem 0.6rem; border-radius: 8px; border: 1px solid var(--border, #ccc); background: transparent; color: inherit; }
.form-actions { display: flex; gap: 0.5rem; }
.group-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem; margin-top: 0.75rem; }
.group-card { display: block; padding: 0.85rem; border: 1px solid var(--border, #ccc); border-radius: 12px; text-decoration: none; color: inherit; }
.group-card:hover { border-color: var(--accent, #3b82f6); }
.group-title { display: flex; justify-content: space-between; gap: 0.5rem; align-items: baseline; }
.group-desc { font-size: 0.85rem; margin: 0.3rem 0 0; }
.invite-list { list-style: none; padding: 0; margin: 0.5rem 0 0; display: flex; flex-direction: column; gap: 0.5rem; }
.invite-list li { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.invite-url { font-size: 0.75rem; overflow-wrap: anywhere; flex: 1 1 12rem; opacity: 0.8; }
.feed-list { list-style: none; padding: 0; margin: 0.5rem 0 0; display: flex; flex-direction: column; gap: 0.4rem; }
.feed-date { font-size: 0.75rem; margin-right: 0.4rem; }
</style>
