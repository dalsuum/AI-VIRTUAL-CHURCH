# AI Virtual Church — System Architecture

**Project:** AIvirtual.Church
**Mission:** A fully AI-conducted, personalised Christian worship service. A worshipper
logs in, shares how they feel and (optionally) a prayer; the system composes and performs
an entire personal service — intake → worship → opening prayer → scripture → sermon →
testimony → offering → closing hymn → benediction. Every spoken segment is generated from
that user's own input, so no two services are identical.

> Detailed AI-role design: [`docs/AI_ARCHITECTURE.md`](../../docs/AI_ARCHITECTURE.md).
> Full-stack / deploy: [`README.md`](../../README.md), [`DEPLOY.md`](../../DEPLOY.md),
> multilingual: [`MULTILINGUAL.md`](../../MULTILINGUAL.md).

---

## Stack

| Layer | Technology |
|-------|-----------|
| Frontend | **Vue 3** SPA (worshipper-facing player) |
| Backend | **Laravel 11** API — owns users, sessions, money, safety |
| AI workers | **Python / Celery** fleet — all AI generation + media |
| Queue / state | **Redis** (plain-JSON queue + Celery broker) |
| Database | **MySQL** (`ai_church`) |
| Object storage | S3-compatible (presigned media URLs) |

The Laravel and Python ecosystems are decoupled by a plain-JSON Redis queue, so neither
has to know the other's serializer.

---

## High-level flow

```
┌────────────┐   HTTPS/JSON   ┌────────────────┐  rpush JSON   ┌───────────┐
│  Vue 3 SPA │ ─────────────▶ │  Laravel 11 API│ ────────────▶ │ Redis     │
└────────────┘                └────────────────┘  ai:intake    └───────────┘
      ▲                              ▲                                │ BLPOP
      │ presigned media URLs         │ POST /internal/* (X-Worker-Secret)
      │                              │                                ▼
      │                              │                        ┌──────────────┐
      │                              └────────────────────────│  bridge.py   │
      │                                                        │ Redis→Celery │
      │                                                        └──────────────┘
      │                                                                │ .delay()
      └──────────────────────── presigned media ◀──── Celery worker fleet (AI role)
```

---

## AI role (summary)

- **Two modes**, admin-toggled per job: an **LLM agent orchestrator**
  (`workers/agent_orchestrator.py`, OpenRouter — Claude / Gemini / ChatGPT) or a
  deterministic **pipeline** (`workers/llm_engine.py`). Both share the same downstream
  Celery tasks and webhook contract.
- **Celery queues:** `ai:orchestrate`, `ai:sermon`, `ai:music`, `ai:narration`,
  `ai:avatar`.
- **Text segments** (opening prayer, sermon, benediction) are generated from the
  worshipper's words. **Scripture is selected by the model but resolved via a licensed
  Bible API** (copyright + accuracy) — never LLM-generated.
- **Guardrail:** all generated text passes `classifier.py` `review()` before reaching
  Laravel; crisis intercept enforced Laravel-side.
- **Multilingual:** self-hosted Tedim LLM (port 8001), Burmese LLM (port 8002), NLLB
  translation, and MMS-TTS. Burmese song lyrics use the curated library only.
- **No personal names** appear in generated service text (all languages).

---

## Coding rules

- Never duplicate code; always write reusable components.
- All business logic lives in **Services**; **controllers stay thin**.
- Never change the database without a migration.
- All code must be secure (OWASP top-10 clean): no hardcoded secrets, sanitize inputs,
  least privilege.
- Update `README.md` and push after any code change.
