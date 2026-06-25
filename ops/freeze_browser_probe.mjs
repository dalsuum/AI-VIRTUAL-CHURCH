// Freeze-window browser probe (hourly). Drives the real SPA in headless Chromium
// and records console errors / Vue warnings / 5xx that only surface in a browser.
// Appends one JSON line to FREEZE_BROWSER_LOG. Requires playwright (installed in
// ops/node_modules by freeze_setup.sh) and the cached Chromium.
//
// Env: FREEZE_ORIGIN, FREEZE_MEMBER_EMAIL, FREEZE_MEMBER_PW, FREEZE_BROWSER_LOG
import { chromium } from 'playwright';
import { appendFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const ORIGIN = process.env.FREEZE_ORIGIN || 'https://aivirtual.church';
const EMAIL  = process.env.FREEZE_MEMBER_EMAIL;
const PW     = process.env.FREEZE_MEMBER_PW;
const LOG    = process.env.FREEZE_BROWSER_LOG ||
  new URL('./logs/freeze_browser.jsonl', import.meta.url).pathname;

const errors = [];
function attach(p, tag) {
  p.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') errors.push(`[${tag}] ${m.type()}: ${m.text()}`); });
  p.on('pageerror', e => errors.push(`[${tag}] pageerror: ${e.message}`));
  p.on('response', r => { if (r.status() >= 500) errors.push(`[${tag}] HTTP ${r.status()} ${r.url()}`); });
}

let ok = false, note = '';
const browser = await chromium.launch();
try {
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  attach(p, 'home');
  // Logged-out home: must be console-clean (no expected-401 noise).
  await p.goto(`${ORIGIN}/#`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  // Login and land on the account dashboard.
  await p.goto(`${ORIGIN}/#login`, { waitUntil: 'networkidle' });
  await p.fill('input[type=email]', EMAIL);
  await p.fill('input[type=password]', PW);
  await p.click('button[type=submit]');
  await p.waitForTimeout(2500);
  await p.goto(`${ORIGIN}/#account`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const body = await p.innerText('body');
  const gauge = /\d+\s*\/\s*\d+\s*tokens/i.test(body);

  // Logout must destroy the session: nav flips back to Login/Register, and the
  // guard then bounces #account -> #login. This is the auth-drift check that the
  // API probe can't do reliably under SESSION_DRIVER=cookie.
  const logoutBtn = await p.$('button:has-text("Logout")');
  if (logoutBtn) { await logoutBtn.click(); await p.waitForTimeout(1500); }
  const nav = await p.innerText('header');
  const loggedOut = /login/i.test(nav) && /register/i.test(nav);
  await p.goto(`${ORIGIN}/#account`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const guardRedirect = (await p.evaluate(() => location.hash)) === '#login';

  note = `gauge=${gauge} logout_clears_nav=${loggedOut} guard_redirect=${guardRedirect}`;
  ok = errors.length === 0 && gauge && loggedOut && guardRedirect;
  await ctx.close();
} catch (e) {
  note = 'script_error: ' + e.message;
} finally {
  await browser.close();
}

const rec = {
  ts: new Date().toISOString(),
  kind: 'browser',
  ok,
  console_issues: errors.length,
  errors: errors.slice(0, 20),
  note,
};
mkdirSync(dirname(LOG), { recursive: true });
appendFileSync(LOG, JSON.stringify(rec) + '\n');
console.log((ok ? 'PASS' : 'FAIL') + ' ' + rec.ts + ' console_issues=' + errors.length + ' ' + note);
process.exit(ok ? 0 : 1);
