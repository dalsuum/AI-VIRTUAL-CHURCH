# ADR 0005 — One polymorphic invitation; `InvitationService` is the only mutator

- **Status:** Accepted
- **Date:** 2026-06-27

## Decision

Every together-activity (worship, bible reading, bible study, prayer, pastor chat, radio)
uses a **single polymorphic `Invitation`** (UUID, nullable `invitable` morph to the
session created on accept). `InvitationService` is the **only** component that writes
invitation status; every transition (`pending → accepted/declined/cancelled/expired`)
flows through one `transition()` method that is row-locked, transactional and idempotent.

## Context

The brief listed six "invite someone to X" features. Built separately they would mean six
workflows, six audit paths, six places to enforce expiry and publish events. The friendship
state machine ([the same pattern]) already proved that centralizing transitions yields one
validator, one event source and safe retries. Audience modes (couple/family/small group/
church/public) are data, not new workflows.

## Alternatives considered

- **Per-activity invitation tables/workflows** — massive duplication; a new activity means
  a new subsystem. Rejected (violates no-duplication, YAGNI).
- **Let controllers/listeners update status** — scatters the state machine, breaks the
  single audit/expiry/event guarantee, and makes idempotency impossible. Rejected.

## Consequences

- A new activity = an enum value + (later) a session factory; never a new table or
  workflow.
- Expiry is enforced in one place (the `invitations:expire` job calls the same
  `transition()`), so a stale invitation can't be accepted.
- Idempotent transitions make the queued event pipeline ([ADR 0002]) replay-safe.
- Session creation is deferred to later phases via an `InvitationAccepted` listener; the
  `invitable` morph + correlation id ([ADR 0003]) are the ready seam.
