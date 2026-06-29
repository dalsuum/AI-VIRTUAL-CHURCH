<script setup>
// The offering segment. Giving is handled entirely by PayPal: we send the
// worshipper to a hosted donate button in a new tab and never touch card or
// account details ourselves. The amount/allocation choices here are guidance —
// the final amount is entered on PayPal's page.
import { ref } from "vue";
import { useI18n } from "vue-i18n";

const { t } = useI18n();
const emit = defineEmits(["skip"]);

const PAYPAL_DONATE_URL =
  "https://www.paypal.com/donate/?hosted_button_id=MEVYT7QZ2CHT6";

const PRESETS = [5, 10, 25, 50]; // whole currency units
// Allocation labels are resolved through i18n at render time (offering.<value>).
const ALLOCATIONS = ["operations", "charity", "missions"];

const amount = ref(10);
const allocation = ref("operations");
const stage = ref("choose"); // "choose" | "done" | "skipped"

function give() {
  window.open(PAYPAL_DONATE_URL, "_blank", "noopener");
  stage.value = "done";
}

function skip() {
  stage.value = "skipped";
  emit("skip");
}
</script>

<template>
  <section class="offering">
    <h2>{{ t("offering.title") }}</h2>

    <template v-if="stage === 'choose'">
      <p class="sub">{{ t("offering.sub") }}</p>

      <div class="amounts">
        <button
          v-for="p in PRESETS"
          :key="p"
          type="button"
          :class="{ active: Number(amount) === p }"
          @click="amount = p"
        >
          ${{ p }}
        </button>
        <input v-model.number="amount" type="number" min="1" step="1" :aria-label="t('offering.customAmount')" />
      </div>

      <label class="alloc">
        {{ t("offering.directToward") }}
        <select v-model="allocation">
          <option v-for="a in ALLOCATIONS" :key="a" :value="a">{{ t('offering.' + a) }}</option>
        </select>
      </label>

      <button class="primary" type="button" @click="give">
        {{ t("offering.give", { amount }) }}
      </button>
      <button class="secondary" type="button" @click="skip">
        {{ t("offering.maybeLater") }}
      </button>
    </template>

    <p v-else-if="stage === 'done'" class="thanks">
      {{ t("offering.thanksDone") }}
    </p>

    <p v-else class="thanks">{{ t("offering.thanksSkipped") }}</p>
  </section>
</template>

<style scoped>
.offering { margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border); }
.offering h2 { font-size: 1.15rem; margin: 0 0 0.25rem; letter-spacing: -0.01em; }
.sub { color: var(--text-muted); margin: 0 0 1rem; }
.amounts { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; }
.amounts button, .amounts input { padding: 0.55rem 0.9rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); cursor: pointer; }
.amounts button:hover { border-color: var(--border-strong); }
.amounts button.active { border-color: var(--primary); color: var(--primary-hover); background: var(--primary-soft); font-weight: 500; }
.amounts input { width: 6rem; cursor: text; }
.amounts input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.alloc { display: block; margin-bottom: 1rem; color: var(--text-muted); }
.alloc select { margin-left: 0.5rem; padding: 0.4rem 0.5rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font: inherit; }
.primary { width: 100%; padding: 0.75rem; border: 0; border-radius: var(--radius-sm); background: var(--primary); color: var(--on-primary); font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.12s ease; }
.primary:hover { background: var(--primary-hover); }
.secondary { width: 100%; margin-top: 0.6rem; padding: 0.6rem; border: 0; border-radius: var(--radius-sm); background: transparent; color: var(--text-muted); font-size: 0.9rem; cursor: pointer; }
.secondary:hover { color: var(--text); }
.thanks { color: var(--success); }
</style>
