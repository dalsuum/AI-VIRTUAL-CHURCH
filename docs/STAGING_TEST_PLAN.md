# Staging Test Plan — Subscription Platform

_Last reviewed: 2026-06-21. Run on staging before tagging a release candidate. Architecture:
[`SUBSCRIPTION_SYSTEM.md`](SUBSCRIPTION_SYSTEM.md). Deploy steps:
[`DEPLOYMENT_CHECKLIST.md`](DEPLOYMENT_CHECKLIST.md)._

> Goal: validate **behaviour under failure and concurrency**, not happy-path only. The
> highest-risk areas are token reservation reconciliation, webhook idempotency, and
> guest-limit enforcement.

---

## 1. Authentication

- [ ] Guest provisioning (walk-up, no login wall)
- [ ] Register / login / logout
- [ ] Forgot password → reset password
- [ ] Blocked user cannot log in (403)

## 2. Subscription lifecycle

- [ ] Guest → Member (register)
- [ ] Member → Premium via Stripe Checkout; webhook activates premium
- [ ] **Duplicate webhook**: redeliver `checkout.session.completed` → NO duplicate
      `subscription_history` row, NO second token grant
- [ ] `customer.subscription.updated` (card/metadata change) → balance NOT reset
- [ ] `invoice.payment_failed` → status `grace`, access retained
- [ ] Cancel → status `cancelled`, access until period end
- [ ] `customer.subscription.deleted` → downgrade to member, wallet → member allowance
- [ ] `subscriptions:expire` downgrades a premium user past `subscription_expires_at`

## 3. Token wallet — reconciliation (highest priority ⭐)

For each, afterwards assert **balance == sum(ledger) == settled reservations**:

- [ ] reserve → AI succeeds → commit
- [ ] reserve → AI fails/timeout → rollback (balance restored)
- [ ] reserve → PHP fatal / worker killed → `reservations:cleanup` refunds it
- [ ] reserve → process dies before commit → row past TTL refunded by cleanup

## 4. Concurrency (highest priority ⭐)

- [ ] Set a member to exactly 1 token; fire ~20 simultaneous "start Bible Study"
      requests. Expect: exactly 1 succeeds, 19 get **402**, balance never negative.
- [ ] Repeat with balance N; expect exactly N successes.

## 5. Guest limit (highest priority ⭐)

Verify the quota holds (to the extent fingerprinting allows) across:

- [ ] clear cookies / site data
- [ ] private / incognito window
- [ ] different browser
- [ ] VPN / different IP
- [ ] mobile hotspot
- [ ] changed user-agent

Expected: cookie OR (IP+fingerprint) match still blocks the 2nd use of a given service.
Document the tolerated bypass (fresh device + VPN resets — accepted).

## 6. AI flows — per service

For **each** AI service (Bible Study, worship service, …):

- [ ] reserve → success → commit → `usage_logs` row `status=ok`
- [ ] reserve → failure → rollback → `usage_logs` row `status=failed`
- [ ] Guest: one free use logged; second use blocked

## 7. Scheduler

- [ ] `tokens:refill-monthly` resets to allowance; running it **twice** in the same
      month does NOT double (idempotent via `tokens_refilled_at`)
- [ ] `subscriptions:expire`, `guests:cleanup`, `reservations:cleanup` produce expected
      results and are safe to re-run

## 8. Ad suppression

- [ ] Guest: ads returned
- [ ] Member/Premium: `GET /ads/active` → `[]` even when called directly

## 9. Feature cascade

- [ ] On expiry/downgrade: ads re-enable, max-pastors drops, allowance changes — all
      without app restart (FeatureService reads plan live)

## 10. Migrations

- [ ] `php artisan migrate:fresh` then re-seed works
- [ ] `php artisan migrate:rollback` reverses cleanly (staging only)

---

## Exit criteria → `v1.0.0-rc1`

All ⭐ sections pass; no negative balances; no duplicate webhook effects; scheduler
idempotent. Fix only bugs found here — no new features. After a stable staging soak,
promote the **same build** to production.
