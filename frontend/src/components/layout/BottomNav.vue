<script setup>
// Mobile-first primary navigation: a fixed bottom tab bar (phones only, ≤640px)
// plus a "More" bottom sheet for the secondary sections. This is additive — the
// desktop top-bar nav in AppHeader stays the primary nav on ≥641px. See
// layout/LAYOUT_CONTRACT.md rule 8.
//
// Icons are Iconify (mdi) via <AppIcon>; no emojis (design-system rule).
import { computed, ref } from "vue";
import AppIcon from "../AppIcon.vue";

const props = defineProps({
  currentHash: { type: String, default: "" },
  isAuthed: { type: Boolean, default: false },
  isAdmin: { type: Boolean, default: false },
  fathersDayEnabled: { type: Boolean, default: false },
  fdTitle: { type: String, default: "Happy Father's Day" },
  stickersEnabled: { type: Boolean, default: false },
});

defineEmits(["logout"]);

// Four primary tabs — the highest-frequency worship surfaces. Everything else
// lives behind "More". `prefix` matches sub-routes (e.g. #pastor?session=…).
const tabs = [
  { href: "#", label: "Home", icon: "mdi:home", root: true },
  { href: "#pastor", label: "Pastor", icon: "mdi:chat", prefix: true },
  { href: "#worship", label: "Worship", icon: "mdi:radio" },
  { href: "#bible", label: "Bible", icon: "mdi:book-cross" },
];

// Secondary destinations shown in the "More" sheet. `show` gates visibility the
// same way AppHeader's nav does, so the sheet mirrors the user's permissions.
const moreItems = computed(() =>
  [
    { href: "#bible-study", label: "Bible Study", icon: "mdi:book-open-page-variant" },
    { href: "#lyrics", label: "Songs", icon: "mdi:music-note" },
    { href: "#journey", label: "Spiritual Journey", icon: "mdi:chart-line", prefix: true, show: props.isAuthed },
    { href: "#vocabulary", label: "Vocabulary", icon: "mdi:translate" },
    { href: "#fathers-day", label: props.fdTitle, icon: "mdi:heart", show: props.fathersDayEnabled },
    { href: "#stickers", label: "Live Stickers", icon: "mdi:sticker-emoji", show: props.stickersEnabled },
    { href: "#account", label: "Account", icon: "mdi:account", show: props.isAuthed },
    { href: "#admin", label: "Admin", icon: "mdi:wrench", show: props.isAdmin },
  ].filter((i) => i.show !== false),
);

// Normalise to the base route (strip ?query) for active-state comparison.
const baseHash = computed(() => {
  const h = (props.currentHash || "").split("?")[0];
  return h === "" ? "#" : h;
});

function matches(item) {
  if (item.root) return baseHash.value === "#";
  return item.prefix ? baseHash.value.startsWith(item.href) : baseHash.value === item.href;
}

// A tab is active when its route matches; the "More" tab is active whenever the
// current route is one of the secondary destinations.
const moreActive = computed(() => moreItems.value.some((i) => matches(i)));

const sheetOpen = ref(false);
function openSheet() { sheetOpen.value = true; }
function closeSheet() { sheetOpen.value = false; }
</script>

<template>
  <!-- Fixed bottom tab bar — phones only (hidden ≥641px via media query). -->
  <nav class="bottom-nav" aria-label="Primary">
    <a
      v-for="tab in tabs"
      :key="tab.href"
      :href="tab.href"
      class="bn-item"
      :class="{ active: matches(tab) }"
      :aria-current="matches(tab) ? 'page' : undefined"
    >
      <AppIcon :name="tab.icon" size="24px" />
      <span class="bn-label">{{ tab.label }}</span>
    </a>

    <button
      type="button"
      class="bn-item"
      :class="{ active: moreActive || sheetOpen }"
      :aria-expanded="sheetOpen"
      aria-haspopup="menu"
      @click="openSheet"
    >
      <AppIcon name="mdi:dots-horizontal" size="24px" />
      <span class="bn-label">More</span>
    </button>
  </nav>

  <!-- "More" bottom sheet -->
  <Teleport to="body">
    <div v-if="sheetOpen" class="sheet-backdrop" @click="closeSheet">
      <div class="sheet" role="menu" aria-label="More" @click.stop>
        <div class="sheet-head">
          <span class="sheet-title">More</span>
          <button type="button" class="sheet-close" aria-label="Close" @click="closeSheet">
            <AppIcon name="mdi:close" size="22px" />
          </button>
        </div>

        <div class="sheet-grid">
          <a
            v-for="item in moreItems"
            :key="item.href"
            :href="item.href"
            class="sheet-item"
            :class="{ active: matches(item) }"
            role="menuitem"
            @click="closeSheet"
          >
            <AppIcon :name="item.icon" size="26px" />
            <span class="sheet-label">{{ item.label }}</span>
          </a>
        </div>

        <div class="sheet-auth">
          <button v-if="isAuthed" type="button" class="sheet-row" role="menuitem" @click="$emit('logout'); closeSheet()">
            <AppIcon name="mdi:logout" size="22px" /><span>Logout</span>
          </button>
          <template v-else>
            <a href="#login" class="sheet-row" role="menuitem" @click="closeSheet">
              <AppIcon name="mdi:login" size="22px" /><span>Login</span>
            </a>
            <a href="#register" class="sheet-row" role="menuitem" @click="closeSheet">
              <AppIcon name="mdi:account-plus" size="22px" /><span>Register</span>
            </a>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* The bar itself is desktop-hidden: only phones get bottom navigation. */
.bottom-nav { display: none; }

@media (max-width: 640px) {
  .bottom-nav {
    position: fixed;
    left: 0; right: 0; bottom: 0;
    z-index: 40;
    display: flex;
    align-items: stretch;
    height: var(--bottom-nav-h);
    padding-bottom: env(safe-area-inset-bottom, 0px);
    background: color-mix(in srgb, var(--surface) 92%, transparent);
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--border);
  }
  .bn-item {
    flex: 1 1 0;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 2px;
    min-height: 44px; /* touch target */
    padding: 6px 2px;
    background: none; border: none; cursor: pointer;
    color: var(--text-faint); text-decoration: none;
    font: inherit;
    transition: color 0.12s;
  }
  .bn-item.active { color: var(--primary); }
  .bn-label { font-size: 11px; line-height: 1; font-family: "Padauk", "Noto Sans Myanmar", var(--font); }
}

/* Bottom sheet (also phone-only in practice; backdrop covers viewport). */
.sheet-backdrop {
  position: fixed; inset: 0; z-index: 60;
  background: rgba(0, 0, 0, 0.45);
  display: flex; align-items: flex-end;
}
.sheet {
  width: 100%;
  background: var(--surface);
  border-top-left-radius: var(--radius);
  border-top-right-radius: var(--radius);
  padding: 16px 16px calc(16px + env(safe-area-inset-bottom, 0px));
  box-shadow: var(--shadow);
  animation: sheet-up 0.18s ease-out;
}
@keyframes sheet-up { from { transform: translateY(100%); } to { transform: translateY(0); } }
.sheet-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.sheet-title { font-size: 16px; font-weight: 600; color: var(--text); }
.sheet-close { background: none; border: none; cursor: pointer; color: var(--text-muted); display: inline-flex; padding: 4px; }
.sheet-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.sheet-item {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
  min-height: 76px; padding: 12px 8px;
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface-2); color: var(--text); text-decoration: none;
  text-align: center;
}
.sheet-item.active { border-color: var(--primary); color: var(--primary); background: var(--primary-soft); }
.sheet-label { font-size: 12px; line-height: 1.2; font-family: "Padauk", "Noto Sans Myanmar", var(--font); }
.sheet-auth { display: flex; gap: 8px; margin-top: 12px; }
.sheet-row {
  flex: 1 1 0;
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  min-height: 44px; padding: 10px;
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface-2); color: var(--text); text-decoration: none;
  font: inherit; cursor: pointer;
}
</style>
