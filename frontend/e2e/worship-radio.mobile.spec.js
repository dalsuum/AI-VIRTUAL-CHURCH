import { test, expect } from "@playwright/test";

const ORIGIN = "http://127.0.0.1:4173";
const CORS = {
  "access-control-allow-origin": ORIGIN,
  "access-control-allow-credentials": "true",
  "access-control-allow-methods": "GET,POST,OPTIONS",
  "access-control-allow-headers": "Content-Type, Accept, X-Guest-Fingerprint, X-XSRF-TOKEN",
};

const MOODS = [
  { key: "energy", label: "Energy", labels: { en: "Energy" }, emoji: "⚡" },
  { key: "feel_good", label: "Feel Good", labels: { en: "Feel Good" }, emoji: "😊" },
  { key: "focus", label: "Focus", labels: { en: "Focus" }, emoji: "🎯" },
  { key: "love", label: "Love", labels: { en: "Love" }, emoji: "❤️" },
  { key: "relax", label: "Relax", labels: { en: "Relax" }, emoji: "🌿" },
  { key: "heartbreak", label: "Heartbreak", labels: { en: "Heartbreak" }, emoji: "💔" },
];

const COVER =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'%3E%3Crect width='120' height='120' fill='%23dbeafe'/%3E%3Cpath d='M30 75h60M35 55h50M44 39h32' stroke='%232563eb' stroke-width='8' stroke-linecap='round'/%3E%3C/svg%3E";

const PLAYLIST = [
  { id: 1, title: "Goodness of God (Live)", artist: "Bethel Music", language: "en", genre: "worship", duration: 304, spotify_url: "https://example.test/goodness", cover_image: COVER, themes: ["praise"] },
  { id: 2, title: "Graves Into Gardens featuring Brandon Lake", artist: "Elevation Worship", language: "en", genre: "worship", duration: 452, spotify_url: "https://example.test/graves", cover_image: COVER, themes: ["victory"] },
  { id: 3, title: "Way Maker", artist: "Leeland", language: "en", genre: "worship", duration: 267, spotify_url: "https://example.test/waymaker", cover_image: COVER, themes: ["faith"] },
];

function json(route, body, status = 200) {
  return route.fulfill({
    status,
    headers: { ...CORS, "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

async function mockApi(page, { authed = false } = {}) {
  await page.route("**/*", async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    const isApi = url.pathname.startsWith("/api/") || url.pathname === "/sanctum/csrf-cookie";

    if (!isApi) return route.continue();
    if (req.method() === "OPTIONS") return route.fulfill({ status: 204, headers: CORS, body: "" });
    if (url.pathname === "/sanctum/csrf-cookie") return route.fulfill({ status: 204, headers: CORS, body: "" });
    if (url.pathname === "/api/languages") {
      return json(route, { languages: {
        en: { native_name: "English", rtl: false },
        my: { native_name: "ဗမာ", rtl: false },
      } });
    }
    if (url.pathname === "/api/auth/session") {
      return json(route, {
        authenticated: authed,
        user: authed ? { id: 1, name: "Test User", is_guest: false, role: "member", is_admin: false } : null,
      });
    }
    if (url.pathname === "/api/fathers-day/config" || url.pathname === "/api/stickers/config") {
      return json(route, { enabled: false });
    }
    if (url.pathname === "/api/music/moods") return json(route, { moods: MOODS });
    if (url.pathname === "/api/music/recommend") {
      return json(route, {
        playlist: PLAYLIST,
        reason: "I searched YouTube for English worship about victory and queued the 3 best matches.",
        themes: ["energy", "victory", "praise"],
      });
    }
    return json(route, {});
  });
}

async function pageOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test.describe("Worship Radio mobile layout", () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
  });

  test("renders compact first-viewport controls without horizontal overflow", async ({ page }) => {
    await mockApi(page);
    await page.goto("/#worship");
    await page.locator(".wr-mood").first().waitFor({ state: "visible" });

    await expect(page.locator(".wr-mood")).toHaveCount(6);
    await expect(page.getByPlaceholder("Search by mood or description")).toBeVisible();
    await expect(page.getByRole("button", { name: "Find Worship Songs" })).toBeVisible();
    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);

    const columnsInFirstRow = await page.evaluate(() => {
      const rows = Array.from(document.querySelectorAll(".wr-mood")).map((el) => el.getBoundingClientRect());
      const firstTop = rows[0].top;
      return rows.filter((rect) => Math.abs(rect.top - firstTop) < 2).length;
    });
    expect(columnsInFirstRow).toBe(2);
  });

  test("shows wrapped result copy and card-style queue rows", async ({ page }) => {
    await mockApi(page);
    await page.goto("/#worship");
    await page.getByRole("button", { name: /Energy/ }).click();
    await page.getByRole("button", { name: "Find Worship Songs" }).click();
    await page.locator(".wr-card.now").waitFor({ state: "visible" });

    await expect(page.locator(".wr-reason")).toContainText("Found worship songs");
    await expect(page.locator(".wr-reason")).toContainText("Searching:");
    await expect(page.locator(".wr-reason")).toContainText("English Worship");
    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);

    const sizes = await page.evaluate(() => {
      const card = document.querySelector(".wr-card.now").getBoundingClientRect();
      const cover = document.querySelector(".wr-card.now .wr-cover").getBoundingClientRect();
      return {
        card: { width: card.width, height: card.height },
        cover: { width: cover.width, height: cover.height },
      };
    });
    expect(sizes.card.width).toBeLessThanOrEqual(362);
    expect(sizes.cover.width).toBeGreaterThanOrEqual(80);
    expect(sizes.cover.height).toBeGreaterThanOrEqual(80);
  });

  test("has no embedded language selector and follows the global language", async ({ page }) => {
    await mockApi(page);
    // Registered after mockApi so this handler takes precedence and can capture
    // the language sent on each recommend request.
    const recommendLangs = [];
    await page.route("**/api/music/recommend", (route) => {
      recommendLangs.push(JSON.parse(route.request().postData() || "{}").language);
      return route.fulfill({
        status: 200,
        headers: { ...CORS, "content-type": "application/json" },
        body: JSON.stringify({ playlist: PLAYLIST, reason: "ok", themes: [] }),
      });
    });
    await page.goto("/#worship");
    await page.locator(".wr-mood").first().waitFor({ state: "visible" });

    // The embedded per-page language buttons must no longer exist anywhere.
    await expect(page.locator(".wr-langs, .wr-lang")).toHaveCount(0);

    // Default global language → English worship request.
    await page.getByRole("button", { name: /Energy/ }).click();
    await page.getByRole("button", { name: "Find Worship Songs" }).click();
    await page.locator(".wr-card.now").waitFor({ state: "visible" });
    expect(recommendLangs.at(-1)).toBe("en");

    // Switching the one global selector switches the worship language too.
    await page.getByLabel("Select language").selectOption("my");
    await page.getByRole("button", { name: "Find Worship Songs" }).click();
    await expect.poll(() => recommendLangs.at(-1)).toBe("my");
  });

  test("keeps the authenticated mobile header from squeezing controls", async ({ page }) => {
    await mockApi(page, { authed: true });
    await page.goto("/#worship");
    await page.locator(".wr-mood").first().waitFor({ state: "visible" });

    await expect(page.getByRole("button", { name: "Toggle My Journey" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Logout" })).toBeVisible();
    await expect(page.getByLabel("Select language")).toBeVisible();
    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);
  });
});
