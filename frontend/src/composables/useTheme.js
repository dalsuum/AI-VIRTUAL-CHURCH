// Light/dark theme, shared across the app. The choice is read once from
// localStorage (or the OS preference on a first visit), applied to <html> as
// data-theme so styles.css can paint from it, and persisted on every toggle.
import { ref } from "vue";

const STORAGE_KEY = "theme";

function preferred() {
  const saved = localStorage.getItem(STORAGE_KEY);
  if (saved === "light" || saved === "dark") return saved;
  return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches
    ? "dark"
    : "light";
}

function apply(value) {
  document.documentElement.setAttribute("data-theme", value);
}

const theme = ref(preferred());
apply(theme.value); // paint before first render so there's no flash

export function useTheme() {
  function setTheme(value) {
    theme.value = value;
    localStorage.setItem(STORAGE_KEY, value);
    apply(value);
  }
  function toggle() {
    setTheme(theme.value === "dark" ? "light" : "dark");
  }
  return { theme, toggle, setTheme };
}
