<script setup>
// A small sun/moon button that flips the global theme. Used in both the worship
// shell and the admin console.
import { useTheme } from "../composables/useTheme";

const { theme, toggle } = useTheme();
</script>

<template>
  <button
    class="theme-toggle"
    type="button"
    @click="toggle"
    :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
    :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
  >
    <span aria-hidden="true">{{ theme === "dark" ? "☀️" : "🌙" }}</span>
  </button>
</template>

<style scoped>
.theme-toggle {
  width: 38px;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--border);
  border-radius: 50%;
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  font-size: 1rem;
  line-height: 1;
  box-shadow: var(--shadow-sm);
  transition: border-color 0.15s ease, transform 0.1s ease;
}
.theme-toggle:hover { border-color: var(--border-strong); }
.theme-toggle:active { transform: scale(0.94); }
</style>
