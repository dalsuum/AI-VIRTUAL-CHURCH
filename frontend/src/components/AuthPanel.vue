<script setup>
// Public login / register entry. Hash-routed (#login / #register) to match the
// rest of the app — no client router. On success the parent reloads /me and
// redirects; register auto-logs-in server-side, so both paths land authenticated.
import { ref, computed, watch } from "vue";
import { api } from "../composables/useApi";

const props = defineProps({
  mode: { type: String, default: "login" }, // "login" | "register"
});
const emit = defineEmits(["authed"]);

const name     = ref("");
const email    = ref("");
const password = ref("");
const busy     = ref(false);
const error    = ref("");
// Set after a successful registration: we no longer auto-login, so the user is asked
// to activate via email before signing in.
const registered = ref(false);

const isRegister = computed(() => props.mode === "register");
const title   = computed(() => (isRegister.value ? "Create your account" : "Welcome back"));
const cta     = computed(() => (isRegister.value ? "Create account" : "Log in"));

// Clear transient state when switching between login/register.
watch(() => props.mode, () => { error.value = ""; password.value = ""; registered.value = false; });

async function submit() {
  error.value = "";
  if (!email.value.trim() || !password.value) {
    error.value = "Email and password are required.";
    return;
  }
  if (isRegister.value && !name.value.trim()) {
    error.value = "Please enter your name.";
    return;
  }
  busy.value = true;
  try {
    if (isRegister.value) {
      // Registration creates a PENDING account and emails an activation link — no
      // auto-login. Show the check-your-email confirmation instead of entering the app.
      await api.register({
        name: name.value.trim(),
        email: email.value.trim(),
        password: password.value,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
      });
      registered.value = true;
      password.value = "";
    } else {
      await api.login({ email: email.value.trim(), password: password.value });
      emit("authed");
    }
  } catch (e) {
    error.value = e?.data?.message || "Something went wrong. Please try again.";
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="auth-wrap">
    <!-- Post-registration confirmation: account is pending until the emailed link is clicked. -->
    <template v-if="registered">
      <h1 class="auth-title">Registration successful</h1>
      <p class="auth-sub">
        Please check your email and activate your account before signing in. The
        activation link expires in 24 hours.
      </p>
      <p class="auth-switch"><a href="#login">← Back to login</a></p>
    </template>

    <template v-else>
    <h1 class="auth-title">{{ title }}</h1>
    <p class="auth-sub">
      {{ isRegister ? "Register to keep your services, tokens and preferences." : "Log in to your account." }}
    </p>

    <form class="auth-form" @submit.prevent="submit">
      <label v-if="isRegister" class="field">
        <span>Name</span>
        <input v-model="name" type="text" autocomplete="name" placeholder="Your name" />
      </label>
      <label class="field">
        <span>Email</span>
        <input v-model="email" type="email" autocomplete="username" placeholder="you@example.com" />
      </label>
      <label class="field">
        <span>Password</span>
        <input
          v-model="password"
          type="password"
          :autocomplete="isRegister ? 'new-password' : 'current-password'"
          placeholder="••••••••"
        />
      </label>

      <p v-if="error" class="auth-error">{{ error }}</p>

      <button class="auth-btn" type="submit" :disabled="busy">
        {{ busy ? "Please wait…" : cta }}
      </button>
    </form>

    <p class="auth-switch">
      <template v-if="isRegister">
        Already have an account? <a href="#login">Log in</a>
      </template>
      <template v-else>
        New here? <a href="#register">Create an account</a>
      </template>
    </p>
    <p class="auth-switch">
      <a href="#">← Back to worship</a>
    </p>
    </template>
  </div>
</template>

<style scoped>
.auth-wrap { max-width: 380px; margin: 0 auto; }
.auth-title { font-size: 1.5rem; margin: 0 0 0.35rem; letter-spacing: -0.02em; }
.auth-sub { color: var(--text-muted); margin: 0 0 1.5rem; line-height: 1.5; font-size: 0.9rem; }
.auth-form { display: flex; flex-direction: column; gap: 0.9rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field span { font-size: 0.8rem; color: var(--text-muted); }
.field input {
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg);
  color: var(--text);
  font-size: 0.95rem;
}
.field input:focus { outline: none; border-color: var(--primary); }
.auth-error { color: var(--danger); font-size: 0.85rem; margin: 0; }
.auth-btn {
  margin-top: 0.4rem;
  padding: 0.7rem 1rem;
  border: none; border-radius: var(--radius-sm);
  background: var(--primary); color: var(--on-primary);
  font-size: 0.95rem; font-weight: 600; cursor: pointer;
  transition: filter 0.12s;
}
.auth-btn:hover:not(:disabled) { filter: brightness(1.06); }
.auth-btn:disabled { opacity: 0.6; cursor: default; }
.auth-switch { text-align: center; font-size: 0.85rem; color: var(--text-muted); margin: 1.1rem 0 0; }
.auth-switch a { color: var(--primary); text-decoration: none; }
.auth-switch a:hover { text-decoration: underline; }
</style>
