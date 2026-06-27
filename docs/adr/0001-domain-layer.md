# ADR 0001 — Domain layer under `app/Domains` (additive, not a refactor)

- **Status:** Accepted
- **Date:** 2026-06-27

## Decision

New community-platform code is organized by domain under `app/Domains/<Domain>/`
(Accounts, Church, Friends, Invitations, Notifications, …), each holding its own Models,
Services, Events, Listeners, Policies and Notifications. Existing flat `app/Services`,
`app/Models`, `app/Notifications` are **left in place** — the two structures coexist.

## Context

The platform is growing well beyond CRUD (church, social, invitations, presence, Bible,
worship, AI). Feature-folder sprawl would make ownership and dependencies hard to reason
about. But the Operating Contract demands the smallest safe diff and backward
compatibility, and a repo-wide move of dozens of existing classes is a large, risky diff
with no behavioral benefit. PSR-4 already maps `App\` → `app/`, so nested domain
namespaces need zero composer changes.

## Alternatives considered

- **Move everything into `app/Domains` now** — large blast radius, churns git history,
  risks breaking the live app for purely cosmetic gain. Rejected (violates smallest-diff).
- **Keep the flat structure for new code too** — would bury new domains among unrelated
  service classes and blur boundaries as more domains arrive. Rejected.

## Consequences

- New domains get clear boundaries immediately; cross-domain references are explicit
  FQCNs (e.g. a relation on `App\Models\User` pointing at `App\Domains\Church\Models\…`).
- Listeners under `app/Domains` are outside Laravel's default event auto-discovery, so
  event→listener wiring is explicit (see [ADR 0002]) — a deliberate, acceptable cost.
- Legacy code can migrate opportunistically later, never in a big-bang.
