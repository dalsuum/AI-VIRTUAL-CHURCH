# ADR 0006 — Presence is ephemeral, accessed only through `PresenceService`

- **Status:** Accepted
- **Date:** 2026-06-27

## Decision

Presence is treated as **ephemeral**. Callers read and write it only through
`App\Domains\Accounts\Services\PresenceService`, never the `Presence` model directly.
Today the service persists to the `presences` table; in Phase 6 it can make Redis the
authoritative store — keeping the table for durable `last_seen_at` / last-activity /
recovery — with no change to controllers. All cross-user reads go through
`PrivacyGate::canViewPresence` (incognito + blocks; a hidden member returns 404).

## Context

Once worship rooms, pastor chat, Bible reading and radio arrive, presence updates become
high-frequency — the wrong shape for a row-per-request relational write on the hot path.
The eventual answer is an in-memory store, but standing that up now would be premature
(no real-time features yet) and violates YAGNI. The cheap, durable move is to fix the
**abstraction boundary** now so the storage swap later is invisible to callers.

## Alternatives considered

- **Read/write the `Presence` model directly from controllers** — couples every caller to
  the relational store, making the Phase 6 Redis migration a wide, risky change. Rejected.
- **Introduce Redis now** — premature infrastructure for features that don't exist yet.
  Rejected (YAGNI); deferred to Phase 6.

## Consequences

- The Phase 6 Redis migration touches one service, not N controllers.
- Visibility stays centralized in [ADR 0004]; presence adds only the incognito layer.
- The `presences` table remains the durable fallback/source of truth when Redis is
  unavailable, so presence degrades gracefully rather than failing.
