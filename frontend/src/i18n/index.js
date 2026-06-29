// Frontend i18n. The set of locales and their metadata (native name, rtl,
// formatting locale) comes from the backend registry (config/languages.php via
// GET /api/languages) — the single source of truth — so adding a language is a
// backend-only change. Milestone locale files can be partial; any
// missing key falls back to English until its reviewed strings land.
import { createI18n } from "vue-i18n";

const STORAGE_KEY = "locale";

// All locale string files. Milestone locales may be partial.
// fallbackLocale below means any missing key falls back to English, so an empty
// or partial locale file never breaks the UI.
const messages = {};
for (const [path, mod] of Object.entries(
  import.meta.glob("./locales/*.json", { eager: true }),
)) {
  messages[path.match(/\/([\w-]+)\.json$/)[1]] = mod.default;
}

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
  messages,
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

export function isRtlLocale(code = i18n.global.locale.value) {
  const normalized = normalizeLanguage(code);
  return Boolean(registry[normalized]?.rtl);
}

/**
 * The single canonical language resolver. Worshipper-facing features must derive
 * their content language from THIS — never from the raw UI locale. Exact registry
 * or locale-file codes win first (so "zh-CN" stays "zh-CN"); only then do we fall
 * back to a base-language match for future regional variants.
 */
export function normalizeLanguage(code = i18n.global.locale.value) {
  const raw = String(code || "en").trim() || "en";
  const known = [...new Set([...Object.keys(registry), ...Object.keys(messages)])];

  if (known.includes(raw)) return raw;

  const exact = known.find((candidate) => candidate.toLowerCase() === raw.toLowerCase());
  if (exact) return exact;

  const base = raw.split("-")[0].toLowerCase();
  const baseMatch = known.find((candidate) => candidate.split("-")[0].toLowerCase() === base);

  return baseMatch || base || "en";
}
