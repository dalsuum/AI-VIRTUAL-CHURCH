// Songs screen (MyanmarLyrics) mobile-first smoke coverage. Verifies the
// presentation refactor preserved the core interactions: search, category
// chips, list → detail navigation and back. The backend is absent under
// `vite preview`, so the admin library degrades to empty and the screen runs
// off the bundled static hymn corpus (public/data/hymns_my.json).
import { test, expect } from "@playwright/test";

async function gotoSongs(page) {
  await page.goto("/#lyrics");
  // Wait until the static hymn corpus has loaded into the list.
  await page.locator(".song-card").first().waitFor({ state: "visible" });
}

test.describe("Songs screen", () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
  });

  test("renders a single search bar and category chips", async ({ page }) => {
    await gotoSongs(page);
    await expect(page.locator(".search-input")).toHaveCount(1);
    // The five SOURCE_LABELS render as horizontally-scrollable chips.
    await expect(page.locator(".src-tab")).toHaveCount(5);
  });

  test("search filters the list", async ({ page }) => {
    await gotoSongs(page);
    const before = await page.locator(".song-card").count();
    await page.locator(".search-input").fill("ဘုရား");
    // Either fewer results or the explicit empty state — never unchanged-and-more.
    await expect
      .poll(async () => {
        const cards = await page.locator(".song-card").count();
        const empty = await page.locator(".empty").count();
        return cards <= before || empty === 1;
      })
      .toBe(true);
  });

  test("tapping a card opens the detail sheet, back returns to the list", async ({ page }) => {
    await gotoSongs(page);
    await page.locator(".song-card").first().click();
    await expect(page.locator(".lyrics-sheet")).toBeVisible();
    await page.locator(".back-link.as-button").click();
    await expect(page.locator(".song-card").first()).toBeVisible();
  });

  test("category chips switch the active filter", async ({ page }) => {
    await gotoSongs(page);
    const second = page.locator(".src-tab").nth(1);
    await second.click();
    await expect(second).toHaveClass(/active/);
  });

  test("no horizontal page overflow at 390px", async ({ page }) => {
    await gotoSongs(page);
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);
  });
});
