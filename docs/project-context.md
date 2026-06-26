# aivirtual.church — Project Context

> Truth source for SaaS phase. Update `last_updated` and the sections below
> whenever the phase materially changes. Companion machine-readable file:
> `/project-state.json` at repo root.

**Current Phase:** Integration / Hardening
**Previous Phase:** MVP / Core Build
**Last Updated:** 2026-06-25

## SaaS Lifecycle (canonical order)

1. Discovery
2. Architecture / Design
3. MVP / Core Build
4. **Integration / Hardening  ← current**
5. Beta (limited users)
6. Production (Live)
7. Maintenance / Iteration

> Note: core features are already deployed to production, but the project as a
> whole sits in Integration / Hardening because major subsystems (AI platform
> kernel, RAG knowledge ingestion, multi-tenant isolation) are still being
> stabilized before a formal Beta/GA gate.

## What is done

- AI Gateway / inference layer implemented
- Guardrails complete
- Orchestrator stable
- Layered AI platform kernel (inference, chat, guardrails, RAG, observability) — commit 08ae7bad
- Unified chat history (folders, branching, search, analytics)
- Billing & subscription system
- Laravel 12.62 upgrade deployed to prod (2026-06-25)

## In progress

- RAG knowledge ingestion (PDF sermons via pdftotext, Qdrant vector + keyword store)
- Multi-tenant isolation
- Release gate v1 (see `docs/RELEASE_GATE_v1.md`)

## Next phase

- Beta deployment to limited users

## How to read phase with Claude

Ask: "Based on docs/project-context.md and project-state.json, what phase is
the project in and why?"
