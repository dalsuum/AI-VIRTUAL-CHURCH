/** @type {import('tailwindcss').Config} */
// Tailwind is an additive utility layer on top of the existing CSS-variable
// design tokens in src/styles.css. Colors map to the same var(--…) names so
// utilities theme for free under [data-theme="dark"] — we never hardcode hex.
// Preflight is disabled so Tailwind does not reset the existing hand-authored
// component styles; we opt into the bits we want via the token mappings below.
export default {
  content: ["./index.html", "./src/**/*.{vue,js}"],
  darkMode: ['selector', '[data-theme="dark"]'],
  corePlugins: {
    preflight: false,
  },
  theme: {
    extend: {
      colors: {
        bg: "var(--bg)",
        surface: "var(--surface)",
        "surface-2": "var(--surface-2)",
        "surface-3": "var(--surface-3)",
        text: "var(--text)",
        "text-muted": "var(--text-muted)",
        "text-faint": "var(--text-faint)",
        border: "var(--border)",
        "border-strong": "var(--border-strong)",
        primary: "var(--primary)",
        "primary-hover": "var(--primary-hover)",
        "primary-soft": "var(--primary-soft)",
        "on-primary": "var(--on-primary)",
        success: "var(--success)",
        danger: "var(--danger)",
      },
      borderRadius: {
        DEFAULT: "var(--radius)",
        sm: "var(--radius-sm)",
      },
      boxShadow: {
        DEFAULT: "var(--shadow)",
        sm: "var(--shadow-sm)",
      },
      // 8px base spacing system (8 / 16 / 24 / 32) called out by the design spec.
      spacing: {
        1: "8px",
        2: "16px",
        3: "24px",
        4: "32px",
      },
      // Bottom-nav height token so content can pad-bottom to clear the fixed bar.
      height: {
        "bottom-nav": "var(--bottom-nav-h)",
      },
    },
  },
  plugins: [],
};
