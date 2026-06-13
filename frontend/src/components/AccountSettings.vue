<template>
  <div class="account">
    <h2 class="acct-title">My Account</h2>

    <!-- Role badge -->
    <div class="role-row">
      <span class="role-badge" :class="role">{{ roleLabel }}</span>
    </div>

    <!-- Name -->
    <section class="acct-section">
      <h3>Display Name</h3>
      <form @submit.prevent="saveName" class="acct-form">
        <input v-model="nameVal" type="text" placeholder="Your name" maxlength="255" class="acct-input" />
        <button type="submit" class="acct-btn" :disabled="savingName || !nameVal.trim()">
          {{ savingName ? 'Saving…' : 'Save Name' }}
        </button>
      </form>
      <p v-if="nameMsg" class="acct-msg" :class="nameMsgClass">{{ nameMsg }}</p>
    </section>

    <!-- Change password (registered users only) -->
    <section class="acct-section" v-if="!isGuest">
      <h3>Change Password</h3>
      <form @submit.prevent="savePassword" class="acct-form col">
        <input v-model="currentPw" type="password" placeholder="Current password" class="acct-input" autocomplete="current-password" />
        <input v-model="newPw"     type="password" placeholder="New password (8+ chars)" class="acct-input" autocomplete="new-password" />
        <input v-model="confirmPw" type="password" placeholder="Confirm new password" class="acct-input" autocomplete="new-password" />
        <button type="submit" class="acct-btn" :disabled="savingPw">
          {{ savingPw ? 'Saving…' : 'Change Password' }}
        </button>
      </form>
      <p v-if="pwMsg" class="acct-msg" :class="pwMsgClass">{{ pwMsg }}</p>
    </section>

    <button class="close-btn" @click="emit('close')">← Back</button>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import { api } from "../composables/useApi";

const emit = defineEmits(["close", "nameChanged"]);

const ROLE_LABELS = {
  admin: "Administrator",
  moderator: "Moderator",
  presenter: "Presenter",
  member: "Member",
  guest: "Guest",
};

const role    = ref("member");
const isGuest = ref(false);
const nameVal = ref("");

const savingName = ref(false);
const nameMsg    = ref("");
const nameMsgClass = ref("ok");

const currentPw = ref("");
const newPw     = ref("");
const confirmPw = ref("");
const savingPw  = ref(false);
const pwMsg     = ref("");
const pwMsgClass = ref("ok");

const roleLabel = computed(() => ROLE_LABELS[role.value] || role.value);

onMounted(async () => {
  try {
    const res = await api.me();
    role.value    = res.user.role || "member";
    isGuest.value = res.user.is_guest || false;
    nameVal.value = res.user.name || "";
  } catch { /* stay with defaults */ }
});

async function saveName() {
  if (!nameVal.value.trim()) return;
  savingName.value = true;
  nameMsg.value = "";
  try {
    await api.updateName(nameVal.value.trim());
    nameMsg.value = "Name updated.";
    nameMsgClass.value = "ok";
    emit("nameChanged", nameVal.value.trim());
  } catch (e) {
    nameMsg.value = e.data?.message || "Failed to update name.";
    nameMsgClass.value = "err";
  } finally {
    savingName.value = false;
  }
}

async function savePassword() {
  pwMsg.value = "";
  if (newPw.value !== confirmPw.value) {
    pwMsg.value = "New passwords do not match.";
    pwMsgClass.value = "err";
    return;
  }
  if (newPw.value.length < 8) {
    pwMsg.value = "New password must be at least 8 characters.";
    pwMsgClass.value = "err";
    return;
  }
  savingPw.value = true;
  try {
    await api.changePassword(currentPw.value, newPw.value);
    pwMsg.value = "Password changed.";
    pwMsgClass.value = "ok";
    currentPw.value = newPw.value = confirmPw.value = "";
  } catch (e) {
    pwMsg.value = e.data?.message || "Failed to change password.";
    pwMsgClass.value = "err";
  } finally {
    savingPw.value = false;
  }
}
</script>

<style scoped>
.account { max-width: 420px; }
.acct-title { margin: 0 0 .75rem; font-size: 1.2rem; }

.role-row { margin-bottom: 1.25rem; }
.role-badge {
  display: inline-block; padding: .25rem .75rem; border-radius: 999px;
  font-size: .78rem; font-weight: 600; letter-spacing: .03em; text-transform: uppercase;
  background: var(--surface-2, #f3f4f6); color: var(--text-muted, #666);
  border: 1px solid var(--border, #e5e7eb);
}
.role-badge.admin     { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.role-badge.moderator { background: #ede9fe; color: #5b21b6; border-color: #ddd6fe; }
.role-badge.presenter { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.role-badge.member    { background: var(--primary-soft, #eff6ff); color: var(--primary, #2563eb); border-color: #bfdbfe; }

.acct-section { margin-bottom: 1.5rem; }
.acct-section h3 { margin: 0 0 .6rem; font-size: .95rem; color: var(--text-muted, #555); font-weight: 600; }

.acct-form { display: flex; gap: .5rem; align-items: flex-start; }
.acct-form.col { flex-direction: column; }
.acct-input {
  flex: 1; padding: .55rem .75rem; border: 1px solid var(--border, #e5e7eb);
  border-radius: var(--radius-sm, 6px); background: var(--surface, #fff);
  color: var(--text, #111); font-size: .9rem; min-width: 0;
}
.acct-input:focus { outline: none; border-color: var(--primary, #2563eb); }
.acct-btn {
  padding: .55rem 1rem; border-radius: var(--radius-sm, 6px);
  background: var(--primary, #2563eb); color: var(--on-primary, #fff);
  border: none; font-size: .9rem; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.acct-btn:disabled { opacity: .5; cursor: default; }
.acct-btn:hover:not(:disabled) { background: var(--primary-hover, #1d4ed8); }

.acct-msg { margin: .4rem 0 0; font-size: .85rem; }
.acct-msg.ok  { color: #16a34a; }
.acct-msg.err { color: #dc2626; }

.close-btn {
  background: none; border: none; color: var(--text-muted, #666);
  font-size: .875rem; cursor: pointer; padding: 0; margin-top: .5rem;
}
.close-btn:hover { color: var(--text); }
</style>
