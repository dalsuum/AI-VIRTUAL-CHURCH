<script setup>
// Application shell: renders the global header + footer exactly once and slots
// the active route's content in between. This is the single place that owns the
// page chrome, so individual views never render their own header/footer.
//
// Future shell additions (NotificationBar, Breadcrumbs, FloatingActions, …) can
// slot in here without touching any page component.
import { onMounted, onUnmounted } from "vue";
import AppHeader from "./AppHeader.vue";
import AppFooter from "./AppFooter.vue";

// Dev-only layout debugger: set `window.__layoutDebug = true` (or add
// `?layoutDebug` to the URL) to outline the shell regions and log scroll state.
// Stripped entirely from production builds via import.meta.env.DEV.
if (import.meta.env.DEV) {
  let logScroll;
  const apply = () => {
    const on =
      window.__layoutDebug === true ||
      new URLSearchParams(window.location.search).has("layoutDebug");
    document.documentElement.toggleAttribute("data-layout-debug", on);
  };
  onMounted(() => {
    apply();
    Object.defineProperty(window, "__layoutDebug", {
      configurable: true,
      get: () => document.documentElement.hasAttribute("data-layout-debug"),
      set: (v) => { v ? document.documentElement.setAttribute("data-layout-debug", "") : document.documentElement.removeAttribute("data-layout-debug"); },
    });
    logScroll = () => {
      if (document.documentElement.hasAttribute("data-layout-debug")) {
        console.log(`[layout] scrollY=${window.scrollY} hash=${window.location.hash}`);
      }
    };
    window.addEventListener("scroll", logScroll, { passive: true });
  });
  onUnmounted(() => window.removeEventListener("scroll", logScroll));
}

defineProps({
  currentHash: { type: String, default: "" },
  isAuthed: { type: Boolean, default: false },
  isAdmin: { type: Boolean, default: false },
  fathersDayEnabled: { type: Boolean, default: false },
  fdTitle: { type: String, default: "Happy Father's Day" },
  stickersEnabled: { type: Boolean, default: false },
});

defineEmits(["logout"]);
</script>

<template>
  <div id="app-shell" data-layout="app" class="page">
    <AppHeader
      :current-hash="currentHash"
      :is-authed="isAuthed"
      :is-admin="isAdmin"
      :fathers-day-enabled="fathersDayEnabled"
      :fd-title="fdTitle"
      :stickers-enabled="stickersEnabled"
      @logout="$emit('logout')"
    />

    <main class="app-main">
      <slot />
    </main>

    <AppFooter />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; min-height: 100vh; }
/* Holds whichever route is active; grows so the footer stays at the bottom.
   A flex column so a full-screen route view (e.g. the Bible reader) can fill
   the remaining viewport between the sticky header and the footer. */
.app-main { flex: 1; min-width: 0; min-height: 0; display: flex; flex-direction: column; }
.app-main > * { flex: 1 1 auto; min-height: 0; }
</style>

<!-- Global (unscoped) so it can outline child-component roots when the dev-only
     window.__layoutDebug flag is on. No effect unless the attribute is set. -->
<style>
html[data-layout-debug] header.topbar { outline: 2px solid #22d3ee !important; outline-offset: -2px; }
html[data-layout-debug] main.app-main { outline: 2px solid #f59e0b !important; outline-offset: -2px; }
html[data-layout-debug] footer.site-footer { outline: 2px solid #a855f7 !important; outline-offset: -2px; }
html[data-layout-debug] #app-shell::before {
  content: "layout-debug"; position: fixed; bottom: 4px; right: 4px; z-index: 9999;
  font: 11px/1.4 monospace; background: #111; color: #0ff; padding: 2px 6px; border-radius: 4px;
}
</style>
