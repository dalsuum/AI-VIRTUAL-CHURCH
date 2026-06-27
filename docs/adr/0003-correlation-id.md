# ADR 0003 — Correlation IDs thread a whole workflow

- **Status:** Accepted
- **Date:** 2026-06-27

## Decision

Every workflow-initiating record carries a `correlation_id` (UUID). All records it
spawns — events, notifications, and (in later phases) sessions, audit rows and analytics
— **reuse the same id**. Downstream components MUST preserve it and MUST NOT generate a
new one unless they begin a genuinely new workflow.

## Context

A single user action fans out across tables and async listeners. Without a shared key,
tracing "what did this invitation cause?" means brittle joins across unrelated tables, and
idempotency has no natural dedupe key. Invitations carry one id across their whole
lifecycle; friendship events carry a per-action id for that action's fan-out.

## Alternatives considered

- **No correlation id; reconstruct via foreign keys** — couples tables, and async side
  effects (notifications) often have no FK back to the origin. Rejected.
- **Per-record random ids** — loses the cross-record link that makes tracing and dedupe
  possible. Rejected.

## Consequences

- The notifications table stores `correlation_id`; listeners dedupe on it (with
  `data->actor_id` for friendship), giving replay safety.
- Future `CreateSessionFromInvitation` reuses `invitation.correlation_id`, so the session,
  its notifications and analytics all trace back to the originating invitation.
- Cross-cutting observability (one workflow, many rows) is a single indexed lookup.
