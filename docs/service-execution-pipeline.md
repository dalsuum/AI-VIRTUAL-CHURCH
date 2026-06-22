# Service Execution Pipeline — Design Proposal

**Status:** Approved · Phase 1 implemented (Worship intake migrated)
**Author:** Claude (with dalsuum08)
**Date:** 2026-06-22

## Implementation status

- ✅ `App\Services\Pipeline\AiServicePipeline` (executor base) + `PipelineResult`.
- ✅ `App\Services\Pipeline\Worship\WorshipServicePipeline` — worship `intake` migrated;
  `ServiceController::intake` is now `return app(WorshipServicePipeline::class)->forToken($token)->handle($request);`.
- ⏳ `StudyController`, `PastorChatController` — to migrate after Worship proves stable.

### Decisions applied (from review)

1. **Executor owns the transaction**, hooks run **after** commit.
2. **Crisis is hard-path, before the action** — but kept **outside** the DB transaction.
   Crisis detection is a network call (LLM classifier); holding a transaction open across
   it would create long-lived locks. So `handle()` runs `prepare()` + `crisis()`
   pre-transaction (both may short-circuit without charging), then opens the transaction
   only around `reserveQuota → execute → commitQuota`. This honours the intent (crisis
   gates the action, never charges on intercept) without the long-transaction anti-pattern.
3. **Controllers don't know about hooks** — each pipeline subclass declares its own
   `hooks()`; the controller only wires + delegates.
4. **`execute()` dispatches the GPU job with `->afterCommit()`** so a worker can never pick
   up the job before the surrounding transaction commits (queue driver is redis,
   `after_commit=false`).

## Motivation

Every AI service endpoint — worship `intake`, Bible `study`, Pastor chat (and any future
AI feature) — repeats the same shape by hand:

1. resolve the caller's identity (registered / guest),
2. gate on quota (member token reserve/commit, or guest single-use),
3. execute a primary action (dispatch the GPU/agent pipeline),
4. perform best-effort enrichment (history mirror, analytics, notifications, …).

When this ordering is enforced only by **controller discipline**, it drifts. It already
did: the history-mirror enrichment was placed *ahead* of the quota write in
`ServiceController::intake`, so a missing-table exception aborted the request before
usage was recorded — guests could reuse a paid feature after a refresh
(`guest_tracking` stayed empty, so `402 guest_limit` never fired). The fix reordered the
two controllers and isolated enrichment in `try/catch`, but nothing structural prevents
the next endpoint from making the same mistake.

**Goal:** make the correct order a *single enforcement boundary*, not a convention each
controller re-implements.

## Execution model

```
Request
   │
   ▼
ResolveIdentity        ─┐
   │                    │  HARD PATH
   ▼                    │  (any failure aborts the request,
QuotaGate               │   rolls back the reservation, and
   │                    │   returns an error to the caller)
   ▼                    │
ExecutePrimaryAction   ─┘
   │
   ├── success ──► commit quota (token commit / guest record)
   │
   ▼
PostCommitHooks        ─┐  SOFT PATH
   ├── History mirror   │  (best-effort; each hook isolated.
   ├── Recommendations  │   One hook failing never affects
   ├── Analytics        │   another, the quota write, or the
   └── Notifications    │   user response — it only logs.)
                       ─┘
```

### Hard path (must all succeed, in order)

- **Resolve identity** — the authenticated user (`*@guest.local` ⇒ guest tier).
- **Quota gate** — guests: assert the single free use is unconsumed; members/premium:
  reserve a token. A blocked guest returns `402 guest_limit` *before any work runs*.
- **Execute primary action** — dispatch the pipeline / agent round. On failure the token
  reservation is rolled back and a `failed` usage row is logged.
- **Commit** — on success, commit the reservation (members) or record the guest's use
  (guests). This is the **one irreversible side-effect** and must complete before any
  soft-path hook runs.

### Soft path (best-effort, isolated)

Everything after a successful commit is optional enrichment: history mirror,
recommendations, metrics, cache warming, notifications. Each hook is wrapped so a failure
**logs and continues** — it can never undo the quota write, abort a sibling hook, or
change the HTTP response.

## Proposed interfaces

```php
interface QuotaGate
{
    /** Throw (402/insufficient-tokens) to abort; return a handle the executor commits. */
    public function authorize(Request $request): QuotaTicket;
}

interface PrimaryAction
{
    /** The irreversible domain operation. Throwing aborts and triggers rollback. */
    public function execute(Request $request): ServiceResult;
}

interface PostCommitHook
{
    /** Best-effort enrichment. Exceptions are caught, logged, and swallowed. */
    public function handle(ServiceResult $result): void;
}
```

A single executor wires them and owns the ordering + isolation:

```php
$result = PipelineExecutor::run(
    request: $request,
    gate:    new GuestOrTokenQuotaGate('service'),
    action:  new WorshipServiceAction($session),
    hooks:   [
        new HistoryMirrorHook('service'),
        new AnalyticsHook('service'),
        // new RecommendationHook(), ...
    ],
);
```

`PipelineExecutor::run` guarantees: gate → action → **commit** → hooks; rollback on action
failure; and a `try/catch` around every hook. Controllers shrink to dependency wiring and
can no longer reorder the critical steps.

## Migration plan (low-risk, incremental)

1. Land `PipelineExecutor` + interfaces with unit tests (no controller changes yet).
2. Port **one** endpoint (`ServiceController::intake`) and confirm parity against the
   existing live verification (first use → `202`; refresh → `402` before execution).
3. Port `StudyController`, then `PastorChatController` (note: there the chat session is the
   primary entity, not a mirror — it maps to `PrimaryAction`, not a hook).
4. Delete the now-redundant per-controller ordering once all three are ported.

## Non-goals (for this iteration)

- Moving enrichment to async queue jobs. The synchronous isolated-hook model is enough to
  close the failure mode; async is a later optimization (`PostCommitHook` can dispatch a
  job internally without changing the executor contract).
- Changing the `guest_tracking` schema. The unique `(ip_hash, fingerprint_hash)` index +
  `lockForUpdate` already provide DB-level and transactional integrity.

## Resolved questions

- **Quota commit/rollback ownership:** the executor calls `reserveQuota` / `commitQuota` /
  `rollbackQuota` on the pipeline, keeping quota semantics owned by the pipeline (the gate),
  not scattered into ticket closures. For worship, services are charged at commit (spend),
  so `reserveQuota` returns `null` and `commitQuota` does the spend/record.
- **Crisis placement:** a distinct hard-path gate (`crisis()`) that runs before the action
  and can short-circuit without charging — and outside the transaction (see decision 2).
