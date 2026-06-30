// Vocabulary learner (#vocabulary) mobile-first smoke coverage. The backend is absent
// under `vite preview`, so the concept list degrades to its empty/error state; the test
// asserts the page CHROME renders without JS errors — the title, the single language
// selector spanning curated + AI sources, and the search bar (i18n keys resolve, no raw
// key leakage).
import { test, expect } from "@playwright/test";

test.describe("Vocabulary learner screen", () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
  });

  test("renders the page chrome with a language selector and search bar", async ({ page }) => {
    const errors = [];
    page.on("pageerror", (e) => errors.push(e));

    await page.goto("/#vocabulary");
    await page.locator(".learn-page").waitFor({ state: "visible" });

    // Title resolves from i18n (not a raw "vocabulary.title" key).
    await expect(page.locator(".learn-title")).toHaveText("Vocabulary");
    // One unified language selector spans curated + AI languages.
    await expect(page.locator(".lang-select")).toHaveCount(1);
    await expect(page.locator(".search-input")).toHaveCount(1);
    expect(errors).toEqual([]);
  });

  test("search input is keyboard-focusable", async ({ page }) => {
    await page.goto("/#vocabulary");
    await page.locator(".search-input").focus();
    await expect(page.locator(".search-input")).toBeFocused();
  });
});
