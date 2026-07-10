// Generates src/icons.data.json: the minimal subset of Iconify (mdi) icon data
// the UI actually uses. Run with `npm run icons:gen` after editing ICON_NAMES.
// This keeps the runtime bundle from carrying the full ~7000-icon collection.
import { getIconData } from "@iconify/utils";
import { writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const here = dirname(fileURLToPath(import.meta.url));

// Single source of truth for every mdi icon used across the app.
const ICON_NAMES = [
  // Bottom-nav primary tabs
  "home", "music-note", "book-cross", "book-open-page-variant", "dots-horizontal",
  // "More" sheet + chrome
  "radio", "chat", "chart-line", "translate", "heart", "sticker-emoji",
  "wrench", "account", "login", "logout", "account-plus", "close",
  "magnify", "cog",
  // Church collaboration (v1.3)
  "church",
  // Worship Radio
  "play", "pause", "skip-next", "stop",
  "flash", "emoticon-happy-outline", "target", "leaf", "heart-broken",
  // Theme toggle
  "weather-sunny", "moon-waning-crescent",
  // Songs screen (MyanmarLyrics)
  "arrow-left", "chevron-left", "chevron-right", "download",
  "file-pdf-box", "file-powerpoint-box", "file-document-outline",
  // Bible reader (BibleReader)
  "chevron-up", "chevron-down", "volume-high", "loading",
  "format-color-highlight", "autorenew", "clipboard-check-outline",
  "content-copy",
];

const collection = (
  await import("@iconify-json/mdi/icons.json", { with: { type: "json" } })
).default;

const out = {};
for (const name of ICON_NAMES) {
  const data = getIconData(collection, name);
  if (!data) {
    console.error(`Unknown mdi icon: ${name}`);
    process.exit(1);
  }
  out[`mdi:${name}`] = data;
}

writeFileSync(join(here, "..", "src", "icons.data.json"), JSON.stringify(out));
console.log(`Wrote ${Object.keys(out).length} icons to src/icons.data.json`);
