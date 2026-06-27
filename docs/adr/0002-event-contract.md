# ADR 0002 — Domain events are a frozen, scalar, past-tense contract

- **Status:** Accepted
- **Date:** 2026-06-27

## Decision

Domain events are named in the **past tense** (facts that already happened:
`InvitationAccepted`, `FriendRequestSent`), carry **plain scalar payloads only** (ids,
correlation id, `occurredAt` — never a mutable model instance), and are **published only
by the owning service**. Names and payload shapes are **frozen** once shipped; new facts
get new events rather than edits to existing ones. Event→listener mappings are registered
explicitly in `CommunityEventServiceProvider`.

## Context

Events are the seam every later feature (notifications, activity feed, analytics, AI
memory, session creation) hangs off. If payloads exposed Eloquent models or changed shape,
every subscriber and any queued/serialized event would break, and external integrations
would be impossible. Listeners under `app/Domains` aren't auto-discovered (see [ADR 0001]),
so a single provider doubles as a readable index of who reacts to what.

## Alternatives considered

- **Pass model instances in events** — convenient, but couples subscribers to the schema,
  breaks on queue serialization, and makes the contract unstable. Rejected.
- **Command-style names** (`CreateSession`, `NotifyFriend`) — these are imperatives that
  belong in services, not records of fact; they invite listeners to drive control flow.
  Rejected.
- **Rely on Laravel event auto-discovery** — doesn't scan `app/Domains`, and an explicit
  map is clearer for cross-domain wiring. Rejected.

## Consequences

- Events are safe to queue, version and consume externally.
- Listeners are side-effects only; they never mutate domain state or call controllers.
- Adding a subscriber (feed/analytics/AI memory) is a new listener in the provider,
  touching no publishing service. Pairs with [ADR 0003] (correlation) and the
  replay-safety guarantee.
