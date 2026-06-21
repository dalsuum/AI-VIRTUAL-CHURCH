# Deployment Checklist — Subscription Platform

_Last reviewed: 2026-06-21. Run top-to-bottom when promoting the subscription feature
(`feature/multilingual-services` → staging → production). See
[`SUBSCRIPTION_SYSTEM.md`](SUBSCRIPTION_SYSTEM.md) for architecture._

---

## Pre-deploy

- [ ] **Back up the production database** (these are additive migrations, but back up anyway).
- [ ] Confirm `.env` has all subscription/token vars (see [`.env.example`](../.env.example)):
  - [ ] `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CURRENCY`
  - [ ] `STRIPE_PREMIUM_PRICE_ID` (recurring price `price_…` from Stripe → Products)
  - [ ] `TOKENS_MEMBER_MONTHLY`, `TOKENS_PREMIUM_MONTHLY` (or accept config defaults)
  - [ ] `TOKENS_RESERVATION_TTL`, `GUEST_TRACKING_RETENTION_DAYS` (optional)

## Database

- [ ] `php artisan migrate --force` (six additive migrations).
- [ ] Spot-check columns exist: `users.subscription_plan`, `users.token_balance`; tables
      `token_ledger`, `token_reservations`, `guest_tracking`, `usage_logs`,
      `subscription_history`.
- [ ] Backfill existing users if needed: registered users default to `subscription_plan =
      member`, `subscription_status = active`. Run `tokens:refill-monthly` once so
      existing members get their first allowance.

## Stripe

- [ ] Create the recurring **premium price** and put its id in `STRIPE_PREMIUM_PRICE_ID`.
- [ ] Register the webhook endpoint **`/webhooks/stripe/subscription`** for events:
  - `checkout.session.completed`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
  - `invoice.payment_failed`
- [ ] Confirm `STRIPE_WEBHOOK_SECRET` matches that endpoint's signing secret.
- [ ] (Existing) the offering webhook stays on `/webhooks/stripe`.

## App caches & assets

- [ ] `php artisan config:cache && php artisan route:cache && php artisan event:cache`.
- [ ] Frontend built: `npm run build` in `frontend/` (no dev server in prod).

## Background processing

- [ ] Scheduler running (`* * * * * php artisan schedule:run`) — drives all four
      maintenance commands.
- [ ] Queue workers running (intake/study dispatch, notifications).
- [ ] Verify each command runs cleanly once, manually:
  - [ ] `php artisan tokens:refill-monthly`
  - [ ] `php artisan subscriptions:expire`
  - [ ] `php artisan guests:cleanup`
  - [ ] `php artisan reservations:cleanup`

## Smoke test (post-deploy)

- [ ] Guest can use each AI service once; second attempt returns 402 `guest_limit`.
- [ ] Register → login → `GET /me` returns `plan`, `token_balance`, `shows_ads:false`.
- [ ] Member sees no ads (`GET /ads/active` → `[]`).
- [ ] Premium checkout → Stripe → webhook flips plan to premium, wallet = premium allowance.
- [ ] Token deduction visible in `/tokens/history`; balance never goes negative.
- [ ] Cancel → status `cancelled`, access retained until expiry.

## Rollback plan

- [ ] Migrations are reversible (`php artisan migrate:rollback` — verify on **staging**,
      never prod). The feature is additive; disabling the new routes + reverting the
      build effectively turns it off without data loss.
