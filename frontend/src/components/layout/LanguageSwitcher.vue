<script setup>
// Language selector. Options come entirely from the backend registry (loaded by
// i18n/loadRegistry from GET /api/languages), so there is no hardcoded language
// list here — adding a language is a backend-only change. Selecting one switches
// vue-i18n + <html lang/dir> and persists the choice.
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { getRegistry, setLocale } from "../../i18n";

const { locale, t } = useI18n();
const registry = ref(getRegistry());

function onChange(e) {
  setLocale(e.target.value);
}
</script>

<template>
  <label class="lang-switch" :title="t('common.selectLanguage')">
    <span class="sr-only">{{ t("common.language") }}</span>
    <select :value="locale" :aria-label="t('common.selectLanguage')" @change="onChange">
      <option v-for="(meta, code) in registry" :key="code" :value="code">
        {{ meta.native_name }}
      </option>
    </select>
  </label>
</template>

<style scoped>
.lang-switch {
  display: inline-flex;
  min-width: 0;
}
.lang-switch select {
  max-width: 180px;
  min-width: 0;
  background: none;
  color: var(--text-muted);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 0.3rem 0.5rem;
  font-size: 0.8rem;
  cursor: pointer;
}
.lang-switch select:hover { color: var(--primary); border-color: var(--primary); }
.sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}
@media (max-width: 640px) {
  .lang-switch {
    flex: 1 1 auto;
  }
  .lang-switch select {
    width: 100%;
    max-width: 48vw;
    min-height: 36px;
  }
}
</style>
