# Subscription, Plans & Token Economy — Architecture

_Last reviewed: 2026-06-21. Scope: the 3-tier AAA (Authentication, Authorization,
Accounting) subscription platform. For the full-stack view see [`README.md`](../README.md);
for the AI worker fleet see [`AI_ARCHITECTURE.md`](AI_ARCHITECTURE.md)._

---

## 1. The three tiers

Billing tier (`users.subscription_plan`) is **separate from** the staff `role`, which
governs admin/console privilege.

| Plan        | How obtained        | Ads | Tokens/month\* | Max pastors\*\* |
|-------------|---------------------|-----|----------------|-----------------|
| **Guest**   | anonymous walk-up    | yes | 1 free use / service | 2 |
| **Member**  | register             | no  | 100            | 3 |
| **Premium** | Stripe subscription  | no  | 1000           | 7 |

\* `config/tokens.php` defaults, env-overridable, and live-tunable by admin
(`plan_overrides` setting). \*\* From the admin-editable `study_agent_tiers` setting via
`StudyTiers`.

## 2. Single source of truth

No controller branches on a plan string.

```
Controller / View
      ↓
FeatureService::for($user)        (per-user façade: showsAds(), maxPastors(), …)
      ↓
PlanService                       (plan → rules; config layered with DB overrides)
      ↓
config/tokens.php  +  Setting('plan_overrides')  +  StudyTiers (max pastors)
```

Precedence is always **DB override → config → enum default**. Plans, statuses, and
ledger entry types are PHP enums in [`backend/app/Enums/`](../backend/app/Enums) — no
magic strings.

## 3. Token wallet (Accounting)

`users.token_balance` is authoritative; every change is appended to `token_ledger`
inside the same `lockForUpdate` transaction that moves it, so concurrent requests can't
double-spend (a lost race surfaces as **HTTP 402**, not 500).

**Two-phase hold** for fallible AI calls
([`TokenService`](../backend/app/Services/TokenService.php)):

```
reserve()  → debit + open pending token_reservations row (TTL)
   ↓
run the AI request
   ↓
commit()   on success   |   rollback()  on failure (refund)
```

If a worker dies mid-request, `reservations:cleanup` (hourly) rolls back any hold past
its TTL, so tokens are never stranded. `spend()` is the single-phase convenience for
actions that can't fail mid-charge.

Reconciliation invariant: **wallet balance == sum(token_ledger) == settled
reservations**, always.

## 4. Guest one-use enforcement

One free use **per service**, tracked in `guest_tracking` by salted SHA-256 hashes of
three signals — IP, a browser fingerprint, and a long-lived `guest_id` cookie — so
clearing cookies alone doesn't reset the quota
([`GuestUsageService`](../backend/app/Services/GuestUsageService.php)). Enforced by the
`guest.limit:{service}` middleware; recorded only **after** a successful run so a failed
attempt never burns the free use.

> Fingerprinting is best-effort: a determined user with a fresh device + VPN can reset.
> The design tolerates that — it deters casual abuse, not a motivated adversary.

## 5. Subscriptions (Stripe)

`POST /subscription/checkout` opens hosted Stripe Checkout through the
[`BillingProvider`](../backend/app/Services/Billing/BillingProvider.php) seam (Stripe
today; a second provider can be added without touching controllers). Premium is **only**
activated/downgraded by the signature-verified webhook
`POST /webhooks/stripe/subscription`.

`subscription_status` (active/trial/grace/expired/cancelled) is explicit, not
date-inferred. Every transition appends to `subscription_history` for support/audit.

**Idempotency:** the wallet is refilled only on the *first* transition into premium —
never on routine `subscription.updated` events (card/metadata/renewal) — and
`transition()`/`setStatus()` are no-ops when nothing changed, so redelivered webhooks
don't double-grant tokens or pollute history.

## 6. Ad suppression

Server-authoritative: `GET /ads/active` returns `[]` for any ad-free plan, so suppression
can't be bypassed by calling the API directly.

## 7. Operational telemetry

`usage_logs` records per-request AI usage (user, service, model, tokens, cost, latency,
**status incl. failures**) for cost forensics —
[`UsageLogger`](../backend/app/Services/UsageLogger.php). This is distinct from
`token_ledger` (wallet accounting) and `ai_usage_ledger` (per-model-turn study
telemetry). Think: *wallet accounting* vs *operational telemetry* vs *model telemetry*.

## 8. Scheduled jobs (`routes/console.php`)

| Command | Cadence | Purpose |
|---------|---------|---------|
| `tokens:refill-monthly` | daily | Reset member/premium wallets to allowance (idempotent within a month) |
| `subscriptions:expire`  | daily | Backstop for missed Stripe deletion webhooks |
| `guests:cleanup`        | daily | Prune stale guest-tracking rows |
| `reservations:cleanup`  | hourly | Refund holds stranded by a crashed worker |

## 9. Data model (new tables/columns)

- `users`: `subscription_plan`, `subscription_status`, `subscription_expires_at`,
  `stripe_customer_id`, `stripe_subscription_id`, `token_balance`, `monthly_allowance`,
  `tokens_refilled_at`
- `guest_tracking`, `token_ledger`, `token_reservations`, `usage_logs`,
  `subscription_history`

## 10. Deliberately deferred (extension points exist, not built)

Multi-tenancy (`church_id`), additional billing providers, feature versioning, and
per-service quotas. The seams (`BillingProvider`, `PlanService`, `FeatureService`) make
these additive later without redesign.
