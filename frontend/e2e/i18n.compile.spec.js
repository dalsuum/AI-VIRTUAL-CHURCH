import { test, expect } from "@playwright/test";
import { readFileSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { baseCompile } from "@intlify/message-compiler";

// Regression guard for the "Invalid linked format" outage: an unescaped `@` in a
// locale string (e.g. `you@example.com`) is parsed by vue-i18n as a linked-message
// token and throws while compiling, which aborts the rendering component and leaves
// an empty card. Here we compile EVERY message in EVERY locale through vue-i18n's
// actual message compiler (`@intlify/message-compiler`, the same one the prod bundle
// uses), so a malformed string fails CI instead of the production UI. We compile
// directly rather than via `t()` because the runtime swallows compile errors into a
// console warning, whereas the production render path throws. Pure data check — no
// browser/page needed.

const LOCALES_DIR = join(dirname(fileURLToPath(import.meta.url)), "../src/i18n/locales");

// Flatten nested message objects into [dottedPath, stringValue] pairs.
function leafEntries(obj, prefix = "") {
  const entries = [];
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === "object" && !Array.isArray(v)) entries.push(...leafEntries(v, path));
    else if (typeof v === "string") entries.push([path, v]);
  }
  return entries;
}

const files = readdirSync(LOCALES_DIR).filter((f) => f.endsWith(".json"));

for (const file of files) {
  const code = file.replace(/\.json$/, "");
  test(`locale ${code} compiles every message`, () => {
    const messages = JSON.parse(readFileSync(join(LOCALES_DIR, file), "utf8"));
    const failures = [];
    for (const [key, value] of leafEntries(messages)) {
      try {
        // Throws on malformed syntax (unescaped @, bad linked/plural format).
        baseCompile(value, { onError: (e) => { throw e; } });
      } catch (e) {
        failures.push(`${key}: ${e.message}`);
      }
    }
    expect(failures, `Uncompilable messages in ${file}:\n${failures.join("\n")}`).toEqual([]);
  });
}
