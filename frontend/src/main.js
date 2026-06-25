import { createApp } from "vue";
import App from "./App.vue";
import "./styles.css";
import { registerIcons } from "./icons";

// Register the offline Iconify icon set before mount so <AppIcon> renders
// without any runtime network call to the Iconify API.
registerIcons();

createApp(App).mount("#app");
