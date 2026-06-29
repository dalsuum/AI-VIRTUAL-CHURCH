<script setup>
// Global site header — rendered once by AppLayout and shared across every route.
// The nav is data-driven: add a page by adding one entry to NAV_ITEMS below and
// its route in App.vue. `active` highlighting is derived from the current hash.
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ThemeToggle from "../ThemeToggle.vue";
import LanguageSwitcher from "./LanguageSwitcher.vue";

const { t } = useI18n();

const props = defineProps({
  currentHash: { type: String, default: "" },
  isAuthed: { type: Boolean, default: false },
  isAdmin: { type: Boolean, default: false },
  fathersDayEnabled: { type: Boolean, default: false },
  fdTitle: { type: String, default: "Happy Father's Day" },
  stickersEnabled: { type: Boolean, default: false },
});

defineEmits(["logout", "toggle-journey"]);

// Single source of truth for the primary nav. `show` gates visibility,
// `prefix` matches sub-routes (e.g. #pastor?session=…), `responsive` renders a
// full/short label pair that collapses to the short form (or icon) on phones.
const navItems = computed(() =>
  [
    { href: "#bible", label: `📖 ${t("nav.bible")}` },
    { href: "#bible-study", label: `💬 ${t("nav.bibleStudy")}` },
    { href: "#pastor", label: `💬 ${t("nav.pastor")}`, prefix: true },
    { href: "#worship", label: `🎶 ${t("nav.worship")}` },
    { href: "#lyrics", label: `🎵 ${t("nav.lyrics")}` },
    { href: "#vocabulary", label: `📖 ${t("nav.vocabulary")}` },
    { href: "#learn", label: `🌐 ${t("nav.learn")}` },
    { href: "#journey", label: `📊 ${t("nav.journey")}`, prefix: true, show: props.isAuthed },
    {
      href: "#fathers-day", icon: "💙", labelFull: props.fdTitle, labelShort: "MV",
      responsive: true, show: props.fathersDayEnabled,
    },
    { href: "#stickers", label: `🎨 ${t("nav.stickers")}`, show: props.stickersEnabled },
    { href: "#admin", label: `🛠 ${t("nav.admin")}`, show: props.isAdmin },
    {
      href: "#account", icon: "👤", labelFull: t("nav.account"), labelShort: "",
      responsive: true, show: props.isAuthed,
    },
  ].filter((item) => item.show !== false),
);

function isActive(item) {
  return item.prefix
    ? props.currentHash.startsWith(item.href)
    : props.currentHash === item.href;
}
</script>

<template>
  <header class="topbar">
    <button
      v-if="isAuthed"
      class="hamburger"
      type="button"
      aria-label="Toggle My Journey"
      @click="$emit('toggle-journey')"
    >☰</button>
    <a class="brand" href="#">
      <span class="brand-mark" aria-hidden="true">✝</span>
      <span class="brand-name">{{ t("brand") }}</span>
    </a>
    <div class="topbar-right">
      <nav class="topbar-nav">
        <a
          v-for="item in navItems"
          :key="item.href"
          :href="item.href"
          class="nav-link nav-page"
          :class="{ active: isActive(item) }"
        >
          <template v-if="item.responsive">
            {{ item.icon }} <span class="nav-label-full">{{ item.labelFull }}</span>
            <span v-if="item.labelShort" class="nav-label-short">{{ item.labelShort }}</span>
          </template>
          <template v-else>{{ item.label }}</template>
        </a>

        <!-- Identity-aware auth controls: Logout for members, Login/Register for guests. -->
        <button v-if="isAuthed" class="nav-link nav-btn" @click="$emit('logout')">{{ t("auth.logout") }}</button>
        <template v-else>
          <a href="#login" class="nav-link" :class="{ active: currentHash === '#login' }">{{ t("auth.login") }}</a>
          <a href="#register" class="nav-link" :class="{ active: currentHash === '#register' }">{{ t("auth.register") }}</a>
        </template>
      </nav>
      <LanguageSwitcher />
      <ThemeToggle />
    </div>
  </header>
</template>

<style scoped>
.topbar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1.25rem;
  background: color-mix(in srgb, var(--bg) 80%, transparent);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--border);
  min-width: 0;
}
.hamburger {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; flex: 0 0 auto;
  background: none; border: 1px solid var(--border); border-radius: 8px;
  color: var(--text); font-size: 1.05rem; line-height: 1; cursor: pointer;
}
.hamburger:hover { color: var(--primary); border-color: var(--primary); }
.topbar-right { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
.topbar-nav { display: flex; align-items: center; gap: 0.25rem; min-width: 0; }
.nav-link {
  display: inline-flex; align-items: center; gap: 0.25rem;
  padding: 0.35rem 0.65rem;
  font-size: 0.8rem; font-family: "Padauk", "Noto Sans Myanmar", sans-serif;
  color: var(--text-muted); text-decoration: none;
  border: 1px solid transparent; border-radius: var(--radius-sm);
  transition: color 0.12s, border-color 0.12s, background 0.12s;
}
.nav-link:hover { color: var(--primary); border-color: var(--border); }
.nav-btn { background: none; cursor: pointer; font-family: inherit; }
.nav-link.active { color: var(--primary); background: var(--primary-soft); border-color: var(--primary); font-weight: 600; }
.nav-label-short { display: none; }
@media (max-width: 640px) {
  /* Mobile shell uses the bottom nav for page navigation, so the header only
     keeps identity controls (Login / Register / Logout). Hide the page links. */
  .nav-page { display: none; }
  .nav-label-full { display: none; }
  .nav-label-short { display: inline; }
  .topbar {
    flex-wrap: wrap;
    gap: 0.45rem 0.55rem;
    padding: 0.6rem 0.75rem;
  }
  .topbar .brand {
    flex: 1 1 calc(100% - 44px);
    min-width: 0;
    margin-inline-end: 0;
  }
  .topbar-right {
    flex: 1 0 100%;
    width: 100%;
    justify-content: space-between;
    gap: 0.5rem;
  }
  .topbar-nav {
    order: 2;
    flex: 0 1 auto;
    justify-content: flex-end;
    overflow: visible;
  }
  .topbar-right :deep(.lang-switch) {
    order: 1;
    min-width: 0;
  }
  .topbar-right :deep(.theme-toggle),
  .topbar-right > :last-child {
    order: 3;
    flex: 0 0 auto;
  }
  .nav-link {
    flex: 0 1 auto;
    min-height: 36px;
    padding: 0.35rem 0.55rem;
  }
}
/* Keep logo + wordmark at the start edge (next to the hamburger); the auto
   margin pushes the auth controls + theme toggle to the far edge instead of
   space-between spreading the brand into the centre. */
.brand { display: inline-flex; align-items: center; gap: 0.55rem; min-width: 0; margin-inline-end: auto; text-decoration: none; color: var(--text); font-weight: 600; }
.brand-mark {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: 9px;
  background: var(--primary); color: var(--on-primary); font-size: 1rem;
}
.brand-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.98rem; letter-spacing: 0; }
</style>
