import { createApp } from "vue";
import App from "./App.vue";
import "./styles.css";
import { registerIcons } from "./icons";
import { i18n, loadRegistry } from "./i18n";

// Register the offline Iconify icon set before mount so <AppIcon> renders
// without any runtime network call to the Iconify API.
registerIcons();

// Load the language registry (and apply the stored/initial locale + dir) before
// mount so the first paint is in the user's language. Never blocks: on failure
// it falls back to English so the app always renders.
loadRegistry().finally(() => {
  createApp(App).use(i18n).mount("#app");
});
