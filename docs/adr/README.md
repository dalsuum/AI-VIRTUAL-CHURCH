# Architecture Decision Records

Short records of the *why* behind the community-platform architecture, so future
contributors understand the reasoning, not just the code. Each is 1–2 pages: Decision,
Context, Alternatives considered, Consequences. Use [`0000-template.md`](0000-template.md)
for new ones; never edit an Accepted ADR to reverse it — supersede it with a new record.

| ADR | Title |
|-----|-------|
| [0001](0001-domain-layer.md) | Domain layer under `app/Domains` (additive, not a refactor) |
| [0002](0002-event-contract.md) | Domain events are a frozen, scalar, past-tense contract |
| [0003](0003-correlation-id.md) | Correlation IDs thread a whole workflow |
| [0004](0004-privacy-gate.md) | `PrivacyGate` is the single visibility & interaction authority |
| [0005](0005-invitation-lifecycle.md) | One polymorphic invitation; `InvitationService` is the only mutator |
| [0006](0006-presence-model.md) | Presence is ephemeral, accessed only through `PresenceService` |

These cover the Phase 1 foundation (`v0.2.0-foundation`). Later phases (Bible, Worship, AI
ministry) add new ADRs as significant decisions arise.
