<script setup>
// Application shell: renders the global header + footer exactly once and slots
// the active route's content in between. This is the single place that owns the
// page chrome, so individual views never render their own header/footer.
//
// Future shell additions (NotificationBar, Breadcrumbs, FloatingActions, …) can
// slot in here without touching any page component.
import AppHeader from "./AppHeader.vue";
import AppFooter from "./AppFooter.vue";

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
  <div id="app-shell" class="page">
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
