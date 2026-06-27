# ADR 0004 — `PrivacyGate` is the single visibility & interaction authority

- **Status:** Accepted
- **Date:** 2026-06-27

## Decision

All "may viewer see / interact with owner?" decisions go through one service,
`App\Domains\Accounts\Services\PrivacyGate`. It resolves the `private/friends/church/
public` tiers plus friendship, block and incognito state. Policies, feed queries,
presence reads and invitation/friend initiation all delegate to it. Default-deny.

## Context

Visibility rules recur across every community feature (profiles, presence, activity feed,
invitations, prayer requests). If each feature re-implemented them, a single missed block
or incognito check would leak data, and the rules would drift apart over time. A block, in
particular, must override every other relationship everywhere.

## Alternatives considered

- **Per-feature visibility checks** — guarantees drift and leaks; impossible to audit.
  Rejected.
- **Policies own visibility individually** — policies are the right *entry point* for
  authorization, but the *rule* must be shared; so policies delegate to the gate rather
  than each deriving it. Accepted as the hybrid.

## Consequences

- One truth table to test (`PrivacyGateTest`); one place to change when a tier is added.
- A block is enforced uniformly: the `not.blocked` middleware (404, unprobeable) plus
  `PrivacyGate::canInteract`/`blockExistsBetween`.
- New visibility tiers (e.g. small group) are one enum case + one gate branch; callers
  don't change. See [ADR 0006] for how presence reads layer on top.
