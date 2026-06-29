<template>
  <div class="reset-wrap">
    <!-- Forgot password: enter email -->
    <div v-if="step === 'forgot'">
      <h2 class="reset-title">{{ t("authReset.forgotTitle") }}</h2>
      <p class="reset-sub">{{ t("authReset.forgotSub") }}</p>
      <form @submit.prevent="submitForgot" class="reset-form">
        <input v-model="email" type="email" :placeholder="t('authReset.emailPh')" class="reset-input" autocomplete="email" required />
        <button type="submit" class="reset-btn" :disabled="submitting">
          {{ submitting ? t('authReset.sending') : t('authReset.sendLink') }}
        </button>
      </form>
      <p v-if="msg" class="reset-msg" :class="msgClass">{{ msg }}</p>
      <button class="reset-link" @click="emit('back')">{{ t("authReset.backToSignin") }}</button>
    </div>

    <!-- Use token to set new password -->
    <div v-else-if="step === 'reset'">
      <h2 class="reset-title">{{ t("authReset.newPwTitle") }}</h2>
      <p class="reset-sub">{{ t("authReset.newPwSub") }}</p>
      <form @submit.prevent="submitReset" class="reset-form col">
        <input
          v-model="token"
          type="text"
          :placeholder="t('authReset.tokenPh')"
          class="reset-input"
          autocomplete="off"
        />
        <input v-model="newPw"     type="password" :placeholder="t('authReset.newPwPh')" class="reset-input" autocomplete="new-password" />
        <input v-model="confirmPw" type="password" :placeholder="t('authReset.confirmPwPh')"    class="reset-input" autocomplete="new-password" />
        <button type="submit" class="reset-btn" :disabled="submitting">
          {{ submitting ? t('authReset.saving') : t('authReset.setPassword') }}
        </button>
      </form>
      <p v-if="msg" class="reset-msg" :class="msgClass">{{ msg }}</p>
      <button class="reset-link" @click="emit('back')">{{ t("authReset.backToSignin") }}</button>
    </div>

    <!-- Done -->
    <div v-else>
      <h2 class="reset-title">{{ t("authReset.doneTitle") }}</h2>
      <p class="reset-sub">{{ t("authReset.doneSub") }}</p>
      <button class="reset-btn" @click="emit('back')">{{ t("authReset.signin") }}</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const { t } = useI18n();
const props = defineProps({
  // Token can be pre-filled from the URL hash: #reset?token=XXX
  initialToken: { type: String, default: "" },
});
const emit = defineEmits(["back"]);

const step      = ref(props.initialToken ? "reset" : "forgot");
const email     = ref("");
const token     = ref(props.initialToken);
const newPw     = ref("");
const confirmPw = ref("");
const submitting = ref(false);
const msg       = ref("");
const msgClass  = ref("ok");

onMounted(() => {
  // Also pick up token from the URL hash (?token=…) in case component mounted
  // directly from a link click.
  if (!token.value) {
    const params = new URLSearchParams(window.location.hash.split("?")[1] || "");
    const t = params.get("token");
    if (t) { token.value = t; step.value = "reset"; }
  }
});

async function submitForgot() {
  msg.value = "";
  submitting.value = true;
  try {
    const res = await api.forgotPassword(email.value.trim());
    msg.value = res.message || t("authReset.checkInbox");
    msgClass.value = "ok";
  } catch (e) {
    msg.value = e.data?.message || t("authReset.requestFailed");
    msgClass.value = "err";
  } finally {
    submitting.value = false;
  }
}

async function submitReset() {
  msg.value = "";
  if (newPw.value !== confirmPw.value) {
    msg.value = t("authReset.mismatch"); msgClass.value = "err"; return;
  }
  if (newPw.value.length < 8) {
    msg.value = t("authReset.tooShort"); msgClass.value = "err"; return;
  }
  if (!token.value.trim()) {
    msg.value = t("authReset.needToken"); msgClass.value = "err"; return;
  }
  submitting.value = true;
  try {
    await api.resetPassword(token.value.trim(), newPw.value);
    step.value = "done";
    // Clean the token from the URL so a refresh doesn't re-open the form.
    window.history.replaceState({}, "", window.location.pathname);
  } catch (e) {
    msg.value = e.data?.message || t("authReset.resetFailed");
    msgClass.value = "err";
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.reset-wrap { max-width: 380px; }
.reset-title { margin: 0 0 .3rem; font-size: 1.2rem; }
.reset-sub { color: var(--text-muted, #666); font-size: .875rem; margin: 0 0 1.25rem; }

.reset-form { display: flex; gap: .5rem; align-items: flex-start; flex-wrap: wrap; }
.reset-form.col { flex-direction: column; }
.reset-input {
  flex: 1; min-width: 0; padding: .55rem .75rem;
  border: 1px solid var(--border, #e5e7eb); border-radius: var(--radius-sm, 6px);
  background: var(--surface, #fff); color: var(--text, #111); font-size: .9rem;
}
.reset-input:focus { outline: none; border-color: var(--primary, #2563eb); }
.reset-btn {
  padding: .55rem 1.1rem; border-radius: var(--radius-sm, 6px);
  background: var(--primary, #2563eb); color: var(--on-primary, #fff);
  border: none; font-size: .9rem; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.reset-btn:disabled { opacity: .5; cursor: default; }
.reset-btn:hover:not(:disabled) { background: var(--primary-hover, #1d4ed8); }

.reset-msg { margin: .5rem 0 0; font-size: .85rem; }
.reset-msg.ok  { color: #16a34a; }
.reset-msg.err { color: #dc2626; }

.reset-link {
  display: inline-block; margin-top: .9rem;
  background: none; border: none; color: var(--text-muted, #666);
  font-size: .85rem; cursor: pointer; padding: 0; text-decoration: underline;
  text-underline-offset: 2px;
}
.reset-link:hover { color: var(--text); }
</style>
