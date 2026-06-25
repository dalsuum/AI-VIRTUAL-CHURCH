// Bible reader (BibleReader) mobile-first smoke coverage. The chapter content
// comes from the backend (/api/bible/*), which is absent under `vite preview`,
// so these assertions cover only the mobile chrome that renders without data:
// the header, translation tabs, book search and emoji-free icon usage.
import { test, expect } from "@playwright/test";

async function gotoBible(page) {
  await page.goto("/#bible");
  await page.locator(".bible-header").waitFor({ state: "visible" });
}

test.describe("Bible reader", () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 780 });
  });

  test("renders the header, translation tabs and book search", async ({ page }) => {
    await gotoBible(page);
    await expect(page.locator(".bible-title")).toBeVisible();
    await expect(page.locator(".lang-tabs .lang-btn").first()).toBeVisible();
    await expect(page.locator(".toc .search-input")).toHaveCount(1);
  });

  test("uses Iconify SVGs, not emoji, in the title and back link", async ({ page }) => {
    await gotoBible(page);
    // The back link and title render an Iconify <svg>, not an emoji glyph.
    await expect(page.locator(".back-link svg").first()).toBeVisible();
    await expect(page.locator(".bible-title svg")).toBeVisible();
    // Guard the design rule: no emoji in the visible header text.
    const headerText = await page.locator(".bible-header").innerText();
    expect(headerText).not.toMatch(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u);
  });

  test("no horizontal page overflow at 390px", async ({ page }) => {
    await gotoBible(page);
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);
  });
});
