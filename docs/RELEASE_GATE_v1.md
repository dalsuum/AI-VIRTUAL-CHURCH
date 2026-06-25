# v1.0 Production Release Gate

A real go/no-go gate for shipping to the public — not a dev checklist. Each item is
either **automatable** (a command/probe you can run) or a **human sign-off**. Do not
ship with any **must-pass** item unchecked. "N/A" is a valid answer if recorded with a
reason.

> Scope: the AI Virtual Church SaaS surface — auth, account, token wallet, billing,
> admin console, and the worship/Bible/study features. Backend = Laravel (`backend/`),
> frontend = Vue SPA (`frontend/`, hash-routed, built to `frontend/dist` and served
> directly by nginx).

---

## 1. Environment & configuration (must-pass)

- [ ] **`.env` complete for prod.** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`,
      `FRONTEND_URL`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` all set to the real
      hosts. Verify: `php artisan about | grep -i env`.
- [ ] **Billing fully configured _or_ deliberately disabled.** Both `STRIPE_SECRET` and
      `STRIPE_PREMIUM_PRICE_ID` set, or both unset. A half-set state is caught by the
      console warning in `AppServiceProvider::boot()` — run `php artisan config:cache`
      and confirm **no** "Billing partially configured" warning. If intentionally
      disabled, confirm the account page hides the upgrade CTA (see §4).
- [ ] **Stripe webhook** endpoint registered at `/api/webhooks/stripe/subscription`
      with the signing secret in `STRIPE_WEBHOOK_SECRET` (only if billing enabled).
- [ ] **Caches warmed:** `php artisan config:cache route:cache` succeed with no error.
- [ ] **HTTPS only**, HSTS on, secure+HttpOnly session cookie, correct `SESSION_DOMAIN`.

## 2. Build & deploy (must-pass)

- [ ] **Frontend built from the deployed commit:** `cd frontend && npm run build`, and
      the live `index.html` references the freshly built `assets/index-*.js`
      (`curl -s https://<host>/ | grep -o 'assets/index-[^"]*\.js'` matches `dist/`).
- [ ] **PHP opcache picks up backend changes** (reload php-fpm on deploy, or confirm
      `opcache.validate_timestamps=On`).
- [ ] **Migrations applied:** `php artisan migrate --force` clean; no pending migrations.
- [ ] **Static assets load** (no 404s for JS/CSS/fonts in the Network tab).

## 3. Authentication & session (must-pass)

- [ ] Register → auto-login → lands on account.
- [ ] Login / logout. **Logout destroys the server session** (re-requesting `/api/me`
      after logout returns 401, not the user).
- [ ] CSRF: a mutating request without a valid `X-XSRF-TOKEN` is rejected (419).
- [ ] Session survives reload and works in a second tab.
- [ ] `/api/auth/session` returns `200 {user:null}` when logged out (no console 401).

## 4. Authorization / route guards (must-pass)

- [ ] Guest (or logged-out) → `#account` redirects to `#login`.
- [ ] Member → `#admin` is blocked (redirected home); `/api/admin/*` returns 403.
- [ ] Admin → `#admin` loads; staff-only nav link visible only to staff.
- [ ] Blocked user cannot log in (403 "suspended").

## 5. Account & token wallet (must-pass)

- [ ] Account page shows correct plan, token balance, and monthly allowance.
- [ ] Admin **Grant tokens** → ledger row written → user sees new balance on next load
      (cross-session propagation).
- [ ] Token spend (run a real AI action) debits the wallet; a failed upstream call
      rolls back the reservation (no phantom charge).
- [ ] **Billing-disabled mode:** with Stripe unset, the account page shows
      "Premium upgrades are not available right now" and **no** upgrade button; a forced
      `POST /api/subscription/checkout` returns 503, not a 500.

## 6. Billing (must-pass only if billing enabled)

- [ ] Upgrade → Stripe Checkout → webhook flips plan to premium and refills tokens once.
- [ ] Cancel → access retained to period end → `subscription.deleted` webhook downgrades.
- [ ] Webhook is idempotent (redelivered event does not double-refill) and
      signature-verified (forged event rejected).

## 7. Browser health (must-pass)

- [ ] **No uncaught JS errors / unhandled rejections** across the core flows.
- [ ] **No Vue warnings** in the console.
- [ ] **No 5xx** and no unexpected 4xx (the only acceptable 4xx are deliberate, e.g.
      auth-required probes that the app handles).
- [ ] **No CORS errors.**
- [ ] Mobile (≤640px): nav usable, forms usable, no horizontal overflow.

## 8. Reliability & ops (human sign-off)

- [ ] Queue worker + scheduler running (`schedule:work`), and reservation cleanup
      (`reservations:cleanup`) fires.
- [ ] Backups: DB backup taken and **restore tested** at least once.
- [ ] Logging: errors reach a place a human will see (not just `laravel.log` on disk).
- [ ] Rate limits in place on auth, intake, and webhook endpoints.

## 9. Staging freeze test (human sign-off)

- [ ] **No code changes for 24h** on staging.
- [ ] Repeated register → login → logout → token-grant loop stays green.
- [ ] No memory/disk creep; no error-rate climb over the window.

---

### Sign-off

| Role | Name | Date | Verdict |
|---|---|---|---|
| Engineering | | | ☐ Go ☐ No-go |
| Product | | | ☐ Go ☐ No-go |

**Ship only when every must-pass item is checked and both verdicts are Go.**
