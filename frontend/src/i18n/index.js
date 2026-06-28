// Frontend i18n. The set of locales and their metadata (native name, rtl,
// formatting locale) comes from the backend registry (config/languages.php via
// GET /api/languages) — the single source of truth — so adding a language is a
// backend-only change. Only English UI strings are authored in Phase 1; every
// other locale falls back to English until its strings land.
import { createI18n } from "vue-i18n";
import en from "./locales/en.json";

const STORAGE_KEY = "locale";

// Date/number presentation. vue-i18n keys formats by locale; we generate the
// same option sets for every registry locale so Intl handles each natively
// (weekdays, months, grouping, etc.) without per-locale boilerplate.
const DATETIME = {
  short: { year: "numeric", month: "short", day: "numeric" },
  long: { year: "numeric", month: "long", day: "numeric", weekday: "long", hour: "numeric", minute: "numeric" },
};
const NUMBER = {
  decimal: { style: "decimal" },
  percent: { style: "percent" },
};

export const i18n = createI18n({
  legacy: false,
  locale: "en",
  fallbackLocale: "en",
  messages: { en },
  // Seeded for en; expanded per discovered locale in applyRegistry().
  datetimeFormats: { en: DATETIME },
  numberFormats: { en: NUMBER },
});

// Locale registry fetched from the backend: { code: { native_name, rtl, ... } }.
let registry = {};

/** The browser/stored/default starting locale, constrained to known codes once
 *  the registry is loaded (before that, en is safe). */
export function initialLocale() {
  return localStorage.getItem(STORAGE_KEY) || "en";
}

/** Apply a locale everywhere: vue-i18n active locale, <html lang/dir>, storage. */
export function setLocale(code) {
  const meta = registry[code];
  if (!meta) return; // unknown until registry loads; ignore
  i18n.global.locale.value = code;
  localStorage.setItem(STORAGE_KEY, code);
  const html = document.documentElement;
  html.setAttribute("lang", code);
  html.setAttribute("dir", meta.rtl ? "rtl" : "ltr");
}

/** Load the backend registry and register per-locale Intl formats. Returns the
 *  registry so callers can build a language selector. Falls back silently to a
 *  single en entry if the endpoint is unreachable, so the UI never breaks. */
export async function loadRegistry(apiBase = import.meta.env.VITE_API_URL || "http://localhost:8000/api") {
  try {
    const res = await fetch(`${apiBase}/languages`, { credentials: "include" });
    const data = await res.json();
    registry = data.languages || {};
  } catch {
    registry = { en: { native_name: "English", rtl: false } };
  }
  for (const code of Object.keys(registry)) {
    i18n.global.setDateTimeFormat(code, DATETIME);
    i18n.global.setNumberFormat(code, NUMBER);
  }
  // Honour a stored/initial choice now that we know the valid set.
  const start = registry[initialLocale()] ? initialLocale() : "en";
  setLocale(start);
  return registry;
}

export function getRegistry() {
  return registry;
}
