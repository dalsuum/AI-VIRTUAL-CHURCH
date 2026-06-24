// Offline Iconify registry.
//
// Per the design system: NO emojis in the UI — every icon is a scalable SVG
// from Iconify (Material Design Icons set). Icons are registered offline so the
// app never makes a runtime network call to the Iconify API.
//
// icons.data.json holds ONLY the icons the UI uses (not the full ~7000-icon mdi
// collection), keeping the JS bundle lean. Regenerate it with:
//   npm run icons:gen   (see scripts/gen-icons.mjs)
// To add an icon: add its mdi name to ICON_NAMES in scripts/gen-icons.mjs,
// run the script, then reference it as <AppIcon name="mdi:<name>" />.
import { addIcon } from "@iconify/vue";
import iconData from "./icons.data.json";

let registered = false;

// Register all bundled icons with @iconify/vue. Idempotent; called once at
// startup (see main.js).
export function registerIcons() {
  if (registered) return;
  for (const [name, data] of Object.entries(iconData)) {
    addIcon(name, data);
  }
  registered = true;
}
