# AI System Architecture — AI Virtual Church

_Last generated: 2026-06-16. Scope: the **AI role** — how a worshipper's intake becomes
a fully generated, personalised worship service. For the full-stack view (Laravel, Vue,
deploy) see [`README.md`](../README.md) and [`DEPLOY.md`](../DEPLOY.md)._

---

## 1. Where the AI sits

The platform is deliberately polyglot. The AI layer is the **Python / Celery worker
fleet** in [`workers/`](../workers). It is decoupled from the Laravel API by a plain-JSON
Redis queue, so neither side has to know the other's serializer.

```
┌────────────┐   HTTPS/JSON   ┌────────────────┐  rpush JSON   ┌───────────┐
│  Vue 3 SPA │ ─────────────▶ │  Laravel 11 API│ ────────────▶ │ Redis list│
└────────────┘                └────────────────┘  ai:intake    └───────────┘
      ▲                              ▲                                │ BLPOP
      │ presigned media URLs         │ POST /internal/* (X-Worker-Secret)
      │                              │                                ▼
      │                              │                        ┌──────────────┐
      │                              └────────────────────────│  bridge.py   │
      │                                                        │ Redis→Celery │
      │                                                        └──────────────┘
      │                                                                │ .delay()
      │                                                                ▼
      │                                                  ┌──────────────────────────┐
      └──────────────────────── presigned media ◀────────│  Celery worker fleet     │
                                                          │  (the AI role)           │
                                                          └──────────────────────────┘
```

[`bridge.py`](../workers/bridge.py) does one thing: `BLPOP` a JSON job off `ai:intake`
and hand it to Celery via `send_task`, keeping the two ecosystems loosely coupled.

---

## 2. Two execution modes

Each job is routed by an **admin toggle in Settings** to one of two modes:

| Mode | Entry point | Behaviour |
|------|-------------|-----------|
| **Agent** (default) | [`agent_orchestrator.py`](../workers/agent_orchestrator.py) `run_agent()` | An LLM agent reasons about *what* to generate, in what order, and whether to retry poor output. Uses tool-calling. |
| **Pipeline** | [`llm_engine.py`](../workers/llm_engine.py) | Hard-coded deterministic sequence of segment generators. |

Both modes share the same downstream Celery tasks (narration, music, avatar) and the same
post-asset webhook contract, so they are interchangeable per job.

---

## 3. The agent orchestrator

`run_agent(job)` drives a tool-calling loop against an LLM:

- **Provider routing** — calls go through **OpenRouter** (`OPENROUTER_API_KEY`). The model
  is chosen by the admin provider toggle (stored in Redis):
  - Claude — `anthropic/claude-sonnet-4-6` (default, `AGENT_LLM_MODEL_CLAUDE`)
  - Gemini — `google/gemini-2.5-flash`
  - ChatGPT — `openai/gpt-4o`
- **Responsibility** — the agent generates **TEXT segments only**: opening prayer,
  scripture *reference*, sermon, benediction. It posts each segment to Laravel
  *immediately* (no batching) and decides whether to retry weak output.
- **Tools exposed to the agent** (see `run_agent`):
  - `select_scripture` → resolves full verse text via the licensed **Bible API**
    (not LLM-generated — copyright + accuracy)
  - `post_text_segment` → publishes a segment, and triggers narration + avatar tasks
  - `find_sermon_video` → optional YouTube sermon enrichment
  - a finalisation tool, called once all 4 text segments are posted
- **Telemetry** — prompt/completion token counts are tracked per session
  (`session_prompt_tokens` / `session_completion_tokens` context vars) and posted back as
  a `telemetry_agent` asset.

> **Important constraint:** `agent_orchestrator.py` is imported by `tasks/__init__.py` and
> must **never** import back from it. All task dispatch goes through
> `app.send_task()` on `tasks.celery_app.app` to avoid a circular import.

---

## 4. Celery queues (the worker fleet)

Single Redis broker; named queues mirror the Laravel side
([`tasks/celery_app.py`](../workers/tasks/celery_app.py)):

| Queue | Tasks | Purpose |
|-------|-------|---------|
| `ai:orchestrate` | `tasks.orchestrate` | Entry point; isolated so it is never blocked |
| `ai:sermon` | `generate_text_segments`, `generate_welcome`, `localize_segment_tedim`, `localize_segment_burmese` | Text generation + localization |
| `ai:music` | `generate_music` | Hymn / song / instrumental generation |
| `ai:narration` | `narrate`, `repair_missing_narration`, `narrate_tedim`, `narrate_burmese` | TTS voice-over |
| `ai:avatar` | `render_avatar` | Optional talking-avatar render |

---

## 5. Segment generation pipeline

A complete service is composed of these segments (intake → … → benediction):

```
intake → welcome → worship → opening prayer → scripture → sermon
       → testimony → offering → closing hymn → benediction
```

- **Text** — opening prayer, sermon, benediction generated from the worshipper's own
  mood + prayer text. Scripture is *selected* by the model, *resolved* by the Bible API.
- **Guardrail** — every generated string passes through
  [`classifier.py`](../workers/classifier.py) `review()` (deny-list now; pluggable for a
  classifier model) before it leaves for Laravel. Crisis intercept is enforced
  Laravel-side via `CrisisInterceptService`.
- **Narration** — narrated segments are dispatched to TTS with a per-segment gender
  (sermon keeps the worshipper-context gender; others alternate).
- **Avatar** — optional; rendered for the avatar-eligible segments when enabled on the job.
- **No personal names** — generated service text never includes the worshipper's name
  (policy, all languages).

---

## 6. Music sources

`generate_music` selects a strategy from [`workers/strategies/`](../workers/strategies):
hymn library, sung hymn, instrumental hymn, YouTube hymn, MusicGen, and Suno (with a
reuse pool). Selection logic chooses the best available source per request.

---

## 7. Multilingual AI services

Services can be localized to **Myanmar (Burmese)** and **Tedim (Zolai)**. The AI layer
runs dedicated self-hosted model services (systemd units in
[`.systemd/prod/`](../.systemd/prod)):

| Service | Unit | Role |
|---------|------|------|
| Tedim LLM | `aivc-tedim-api` (port 8001) | Tedim/Zolai generation via local Ollama |
| Burmese LLM | `aivc-burmese-api` (port 8002) | Burmese generation |
| NLLB | `aivc-nllb-api` | NLLB-200 translation (works for Burmese; **not** Tedim) |
| MMS-TTS | `aivc-mms-tts` | Self-hosted Burmese/Tedim speech synthesis |

Routing notes:
- Burmese translation → RunPod NLLB with an Ollama fallback.
- Burmese & Tedim localization are **serialized** by a semaphore in
  `burmese_router.py` / `tedim_router.py`.
- The `burmese-myanmar` model emits word-salad for lyrics → **Burmese song lyrics use
  the curated library only**; the model is not called for them.
- Tedim MMS-TTS narrator and worship/closing-hymn segments are protected and must not be
  removed by pipeline changes.

See [`MULTILINGUAL.md`](../MULTILINGUAL.md) for the full multilingual design.

---

## 8. Webhook contract (AI → Laravel)

Workers post results back to Laravel internal endpoints authenticated with
`X-Worker-Secret` (`WORKER_WEBHOOK_SECRET`):

- `POST /internal/asset-ready` — a text/audio/avatar/telemetry asset is ready
- `POST /internal/music-track` — a generated music track is ready

Laravel stores the asset, then the Vue SPA polls and plays it via presigned media URLs.

---

## 9. Key environment / config

| Variable | Purpose |
|----------|---------|
| `OPENROUTER_API_KEY`, `OPENROUTER_BASE_URL` | Agent + text LLM access |
| `AGENT_LLM_MODEL_CLAUDE / _GEMINI / _CHATGPT` | Per-provider model IDs |
| `LARAVEL_WEBHOOK_URL`, `WORKER_WEBHOOK_SECRET` | Worker → Laravel callbacks |
| `REDIS_URL` | Broker + admin-toggle state |

---

## 10. systemd services (production)

`aivc-bridge`, `aivc-queue`, `aivc-workers`, `aivc-workers-music`, `aivc-scheduler`,
`aivc-tedim-api`, `aivc-burmese-api`, `aivc-nllb-api`, `aivc-mms-tts`,
`aivc-update-checker(.timer)` — see [`.systemd/prod/`](../.systemd/prod) and
[`SERVICE_RESTART_RUNBOOK.md`](../SERVICE_RESTART_RUNBOOK.md).
