// Learner Vocabulary (#learn) mobile-first smoke coverage. The backend is absent
// under `vite preview`, so the concept list degrades to its empty/error state; the
// test therefore asserts the page CHROME renders without JS errors and the single
// search bar + category group are present (i18n keys resolve, no raw key leakage).
import { test, expect } from "@playwright/test";

test.describe("Learn vocabulary screen", () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
  });

  test("renders the learner page chrome with a single search bar", async ({ page }) => {
    const errors = [];
    page.on("pageerror", (e) => errors.push(e));

    await page.goto("/#learn");
    await page.locator(".learn-page").waitFor({ state: "visible" });

    // Title resolves from i18n (not a raw "learn.title" key).
    await expect(page.locator(".learn-title")).toHaveText("Learn Vocabulary");
    await expect(page.locator(".search-input")).toHaveCount(1);
    // The category group always renders at least the "All" chip.
    await expect(page.locator(".cat-btn").first()).toBeVisible();
    expect(errors).toEqual([]);
  });

  test("search input is keyboard-focusable", async ({ page }) => {
    await page.goto("/#learn");
    await page.locator(".search-input").focus();
    await expect(page.locator(".search-input")).toBeFocused();
  });
});
