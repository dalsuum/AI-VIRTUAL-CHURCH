<template>
  <div class="account">
    <h2 class="acct-title">{{ t("account.title") }}</h2>

    <!-- Role badge -->
    <div class="role-row">
      <span class="role-badge" :class="role">{{ roleLabel }}</span>
    </div>

    <!-- Name -->
    <section class="acct-section">
      <h3>{{ t("account.displayName") }}</h3>
      <form @submit.prevent="saveName" class="acct-form">
        <input v-model="nameVal" type="text" :placeholder="t('account.namePlaceholder')" maxlength="255" class="acct-input" />
        <button type="submit" class="acct-btn" :disabled="savingName || !nameVal.trim()">
          {{ savingName ? t("common.saving") : t("account.saveName") }}
        </button>
      </form>
      <p v-if="nameMsg" class="acct-msg" :class="nameMsgClass">{{ nameMsg }}</p>
    </section>

    <!-- Spiritual profile preferences (registered users only) -->
    <section class="acct-section" v-if="!isGuest">
      <h3>{{ t("account.spiritualProfile") }}</h3>
      <form @submit.prevent="saveProfile" class="acct-form col">
        <label class="acct-label">{{ t("account.favLanguage") }}
          <select v-model="profile.fav_language" class="acct-input">
            <option value="">—</option><option value="en">English</option>
            <option value="my">Burmese</option><option value="td">Tedim (Zolai)</option>
          </select>
        </label>
        <label class="acct-label">{{ t("account.favBibleVersion") }}
          <input v-model="profile.fav_bible_version" class="acct-input" maxlength="12" :placeholder="t('account.favBiblePlaceholder')" />
        </label>
        <label class="acct-label">{{ t("account.favWorshipLanguage") }}
          <select v-model="profile.fav_worship_language" class="acct-input">
            <option value="">—</option><option value="en">English</option>
            <option value="my">Burmese</option><option value="td">Tedim (Zolai)</option>
          </select>
        </label>
        <label class="acct-label">{{ t("account.spiritualGoals") }}
          <textarea v-model="profile.spiritual_goals" class="acct-input" rows="2" maxlength="2000"
            :placeholder="t('account.goalsPlaceholder')"></textarea>
        </label>
        <label class="acct-checkbox">
          <input type="checkbox" v-model="profile.ai_memory_enabled" />
          {{ t("account.aiMemory") }}
        </label>
        <button type="submit" class="acct-btn" :disabled="savingProfile">
          {{ savingProfile ? t("common.saving") : t("account.saveProfile") }}
        </button>
      </form>
      <p v-if="profileMsg" class="acct-msg" :class="profileMsgClass">{{ profileMsg }}</p>
    </section>

    <!-- Change password (registered users only) -->
    <section class="acct-section" v-if="!isGuest">
      <h3>{{ t("account.changePassword") }}</h3>
      <form @submit.prevent="savePassword" class="acct-form col">
        <input v-model="currentPw" type="password" :placeholder="t('account.currentPassword')" class="acct-input" autocomplete="current-password" />
        <input v-model="newPw"     type="password" :placeholder="t('account.newPassword')" class="acct-input" autocomplete="new-password" />
        <input v-model="confirmPw" type="password" :placeholder="t('account.confirmPassword')" class="acct-input" autocomplete="new-password" />
        <button type="submit" class="acct-btn" :disabled="savingPw">
          {{ savingPw ? t("common.saving") : t("account.changePassword") }}
        </button>
      </form>
      <p v-if="pwMsg" class="acct-msg" :class="pwMsgClass">{{ pwMsg }}</p>
    </section>

    <!-- Subscription & tokens (registered users only) -->
    <section class="acct-section" v-if="!isGuest">
      <h3>{{ t("account.subscription") }}</h3>
      <div class="sub-card">
        <div class="sub-row">
          <span class="plan-badge" :class="plan">{{ planLabel }}</span>
          <span v-if="subStatus && subStatus !== 'active'" class="sub-status">{{ subStatus }}</span>
        </div>

        <!-- Token gauge -->
        <div class="token-line">
          <div class="token-bar">
            <div class="token-fill" :style="{ width: tokenPct + '%' }"></div>
          </div>
          <span class="token-text">{{ t("account.tokens", { balance: tokenBalance, allowance: monthlyAllowance }) }}</span>
        </div>

        <p v-if="expiresAt" class="sub-meta">{{ t("account.renewsEnds", { date: formatDate(expiresAt) }) }}</p>

        <div class="sub-actions">
          <!-- Hide the upgrade CTA entirely when no payment provider is configured,
               so we never offer a checkout that can't complete. Premium members can
               still cancel (that path doesn't need a new checkout). -->
          <template v-if="isPremium">
            <button class="acct-btn ghost" :disabled="subBusy" @click="cancel">
              {{ subBusy ? t("account.working") : t("account.cancel") }}
            </button>
          </template>
          <template v-else-if="billingEnabled">
            <button class="acct-btn" :disabled="subBusy" @click="upgrade">
              {{ subBusy ? t("account.redirecting") : t("account.upgrade") }}
            </button>
          </template>
          <p v-else class="sub-meta">{{ t("account.noUpgrade") }}</p>
        </div>
        <p v-if="subMsg" class="acct-msg" :class="subMsgClass">{{ subMsg }}</p>
      </div>
    </section>

    <!-- Token history (registered users only) -->
    <section class="acct-section" v-if="!isGuest">
      <h3>
        {{ t("account.tokenHistory") }}
        <button class="link-btn" @click="toggleHistory">{{ showHistory ? t("common.hide") : t("common.show") }}</button>
      </h3>
      <ul v-if="showHistory" class="ledger">
        <li v-for="(e, i) in history" :key="i" class="ledger-row">
          <span class="ledger-type">{{ e.type }}</span>
          <span class="ledger-ref">{{ e.reference || "—" }}</span>
          <span class="ledger-amt" :class="e.amount < 0 ? 'neg' : 'pos'">
            {{ e.amount > 0 ? "+" : "" }}{{ e.amount }}
          </span>
        </li>
        <li v-if="!history.length" class="ledger-empty">{{ t("account.noActivity") }}</li>
      </ul>
    </section>

    <button class="close-btn" @click="emit('close')">← {{ t("common.back") }}</button>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const { t, te } = useI18n();

const emit = defineEmits(["close", "nameChanged"]);



const role    = ref("member");
const isGuest = ref(false);
const nameVal = ref("");

const savingName = ref(false);
const nameMsg    = ref("");
const nameMsgClass = ref("ok");

// Spiritual profile preferences.
const profile = ref({
  fav_language: "", fav_bible_version: "", fav_worship_language: "",
  spiritual_goals: "", ai_memory_enabled: true,
});
const savingProfile = ref(false);
const profileMsg = ref("");
const profileMsgClass = ref("ok");

const currentPw = ref("");
const newPw     = ref("");
const confirmPw = ref("");
const savingPw  = ref(false);
const pwMsg     = ref("");
const pwMsgClass = ref("ok");

const roleLabel = computed(() => te(`account.roles.${role.value}`) ? t(`account.roles.${role.value}`) : role.value);

// Subscription + token state.
const plan             = ref("member");
const subStatus        = ref("active");
const isPremium        = ref(false);
const expiresAt        = ref(null);
const tokenBalance     = ref(0);
const monthlyAllowance = ref(0);
const billingEnabled   = ref(true); // assume on until /subscription says otherwise
const subBusy          = ref(false);
const subMsg           = ref("");
const subMsgClass      = ref("ok");
const showHistory      = ref(false);
const history          = ref([]);

const planLabel = computed(() => te(`account.plans.${plan.value}`) ? t(`account.plans.${plan.value}`) : plan.value);
const tokenPct  = computed(() => {
  if (!monthlyAllowance.value) return 0;
  return Math.max(0, Math.min(100, Math.round((tokenBalance.value / monthlyAllowance.value) * 100)));
});

function formatDate(d) {
  try { return new Date(d).toLocaleDateString(); } catch { return d; }
}

async function loadSubscription() {
  try {
    const s = await api.subscriptionStatus();
    plan.value             = s.plan || "member";
    subStatus.value        = s.status || "active";
    isPremium.value        = !!s.is_premium;
    expiresAt.value        = s.expires_at || null;
    tokenBalance.value     = s.token_balance ?? 0;
    monthlyAllowance.value = s.monthly_allowance ?? 0;
    billingEnabled.value   = s.billing_enabled !== false;
  } catch { /* leave defaults */ }
}

async function upgrade() {
  subBusy.value = true; subMsg.value = "";
  try {
    const { checkout_url } = await api.subscriptionCheckout();
    if (checkout_url) window.location.href = checkout_url;
  } catch (e) {
    subMsg.value = e.data?.message || t("account.errCheckout");
    subMsgClass.value = "err";
    subBusy.value = false;
  }
}

async function cancel() {
  if (!confirm("Cancel your premium subscription? You'll keep access until the period ends.")) return;
  subBusy.value = true; subMsg.value = "";
  try {
    const res = await api.subscriptionCancel();
    subMsg.value = res.message; subMsgClass.value = "ok";
    await loadSubscription();
  } catch (e) {
    subMsg.value = e.data?.message || t("account.errCancel");
    subMsgClass.value = "err";
  } finally {
    subBusy.value = false;
  }
}

async function toggleHistory() {
  showHistory.value = !showHistory.value;
  if (showHistory.value && !history.value.length) {
    try { history.value = (await api.tokenHistory()).entries || []; } catch { /* ignore */ }
  }
}

onMounted(async () => {
  try {
    const res = await api.me();
    role.value    = res.user.role || "member";
    isGuest.value = res.user.is_guest || false;
    nameVal.value = res.user.name || "";
    const u = res.user;
    profile.value = {
      fav_language: u.fav_language || "",
      fav_bible_version: u.fav_bible_version || "",
      fav_worship_language: u.fav_worship_language || "",
      spiritual_goals: u.spiritual_goals || "",
      ai_memory_enabled: u.ai_memory_enabled !== false,
    };
  } catch { /* stay with defaults */ }
  if (!isGuest.value) await loadSubscription();
});

async function saveProfile() {
  savingProfile.value = true;
  profileMsg.value = "";
  try {
    await api.updateProfile(profile.value);
    profileMsg.value = t("account.profileSaved");
    profileMsgClass.value = "ok";
  } catch (e) {
    profileMsg.value = e.data?.message || t("account.errSaveProfile");
    profileMsgClass.value = "err";
  } finally {
    savingProfile.value = false;
  }
}

async function saveName() {
  if (!nameVal.value.trim()) return;
  savingName.value = true;
  nameMsg.value = "";
  try {
    await api.updateName(nameVal.value.trim());
    nameMsg.value = t("account.nameUpdated");
    nameMsgClass.value = "ok";
    emit("nameChanged", nameVal.value.trim());
  } catch (e) {
    nameMsg.value = e.data?.message || t("account.errSaveName");
    nameMsgClass.value = "err";
  } finally {
    savingName.value = false;
  }
}

async function savePassword() {
  pwMsg.value = "";
  if (newPw.value !== confirmPw.value) {
    pwMsg.value = t("account.pwMismatch");
    pwMsgClass.value = "err";
    return;
  }
  if (newPw.value.length < 8) {
    pwMsg.value = t("account.pwTooShort");
    pwMsgClass.value = "err";
    return;
  }
  savingPw.value = true;
  try {
    await api.changePassword(currentPw.value, newPw.value);
    pwMsg.value = t("account.pwChanged");
    pwMsgClass.value = "ok";
    currentPw.value = newPw.value = confirmPw.value = "";
  } catch (e) {
    pwMsg.value = e.data?.message || t("account.errChangePassword");
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

/* Subscription card */
.sub-card {
  border: 1px solid var(--border, #e5e7eb); border-radius: var(--radius-sm, 8px);
  padding: 1rem; background: var(--surface, #fff);
}
.sub-row { display: flex; align-items: center; gap: .6rem; margin-bottom: .75rem; }
.plan-badge {
  display: inline-block; padding: .25rem .75rem; border-radius: 999px;
  font-size: .78rem; font-weight: 700; letter-spacing: .03em; text-transform: uppercase;
  background: var(--surface-2, #f3f4f6); color: var(--text-muted, #666);
}
.plan-badge.member  { background: var(--primary-soft, #eff6ff); color: var(--primary, #2563eb); }
.plan-badge.premium { background: linear-gradient(90deg,#fef3c7,#fde68a); color: #92400e; }
.sub-status { font-size: .75rem; text-transform: capitalize; color: #b45309; font-weight: 600; }

.token-line { display: flex; align-items: center; gap: .6rem; margin: .5rem 0; }
.token-bar { flex: 1; height: 8px; border-radius: 999px; background: var(--surface-2, #eef0f2); overflow: hidden; }
.token-fill { height: 100%; background: var(--primary, #2563eb); transition: width .3s; }
.token-text { font-size: .8rem; color: var(--text-muted, #555); white-space: nowrap; }

.sub-meta { font-size: .78rem; color: var(--text-muted, #777); margin: .25rem 0 .75rem; }
.sub-actions { margin-top: .5rem; }
.acct-btn.ghost { background: none; color: var(--primary, #2563eb); border: 1px solid var(--primary, #2563eb); }
.acct-btn.ghost:hover:not(:disabled) { background: var(--primary-soft, #eff6ff); }

.link-btn { background: none; border: none; color: var(--primary, #2563eb); font-size: .8rem; cursor: pointer; margin-left: .5rem; }
.ledger { list-style: none; padding: 0; margin: 0; font-size: .82rem; }
.ledger-row { display: flex; align-items: center; gap: .5rem; padding: .35rem 0; border-bottom: 1px solid var(--border, #f0f0f0); }
.ledger-type { text-transform: capitalize; color: var(--text-muted, #555); flex: 0 0 5.5rem; }
.ledger-ref { flex: 1; color: var(--text-muted, #999); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ledger-amt { font-variant-numeric: tabular-nums; font-weight: 600; }
.ledger-amt.neg { color: #dc2626; }
.ledger-amt.pos { color: #16a34a; }
.ledger-empty { color: var(--text-muted, #999); padding: .5rem 0; }
</style>
