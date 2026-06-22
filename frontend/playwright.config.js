import { defineConfig, devices } from "@playwright/test";

// Layout-shell smoke suite. Serves the production build with `vite preview`
// (no backend — the SPA treats API failures as "logged out", so the global
// header/footer shell still renders, which is exactly what we assert here).
export default defineConfig({
  testDir: "./e2e",
  timeout: 30_000,
  fullyParallel: true,
  reporter: process.env.CI ? "list" : "line",
  use: {
    baseURL: "http://127.0.0.1:4173",
    trace: "on-first-retry",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } } },
    { name: "mobile", use: { ...devices["Desktop Chrome"], viewport: { width: 390, height: 844 } } },
  ],
  webServer: {
    command: "npm run preview -- --port 4173 --host 127.0.0.1",
    url: "http://127.0.0.1:4173",
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
});
