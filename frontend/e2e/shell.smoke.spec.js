// Layout-shell smoke suite — locks in the global header/footer refactor.
// Verifies the exact class of regression that the AppLayout consolidation can
// introduce: duplicated/missing chrome, broken containment, scroll restoration.
import { test, expect } from "@playwright/test";

// Every primary route should render the shell exactly once.
const ROUTES = ["", "#bible", "#worship", "#pastor", "#journey", "#vocabulary", "#bible-study"];

async function goto(page, hash) {
  await page.goto(`/${hash}`);
  // The header is the reliable "shell is mounted" signal across all routes.
  await page.locator("header.topbar").first().waitFor({ state: "visible" });
}

test.describe("global layout shell", () => {
  for (const hash of ROUTES) {
    test(`route "${hash || "/"}" renders exactly one header and one footer`, async ({ page }) => {
      await goto(page, hash);
      await expect(page.locator("header.topbar")).toHaveCount(1);
      await expect(page.locator("footer.site-footer")).toHaveCount(1);
      // The single <main> shell wraps the route content.
      await expect(page.locator("main.app-main")).toHaveCount(1);
    });
  }

  test("default intake page keeps the centered card layout", async ({ page }) => {
    await goto(page, "");
    const shell = page.locator("main.app-main .shell");
    await expect(shell).toBeVisible();
    // .shell is constrained (max-width 600) and centered — not full-bleed.
    const box = await shell.boundingBox();
    const vw = page.viewportSize().width;
    expect(box.width).toBeLessThan(640);
    if (vw > 700) {
      // Centered: left margin ≈ right margin.
      const rightGap = vw - (box.x + box.width);
      expect(Math.abs(box.x - rightGap)).toBeLessThan(4);
    }
  });

  test("no horizontal page overflow (nav does not break layout)", async ({ page }) => {
    for (const hash of ["", "#bible", "#worship"]) {
      await goto(page, hash);
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      // The nav itself may scroll internally, but the page must not overflow.
      expect(overflow, `route ${hash || "/"} overflows horizontally`).toBeLessThanOrEqual(1);
    }
  });

  test("footer sits at/below the fold, not floating mid-viewport", async ({ page }) => {
    await goto(page, "");
    const footer = await page.locator("footer.site-footer").boundingBox();
    const vh = page.viewportSize().height;
    // On a short page the sticky-footer layout keeps it at the bottom.
    expect(footer.y + footer.height).toBeGreaterThanOrEqual(vh - 2);
  });
});

test.describe("scroll restoration", () => {
  test("scrolls to top when the base route changes", async ({ page }) => {
    await goto(page, "#bible");
    // Force the page tall enough to scroll, then scroll down.
    await page.evaluate(() => {
      const d = document.createElement("div");
      d.style.height = "3000px";
      document.body.appendChild(d);
      window.scrollTo(0, 1200);
    });
    expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
    // Navigate to a different base route → should reset to top.
    await page.evaluate(() => { window.location.hash = "#worship"; });
    await page.waitForFunction(() => window.scrollY === 0);
    expect(await page.evaluate(() => window.scrollY)).toBe(0);
  });

  test("does NOT reset scroll on a same-page ?session= change", async ({ page }) => {
    await goto(page, "#pastor");
    await page.evaluate(() => {
      const d = document.createElement("div");
      d.style.height = "3000px";
      document.body.appendChild(d);
      window.scrollTo(0, 900);
    });
    await page.evaluate(() => { window.location.hash = "#pastor?session=abc"; });
    // Give the hashchange handler a tick; scroll position must be preserved.
    await page.waitForTimeout(150);
    expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  });
});
