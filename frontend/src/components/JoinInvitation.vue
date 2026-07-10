<script setup>
// Invitation landing (v1.3 Phase F) — where every join_url / QR code arrives:
// #join?token=…  Preview is PUBLIC and informational; redeem() is the
// authoritative check (validity, expiry, revocation, permissions), so we never
// assume the link is still good after the user comes back from authenticating —
// the preview is re-fetched on every mount and redemption re-validates anyway.
// Unauthenticated users authenticate without the invitation being mentioned;
// auth.intended (read by App.vue's navigateAfterAuth) brings them back here.
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const props = defineProps({
  currentHash: { type: String, default: "" },
  isAuthed: { type: Boolean, default: false },
});
const { t } = useI18n();

const token = computed(() => {
  const q = (props.currentHash || window.location.hash).split("?")[1] || "";
  return new URLSearchParams(q).get("token") || "";
});

const state = ref("loading");   // loading | preview | joined | invalid
const preview = ref(null);
const error = ref("");
const busy = ref(false);
const joinedGroupId = ref(null);

async function load() {
  if (!token.value) {
    state.value = "invalid";
    return;
  }
  state.value = "loading";
  error.value = "";
  try {
    preview.value = await api.previewInvitation(token.value);
    state.value = "preview";
  } catch {
    state.value = "invalid";   // unknown/revoked-and-pruned token → 404
  }
}
onMounted(load);
watch(token, load);

async function join() {
  busy.value = true;
  error.value = "";
  try {
    const res = await api.redeemInvitation(token.value);
    joinedGroupId.value = res.group_id;
    state.value = "joined";
  } catch (e) {
    error.value = e.message;   // 409: revoked / expired / no remaining uses
  } finally {
    busy.value = false;
  }
}

// Authenticate first, then come back here — the stored intent survives login.
function authenticate(mode) {
  sessionStorage.setItem("auth.intended", window.location.hash);
  window.location.hash = mode;
}

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : "");
</script>

<template>
  <div class="join-page">
    <div class="card join-card">
      <p v-if="state === 'loading'" class="muted">{{ t("join.checking") }}</p>

      <template v-else-if="state === 'invalid'">
        <h1>{{ t("join.title") }}</h1>
        <p class="join-error">{{ t("join.invalid") }}</p>
      </template>

      <template v-else-if="state === 'preview'">
        <h1>{{ t("join.title") }}</h1>
        <p class="join-headline">
          {{ t("join.toGroup", { group: preview.group?.name, church: preview.church?.name }) }}
        </p>
        <p class="join-meta">
          <span class="badge">{{ t(`church.types.${preview.group?.type}`) }}</span>
          <span class="badge">{{ t("join.members", { n: preview.group?.member_count ?? 0 }) }}</span>
          <span v-if="preview.expires_at" class="badge">
            {{ t("join.validUntil", { date: fmtDate(preview.expires_at) }) }}
          </span>
        </p>
        <p v-if="preview.inviter?.name" class="muted">
          {{ t("join.invitedBy", { name: preview.inviter.name }) }}
        </p>
        <p v-if="preview.message" class="join-message">“{{ preview.message }}”</p>

        <p v-if="!preview.usable" class="join-error">{{ t("join.notUsable") }}</p>

        <div v-else class="join-actions">
          <button v-if="isAuthed" class="btn" :disabled="busy" @click="join">
            {{ t("join.join") }}
          </button>
          <template v-else>
            <button class="btn" @click="authenticate('#login')">{{ t("join.signIn") }}</button>
            <button class="btn ghost" @click="authenticate('#register')">{{ t("join.createAccount") }}</button>
          </template>
        </div>
        <p v-if="error" class="join-error">{{ error }}</p>
      </template>

      <template v-else-if="state === 'joined'">
        <h1>{{ t("join.welcome") }}</h1>
        <p class="join-headline">{{ t("join.joined", { group: preview.group?.name }) }}</p>
        <div class="join-actions column">
          <a class="btn" :href="`#group?id=${joinedGroupId}`">{{ t("join.goToGroup") }}</a>
          <a class="btn ghost" :href="`#group?id=${joinedGroupId}`">📖 {{ t("join.startReading") }}</a>
          <a class="btn ghost" href="#church">{{ t("join.toDashboard") }}</a>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.join-page { width: min(480px, 100%); margin: 0 auto; padding: 1.5rem 1rem; }
.join-card { text-align: center; }
.muted { color: var(--text-muted, #888); }
.join-headline { font-size: 1.1rem; margin: 0.5rem 0; }
.join-meta { display: flex; gap: 0.4rem; justify-content: center; flex-wrap: wrap; margin: 0.5rem 0; }
.badge { font-size: 0.75rem; padding: 0.15rem 0.6rem; border-radius: 999px; background: var(--surface-2, rgba(128,128,128,.15)); }
.join-message { font-style: italic; opacity: 0.85; }
.join-error { color: var(--danger, #c0392b); font-size: 0.9rem; margin-top: 0.5rem; }
.join-actions { display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap; margin-top: 1rem; }
.join-actions.column { flex-direction: column; align-items: stretch; }
.btn { display: inline-block; padding: 0.5rem 1.1rem; border-radius: 10px; border: 1px solid var(--border, #ccc); background: var(--accent, #3b82f6); color: #fff; cursor: pointer; text-decoration: none; text-align: center; }
.btn.ghost { background: transparent; color: inherit; }
.btn:disabled { opacity: 0.6; cursor: default; }
</style>
