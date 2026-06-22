<script setup>
// Global site header — rendered once by AppLayout and shared across every route.
// The nav is data-driven: add a page by adding one entry to NAV_ITEMS below and
// its route in App.vue. `active` highlighting is derived from the current hash.
import { computed } from "vue";
import ThemeToggle from "../ThemeToggle.vue";

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
    { href: "#bible", label: "📖 Bible" },
    { href: "#bible-study", label: "💬 Bible Study" },
    { href: "#pastor", label: "💬 Pastor", prefix: true },
    { href: "#worship", label: "🎶 Worship" },
    { href: "#lyrics", label: "🎵 သီချင်း" },
    { href: "#vocabulary", label: "📖 Vocabulary" },
    { href: "#journey", label: "📊 Journey", prefix: true, show: props.isAuthed },
    {
      href: "#fathers-day", icon: "💙", labelFull: props.fdTitle, labelShort: "MV",
      responsive: true, show: props.fathersDayEnabled,
    },
    { href: "#stickers", label: "🎨 Stickers", show: props.stickersEnabled },
    { href: "#admin", label: "🛠 Admin", show: props.isAdmin },
    {
      href: "#account", icon: "👤", labelFull: "Account", labelShort: "",
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
      <span class="brand-name">AI Virtual Church</span>
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
        <button v-if="isAuthed" class="nav-link nav-btn" @click="$emit('logout')">Logout</button>
        <template v-else>
          <a href="#login" class="nav-link" :class="{ active: currentHash === '#login' }">Login</a>
          <a href="#register" class="nav-link" :class="{ active: currentHash === '#register' }">Register</a>
        </template>
      </nav>
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
}
.hamburger {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; flex: 0 0 auto;
  background: none; border: 1px solid var(--border); border-radius: 8px;
  color: var(--text); font-size: 1.05rem; line-height: 1; cursor: pointer;
}
.hamburger:hover { color: var(--primary); border-color: var(--primary); }
.topbar-right { display: flex; align-items: center; gap: 0.75rem; }
.topbar-nav { display: flex; align-items: center; gap: 0.25rem; }
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
  /* Logo-only brand to free up width for the nav + theme toggle. */
  .brand-name { display: none; }
  .topbar { gap: 0.5rem; padding: 0.7rem 0.85rem; }
  /* Keep the nav from squeezing the theme toggle off-screen: let it scroll. */
  .topbar-right { gap: 0.5rem; min-width: 0; }
  .topbar-nav { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .topbar-nav::-webkit-scrollbar { display: none; }
  .nav-link { flex: 0 0 auto; }
  /* The theme toggle must always stay visible. */
  .theme-toggle, .topbar-right > :last-child { flex: 0 0 auto; }
}
.brand { display: inline-flex; align-items: center; gap: 0.55rem; text-decoration: none; color: var(--text); font-weight: 600; }
.brand-mark {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: 9px;
  background: var(--primary); color: var(--on-primary); font-size: 1rem;
}
.brand-name { font-size: 0.98rem; letter-spacing: -0.01em; }
</style>
