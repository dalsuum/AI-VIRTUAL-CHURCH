# AI Virtual Church

A fully **AI-conducted worship service**. A worshipper logs in, says how they feel and
optionally writes a prayer, and the system composes and performs an entire personal
service — intake → worship → opening prayer → scripture → sermon → testimony → offering
→ closing hymn → benediction. Every spoken segment is generated from that user's own
input, so **no two services are identical**.

The stack is deliberately polyglot: a **Laravel** API owns users, sessions, money, and
safety; a fleet of **Python/Celery** workers does the AI generation and media; a **Vue 3**
SPA is the worshipper-facing player. The two ecosystems are decoupled by a plain-JSON
Redis queue so neither has to know the other's serializer.

---

## Table of contents

- [Architecture at a glance](#architecture-at-a-glance)
- [The service flow, end to end](#the-service-flow-end-to-end)
- [Components](#components)
  - [Backend (Laravel)](#backend-laravel)
  - [Workers (Python / Celery)](#workers-python--celery)
  - [Frontend (Vue 3)](#frontend-vue-3)
- [Data model](#data-model)
- [The segment pipeline in detail](#the-segment-pipeline-in-detail)
- [AI agent orchestration](#ai-agent-orchestration)
- [Multilingual services (Myanmar & Tedim)](#multilingual-services-myanmar--tedim)
- [Music: four sources + a reuse pool](#music-four-sources--a-reuse-pool)
- [Narration & avatar (optional enrichments)](#narration--avatar-optional-enrichments)
- [Safety: the crisis intercept](#safety-the-crisis-intercept)
- [Content filter (YouTube allow/block firewall)](#content-filter-youtube-allowblock-firewall)
- [Offering & financial ledger](#offering--financial-ledger)
- [Testimonies](#testimonies)
- [Admin console](#admin-console)
- [Scheduled services](#scheduled-services)
- [Running locally](#running-locally)
- [Deploying to production](#deploying-to-production)
- [Server Restart Runbook](#server-restart-runbook)
- [Suno Pool CRUD Manual](#suno-pool-crud-manual)
- [Environment variables](#environment-variables)
- [API reference](#api-reference)
- [Project status](#project-status)
- [Acknowledgements — free AI services](#acknowledgements--free-ai-services)
  - [YouTube Content Creators](#youtube-content-creators)

---

## Architecture at a glance

```
Service-generation pipeline (async — sermon · music · avatar · narration; Redis → Celery)

┌────────────┐    HTTPS/JSON     ┌──────────────────┐   rpush JSON    ┌─────────────┐
│  Vue 3 SPA │ ───────────────▶  │  Laravel 12 API  │ ──────────────▶ │ Redis list  │
│ (frontend) │ ◀───────────────  │   (backend)      │   ai:intake     │ ai:intake   │
└────────────┘   poll / WS       └──────────────────┘                 └─────────────┘
      ▲                                  ▲                                    │ BLPOP
      │ presigned media URLs             │ POST /internal/asset-ready         ▼
      │                                  │  (X-Worker-Secret)         ┌────────────────┐
      │                                  └────────────────────────────│  bridge.py     │
      │                                                               │ (Redis→Celery) │
      │                                                               └────────────────┘
      │                                                                        │ .delay()
      │                                                            ┌───────────┴───────────┐
      │                                                            ▼                       ▼
      │                                                   ┌─────────────────┐   ┌──────────────────┐
      └─── object storage (S3 / local) ◀───────────────── │ Celery workers  │   │  OpenRouter LLM  │
                                                          │ sermon · music  │   │  Bible · Suno    │
                                                          │ avatar · narr.  │   │  YouTube · TTS   │
                                                          │ my/td direct    │   └──────────────────┘
                                                          └────────┬────────┘
                                                                   │ HTTP (localhost)
                                                          ┌────────▼────────┐
                                                          │  Language APIs  │   ┌──────────────────┐
                                                          │  FastAPI :8001  │──▶│  Ollama          │
                                                          │  /tedim/*  (td) │   │  tedim-zolai 1b  │
                                                          │  /falam/* (cfm) │   │  falam-lai 1b    │
                                                          │  /hakha/* (cnh) │   │  hakha-lai 1b    │
                                                          │  /mizo/*  (lus) │   │  mizo-lushai 1b  │
                                                          │  /paite/* (pck) │   │  paite-zomi 1b   │
                                                          │  FastAPI :8002  │──▶│  burmese-myanmar │
                                                          │  /burmese/* (my)│   └──────────────────┘
                                                          └─────────────────┘
```

**Why a Redis list and not a shared Celery broker?** Laravel and Python don't share a
task serializer. `DispatchServiceJob` just `rpush`es a language-agnostic JSON blob onto
`ai:intake`; `bridge.py` `BLPOP`s it and hands it to the Celery orchestrator. The
contract between the two worlds is "a JSON object on a list," nothing more.

**The chat path is separate and synchronous.** Pastor Chat and Bible Study don't use the
Redis/Celery pipeline — they are request/response. When `KNOWLEDGE_ENABLED` is on, the Laravel
`Knowledge` layer (`App\Services\Knowledge`) grounds each reply in retrieved context (hybrid
RAG); when off it falls back to `NullKnowledgeRetriever` (no retrieval) so production is
unaffected. Query embeddings come from the Python embedding service (`POST /knowledge/embed`,
driver `worker`); vectors **and** the keyword index live in Qdrant (driver `qdrant`); both
degrade to in-process stubs (`hash` / `memory`) in dev. Corpora are loaded offline with
`php artisan knowledge:ingest`.

```
Chat / RAG path (synchronous — Pastor Chat · Bible Study), gated by KNOWLEDGE_ENABLED
(off → NullKnowledgeRetriever, no retrieval; on → each reply grounded in retrieved context)

┌────────────┐  HTTPS/JSON  ┌──────────────────┐  grounded prompt  ┌──────────────────┐
│  Vue 3 SPA │ ───────────▶ │  Laravel 12 API  │ ────────────────▶ │  OpenRouter LLM  │
│ (frontend) │ ◀─────────── │   (backend)      │ ◀───── reply ──── │                  │
└────────────┘  reply       └────────┬─────────┘                   └──────────────────┘
                                     │ App\Services\Knowledge — Hybrid RAG
                                     │ keyword + vector → RRF → rerank → KnowledgeContext
                        ┌────────────┴────────────┐
                   embed query             keyword + vector search
                        ▼                         ▼
              ┌──────────────────┐       ┌──────────────────┐
              │ Embedding service│       │   Qdrant  :6333  │
              │  FastAPI  :8004  │       │ vector + keyword │
              │ all-MiniLM-L6-v2 │       │   collections    │
              └──────────────────┘       └────────▲─────────┘
                                                  │ php artisan knowledge:ingest
                                                  │ chunk → embed → upsert
                            corpora: bible · sermon · faq · docs · prayer · commentary …
```

---

## AI Platform kernel (Inference · Chat · Guardrails · Knowledge · Observability)

A layered, controller-thin execution engine for AI surfaces. Controllers call **one**
thing — the Chat Orchestrator — and every concern lives behind a stable interface, so
layers swap by binding and roll back by flag. Each layer is dark-by-default; production
behaviour is unchanged until the corresponding flag is enabled.

```
Controller → ChatOrchestrator
   ├─ Input Guardrails  (Chain of Responsibility: crisis, injection, abuse, PII, rate-limit)
   ├─ Knowledge (Hybrid RAG)  → KnowledgeContext only
   ├─ Prompt Builder  (receives KnowledgeContext; never queries storage)
   ├─ Inference Gateway  (provider abstraction + circuit breaker + fallback)
   ├─ Output Guardrails  (HTML/markdown/username sanitisers, moderation, hallucination,
   │                      citation, theology — policy externalised from execution)
   ├─ Persistence  (unified history spine)
   └─ Telemetry / Tracing  (one trace per request)
```

| Layer | Namespace | Key seam | Flag |
|---|---|---|---|
| Inference | `App\Services\Inference` | `InferenceGateway` | — (always on) |
| Chat Orchestrator | `App\Services\Chat` | `ChatOrchestrator` | — |
| Guardrails | `App\Services\Chat\Guardrails` | `InputGuardrail` / `OutputGuardrail` pipelines | bind in `GuardrailServiceProvider` |
| Knowledge (RAG) | `App\Services\Knowledge` | `KnowledgeRetriever → KnowledgeContext` | `KNOWLEDGE_ENABLED` |
| Observability | `App\Services\Observability` | `Tracer` (Null by default) | `OBSERVABILITY_TRACING` |

**Design invariants (test-backed):**
- **Inference resilience** — per-provider retry + circuit breaker (`ResilientProvider`),
  cross-provider fallback chains by language (`config/inference.php`). Adding OpenAI/
  Gemini/DeepSeek = one `InferenceProvider` class.
- **Hybrid retrieval** — keyword + vector fused by Reciprocal Rank Fusion, then reranked
  and budget-bounded into a `KnowledgeContext`. Vector store abstracted (`VectorStore`):
  in-memory/FAISS-via-worker now, **Qdrant** for scale — swap by binding.
- **Per-capability RAG** — each `ChatCapability` declares `usesKnowledge()`. Bible Study is
  reference-heavy (always on); Pastor Chat is relational and config-gated by
  `knowledge.capabilities.pastor_uses_knowledge` (env `KNOWLEDGE_PASTOR_RAG`, default on) so it
  can be grounded in the sermon/KB corpus or returned to purely conversational without a code change.
- **Failure contract** — retrieval degrades, never cascades: a vector/embedding outage
  falls back to keyword; total outage yields `EMPTY_DUE_TO_FAILURE` (distinct from a
  clean `EMPTY_DUE_TO_NO_MATCH`); corrupt chunks are dropped before they reach the prompt.
  *Vector failure never becomes chat failure.*
- **Lazy corpora** — a configured corpus whose Qdrant collection isn't provisioned yet is
  skipped (no embedding/keyword/vector call, no false `degraded`), via a cached
  `ManagesCollections::hasCollection()`. Newly-ingested collections light up automatically
  within the cache TTL. Fails open: if the collection listing can't be fetched, retrieval
  still runs so a genuine outage surfaces as `degraded`/`failed`, not a clean no-match.
- **Guardrails** — composable chain with per-capability enable/disable and policy in
  `config/guardrails.php` (not in guard code). Output guards thread/sanitise text;
  validators short-circuit. Knowledge-backed surfaces can fail closed when retrieval is
  down.
- **Observability** — `OBSERVABILITY_TRACING=true` emits one trace per request
  (`chat.request → guardrails.pre → retrieval.hybrid{rrf.fusion, rerank, context.build}
  → inference.llm → guardrails.post → persistence.write`), persisted by correlation id
  and materialised (never re-run) at `GET /api/v1/chat/debug/{correlationId}` (staff only).

**Security gate — multi-tenant knowledge:** corpora today are SHARED and read-only (bible,
sermon), so there is no tenant boundary to cross. **Private / per-church corpora MUST NOT be
enabled until tenant-scoped vector filtering is enforced on the retrieval path** (the tenant
filter made non-optional so an unscoped query returns zero cross-tenant rows). The invariant
is pinned by `tests/Unit/Knowledge/TenantIsolationTest.php` (one passing isolation test + one
skipped gate test to unskip when the feature lands).

**First end-to-end slice:** `POST /api/v1/chat/study` (Bible Study capability) runs the
full pipeline; the controller depends only on `ChatOrchestrator`. Ingestion is CLI/worker
side via `php artisan knowledge:ingest {collection} {file} --chunker=bible|text`.

Config: `config/{inference,chat,guardrails,knowledge,observability}.php`. Service
providers: `Inference`, `Observability`, `Chat`, `Guardrail`, `Knowledge` (registered in
`bootstrap/providers.php`, in dependency order).

### Ingesting sermon PDFs (incl. Burmese / Tedim)

PDFs are chunked, embedded and indexed **out of band** (CLI/worker, never the web request).
English sermons live in `backend/storage/app/knowledge/sermons/`. To add other languages,
keep them in **per-language folders** so the right `--lang` tag is stamped on every chunk:

```bash
# 1. Upload the PDFs to the box (scp / rsync / VS Code Remote-SSH file explorer)
mkdir -p backend/storage/app/knowledge/sermons_my   # Burmese (Myanmar)
mkdir -p backend/storage/app/knowledge/sermons_td   # Tedim (Zolai)
scp '*.pdf' user@host:/opt/ai-church/backend/storage/app/knowledge/sermons_my/

# 2. Ingest each language (run on the WORKER box with Qdrant + worker embedding active,
#    or the index lives in-memory and never reaches chat retrieval)
cd backend
php artisan knowledge:ingest sermon storage/app/knowledge/sermons_my --chunker=text --lang=my
php artisan knowledge:ingest sermon storage/app/knowledge/sermons_td --chunker=text --lang=td
```

**Harvesting Burmese sermons from the web.** `backend/storage/knowledge/sermons_my/` holds a
standalone crawler (`run.py`) that builds a clean Burmese training dataset from public sermon
sites (myanmar3am.com, minlwin.wordpress.com). It honours robots.txt, throttles requests,
checkpoints every 20 pages (resumable), and emits `dataset/{train,instruction,qa}.jsonl` plus
`metadata.csv`. Run `pip install -r requirements.txt && python run.py` in that folder; see its
README for output schema and the licensing caveat. Crawled output is gitignored (regenerable
and may be copyrighted).

**Validating the corpus before ingest.** Run the corpus validator to check for corrupt files,
duplicates, and coverage before ingesting into the RAG pipeline:

```bash
python workers/tools/validate_dataset.py
# Checks: total sermons, per-source counts, SHA256 duplicates, missing title/author/date,
# invalid JSON, UTF-8 errors, Bible ref parse rate, average length, top 10 books referenced.
# Exits 0 on clean corpus, 1 on fatal issues (bad JSON, UTF-8, or duplicates).
```

**Ingesting the crawled corpus into the Knowledge RAG.** The corpus must be exported to the
single-file `[{id,text,metadata}]` format the ingestion pipeline expects, then ingested:

```bash
# 1. Export raw/*.json → storage/app/knowledge/sermons_my.json
python workers/tools/export_sermons_my.py

# 2. Ingest into the 'sermon' Qdrant collection
php artisan knowledge:ingest sermon storage/app/knowledge/sermons_my.json --chunker=text --lang=my
```

After ingest, sermon retrieval is automatically combined with Bible retrieval in Pastor Chat
(the `RetrievalOrchestrator` fans out across all corpora including `sermon`).

### Ingesting the Bible corpus (uses the project's OWN bundled text)

The `bible` corpus is built from the same vendored translations the Bible reader serves
(`workers/data/*.json`) — no external source. `workers/tools/export_bible.py` reuses
`bible_api` (the single source of truth) and emits **one document per verse** to
`storage/app/knowledge/bible_<lang>.json`, then `knowledge:ingest` indexes them:

```bash
# 1. Export one or more translations (default = every bundled translation)
python workers/tools/export_bible.py --langs en          # English (BSB) only
python workers/tools/export_bible.py                      # all ~24 translations

# 2. Ingest each language file into the shared 'bible' Qdrant collection
cd backend
php artisan knowledge:ingest bible storage/app/knowledge/bible_en.json --chunker=text
```

Use `--chunker=text`, **not** `--chunker=bible`: the verse chunker re-derives verse
boundaries by splitting on digit prefixes, which corrupts translations whose verse text
contains numerals (e.g. BSB "Methuselah lived 969 years"). One verse per document + the
prose chunker keeps a single chunk per verse with an exact, pre-built reference. Verses are
stamped with the translation's app code as `language`; retrieval filters on the conversation
language, so ingest the codes that are real chat languages (a translation-only code like
`kjv` will index but never be retrieved). Embedding runs on the local worker (~66 verses/s →
~8 min per translation); `bible` outranks `sermon` via `source_priority`.

### RAG acceptance / regression eval

`knowledge:eval` is the "unit tests for retrieval" — it runs real queries through the live
`RetrievalOrchestrator` and asserts retrieval **accuracy**, **citation** correctness and
**language** correctness, exiting non-zero on any regression so a deploy can gate on it:

```bash
php artisan knowledge:eval            # all conversation languages with data present
php artisan knowledge:eval --lang=my  # one language;  --k=10 to widen the window
```

Two case kinds: near-verbatim **English semantic** questions (expected book+chapter must
surface), and per-language **anchor roundtrip** — the verse's own text (read from the exported
`bible_<lang>.json`, never transcribed) must return that exact reference with no
cross-language leakage. Languages without ingested data are skipped, not failed.

### Learner vocabulary (multilingual, AI-generated)

Separate from the curated Zolai/Chin reference dictionary (`vocabularies` table — left
untouched), the **learner** view renders any curated concept into any of the 14 supported
languages on demand. `GET /api/vocabulary/{id}/learn?lang=ja` serves the cached entry, or
returns `202 generating` and enqueues a `vocab_generate` job. The history worker
(`_run_vocab_generate`) produces a structured entry (pronunciation, meaning, definition,
examples, synonyms/antonyms/related, optional Bible verse) under the same authoritative
**LANGUAGE LAW** as Pastor Chat, and the signed `/internal/history-callback` (`vocab_entry`
mode) caches it in `vocab_entries`. No new authored content and no per-language schema — the
existing dictionary is the seed concept list. Deploy step: `php artisan migrate`.

The learner UI lives at `#vocabulary` (`VocabularyLearn.vue`): a searchable concept browser
(exact→prefix→fuzzy ranking) with a **single language selector spanning both sources** — the
curated Chin/Zo, Burmese and Hebrew columns render straight off the concept row (instant,
canonical), while the AI languages are generated into `vocab_entries` on demand and cached.
The worshipper never sees the seam. All chrome is vue-i18n; the page is responsive,
RTL-aware and keyboard-accessible. Frontend deploy: `npm run build` in `frontend/`.

AI generation/Explain is offered only for languages with a learner code —
`VocabEntry::learnerLanguages()` (the interface registry minus
`VocabEntry::NON_LEARNER_LANGUAGES`, currently `he`). Hebrew stays a Bible/reference locale,
shown from its curated column but **not** AI-generated (the current model returns English for
Hebrew); the Chin-only columns (Falam/Hakha/Matu/Mizo/Paite/Sizang) likewise render from the
curated reference. The `learn`/`explain` endpoints reject non-learner locales (422).

Per-user features (Phase C) are the **only** stored user state: `user_vocabulary` holds
favorites and viewed history (`POST/DELETE /api/vocabulary/{id}/favorite`,
`GET /api/me/vocabulary?kind=favorite|viewed`; views recorded on `learn` for registered
users). AI **Explain** (`POST /api/vocabulary/{id}/explain?lang=`) is a teaching explanation
generated under the same LANGUAGE LAW and **cached** on `vocab_entries.explanation` per
(concept, language) — never stored per user. Bible-verse examples and pronunciation already
ship inside the generated entry payload.

Generation is **deduped**: while an entry is in flight a 45s `Cache::add` lock means the UI's
polling can't spawn duplicate jobs (which otherwise self-inflicted a provider rate-limit storm
and left cards stuck on "Generating…"). After 45s a retry is allowed, and the UI surfaces a
Retry button instead of spinning forever if generation never lands.

Pastor Chat replies in the worshipper's selected interface language (`_pastor_system` in
`workers/plugins/history/driver.py`). The reply-language instruction is authoritative — the
model keeps replying in the chosen language even when the worshipper types in English, and only
switches when the worshipper explicitly asks. Adding a locale to `config/languages.php` (and the
mirrored `_LANG_NAME` map) is all that is needed for the Pastor to answer in it.

The directory form parses every `*.pdf` inside; a corrupt/unreadable file is **skipped with a
warning**, never aborting the batch. Required infra env (backend `.env`):

```
KNOWLEDGE_ENABLED=true
KNOWLEDGE_VECTOR=qdrant
KNOWLEDGE_EMBEDDING=worker
KNOWLEDGE_WORKER_URL=http://127.0.0.1:8001   # workers/knowledge_embed_service.py (api:app)
KNOWLEDGE_EMBEDDING_DIMS=384                  # MUST match the embed model's output dim
```

**Two prerequisites for Burmese / Tedim specifically** (the default setup is English-only):

1. **Use a multilingual embedding model.** The worker defaults to `all-MiniLM-L6-v2`
   (English-centric — it embeds Myanmar/Tedim poorly). Set
   `KNOWLEDGE_EMBED_MODEL=paraphrase-multilingual-MiniLM-L12-v2` in the worker env (same
   384 dims, drop-in) and restart the worker. Vectors from two different models cannot be
   mixed in one collection — **re-ingest all corpora** after switching models.
2. **Verify PDF text extraction first.** `pdftotext` can mangle Myanmar script for
   image-based PDFs. Spot-check one file before bulk ingest:
   `pdftotext -layout -enc UTF-8 sermons_my/<file>.pdf - | head -40`. If the text is
   garbled/tofu, OCR it first: `ocrmypdf -l mya in.pdf out.pdf`.

---

## The service flow, end to end

1. **Auth.** The worshipper registers, logs in, or starts as a **guest** (an anonymous
   `*@guest.local` account is minted). Auth uses Sanctum SPA session cookies (HttpOnly,
   CSRF-protected); no bearer tokens are stored in JS. **Registration is verified by
   email**: `POST /register` creates a `pending` account (no auto-login, no tokens) and
   emails a single-use, 24h, sha256-hashed activation link. Clicking it hits
   `GET /activate?token=…` ([ActivationController.php](backend/app/Http/Controllers/ActivationController.php)),
   which activates the account, stamps `email_verified_at`, and grants the **Member
   monthly package** through the existing ledger-backed [TokenService](backend/app/Services/TokenService.php)
   (`MEMBER_MONTHLY_TOKENS`, default 100). Login is blocked until the account is active.
   Never-activated accounts are pruned hourly by `users:cleanup-pending`. See
   [AccountActivationService.php](backend/app/Services/AccountActivationService.php).
   If a **registered** session goes stale mid-flow (idle past the session lifetime, or
   rotated by a password/email change → 401 from `EnsureAccountIsUsable`), the intake flow
   routes the worshipper to sign in again rather than silently downgrading them to a guest
   (which would re-run the service anonymously and trip the one-free-use guest quota).
2. **Start a session.** `POST /service/start` creates a `service_sessions` row and
   **locks the music source** (`hymn_sung` | `hymn` | `suno` | `youtube`) from the
   user's saved preference, so the choice can't drift mid-service.
3. **Intake.** `POST /service/{token}/intake` submits `mood` + optional `prayer_text`
   (+ optional `scheduled_at`).
4. **Safety gate — runs *before* anything is queued.** `CrisisInterceptService` scans
   the prayer text. If it trips a crisis keyword, the session is marked `abandoned`, a
   static vetted resource card is returned, and **the LLM pipeline is never invoked.**
5. **Dispatch.** A clean intake is persisted and `DispatchServiceJob` `rpush`es the job
   onto `ai:intake`. (If `scheduled_at` is set, the session is parked as `scheduled` and
   released later by the minute-ly scheduler instead.)
6. **Orchestrate.** `bridge.py` pops the job → Celery `tasks.orchestrate`:
   - builds the **intake plan** (the model picks a scripture reference, a Suno prompt,
     and a YouTube query) from the user's own words;
   - fires a **welcome-back** greeting (registered users only) onto its own task so the
     countdown screen has something personal while heavier segments compose;
   - **fans out** to `generate_text_segments` (sermon queue) and `generate_music`
     (music queue), which run in parallel.
7. **Generate + enrich.** Each spoken segment is generated, run through a
   post-generation **classifier** guardrail, and optionally enriched with a
   **talking-head avatar video** (HeyGen or Local Open Source) and/or **narration audio** (TTS).
8. **Deliver.** Every finished asset is `POST`ed back to Laravel's
   `/api/internal/asset-ready` webhook (shared-secret auth). Laravel upserts the
   `service_assets` row — each pass *enriches* the row (text, then audio, then video)
   rather than clobbering it.
9. **Play.** The Vue `ServicePlayer` polls `GET /service/{token}` (WebSocket is the
   intended primary; polling is the working fallback) and walks the worshipper through
   one stage at a time, auto-reading each segment aloud and auto-advancing.

> **Degrade, never block.** Every enrichment task (scripture resolution, music, avatar,
> narration) is wrapped so a provider outage or missing key leaves the worshipper with
> the text segment instead of crashing the service.

---

## Components

### Backend (Laravel)

Laravel 12 (PHP 8.2+), Sanctum auth, Stripe PHP SDK. Owns everything stateful and
everything that must be trustworthy: users, sessions, the safety gate, the money ledger,
moderation, and the admin console.

| Area | File |
|------|------|
| Auth (register / login / guest / me / music-source / email / password) | [AuthController.php](backend/app/Http/Controllers/AuthController.php) |
| Service lifecycle (start / intake / show / resume) | [ServiceController.php](backend/app/Http/Controllers/ServiceController.php) |
| Laravel→Python dispatch | [DispatchServiceJob.php](backend/app/Jobs/DispatchServiceJob.php) |
| Worker callback (asset-ready) | [WebhookController.php](backend/app/Http/Controllers/WebhookController.php) |
| Crisis safety gate | [CrisisInterceptService.php](backend/app/Services/CrisisInterceptService.php) |
| Offering / Stripe | [OfferingController.php](backend/app/Http/Controllers/OfferingController.php), [OfferingService.php](backend/app/Services/OfferingService.php) |
| Testimonies | [TestimonyController.php](backend/app/Http/Controllers/TestimonyController.php) |
| Admin console | [AdminController.php](backend/app/Http/Controllers/AdminController.php), [EnsureAdmin.php](backend/app/Http/Middleware/EnsureAdmin.php) |
| Scheduled-service release | [DispatchDueServices.php](backend/app/Console/Commands/DispatchDueServices.php) |
| Security headers (HSTS, CSP, X-Frame-Options, …) | [SecurityHeaders.php](backend/app/Http/Middleware/SecurityHeaders.php) |
| Scheduled-service confirmation email | [ServiceScheduledNotification.php](backend/app/Notifications/ServiceScheduledNotification.php) |
| Service-ready reminder email | [ServiceReminderNotification.php](backend/app/Notifications/ServiceReminderNotification.php) |
| Routes | [api.php](backend/routes/api.php) |
| **Tedim** — local Ollama/FastAPI client used by legacy localization/admin paths | [TedimLlmService.php](backend/app/Services/TedimLlmService.php) |
| **Myanmar** — local Ollama/FastAPI client used by legacy localization/admin paths | [BurmeseLlmService.php](backend/app/Services/BurmeseLlmService.php) |
| **Myanmar/Tedim localization jobs** | Legacy compatibility only. New services generate directly in `my`/`td`; `WebhookController` no longer dispatches post-generation localization jobs. |

**Admin Console → AI Usage (live monitor).** A read-only, admin-only tab that polls `GET /api/admin/ai-usage` every 20s and shows month-to-date AI activity at a glance: token-metered LLM usage per module from `ai_usage_ledger` (calls, prompt/output tokens) with an **estimated** USD cost, plus per-service operational counts from `usage_logs` (calls, today, failures, avg latency). Cost is estimated from token counts × per-model list price in [`config/inference.php`](backend/config/inference.php) (`pricing.models` + `pricing.modules`) — authoritative bills live on each provider's dashboard, and media APIs (Suno/RunPod/D-ID) are not token-metered here so they don't appear. [`AdminController::aiUsage`](backend/app/Http/Controllers/AdminController.php) + the `ai-usage` tab in [AdminConsole.vue](frontend/src/components/AdminConsole.vue).

### Workers (Python / Celery)

A single Celery app with one Redis broker and **named queues that mirror the work**:

| Queue | Task | Does |
|-------|------|------|
| `ai:orchestrate` | `orchestrate` | Session startup only (~20 s: build plan, fan out). Dedicated 2-worker pool (`aivc-workers-orchestrate.service`) so new requests are never blocked behind long content-generation tasks. |
| `ai:sermon` | `generate_text_segments`, `generate_welcome` | LLM plan + direct target-language prayer / sermon / benediction + welcome greeting. Legacy `localize_segment_*` tasks remain importable but are not dispatched for new services. |
| `ai:music` | `generate_music` | Hymn, Suno, or YouTube, resolved per session |
| `ai:avatar` | `render_avatar` | RunPod talking-head video. Runs in its **own** dedicated 2-worker pool (`aivc-workers-avatar.service`), isolated from sermon/narration — so a RunPod outage that makes `render_avatar` retry-loop can only back up `ai:avatar`, never starve narration. |
| `ai:narration` | `narrate`, `narrate_tedim`, `narrate_burmese` | text-to-speech of the spoken segments (English Edge/OpenAI/Kokoro; Myanmar/Tedim via local MMS-TTS). The **opening prayer takes the first TTS slot** (slot 0); scripture and later segments are staggered behind it (up to 60 s apart for CPU MMS-TTS) so the preparing screen — which gates on opening-prayer audio — opens as fast as possible. |

| Module | Responsibility |
|--------|----------------|
| [bridge.py](workers/bridge.py) | `BLPOP ai:intake` → `orchestrate.delay()`. The Laravel↔Python seam. |
| [tasks/\_\_init\_\_.py](workers/tasks/__init__.py) | The orchestrator and all generation tasks; posts assets back to Laravel. |
| [tasks/celery_app.py](workers/tasks/celery_app.py) | Celery config + queue routing. |
| [tasks/celery_tedim_tasks.py](workers/tasks/celery_tedim_tasks.py) | Legacy Tedim localization/narration tasks kept for compatibility. New services use `generate_text_segments(..., language='td')` and `tasks.narrate(..., language='td')`. |
| [tasks/celery_burmese_tasks.py](workers/tasks/celery_burmese_tasks.py) | Legacy Myanmar localization/narration tasks kept for compatibility. New services use `generate_text_segments(..., language='my')` and `tasks.narrate(..., language='my')`. |
| [tedim_router.py](workers/tedim_router.py) | FastAPI router: `POST /tedim/translate`, `POST /tedim/generate`, `GET /tedim/verse?ref=`. Redis db 2 cache (30-day TTL). Single-inference semaphore. `_validate_tedim()` multi-layer gatekeeper: (1) min 60-char length; (2) must contain core Tedim theological vocabulary; (3) ≥2 sentence-final `hi`/`hen` particles; (4) sentence-ending ratio — ≥60% must end with `hi`/`hen`/`in`/`amen` (genuine Tedim grammar); (5) no sentence-initial `hi`/`hen`; (6) no consecutive repeated words; (7) trigram-loop guard — rejects output where any 3-word phrase repeats ≥3 times. Returns HTTP 502 on failure so `llm_engine` uses handcrafted Tedim instead of serving word salad. `_ollama()` uses `temperature 0.3`, `top_p 0.85`, `top_k 40`, `repeat_penalty 1.3`. |
| [burmese_router.py](workers/burmese_router.py) | FastAPI router: `POST /burmese/translate`, `POST /burmese/generate`, `GET /burmese/verse?ref=`. Redis db 3 cache (30-day TTL). Shares the same semaphore pattern as the Tedim router. Myanmar Unicode only — no Zawgyi. |
| [chin_router.py](workers/chin_router.py) | Config-driven sibling of `tedim_router.py` adding four more Chin/Zo languages: **Falam** (`cfm`, `/falam/*`, ollama `falam-lai`) and **Hakha** (`cnh`, `/hakha/*`, ollama `hakha-lai`) on their own instruction-tuned Ollama models (llama3.2:1b + per-language Modelfile system prompt); **Mizo** (`lus`, `/mizo/*`) and **Paite** (`pck`, `/paite/*`) have no instruction model upstream so they are backed by the **goldfish-models** monolingual LMs via `goldfish_service` (`engine: "goldfish"` in their LANGS config — `goldfish-models/lus_latn_full`, `goldfish-models/pck_latn_full`). Each exposes `POST /<lang>/translate`, `POST /<lang>/generate`, `GET /<lang>/verse?ref=`; `_infer()` dispatches to the configured engine. Shared single-inference semaphore + Redis db 2 cache (30-day TTL). Validation is intentionally lighter than Tedim (length ≥60, language vocabulary markers, repeated-word + trigram-loop guards, English-paragraph/parenthetical-note stripping) — it omits Tedim's `hi`/`hen` sentence-final-particle grammar, which is Tedim-specific. Fails with HTTP 502 so `llm_engine` falls back to curated content, same contract as Tedim. The Falam/Hakha base 1B is a placeholder; the Modelfiles (`Modelfile.falam/.hakha/.mizo/.paite` at repo root) document the path to fine-tuned per-language GGUFs. Bible verse text is looked up from `bible_api`, never generated. |
| [goldfish_service.py](workers/goldfish_service.py) | Serves the **goldfish-models** monolingual GPT-2-style causal LMs for the two Bible-only Chin/Zo languages with no instruction model or native TTS voice: **Mizo** (`lus` → `goldfish-models/lus_latn_full`) and **Paite** (`pck` → `goldfish-models/pck_latn_full`). Models load lazily on first use (transformers + torch, CPU, thread-capped, single-inference semaphore — mirrors `mms_tts_service`). Completion-only, so callers prime it with a native-language seed and it continues. `chin_router` imports `generate_text()` in-process; `POST /goldfish/generate` + `GET /goldfish/languages` expose it over HTTP. Each narrator is **admin-toggleable** from the Admin Console → Bible page — `AdminController` mirrors `narration_lus`/`narration_pck` to Redis (`ai:narration_<iso>`), which `is_enabled()` consults; when off, generation raises HTTP 502 so the caller falls back to curated content. |
| [api.py](workers/api.py) | Unified FastAPI app mounting Tedim, Burmese, the four Chin/Zo languages (Falam/Hakha via Ollama, Mizo/Paite via goldfish — `chin_router.ROUTERS`), the `/goldfish/*` goldfish LLM service, `/tts/speak` MMS-TTS, and `/stt/transcribe` MMS-ASR routers plus `/health`. Typically run as two separate uvicorn instances: port 8001 (`aivc-tedim-api`) for Tedim/Chin/MMS requests, port 8002 (`aivc-burmese-api`) for Burmese. |
| [mms_tts_api.py](workers/mms_tts_api.py) | Dedicated MMS speech app on port 8003. Mounts only `/tts/*` and `/stt/*` so PyTorch speech work can run separately from Ollama LLM inference. `/tts/speak` splits long input on sentence boundaries and synthesises chunk-by-chunk (concatenating the waveforms) so a full Bible chapter no longer OOM-kills the worker on a small host; `MMS_TTS_TIMEOUT=600` covers the multi-minute first-run synthesis (cached thereafter). Burmese Bible narration uses Edge TTS (`my-MM-NilarNeural`/`ThihaNeural`), resolved per-language in `BibleController::edgeVoice()` — an English Edge voice cannot synthesise Burmese script. |
| [nllb_api.py](workers/nllb_api.py) · [nllb_service.py](workers/nllb_service.py) · [hf_space_nllb/](workers/hf_space_nllb/) | **Burmese prose is produced by translating the already-good English segment** (`facebook/nllb-200-distilled-600M`, `eng_Latn`→`mya_Mymr`) rather than generating Myanmar directly with the Ollama `burmese-myanmar` model, which emitted word-salad. `_complete()` in [llm_engine.py](workers/llm_engine.py) tries translators in order: **(1) the NLLB ZeroGPU Space** (`NLLB_HF_SPACE`, off-box on HF's GPU — `hf_space_nllb/app.py`, called via `gradio_client`); **(2) the local NLLB service** (`nllb_api.py` on port 8004, `POST /nllb/translate`, lazy-loaded model, Redis db 4 cache, `_clean_myanmar` strips per-syllable spacing). If both fail it **raises** so the caller serves its safe curated/hardcoded Burmese fallback — the Ollama `burmese-myanmar` model is deliberately NOT in the chain. The local service is a light standby (model loads only if the Space is unreachable), keeping the 2.3 GB model off the CPU box. **Tedim is NOT routed through NLLB** — `tdt_Latn` tested as unusable (echoes English back), so Tedim stays on Ollama `tedim-zolai`. Note: HF's *free serverless* tier no longer hosts NLLB (`Model not supported by provider hf-inference`), which is why a self-hosted ZeroGPU Space is used. |
| [hymns_my.py](workers/hymns_my.py) | Loader for the 852-song `data/hymns_my.json` Burmese library; mood-based selection for `MyanmarHymnStrategy`. |
| [hymns_td.py](workers/hymns_td.py) | Loader for `data/hymns_td.json` (bundled, 467 hymns); mood selection + YouTube-embed priority for `TedimHymnStrategy`. |
| [strategies/sung_hymn_strategy.py](workers/strategies/sung_hymn_strategy.py) | Unified `hymn_sung` strategy for all languages. English: public-domain 78rpm vocal recording. Burmese/Tedim: locally-downloaded `.sung.mp3` under `hymns_my/` or `hymns_td/`; falls back to English audio with localized lyrics overlay. Carries optional LRC `timings` from the hymn library through `MusicResult` to the player — attached only when the on-screen lyrics match the audio (the English path, not the my/td→English fallback). |
| [strategies/instrumental_hymn_strategy.py](workers/strategies/instrumental_hymn_strategy.py) | Unified `hymn` (instrumental) strategy for all languages. Burmese/Tedim selection is restricted to slugs whose MP3 is actually seeded (via `storage.list_keys`) so it stays in-language instead of silently falling through to English MIDI. |
| [strategies/local_ai_strategy.py](workers/strategies/local_ai_strategy.py) | `local_ai` music source — GPU-preferred MusicGen (subclass of `MusicGenStrategy`). Auto-detects CUDA; falls back to CPU. |
| [strategies/_suno_custom.py](workers/strategies/_suno_custom.py) | Shared helper that builds and calls Suno customMode with exact lyrics for a given language/style. **Lyric sanitization rules (must be kept up to date when the hymn data format changes):** (1) `ထပ်ဆိoရန်[။]` on its own line → `[Chorus]` — this is the classic Burmese hymnal "Repeat/Chorus" marker, not a lyric; (2) Burmese numeral verse prefixes `၁ lyric text` → `[Verse 1]\nlyric text`; standalone `၁` → `[Verse 1]`. Suno treats `[Verse N]`/`[Chorus]` as structural metatags and never sings them. Any new non-lyric patterns found in `hymns_my.json` must be added to `_MY_SECTION_TAGS` or the verse-number substitution in `sanitize_lyrics()` — never leave raw structural markers reaching the Suno prompt. |
| [tools/seed_language_data.py](workers/tools/seed_language_data.py) | One-time seeder: downloads or builds the registered Bible corpus into `workers/data/`: English KJV, Judson 1835 (Myanmar), Lai Siangtho 1932 (Tedim), seven Chin/Zo language Bibles, Hebrew WLC, Japanese Colloquial 1955, Hindi IRV 2019, Arabic Van Dyck, Chinese Union Simplified, Reina Valera 1909, Korean Revised Version, Thai KJV, the canonical book index, and Myanmar hymns. |
| [tools/build_kjv_bible.py](workers/tools/build_kjv_bible.py) | Builds the English Authorized (King James) Version into `data/kjv.json` in the dalsuum/bible schema, from the public-domain getbible.net `kjv` module. Full 66-book canon (Genesis=1…Revelation=66), canonical numbering. A second English edition alongside BSB; the output is also the file to commit to dalsuum/bible so it seeds like the other translations. |
| [tools/build_hebrew_bible.py](workers/tools/build_hebrew_bible.py) | Builds the Hebrew Tanakh (Westminster Leningrad Codex) into `data/wlc.json` in the dalsuum/bible schema, from the public-domain getbible.net `codex` module. Old Testament only (39 books, Genesis=1…Malachi=39), pointed Hebrew, right-to-left. The output is also the file to commit to dalsuum/bible so it seeds like the other translations. |
| [tools/seed_tedim_hymns.py](workers/tools/seed_tedim_hymns.py) | Refreshes `data/hymns_td.json` if you want to pick up newly added hymns. Not required at deploy — the file is bundled in the repo. |
| [tools/seed_tedim_midi.py](workers/tools/seed_tedim_midi.py) | Optional: instrumental fallback renders from the Tedim Hymn 7th Edition MIDI library (needs fluidsynth + ffmpeg). |
| [tools/import_myanmar_hymns.py](workers/tools/import_myanmar_hymns.py) | Regenerates `data/hymns_my.json` from the upstream dalsuum/myanmar-hymns source repo. |
| [tools/build_tedim_dataset.py](workers/tools/build_tedim_dataset.py) | Builds a JSONL fine-tuning dataset (~56 600 examples, 31 MB) from the Lai Siangtho 1932 Bible, Tedim hymnal (467 hymns), and Zolai vocabulary/grammar guide. Outputs `data/tedim_finetune.jsonl` (90 % train) and `data/tedim_finetune_val.jsonl` (10 % val) in standard chat-format for LoRA fine-tuning on Llama 3 / Mistral. |
| [tools/collect_myanmar_lyrics.py](workers/tools/collect_myanmar_lyrics.py) | Collects Myanmar Christian worship lyrics from OpenLyrics XML sources and a Blogspot index. Enforces Myanmar Unicode, strips guitar chord lines, deduplicates, and writes the result to `data/myanmar_lyrics_collection.json`. Run once (or periodically) to grow the lyrics corpus for Suno and fine-tuning use. Requires `requests` + `beautifulsoup4`. |
| [tools/tap_lyrics.py](workers/tools/tap_lyrics.py) | LRC "Tapper": plays a hymn's local sung MP3 via ffplay and captures one timestamp per lyric line on the spacebar (undo/restart/finish), then merges a `timings` array into the matching `hymns_td.json` / `hymns_my.json` object (1-space indent, literal unicode — minimal diff). td/my only (English hymns live in `hymns.py`, not JSON). Authors the line cues the player consumes for synced lyrics. |
| [llm_engine.py](workers/llm_engine.py) | Intake plan via OpenRouter; spoken prose generated directly in English/Myanmar/Tedim. Myanmar/Tedim prose is routed to the local FastAPI/Ollama services when configured. **Safe hardcoded fallbacks apply to all languages** (English included) — if OpenRouter or the local model times out or returns unusable text at any segment (welcome, prayer, sermon, benediction), the fallback fires instead of leaving the service frozen. Strips markdown / stage directions to clean spoken prose. **Burmese output plausibility guard** (`_is_my_plausible`) counts Myanmar Unicode codepoints (U+1000–U+109F) in each generated segment; fragments below the per-segment minimum (20 for welcome, 60 for prayer, 30 for benediction, 80 for sermon) are treated as garbled model output and the safe fallback fires immediately rather than sending fragmentary text to the worshipper. **AI-composed song lyrics (`generate_music_lyrics`, Myanmar/Tedim)** are sourced library-first: `_library_lyrics_for_mood` searches the worship library (`song_library.py` → `GET /songs`) with the worshipper's mood and reuses the first matching, in-language curated song — guaranteeing correct vocabulary and phrasing. **Burmese** then goes straight to the curated fallback (the local llama3.2:3b-based model emits word-salad, not real Myanmar, so it is not called for lyrics); **Tedim** composes via its local Zolai Ollama model when no library song matches. Every path passes the hardened language guard before reaching Suno — for `my` the guard requires a core worship term (ဘုရား/ကိုယ်တော်/ယေရှု…) and rejects low unique-token ratios so model word-salad can never reach Suno. |
| [bible_api.py](workers/bible_api.py) | Resolves a scripture *reference* to verse *text* from bundled public-domain / freely licensed translations: BSB and King James Version (English), Judson 1835 (Myanmar), Lai Siangtho 1932 (Tedim), Westminster Leningrad Codex (Hebrew Tanakh — Old Testament only, right-to-left), seven Chin/Zo language Bibles from the Bible Society of Myanmar (Falam `cfm`, Hakha `cnh`, Mizo `lus`, Paite `pck`, Sizang `csy`, Mara `mrh`, Matu `hlt`), plus world-language editions — Arabic Van Dyck `ar`, German Luther 1912 `de`, Reina Valera 1909 `es`, French Ostervald 1877 `fr`, Hindi IRV 2019 `hi`, Japanese Colloquial 1955 `ja`, Korean Revised Version `ko`, Tamil IRV 2019 `ta`, Thai KJV `th`, and Chinese Union Simplified `zh-CN`. Copyrighted JCB (`ja-jcb`) and CCB (`zh-CN-ccb`) are drop-in only: hidden by default and activated only when a licensed same-schema JSON file is installed. Source and redistribution terms are recorded in [docs/BIBLE_TRANSLATION_SOURCES.md](docs/BIBLE_TRANSLATION_SOURCES.md). The model never writes scripture. Also exposes browse helpers (`list_books`, `chapter`) for the online Bible reader — `list_books` carries English `aliases` (name/shortname/abbr) per book on non-English translations so the reader's book search matches either the native heading or the English name ("Genesis"/"Gen"); partial-canon translations (Hebrew) pad their table of contents out to the full 66 books with the missing books flagged `available: false` so the reader greys them, while translations with extra deuterocanonical books or unreliable native book names (Matu) are capped to and labelled from the canonical 66-book index. |
| [bible_router.py](workers/bible_router.py) | FastAPI routes backing the online Bible reader: `/bible/books`, `/bible/chapter`, `/bible/languages` (read-only) and `POST /bible/narrate` (chapter text-to-speech). Serves the same in-memory public-domain / freely licensed translations as `bible_api`; Laravel proxies these under `/api/bible`. Narration reuses `narrator.synthesize()` and caches one audio file per (translation, book, chapter, voice) under `bible-audio/…`, so each chapter is synthesized once. `POST /bible/narrate-text` narrates an **arbitrary passage** (an AI Bible Study reply) with the same providers/voices, caching by content hash under `study-audio/…`; Laravel exposes it (auth-gated) as `POST /api/v1/study/narrate`, used by the optional, per-device **🔊 Narration** toggle on the Bible Study page (off by default, independent of any other narration). When checked, replies **auto-play in order** (one at a time, markdown flattened to plain prose first); each bubble's icon is a stop (⏸) / play (🔊) control, and unchecking stops playback immediately. |
| [classifier.py](workers/classifier.py) | Post-generation deny-list guardrail (`review() → (ok, reason)`). |
| [strategies/](workers/strategies/) | `MusicStrategy` interface + `HymnStrategy` / `SunoStrategy` / `YouTubeStrategy`, returning a normalized `MusicResult`. All functions are synchronous — they run inside Celery tasks that have no event loop. `YouTubeStrategy` accepts a `language` argument so `_LANG_CONFIG` routes each service to its correct filter set. **Sermon slot** — three-gate filter: (1) title must contain a preaching indicator (`sermon_title_require_any`; word-boundary for Latin scripts, substring for non-Latin scripts), (2) title must NOT contain choir/music/concert keywords (`sermon_title_reject_any`; same matching rules), (3) channel must not be in `channel_reject_any`. Burmese (`my`) sermon search also ignores English-only agent queries, searches with Burmese sermon terms, requests Myanmar-language/region results, and requires Myanmar script in the video title so English sermons cannot fill the Burmese sermon slot. **Tedim** (`td`) sermon search leads with native vocabulary queries (`thugenna`, `thu gen`) and requires at least one Zomi/Tedim identity word (zomi, tedim, zolai, thugenna, thugen, thu gen) in the video title — Tedim uses Latin script so a Unicode-range check is not possible; the identity-word gate serves the same role as the Myanmar-script check. **Worship music slot** — three-gate filter: (1) title must contain at least one Christian/worship term (`music_title_require_any`) to block cartoons and secular videos, (2) title must not be in `music_title_reject_any`, (3) channel check. After both filters, results are scored by mood-keyword density and the best match wins. `"sunday"` is absent from all sermon require-lists — it caused "Mission Sunday Choir" events to appear as sermons. Per-language sermon require-lists: **English** — sermon/preaching/message/pastor/rev/teaching/bible study/gospel; **Burmese** (`my`) — `တရားဟောချက်`/`တရားဟော`/`နုတ်ကပတ်တော်`/`သွန်သင်ချက်` plus pastor/rev; **Tedim** (`td`) — sermon/preaching/message/pastor/rev/thugenna/thu gen/thugen (+ identity gate). Tedim also rejects cartoon/animation/movie/drama from the music slot. **Language-first for all 14 languages (not just en/my/td):** every language in `worship_moods.php` now has a full `_LANG_CONFIG` entry, so a Korean/Japanese/French/… service builds its sermon **and** worship queries from native vocabulary (`query_anchor` + `sermon_query_terms` / `worship_query_terms`, e.g. `기독교 설교`, `日本語 賛美歌`) instead of silently falling back to English. Each request is biased with `relevanceLanguage`/`regionCode`, and a shared script gate (`_native_language_ok`, mirroring `MusicRecommendationService::titleLanguageScriptOk`) requires a non-Latin language's own script in the title and rejects any title carrying a foreign major script (Korean must be Hangul, Japanese kana/han, Arabic Arabic, …) — so the Korean sermon/worship slot stays Korean. Latin-script languages (en/fr/de/es/td) rely on native query terms + `relevanceLanguage` plus the foreign-script reject (en/my/td keep their original bespoke gates). Adding a new language requires only a new `_LANG_CONFIG` entry (+ its code in `_NATIVE_QUERY_LANGS`). **Caption-aware ranking (Phase 2b.1):** sermon discovery **prefers** videos that carry a caption track (a `videos.list` `contentDetails.caption` check via `_videos_with_captions`, adding `_CAPTION_SCORE_BONUS` to the candidate score before the top-5 pick) so a translatable transcript exists for the future subtitle step — a preference, **not** a filter: uncaptioned sermons still qualify and the lookup fails open, so native-first selection never regresses. |
| [hymns.py](workers/hymns.py) / [seed_hymns.py](workers/seed_hymns.py) | Public-domain hymn library (lyrics + recordings) and the one-time seeder that renders/downloads it into storage. |
| [song_library.py](workers/song_library.py) | Live worship-song reader. The `songs` DB table is the single source of truth (admin Lyrics tab); the worker reads songs on demand from the backend's public `GET /songs` endpoint — the same data the website uses. One store, no JSON copy, no drift. Override the backend base with the `CHURCH_API_URL` env var. |

**Admin Lyrics tab — bulk export/import.** The Song Library list has an **Export** menu (CSV, TXT, PDF, JSON). Each row has a checkbox plus a header "select all" (scoped to the current view); when songs are ticked the export covers **just that selection** (the Export button shows the count, e.g. `Export (3)`), and with nothing ticked it falls back to the *currently filtered view* (respects the language tabs + search). The PDF export renders its hidden layout off-screen *inside the DOM* (html2canvas cannot rasterise a detached node — doing so produced blank/white pages), then removes it once the file is saved. There is also an **Import** button that accepts **CSV or JSON** for bulk add. Import skips songs that already exist (same title + language, case-insensitive) so re-importing a file is a no-op. CSV columns (header row, case-insensitive): `language, title, artist, category, lyrics, url`. Endpoint: `POST /admin/songs/import` (`lyrics.manage`, 5 MB cap). PDF is export-only (printable songbook); PDF/TXT import is intentionally unsupported because parsing free-form layouts is unreliable.

**Admin Vocabulary tab — DB-backed reference editor.** The `#vocabulary` reference is multilingual: each word carries a gloss per Chin/Zo language plus Burmese, Hebrew and English, stored in the `vocabularies` DB table and edited from **Admin Console → Vocabulary** (`vocabulary.manage` permission). Language columns are `zolai` (default, required), `falam`, `hakha`, `matu`, `mizo`, `paite`, `sizang`, `burmese`, `hebrew`, `english` (required) — the ethnic-language set added in `2026_06_21_000001_add_ethnic_languages_to_vocabularies_table` mirrors the Bible reader voice rows; all but zolai/english are nullable and filled in over time. The public page ([VocabularyLearn.vue](frontend/src/components/VocabularyLearn.vue) at `#vocabulary`) reads this curated data live, and its **single language dropdown** also spans the AI-generated languages (cached in `vocab_entries`) so a worshipper reads each word in their own tongue. The admin list table also has a **"Show language" dropdown** — with so many language columns it would otherwise overflow, so the list shows only Zolai + the chosen language + English (the editor form still exposes every language). Add/edit/delete rows with category filter + search (matches every language); the public page reads the same data live via `GET /api/vocabulary`. The optional `hebrew` column (added alongside the Hebrew Tanakh/WLC Bible reader) renders right-to-left. The legacy `notes` column is retained in the DB but is no longer shown or edited in the UI/API. Seeded once from `frontend/src/data/zolai_vocabulary.json` via `php artisan db:seed --class="Database\Seeders\VocabularySeeder"` (idempotent — upserts by zolai+english+category). Burmese glosses were machine-seeded as best-effort and are meant to be corrected here.

**Front song panel — worship-ready exports.** The public Myanmar worship-song page (`MyanmarLyrics.vue`) reads the worship library **live** from the public `GET /songs` endpoint — the same `songs` DB table the admin Lyrics tab writes, so there is no duplicated static JSON to drift (the old `public/data/myanmar_lyrics_collection.json` fetch was removed; the 852-song `hymns_my.json` corpus stays static because it is not in the DB). An open song can be downloaded as **.TXT**, **PDF**, or **PPTX**. The PowerPoint export (`pptxgenjs`, lazy-loaded so it never bloats the main bundle) builds a 16:9 deck: a title slide plus one slide per verse/section, large centred white text on a dark projection background, with chord markers stripped for clean congregation slides — ready to open in PowerPoint at the start of worship. The page uses the **mobile-first design system**: a sticky search bar (leading magnify icon), horizontally-scrollable category chips (44px touch targets, never wrapping), a single-column responsive **song-card list** with larger Burmese-optimised titles, and Iconify (`mdi`) icons throughout (no emojis) — back/pagination arrows, the per-card music glyph, and the export buttons. Presentation only: search, category filtering, pagination, the detail/chord-sheet view, and all three exports are unchanged. Covered by [frontend/e2e/songs.smoke.spec.js](frontend/e2e/songs.smoke.spec.js) (search/chips/card→detail→back at 390px).
| [data/myanmar_lyrics_collection.json](workers/data/myanmar_lyrics_collection.json) | Scraper **staging** output from `tools/collect_myanmar_lyrics.py` — not read at runtime. Load it into the `songs` table with `php artisan songs:import-corpus` (idempotent), after which the DB is authoritative and the worker reads songs live via `song_library.py`. |
| [test_llm_engine.py](workers/test_llm_engine.py) | Unit tests for `llm_engine` — covers `_strip_formatting`, `_is_my_plausible`, `_fix_tedim_vocab`, and the per-segment fallback behaviour. Run with `python -m unittest test_llm_engine`. |
| [test_agent_orchestrator.py](workers/test_agent_orchestrator.py) | Unit tests for `agent_orchestrator` — covers JSON parse tolerance, MAX_TURNS recovery, and tool-call error handling. Run with `python -m unittest test_agent_orchestrator`. |
| [avatar.py](workers/avatar.py) | HeyGen render (submit → poll → store → URL). Key-gated. |
| [narrator.py](workers/narrator.py) | OpenAI/Kokoro/Edge/MMS narration. `edge_tts` uses real Microsoft cloud TTS for all languages (Myanmar: `my-MM-NilarNeural`/`ThihaNeural`; Tedim: `EDGE_TTS_VOICE_TD`); `mms_tts` routes to local `facebook/mms-tts-mya`/`mms-tts-ctd`. MMS calls have a bounded timeout. **Number normalization** (`_normalize_mms_text`): Arabic and Burmese digits (0–9 / ၀–၉) are converted to spoken words (`_spell_burmese` / `_spell_tedim`) before text reaches the acoustic model, preventing silent digit output; verse separators (`3:16` or `၃း၁၆`) are split into two independent numbers. |
| [storage.py](workers/storage.py) | Object storage with two interchangeable backends: **local dir** (dev) or **S3** (prod). |

### Frontend (Vue 3)

Vue 3 + Vite, Stripe.js for the offering. A thin SPA whose job is to walk through the
service one stage at a time.

| Component | Role |
|-----------|------|
| [App.vue](frontend/src/App.vue) | Stage machine: `intake` → `preparing` → `service`; routes `#admin` to the console, `#vocabulary` to the unified vocabulary page. **Auth + account entry** is hash-routed too (no client router): `#login`/`#register` render [AuthPanel.vue](frontend/src/components/AuthPanel.vue) (posts to `api.login()`/`api.register()`; register no longer auto-logs-in — it shows a "check your email to activate" confirmation), and `#account` renders [AccountSettings.vue](frontend/src/components/AccountSettings.vue) (token balance, plan, upgrade/cancel, change password). On load `App.vue` resolves identity via `GET /me` and applies lightweight **route guards** mirroring requireAuth/requireGuest/requireAdmin — `#account` needs a registered login (else → `#login`), `#login`/`#register` bounce already-authenticated users to `#account`, and logged-in non-staff are kept out of `#admin` (the console keeps its own login form for the unauthenticated case). The **topbar nav** is identity-aware: logged-out visitors see **Login / Register**; registered users see **Account / Logout**; admins additionally see an **Admin** link. A guest session (`*@guest.local`) counts as logged-out for this UI. The preparing screen opens as soon as the opening-prayer audio lands; a ~140 s failsafe (counted from when the prayer **text** is ready, not from full service "complete") opens the door even if a slow/failed narration callback never produces that audio, so worshippers are never trapped on "Generating voice narration…". The **topbar** is responsive: on phones (≤640px) the brand keeps the **logo mark + "AI Virtual Church" wordmark** (beside the hamburger) and the **page nav links are hidden entirely** (`.nav-page { display:none }`) — mobile page navigation lives in the **bottom nav** ([BottomNav.vue](frontend/src/components/layout/BottomNav.vue)), so the header keeps only identity controls (**Login / Register** for guests, **Logout** for members) plus the **light/dark theme toggle**, which always stays visible on the right. **Global layout:** the header (sticky topbar + nav) and footer ("AI can make mistakes…" + donate link) are rendered **once** in `App.vue` and wrap **every** route — the active view (Bible, Bible Study, Worship, Pastor, Journey, Vocabulary, Admin, intake/account/auth, …) swaps inside a single `<main class="app-main">` between them, so the header/footer never re-render, disappear, or duplicate on navigation (hash routing means no full page reload, so header state — session, language toggle — is preserved). `.app-main` is a flex column that stretches a full-screen route view (e.g. the Bible reader's freeze-pane) to fill exactly the space between the header and footer. Route components no longer carry their own copy of the site header/footer (the duplicates in `BibleStudy.vue` were removed). The shell is extracted into reusable components under [frontend/src/components/layout/](frontend/src/components/layout/): **AppLayout.vue** (the `#app-shell` wrapper — header + `<main>` slot + footer; the seam where future shell pieces like a notification bar or breadcrumbs can be added), **AppHeader.vue** (sticky topbar; the nav is **data-driven** from a single `navItems` array — add a page by adding one entry + its route — with `active` highlighting derived from the reactive `currentHash`), and **AppFooter.vue**. App.vue passes identity/feature-flag props down and handles `@logout`. On route change App.vue also does **scroll restoration** (`window.scrollTo(0,0)` when the base hash changes, ignoring same-page `?session=` suffixes) so a new page always starts at the top. A **Playwright layout-shell smoke suite** ([frontend/e2e/shell.smoke.spec.js](frontend/e2e/shell.smoke.spec.js), `npm run test:e2e`) locks this in: it loads every primary route at 1440px **and** 390px and asserts exactly one header + one footer + one `<main>` per route, the centered intake card, no horizontal overflow, sticky-footer positioning, and the scroll-restoration rules (resets on base-route change, preserves on `?session=` change). It runs as its **own path-filtered CI workflow** ([.github/workflows/e2e.yml](.github/workflows/e2e.yml)) that triggers only when shell-relevant files change (App.vue, `components/layout/**`, global CSS, the spec) — not on every frontend edit — serving the production build with `vite preview` (the SPA treats the absent backend as "logged out" so the shell still renders). The shell carries a `data-layout="app"` anchor and the suite asserts exactly one exists, closing the nested-shell loophole. The rules these tests defend are written down in [components/layout/LAYOUT_CONTRACT.md](frontend/src/components/layout/LAYOUT_CONTRACT.md) (AppLayout owns header/footer; one shell per route tree; pages don't reintroduce chrome; full-width pages are intentional exceptions; scroll-reset + responsive-nav rules). A dev-only `window.__layoutDebug = true` (or `?layoutDebug`) flag outlines the header/main/footer regions and logs scroll state — stripped from production builds. |
| [BibleReader.vue](frontend/src/components/BibleReader.vue) | Online Bible reader at `#bible`. Browse books/chapters in English (Berean Standard Bible, 2020 & King James Version), Burmese (Judson 1835), Tedim (Lai Siangtho 1932), Hebrew (Westminster Leningrad Codex — the Tanakh, Old Testament only, rendered **right-to-left**; the New Testament books are shown greyed/unselectable for this translation) seven Chin/Zo language Bibles from the Bible Society of Myanmar (Falam, Hakha, Mizo, Paite, Sizang, Mara, Matu — Latin script), and world-language editions (Arabic Van Dyck, German Luther 1912, Reina Valera 1909, French Ostervald 1877, Hindi IRV 2019, Japanese Colloquial 1955, Korean Revised Version, Tamil IRV 2019, Thai KJV, and Chinese Union Simplified); the active translation's version and year are shown beneath the language tabs, and switching translation keeps your place. The **open translation follows the one global language authority**: changing the header language opens the matching Bible automatically (resolved data-drivenly from the available `versions` in `GET /api/bible/config`, picking the reader's remembered preferred edition for that language — e.g. CCB vs Chinese Union — or the first available). Tapping a translation tab still works and is remembered as that language's preferred edition. A **🔊 Listen** button narrates the open chapter aloud — English via the admin's configured voice provider, Burmese, Tedim, Falam, Hakha & Matu via the native MMS-TTS voices (`mms-tts-mya`/`-ctd`/`-cfm`/`-cnh`/`-hlt`), Hebrew and the world-language Bibles via native Edge neural voices — reusing the service narration stack, and **highlights each verse as it's read** (proportional-timing, like the service player). A **📖 Continuous** toggle (per-device, **off** by default) makes the narration roll past the end of the chapter into the next chapter — and on into the next book across both testaments — until the very end of the Bible, instead of stopping at the chapter end. The chapter view is a **freeze-panes layout** (flex `100dvh`, not `position: sticky`, which silently fails on mobile Chrome): the control bar, chapter title, player and **Previous/Next** stay frozen while only the verse list scrolls. The frozen panel is **collapsible** (▾ Controls / ▴ Hide, remembered per-device): collapsing folds away the app header, language tabs and toggle buttons down to a slim handle (back · chapter title · toggle) for maximum reading space, while the audio player + Speed row stay visible whenever narration is loaded so you can read with the panel folded and still control playback. A **playback-speed** control (0.75×/Normal/1.25×/1.5×, remembered per-device) sets the narration rate — highlighting stays in sync since it maps `currentTime/duration`; background music keeps looping at its own natural tempo regardless of narration speed. Highlighting and music on/off are **per-device reader preferences** layered over the admin default: the admin setting is what a first-time reader sees, but each reader's own toggle (in `localStorage`) wins. The control bar is **responsive**: full labels on wide screens, collapsing to a wrapping **icon-only toolbar** (← / 🔊 / ✨ / 🎵 / 📋 + chapter picker, with on/off shown by colour) on phones so nothing overflows or is hidden. Reading-comfort controls (all per-device): a **text-size** selector (Normal / Medium / Large, for older eyes — scales the verse font and line spacing), a **peaceful reading background** beyond dark/light (Default / Sepia / Cream / Mint / Sky / Night — each a self-contained background+text pair so contrast stays readable in either app theme, applied to the verse pane only), and a **📋 Select** mode: tap to pick one or many verses, then **Copy** a clean shareable block to the clipboard — `Genesis 1:1-3, 5 (Berean Standard Bible (2020))` followed by the numbered verses, with consecutive verses collapsed into ranges — for pasting into notes or social media (off by default so taps never disturb reading; cleared on chapter change). An admin can also play **looping background music** softly behind the narration, with three modes (`bible_bg_music_mode`): **off**, **static** (one fixed track chosen from a **music library** — admins upload multiple local **.mp3/.ogg** tracks (`POST /api/admin/bible/bg-music/upload`), see them listed alongside the AI-generated loops (`GET …/bg-music/library`), preview each, delete uploads (`DELETE …/bg-music/library/{id}`), **tag each upload with a mood + time-of-day** (`PATCH …/bg-music/library/{id}`) and pick which one plays (`POST …/bg-music/select`); the `BibleBgMusicLibrary` service keeps a JSON manifest for uploads and discovers AI loops from the worker's `bible-bg/` dir, and every track streams back through the public `GET /api/bible/bg-music/file?src=…&key=…` so no external hosting/CORS is needed. When uploaded tracks carry tags, the reader auto-picks the best-fitting upload per chapter via `GET /api/bible/bg-music/match`, using the **same coarse theme + reader time-of-day** AI mode uses (the worker's `GET /bible/bg-music/classify` returns the chapter's `{theme, tod}`; Laravel scores each upload's tags — exact match 2, `any` wildcard 1 — and falls back to the single selected track, or to time-of-day-only matching if the worker is unreachable). With no tags it stays a single fixed track, exactly as before), or **ai** — an instrumental loop generated per chapter, keyed by a coarse **theme** inferred from the chapter text (comfort/praise/lament/hope/peace/wisdom) and the **reader's local time of day** (morning/afternoon/evening/night). AI tracks are stored under a deterministic, language-independent key `bible-bg/{theme}_{tod}.mp3`, so existence-check + presign needs no DB registry; the first reader to hit an uncached bucket triggers a one-off MusicGen generation offloaded to the `ai:music` Celery worker (`tasks.generate_bible_bg`, engine `bible_bg_music_engine` = `musicgen`|`local_ai`) and falls back to silence until it's cached. The background-music loop is **independent of the voice**: when the reader has 🎵 Music on it plays whenever a chapter is open — softly under the narration when there's a voice, and **on its own when the language has no narrator or the voice is off** (so a non-narrated translation still gets ambient music). Every Bible setting lives on its own **Admin Console → Bible** page: the Bible voice as one compact row **per translation** (all registered versions — English/KJV get every provider, Burmese/Tedim/Falam/Hakha/Matu add native MMS-TTS, Hebrew and the world-language Bibles use native Edge voices, the remaining Chin/Zo Bibles (Mizo, Paite, Sizang, Mara — no upstream MMS-TTS) read phonetically via the English Edge voice, and any version can be set to **Off**), the highlight default, and all background-music settings (`bible_narration_mode_*`, `bible_text_highlight_enabled`, `bible_bg_music_*`, independent of the live-service narrator but inheriting the voice when unset), **plus a per-version feature matrix** (`bible_features`). The matrix has one row per translation and a checkbox per control — **Show tab** (hides the whole translation from the reader, and `BibleController` rejects its `/api/bible/*` access with 404) and one toggle each for 🔊 Listen, ✨ Highlight, 📖 Continuous, 🎵 Music, 📋 Select, playback **Speed**, **Aa** text size and **Color** themes. Everything defaults on, so an unconfigured install behaves exactly as before; the reader reads `versions` + `features` from `GET /api/bible/config` and hides any disabled tab or button. AI resolution goes through `GET /api/bible/bg-music` → worker `POST /bible/bg-music` ([bible_bg.py](workers/bible_bg.py)). Reads via the public `/api/bible/*` proxy (`BibleController`, incl. `/bible/config`) — no auth required. **Mobile-first design-system pass:** all control glyphs are now Iconify (`mdi`) SVGs via `AppIcon` (no emojis) — back/All-books arrows, the collapse chevron, Listen (`volume-high`, spinning `loading` while preparing), Highlight, Continuous, Music, Select, Copy, and Prev/Next; every action/toggle button has a 44px touch target. Presentation only — narration, highlighting, continuous reading, background music, speed, text-size, colour themes, select/copy and freeze-pane scrolling are unchanged. Covered by [frontend/e2e/bible.smoke.spec.js](frontend/e2e/bible.smoke.spec.js) (header/tabs/search render + emoji-free icons + no 390px overflow). |
| [VocabularyLearn.vue](frontend/src/components/VocabularyLearn.vue) | The **single Vocabulary page** at `#vocabulary` (nav label "Vocabulary") — it replaces the former split between a comparison-table dictionary and a separate "Learn" page. A searchable concept browser (exact→prefix→fuzzy ranking) with **one language selector spanning both data sources**: the curated Chin/Zo, Burmese and Hebrew columns render straight off the concept row (instant, canonical), while the AI languages (French/German/Spanish/Japanese/Chinese/Korean/Hindi/Tamil/Thai/Arabic) are generated into `vocab_entries` on demand and cached — the worshipper never sees the seam. **Default language follows the one global language authority** (English→English, မြန်မာ→Burmese, Zolai→Zolai). In the **browse grid**, each card's secondary gloss is the selected curated word (Chin/Zo) or the Zolai seed (for English); **AI languages show no per-card gloss** — their word is generated only when a concept is opened, so the grid never misleadingly shows the Zolai seed when e.g. Japanese is selected. Opening a concept shows a detail card for the selected language; AI languages poll while generating (capped, with a Retry). 🤖 **Explain** + ⭐ favourites + 🕘 history are offered for languages with a learner code (`VocabEntry::learnerLanguages()`); the Chin-only columns (Falam/Matu/Mizo/Paite/Sizang) + Hebrew render their curated word only. Loads **live** from `GET /api/vocabulary` — the `vocabularies` DB table is the source of truth, edited from **Admin Console → Vocabulary**. Hebrew/Arabic render right-to-left. |
| [IntakeForm.vue](frontend/src/components/IntakeForm.vue) | Mood picker (first question). **No language tab** — the service language follows the global header language (see **Single language authority**), constrained to the service-capable set (`config.enabled_languages`, admin-toggled per language). When the chosen UI language is **not** in that set (e.g. a UI-only locale such as Arabic/Hebrew), the form **blocks generation with a clear notice** (`intake.errors.languageUnavailable`, "Services aren't available in {lang} yet") and disables the Begin button — rather than silently generating in English and charging for the wrong language. All visible strings come from vue-i18n (the old per-language `STRINGS`/mood/source maps were migrated into `en`/`my`/`td` locale files). For first-time visitors, name/email/prayer/music-source/scheduling are collapsed behind an "Add a prayer request or schedule" toggle so the main path is one-tap. Returning users always see the full form. Passes the (clamped) service `language` and `mood` to the preparing screen so countdown verses load in the right Bible translation immediately. Moods, music sources, and scheduling toggle are all driven by `GET /config`. |
| [PreparingView.vue](frontend/src/components/PreparingView.vue) | Countdown screen; accepts `language` and `mood` props from the intake event so mood-matched Scripture cards load in the correct Bible translation before the server poll returns. Card type is `'verse'` (labelled "Scripture"); label `'banner'` shows admin text; `'testimony'` shows a worshipper story. Opens immediately via `nextTick` when `mediaReady` arrives — no longer waits for the next 1-second tick. |
| [ServicePlayer.vue](frontend/src/components/ServicePlayer.vue) | The full-screen, one-stage-at-a-time player. Auto-reads each segment (server video → server audio → browser Web Speech), auto-advances. |
| [MusicPlayer.vue](frontend/src/components/MusicPlayer.vue) | Plays the worship track: stored audio, or an embedded YouTube `<iframe>`. **LRC synced lyrics:** when the `music_asset` carries a `timings` array, the hymn verses render line-by-line and the active line is highlighted + smooth-scrolled in time with the audio (binary-search on the native `timeupdate` event, no polling); without `timings` it shows the plain verse block. |
| [OfferingForm.vue](frontend/src/components/OfferingForm.vue) | Stripe PaymentIntent confirmation. |
| [TestimonyWall.vue](frontend/src/components/TestimonyWall.vue) | The approved testimony wall + submit-your-own. |
| [AdminConsole.vue](frontend/src/components/AdminConsole.vue) | Permission-driven tab navigation (TABS registry — add one entry to wire a new tab's nav button, permission check, and data loader). Tabs: Dashboard, Services, Donors, Testimonies, Users, Prayer Requests, Settings, AI Music Pool, Voice Studio, Voice Training, Permissions, Language Review, System. Non-admin staff see only the tabs permitted by the Permissions matrix; settings are read-only for non-admins. The console header carries only the **Admin Console** title — sign-out and the light/dark theme toggle come from the **global app header**, so the duplicate copies once rendered here were removed. |
| [useApi.js](frontend/src/composables/useApi.js) / [useTheme.js](frontend/src/composables/useTheme.js) | API client + light/dark theme. Mutating requests auto-recover from a stale CSRF token: on a `419` the client refreshes the `XSRF-TOKEN` cookie and retries once, so admin writes (e.g. deleting an ad) don't fail after a session rotates in a long-open tab. |
| [BottomNav.vue](frontend/src/components/layout/BottomNav.vue) | **Mobile-first primary navigation** — a fixed bottom tab bar shown only on phones (≤640px; `display:none` above that, where the topbar nav stays primary, so the two navigation systems never coexist — see [LAYOUT_CONTRACT.md](frontend/src/components/layout/LAYOUT_CONTRACT.md) rule 8). Four primary tabs (**Home / Songs / Bible / Study**) plus a **"More" bottom sheet** that surfaces every secondary, permission-gated destination (Worship Radio, Pastor Chat, Spiritual Journey, Vocabulary, Father's Day, Live Stickers, Account, Admin) and the auth actions (Login/Register or Logout). Rendered once by `AppLayout`; page content reserves `--bottom-nav-h` of bottom padding on phones so nothing hides behind the bar. The smoke suite asserts exactly one nav with five tabs at 390px and hidden at 1200px. |
| [AppIcon.vue](frontend/src/components/AppIcon.vue) / [icons.js](frontend/src/icons.js) | **Iconify icon system — no emojis in the UI.** Every icon is a scalable SVG from the Material Design Icons (`mdi`) set, registered **offline** (no runtime Iconify-API calls) from [icons.data.json](frontend/src/icons.data.json) — a minimal subset of only the icons the app uses, regenerated with `npm run icons:gen` ([scripts/gen-icons.mjs](frontend/scripts/gen-icons.mjs)) so the bundle never carries the full ~7000-icon collection. Use as `<AppIcon name="mdi:home" />`. |

**Single language authority.** The application has exactly **one** language
selector — the global [LanguageSwitcher.vue](frontend/src/components/layout/LanguageSwitcher.vue)
in the header, whose options come entirely from the backend registry
(`config/languages.php` → `GET /api/languages`). Worshipper-facing features never own their
own language state and never hardcode language lists; they derive their content language from
the single canonical resolver `normalizeLanguage(locale)` in
[i18n/index.js](frontend/src/i18n/index.js) (it maps a UI locale such as `zh-CN` to its base
content code `zh`, keeping UI-locale and content-language distinct concepts with one source of
truth). Changing the global language instantly drives every worshipper feature with no page
reload:

- **Worship Radio** — `POST /music/recommend` language.
- **Bible Reader** — opens the matching translation (registry → available versions → the
  worshipper's remembered preferred edition per language).
- **Pastor Chat** — the default reply language (`pastor.start`); the worker still understands
  any language the worshipper types and honours an explicit "reply in X" request mid-conversation.
- **Church Service (IntakeForm)** — the whole service (prayers, sermon, scripture, hymns,
  narration voice) is generated in the global language, **clamped** to the service-capable set
  (`config.enabled_languages` → en/my/td) so generation never receives a language it can't
  produce. Its strings are now in vue-i18n, not a private `STRINGS` map.
- **AI Bible Study** — questions/summaries/narration language, clamped to the study-supported set.
- **Vocabulary** — default selected language in the unified vocabulary picker.
- **Narration voice** — follows automatically because it is resolved per-language on the
  backend (`Setting::narrationMode($language)`); when the language changes, the voice changes.

**Localization foundation (Phase 3).** `en.json` is the **canonical source** — every
worshipper-facing string is sourced through `vue-i18n` (`t()`), grouped into logical namespaces
(`common`, `buttons`, `nav`, `pastor`, `intake`, `study`, `worship`, `bible`, `vocabulary`,
`player`, `preparing`, `offering`, `testimony`, `authPanel`, `authReset`, `history`, `journey`,
`songs`, `fathersDay`, `stickers`, `theme`, `ads`). No worshipper-facing component carries
literal UI text (only developer comments, brand names like Facebook/WhatsApp, and admin-only
screens remain plain). **English, Burmese (`my`) and Tedim (`td`)** are fully translated for the
core worship surfaces; the two seasonal features (Father's Day, Live Stickers) and a few
account/utility surfaces are English-only for now and fall back to English. The remaining ten
locales (`ja`, `zh-CN`, `hi`, `ko`, `ar`, `th`, `es`, `fr`, `de`, `ta`) are scaffolded and
inherit English per key until reviewed by native speakers — never machine-translated. Missing
keys fall back to English (`fallbackLocale: "en"`), so the UI never breaks. *(The newly authored
`my`/`td` strings beyond the original Church-Service set are best-effort and warrant a native
review pass.)* **Admin tools**
(AdminConsole, VoiceStudio, AdsManager, MusicCatalogManager, AdminLyricsManager) keep their own
per-language selectors — those configure backend per-language settings, not the worshipper's UI.
The architectural invariant: **never introduce another language state** — every worshipper-facing
component derives language from this one authority.

**Styling & PWA.** A [Tailwind CSS](frontend/tailwind.config.js) utility layer is wired on top of the existing CSS-variable design tokens (`src/styles.css`): Tailwind's color/radius/shadow scales map to the same `var(--…)` token names, so utilities theme for free under `[data-theme="dark"]` and we never hardcode hex. Preflight is disabled so the hand-authored component styles are untouched — Tailwind is purely additive. The app is an **installable PWA**: [public/manifest.webmanifest](frontend/public/manifest.webmanifest) + theme-color/apple-touch meta in [index.html](frontend/index.html), standalone display, SVG app icon. (Offline service-worker caching is a planned follow-up.)

---

## Data model

| Table | Purpose | Notable columns |
|-------|---------|-----------------|
| `users` | Worshippers (incl. guests) | `music_source` enum (`hymn_sung`/`hymn`/`suno`/`youtube`, default `hymn_sung`), `presenter_gender` enum (`female`/`male`, default `female` — controls avatar and TTS voice pairing), `name_provided` (false ⇒ display-only placeholder, kept out of the spoken service), `is_admin`, `is_blocked`, `timezone` |
| `service_sessions` | One worship visit | `session_token` (64), `status` (`initializing`/`active`/`completed`/`abandoned`/`scheduled`), `music_source` (locked), `language` (`en`/`my`/`td`), `presenter_gender` (locked from user preference at start), `tedim_status` / `burmese_status` (legacy readiness markers kept for older UI/admin paths), `scheduled_at` |
| `service_intakes` | The user's input + the plan | `mood`, `custom_mood` (free-text when the worshipper selects "other"), `prayer_text`, `scripture_ref`, `music_prompt`, `music_query` (1:1 with session) |
| `service_assets` | Generated segments | `segment` enum (`welcome`/`worship`/`opening_prayer`/`scripture`/`sermon`/`testimony`/`offering`/`closing_hymn`/`benediction`), `asset_type` (`video`/`audio`/`text`/`url`/`youtube`), `storage_key`, `audio_key`, `provider_ref`, `text_payload` (already in the service language for new `my`/`td` sessions), legacy `tedim_text` / `burmese_text`, `lyrics` (hymn verses or AI-composed lyrics for on-screen display), `timings` (optional JSON LRC line cues `[{time, line_index}]` paired with `lyrics` for synced highlighting; null = plain verses), `status` |
| `music_tracks` | Language-and-mood-keyed reuse pool | `mood`, `language`, `provider_ref` (unique — dedupes), `storage_key`, `title`, `lyrics`, `source` (`suno`/`musicgen`/`local_ai`). Populated by the worker after each fresh AI generation; drawn from when a worshipper is new to a mood. |
| `settings` | Global admin key/value | `key` (PK) / `value` (string). Holds `narration_mode`, per-language narration toggles (`narration_en`/`narration_my`/`narration_td`), `text_highlight_enabled`, language-tab toggles (`lang_en`/`lang_my`/`lang_td`), countdown-card controls (`countdown_content_enabled`, `countdown_content_source`, `countdown_banners`), `music_reuse`, `storage_backend`, `avatar_enabled`, `local_avatar_enabled`, `runpod_enabled`, plus admin-curated intake options: `moods`, `music_sources`, `default_music_source`, `scheduling_enabled`, and ad-slot controls (`ad_slot_enabled`, `ad_slot_html`). |
| `prayer_requests` | Raw intake log + token accounting | `raw_input`, `extracted_mood`, `tokens_used` |
| `testimonies` | Shared testimonies | `content`, `source` (`user_submitted`/`ai_generated`), `approved` |
| `financial_ledger` | Offerings | `amount`, `currency`, `transaction_hash` (idempotency), `allocation_type` (`operations`/`charity`/`missions`) |
| `crisis_intercepts` | Safety audit log | `session_hash` (sha256, not the raw token), `trigger_keyword`, `resource_served` |

**Segments:** `welcome`, `worship`, `opening_prayer`, `scripture`, `sermon`, `testimony`,
`offering`, `closing_hymn`, `benediction`. The `welcome` segment (registered users only) is
fired on its own task early so the countdown screen has something personal while heavier
segments compose.

---

## The segment pipeline in detail

`generate_text_segments` is where the service takes shape:

1. **Scripture** — the model picked a reference in the plan; `bible_api.resolve()`
   supplies the words from the bundled BSB. If the reference is unparseable, the
   worshipper still gets the *reference* rather than an aborted segment. Scripture is
   shown as written (gets narration, but **no avatar**).
2. **Opening prayer / sermon / benediction** — each generated from name + mood
   (+ prayer text / scripture ref), then run through `classifier.review()`. **Burmese
   (`my`) opening prayers are an exception: they are served directly from the curated
   native corpus** (`workers/data/prayers_my.json`, 100+ real Myanmar church prayers,
   mood-matched, recent-repeat-avoiding) instead of the English→NLLB translation path —
   machine translation produced stilted, word-repeating Myanmar, so prayers bypass the
   model/translator entirely, mirroring how Burmese song lyrics work. Blocked
   content is replaced with `"(content withheld pending review)"`. Surviving text is
   posted as the segment, and — if enabled — fanned out to `render_avatar` and `narrate`.
   The **sermon** is generated *without* a name: the prompt forbids addressing the
   listener by name, and `llm_engine._strip_name()` is a belt-and-suspenders safety net
   that scrubs any literal name (and repairs the leftover vocative punctuation) for the
   free models that slip one in anyway.
3. **Music** (`generate_music`) — resolves the locked `music_source` to a strategy and
   posts the result to **both** the `worship` and `closing_hymn` segments. **Delivery
   guarantee:** after 3 failed attempts on a remote source (Suno/YouTube/MusicGen/Local AI),
   it falls back to the always-present local hymn library (`hymn_sung` → `hymn`) before
   degrading to the text "music unavailable" notice. A hymn reached via this fallback is
   never added to the Suno/MusicGen reuse pool.

The webhook is **idempotent per (session, segment)**: text arrives first, then a later
narration pass fills `audio_key` and a later avatar pass flips `asset_type` to `video`
— each pass falls back to existing values for fields it doesn't carry, so nothing is
erased.

---

## AI agent orchestration

The system supports two orchestration modes switchable at runtime from **Admin → Settings** with no worker restart required.

### Pipeline mode (default)

A hard-coded Python function (`tasks._orchestrate_pipeline`) fans the job out into parallel Celery tasks:

```
orchestrate(job)          ← ai:orchestrate queue (dedicated, never blocked)
  ├─ generate_welcome      (ai:sermon queue, registered users only)
  ├─ generate_text_segments (ai:sermon queue)
  │    ├─ resolve scripture
  │    ├─ generate opening_prayer → post → [narrate] [avatar]
  │    ├─ generate sermon        → post → [narrate] [avatar]
  │    └─ generate benediction   → post → [narrate] [avatar]
  └─ generate_music         (ai:music queue, parallel)
```

Fast, predictable, and cheap — the LLM is only called for content generation.

### Agent mode

When **AI Agent** is selected, `tasks.orchestrate` hands the job to `workers/agent_orchestrator.py`. Before the agent loop starts, the orchestrator pre-dispatches two guaranteed operations in parallel with the Celery pipeline:

```
run_agent(job)
  ├─ build_intake_plan()          — one LLM call: get scripture_ref, music details
  ├─ send_task("generate_music")  — dispatched immediately, async (worship + closing_hymn)
  ├─ send_task("generate_welcome") — dispatched immediately (registered users only)
  └─ agent loop (up to 24 turns) — handles 4 text segments only
```

The agent receives a system prompt with the pre-chosen `scripture_ref` and a set of **8 tools** (text-only — music and welcome are already running):

| Tool | What it does |
|------|-------------|
| `resolve_scripture` | Fetch the Bible passage text. Falls back to the plan's `scripture_ref` if the agent passes an empty or invalid ref. |
| `generate_opening_prayer` | Generate prayer text |
| `generate_sermon` | Generate sermon text (or skipped for YouTube mode) |
| `generate_benediction` | Generate benediction text |
| `find_sermon_video` | Find a YouTube sermon. MUST include keywords from the worshipper's prayer as the query; falls back to the plan's `preaching_query` if empty. **Pure discovery** — returns `{"found": True, "video_id", "title"}` or `{"found": False, "reason": "no_match"}` (it does not raise on a miss, and does not decide fallback). **Fallback policy lives in the orchestrator** (both the pipeline and agent paths): when no native sermon passes, it retries once in English (generic query, translation deferred) before dropping the segment. The YouTube **worship** slot mirrors this — a no-native-result YouTube worship search falls back to English worship before the local-hymn delivery guarantee. |
| `post_text_segment` | Deliver a segment to the frontend + dispatch TTS/avatar. **Built-in safety review** — content is classifier-checked inside this tool; the agent no longer calls a separate `review_content` step. |
| `post_youtube_sermon` | Deliver a YouTube video as the sermon segment |
| `finish_service` | Signal that the service is complete |

The agent reasons in a tool-use loop (up to 24 turns) and can: retry poor-quality output, skip YouTube segments when a video is found, and adapt content based on user history. Pre-dispatching music guarantees worship segments always appear regardless of how the LLM provider orders its tool calls. If the agent provider rejects a request or crashes before completion, `tasks.orchestrate` logs the sanitized provider error and falls back to pipeline mode for that service so the session does not stay active with no assets.

**Robustness improvements:** LLM calls automatically retry up to 3 times with exponential backoff on 429/5xx errors. JSON tool-call arguments are parsed tolerantly (markdown code fences are stripped, a regex fallback extracts embedded JSON). If MAX_TURNS is reached, the agent attempts to call `finish_service` before exiting so the frontend never spins forever. Token usage (prompt + completion) is tracked across all turns and posted back to Laravel as a `telemetry_agent` asset for cost visibility.

### Switching modes

The toggle is stored in the `settings` DB table **and** mirrored to Redis (`ai:orchestration_mode`) so workers read it without a DB query and the change takes effect on the next service:

```
Admin → Settings → Orchestration mode
  [ Pipeline (Active ✓) ]   [ AI Agent ]
```

### Agent model selection

When agent mode is on, a second row of buttons lets you choose which LLM conducts the service. All three providers go through the existing **OpenRouter API key** — no extra credentials needed:

```
Agent model
  [ Claude (Active ✓) ]   [ Gemini ]   [ ChatGPT ]
```

| Provider | Default model | Env override |
|----------|--------------|--------------|
| Claude | `anthropic/claude-sonnet-4-6` | `AGENT_LLM_MODEL_CLAUDE` |
| Gemini | `google/gemini-2.5-flash` | `AGENT_LLM_MODEL_GEMINI` |
| ChatGPT | `openai/gpt-4o` | `AGENT_LLM_MODEL_CHATGPT` |

The selection is stored in Redis (`ai:agent_provider`) and read per-job, so you can switch between runs without restarting the workers.

### Dynamic preparation screen

The worshipper-facing countdown screen (`PreparingView.vue`) was replaced with a **live progress checklist** that ticks off each step as the worker posts it:

```
◉ Service created
◉ Scripture selected
○ Opening prayer composed…   ← pulsing ring on the pending step
○ Worship music ready…
○ Voice narration ready…     (only shown when server TTS is on)
```

Doors open the instant all required steps check off — no fixed countdown to drain. The "Voice narration" step is hidden when narration is `off` or `browser`; the "Worship music" step is hidden when `music_source=musicgen` would not produce a hosted file.

---

## AI Worship Radio (mood-based worship companion)

A worship-music companion reached at `#worship`. A worshipper picks one of **six universal
moods** (⚡ Energy, 😊 Feel Good, 🎯 Focus, ❤️ Love, 🌿 Relax, 💔 Heartbreak) or types free text
("I feel lonely and tired"). The worship language is **not** chosen here — it follows the
one global language selector in the header (see **Single language authority** below), so
there are no per-page language buttons. The app builds a
mood-matched playlist, explains *why* it chose those songs, and **plays continuously** until
the worshipper presses Stop, fetching a fresh batch (with no recent repeats) each time the
queue runs low.

**Single source of truth — [`config/worship_moods.php`](backend/config/worship_moods.php).**
The six mood ids (`energy` / `feel_good` / `focus` / `love` / `relax` / `heartbreak`) are
language-independent and stable; only their labels are translated. That one file holds each
mood's emoji, per-language labels, expansion **concepts**, and free-text **triggers**, plus
the per-language search **hint** + broad **fallback** queries — consumed by the UI, the mood
expansion, and the YouTube query generation alike. Adding a language = a label per mood + a
`languages` entry; **no code change**. Free text routes to a category by trigger words
("I'm anxious" → `relax`, "I need encouragement" → `energy`), and legacy stored mood keys
(`anxiety`/`peace`/…) are triggers too, so pre-redesign saved sessions still resume correctly.

**Phase 1 (shipped).** No-LLM recommendation engine (scoring is deterministic; the final pick is score-weighted **random** so a repeated mood never replays the same playlist):

| Piece | Role |
| --- | --- |
| `worship_tracks` table / `WorshipTrack` model | Metadata-only catalog (title, artist, language, themes/moods/scriptures JSON, official YouTube/Spotify/Apple links, popularity). **No hosted audio** — copyright-safe. |
| `MoodExpansionService` | Reads `config/worship_moods.php` (the only source). Expands a mood id or free text into spiritual **concept** tags, with an optional admin JSON override (`music.mood_dictionary`) layered on top. Exposes `labels()` / `label()` (per-language native words, so chips re-label on language switch) and `emoji()`. The English label stays the search anchor — concept expansion is language-independent — and `MusicRecommendationService` swaps the **native term into the YouTube discovery query** so a Burmese/Zolai mood actually collects Burmese/Zolai worship rather than English-keyword results. |
| `MusicRecommendationService` | The "Music Recommendation Agent" — **two sources, curated-first**. (1) The admin-managed **curated catalogue** (`worship_tracks`) is scored — **language 40 / mood 30 / theme 20 / popularity 10** — then ordered for **variety**: mood-matched tracks are shuffled with a score-weighted random key (`orderForVariety()` — Efraimidis–Spirakis `key = u^(1/score)`, so a stronger match stays likelier near the top but every search surfaces a fresh mix instead of the same top-N), with language-only filler appended in score order only to top up a thin pool. Recent-50 no-repeat exclusion, artist diversity, and a 5–10 clamp (`music.min_playlist` / `music.max_playlist`) still apply; language is a hard filter. (2) When the same-language curated pool can't fill the playlist, a **live YouTube fallback** (`liveTracks()`) tops it up. **The database is only ever what an admin curates — user searches NEVER write to it.** Live results are searched on demand (`YoutubeSongSearchService`, content-filtered and restricted to **embeddable + syndicated + medium-length** videos — `videoEmbeddable`/`videoSyndicated`/`videoDuration=medium` — so un-embeddable "watch on YouTube only" uploads and hashtag **Shorts**, which load in the embed but buffer forever without playing, are excluded at the source) via the **specific→broad query ladder** (`discoveryQueries()`: native mood+theme query, then the language's `fallback` list from `config/worship_moods.php`, e.g. `Zomi worship song`), and returned as **transient tracks with a stable negative synthetic id** (derived from the video url) so the no-repeat window + history work with no DB row. The raw result pool is held in a short-TTL **performance cache** (`searchLive()`, keyed by content-filter epoch + language + mood, `music.youtube_discovery_ttl`, default 6h) — a Redis/file cache, not storage — so the same mood within the TTL reuses the pool instead of re-hitting the quota — the cached pool is **shuffled per request** (after the cache, so the quota is untouched) so live-heavy languages also rotate their hits each search. Toggle the fallback with `music.youtube_discovery` (default on); requires `YOUTUBE_API_KEY`. **Language-script gate (`titleLanguageScriptOk()`):** a live result whose title is in a script foreign to the language (an English search returning a Hindi/Devanagari or Chinese/CJK upload) is dropped, never served as `en` — Latin allowed for all, Myanmar additionally for `my`, romanised titles pass. **Source-aware reason:** the explanation states whether songs came from the catalogue, from YouTube, or both (never "I selected 0 songs"). |
| `MusicController` | Public `GET /api/music/moods` + `POST /api/music/recommend` (throttled, no auth). |
| `WorshipTrackAdminController` | `music.manage`-gated CRUD + playlist settings; http(s)-only URL validation. **JSON import/export** (`GET /export`, `POST /import`): export the catalog (optionally one language) as a portable document, and bulk-import it back — each row is independently validated (reusing the CRUD rule set), moods are normalized to canonical ids via `MoodExpansionService::canonical()` (so pre-collapse `peace`/`hope`/… still import), duplicates (same title+artist+youtube) are skipped not re-inserted, and rejected rows are reported per-row. Lets admins keep curated libraries under version control and move them between environments. |
| `WorshipRadio.vue` | `#worship` page: mood selector, AI-reason banner, song cards, YouTube IFrame player with auto-advance + continuous autoplay. **No embedded language picker** — the worship language follows the one global language authority (`normalizeLanguage(locale)`); see **Single language authority**. **No-start watchdog:** some restricted videos never fire `onError` — they loop buffering→unstarted and never play; if a track hasn't reached `PLAYING` within 12s it is skipped (`armWatchdog()`/`clearWatchdog()`) so the radio never hangs on a silent track. Mood chips render the language's native label (`m.labels[language]`) and submit the canonical mood **key** (not the visible label), so switching the global language re-labels every chip and still drives the same theme/keyword search server-side. **Reuse:** "▶ Continue" on a saved music session in the history sidebar opens `#worship?mood=…`; on mount the page prefills the chip (or free text) from the hash and auto-starts (language comes from the global selector), so a worshipper resumes the same mood with a fresh playlist. |
| `MusicCatalogManager.vue` | Admin **Worship Radio** tab: catalog CRUD + playlist settings, plus **📥 Import JSON / 📤 Export JSON** toolbar buttons (export downloads the filtered catalog as a file; import reads a `{ "tracks": [...] }` file and reports imported/skipped/rejected counts). |

Seed the curated catalog (en/my/td) with
`php artisan db:seed --class="Database\Seeders\WorshipTrackSeeder"`, then attach real
embeddable links with `php artisan worship:backfill-links`. The catalog is **curated-only**:
it only ever contains what an admin adds — worshipper searches are served live from YouTube
and **never written to the database**. When the curated pool for a language is thin or empty,
the live fallback fills the playlist on the fly.

**Deferred to Phase 2:** feedback/learning loop, saved & shared playlists, per-user
personalization, multi-provider streaming fallback, AI-pastor→worship handoff, and
devotional/sleep/24-7 radio modes. The schema is forward-compatible with these.

## AI Bible Study (multi-agent discussion)

A live, multi-pastor Bible discussion built on a reusable **AI Core platform** so future
ministry modules (sermon, prayer room, counseling, discipleship) can share the same
orchestration, personas, prompts, and admin console. Reached at `#bible-study`.

**The experience.** A worshipper picks a translation, conversation style, and
number of pastors (2–7), then asks any Bible question. **Language is not picked here** — it
follows the global header language (see **Single language authority**), clamped to the
study-supported set (`config.languages`); only the translation sub-choice remains. A panel of *fictional* pastors —
inspired by different preaching traditions but **never revealing or naming any real
figure** — discusses it under a moderator, streaming live. Each round runs
**FRAME → DELIBERATE → SYNTHESIZE**: the moderator frames the question and assigns each
pastor a distinct angle, the weighted pastors deliberate in turn (agreeing, building,
respectfully differing), and the moderator synthesizes agreements + honest disagreements
with a verse spine. Ending the discussion produces a structured summary (key verses,
lessons, prayer, action points, reflection questions, study plan).

**Reuse from history.** "▶ Continue" on a saved study opens `#bible-study?session=<chat_id>`
(the unified-history chat-session id). On mount the page resolves the kind via `historyShow`:
**bridged** multi-pastor studies (a `bibleMeta.study_session_id`) load their rich transcript
+ saved summary via `studyShow`; **chat-spine** studies (AI-platform `/v1/chat/study`, turns
on the session graph) render their `messages` directly. Either way the transcript is shown
read-only ("Past discussion", no live stream); "New Study" returns to the setup form.
A reopened study shows the **original** summary it ended with — never a regenerated one:
bridged studies render their structured `study_summaries`; chat-spine studies render the
`chat_sessions.summary` that `HistoryService::recordMessage` already auto-generates (via the
`title_summary` worker after the first few turns). Studies too short to have produced a
summary simply show none.

**AI Core platform.** Everything is module-keyed and admin-editable, no code change to add
a module or language:

| Service | Role |
|---|---|
| `module_manifests` | per-module config (languages, agent bounds, memory strategy, RAG sources) |
| `ai_personas` | fictional pastors/moderators; weighted participation; **server-only** `system_prompt` + `tradition_tag` lens |
| `ai_prompt_templates` | frame/pastor/synthesis/summary bodies (server-only) |
| `ai_provider_profiles` | OpenRouter / Ollama / RunPod / LM Studio; **encrypted** keys, never returned |
| `ai_tools` + `manifest_tools` | closed tool registry + per-module allow-list (no dynamic exec) |
| `ai_memories`, `ai_usage_ledger`, `ai_audit_log` | owner-scoped recall, cost telemetry, append-only audit |

The Python side mirrors this: [`workers/core/`](workers/core/) holds `scripture` (immutable
resolved-verse DTOs over `bible_api`), `prompt_engine` (7-layer injection-resistant
composition), `persona_engine`, `memory`, `rag`, `events`, `tool_registry`; `core_orchestrator`
runs the round; [`workers/plugins/bible_study/driver.py`](workers/plugins/bible_study/driver.py)
is the thin Celery driver (`tasks.study_discuss`, `ai:study` queue, via the bridge).

**Security model.** System prompts, persona lens tags, template bodies, and provider keys
are server-only and never serialized to any API or SSE event. The prompt engine separates
**trusted** context (moderator frame + canonical verses, in the system role) from
**untrusted** content (other pastors + the worshipper question, fenced in the user role)
to resist cross-agent and user prompt injection; ASCII-only fences are spoof-neutralized.
Safety is two-stage: a cheap pre-filter before dispatch + the authoritative `classifier`
post-filter on every turn. Sessions are owner-scoped; the SSE stream is gated by a
**hash-only, CSPRNG stream token** (constant-time compared, idle-TTL'd, soft device
fingerprint) with a per-user concurrency cap. Worker callbacks are **HMAC-signed with a
timestamp tolerance**. Scripture is never model-generated — only references are cited; exact
text comes from the local corpus (`resolved=false` on gaps, never fabricated).

**Streaming.** The worker publishes seq-stamped events to a durable, **size-capped + TTL'd**
Redis log; the Laravel SSE endpoint polls it by `seq` (proxy-safe, heartbeated) and the Vue
client dedupes/orders on `seq`, so reconnect replay is idempotent. The endpoint returns a raw
Symfony `StreamedResponse`, so any global response-decorating middleware must be
StreamedResponse-safe — e.g. `SetLocale` sets its cookie via `$response->headers->setCookie()`,
**not** the `withCookie()` macro (which only exists on `Illuminate\Http\Response` and would
500 every stream open, trapping the client in an endless "Reconnecting…" loop). **Unauthenticated
API/SSE requests return a clean `401` JSON, never a login redirect.** [`bootstrap/app.php`](backend/bootstrap/app.php)
overrides `redirectGuestsTo()` to return `null` for `api/*` (this API-only backend has no `login`
route, so the framework default would throw *Route [login] not defined* → 500) and renders
`AuthenticationException` as `401` JSON regardless of the `Accept` header — so an SSE reconnect on
an expired session surfaces a clean auth failure instead of a mid-stream 500 that re-triggers the
client's reconnect loop (EventSource sends `Accept: text/event-stream`, not JSON, so without this it
took the non-JSON redirect path). Regression-covered by
[`AuthSecurityHardeningTest`](backend/tests/Feature/AuthSecurityHardeningTest.php), which drives an
unauthenticated `Accept: text/event-stream` request and asserts `401` (was 500).

**Setup.** `php artisan migrate` then `php artisan db:seed --class=Database\Seeders\BibleStudySeeder`
(idempotent: seeds the manifest, fictional personas + templates for all 7 languages, provider
profiles, and the tool registry). Set the `STUDY_*` env vars (see `.env.example`) and run the
`ai:study` Celery worker ([`.systemd/prod/aivc-workers-study.service`](.systemd/prod/aivc-workers-study.service))
alongside the bridge. Admin manage it under the console's **Bible Study** tab.

---

## Community platform (digital church) — Phase 1 foundation

AI Virtual Church is growing from a personal AI worship app into a **community platform**
where members worship, study, pray and talk with the AI pastor *together*. The design is
clean-architecture, event-driven and backward compatible — it builds on the existing
`chat_sessions` spine rather than replacing it.

**One abstraction for every "together" activity.** Worship-together, read-together,
study-together, pray-together, pastor-chat-together and radio-together are not six
features — they are one polymorphic **Invitation → Session → Participant** model over
session types. New audiences (couple, family, small group, church, public) are added by
extending enums and policies, never new subsystems.

**Domain layer.** New community code lives under `app/Domains/<Domain>/` (Accounts,
Church, Friends, Invitations, Bible, Worship, Pastor, Notifications, AI, Analytics) and
coexists with the existing flat `app/Services|Models|Notifications` — no repo-wide rename.

**Phase 1 foundation (shipped in this slice).**
- **Church hierarchy** — `churches` (tenant-ready, nullable `church_id` everywhere; multi-tenant
  enforcement deferred to Phase 6) + `church_memberships` carrying the contextual role
  (`owner…guest`, `App\Enums\ChurchRole`), distinct from the platform privilege role.
- **Privacy** — `privacy_settings` (profile/activity/presence visibility, friend-only mode,
  incognito) resolved through a single authority, **`PrivacyGate`**
  (`app/Domains/Accounts/Services`). Every policy, feed, presence read and invitation check
  funnels through it, so visibility rules (`private/friends/church/public` × friend/block/
  incognito) live in exactly one place.
- **Presence** — `presences` durable last-seen store (Redis hot path lands in Phase 6).
- **Friend system** — `friendships` with one canonical row per unordered pair
  (`user_id = min`, `friend_id = max`); `Friendship::areFriends()/blockExistsBetween()` are
  the single source of truth for pair state.

**Friend system (PR 2).** A full friendship **state machine** owned by one service,
`App\Domains\Friends\Services\FriendshipService` — the single transition validator,
audit point and event source. States `NONE / PENDING / ACCEPTED / BLOCKED` with
transitions request, accept, reject, cancel, remove, block, unblock and a one-sided
favorite. Removal/reject/cancel/unblock **soft-delete** the canonical row (audit history
and AI memory survive; a later re-request restores the same row). A **block overrides
every relationship**, enforced centrally by the `not.blocked` middleware (404, so a block
can't be probed) and `PrivacyGate`. Seven **frozen domain events**
(`FriendRequestSent/Accepted/Rejected`, `FriendRemoved`, `FriendBlocked`, `FriendUnblocked`,
`FriendFavorited`) form the public contract that notifications, the activity feed,
analytics and AI memory subscribe to in later phases. Endpoints live under `/api/friends/*`
(rate-limited; initiation routes carry `not.blocked`). Authorization for initiating contact
flows through the `friend-interact` gate → `FriendshipPolicy` → `PrivacyGate`.

**Invitations + event pipeline (PR 3).** One **polymorphic invitation** for every
together-activity (worship, bible reading, bible study, prayer, pastor chat, radio).
`App\Domains\Invitations\Services\InvitationService` is the **only** component that
mutates invitation state — every transition (`pending → accepted/declined/cancelled/
expired`) flows through one `transition()` method, giving a single place for audit,
expiry enforcement, session creation (later phases), event publishing and idempotency
(re-running a completed transition is a no-op; queue retries are safe). Each invitation
carries a **`correlation_id`** that every record it spawns (session, notifications,
audit, analytics) reuses, so a whole workflow is traceable. A scheduled
`invitations:expire` job sweeps stale invitations through the same transition path.

The **event-driven layer** goes live here. Domain events are **past-tense facts**
(`InvitationSent/Accepted/Declined/Cancelled/Expired`) published only by the service;
queued, **idempotent** listeners react (registered in `CommunityEventServiceProvider`,
since `app/Domains` is outside auto-discovery). Listeners are side-effects only — they
never mutate domain state.

Notifications use a **router layered by priority**: the domain sets a
`NotificationPriority` (`critical/high/normal/low`) and the priority — not the calling
code — decides channels (`CommunityNotification::via()`). Today database (in-app inbox)
+ email are wired; WebSocket/push slot into `NotificationPriority::channels()` in Phase 6
without touching any notification. A `CommunityDatabaseChannel` persists `priority` and
`correlation_id` on each row; listeners dedupe on those keys.

**Presence, privacy & church authorization (PR 4).** Presence is **ephemeral**: callers
go through `App\Domains\Accounts\Services\PresenceService` (never the model), so Phase 6
can make Redis the authoritative store — keeping the `presences` table only for durable
last-seen/recovery — without changing a controller. Cross-user presence reads are
visibility-filtered through `PrivacyGate::canViewPresence` (honoring incognito + blocks;
a hidden member returns 404, not 403). The **Privacy API** (`/api/me/privacy`) exposes the
stable Phase 1 settings (profile/activity/presence visibility, friend-only mode, incognito);
notification-channel preferences are left for the later reminder/notification work.
**Church authorization** runs through `ChurchPolicy`, whose abilities (view/createSession/
moderate/manage) declare a minimum role and defer the comparison to `ChurchRole::atLeast` —
**the enum owns the hierarchy**, no policy hard-codes role order. A default-church backfill
(`php artisan community:backfill-default-church`) is idempotent, transactional and
resumable: it creates the church once and only the missing memberships, safe to rerun.

Covered by `tests/Unit/PrivacyGateTest.php` and the feature suite (`FriendshipTest`,
`InvitationTest`, `EventReplaySafetyTest`, `PresencePrivacyTest`, `ChurchAuthorizationTest`,
`BackfillDefaultChurchTest`). **Phase 1 (foundation) is complete** — tagged
`v0.2.0-foundation` as a known-good architectural checkpoint. Bible reading, study groups,
worship sessions and AI ministry (Phases 2–5) build on this social + event-driven base
without revisiting core identity, authorization or relationship logic.

> **Deploy note.** Phase 1 migrations are additive/nullable (backward compatible). After
> deploying, run `php artisan migrate` then the one data step
> `php artisan community:backfill-default-church` (idempotent).

The detailed reasoning behind these choices is recorded as architecture decision records
in [`docs/adr/`](docs/adr/) — domain layer (0001), event contract (0002), correlation IDs
(0003), `PrivacyGate` (0004), invitation lifecycle (0005) and presence model (0006). Treat
the frozen event names and payloads there as public architecture: prefer additive changes.

### Phase 2 — Bible reading plans & daily reminders (PR 5)

The first domain built **entirely on** the Phase 1 platform — no new infrastructure. A
reading plan is ordered **data** (`reading_plans` → `reading_plan_days`); the seeded
"Bible in a Year" spreads all 1,189 chapters of the canon across 365 days, and adding more
plans (NT-in-90, Psalms-in-30, Chronological, Advent/Lent) is just more seed rows. Each day
carries a **stable `slug`** (`day-001`) so bookmarks, deep links, notifications and future
AI reflections reference it durably, not by integer position.

Enrollment is **progress-anchored, not calendar-anchored**: `current_sequence` only
advances when the user completes a day, so missing real-world days never skips content —
the reminder always opens the *current* day. `ReadingPlanService` is the **sole mutator**
of progress; `completeToday()` is idempotent per local day and emits the frozen, past-tense
`ReadingDayCompleted` / `ReadingPlanCompleted`. A queued, idempotent `UpdateReadingStreak`
listener maintains streaks (consecutive local days). The `reading:remind` scheduler is
timezone-correct and idempotent per `(user, slot, local-date)`, emitting `ReadingReminderSent`
only after delivery — enrollment never fabricates completion events. Reminder notifications
are the one case where **channel selection follows the user's `reminder_settings.channels`**
(in-app/email; push is Phase 6) rather than priority, since reminders are user-configured.

Endpoints: `GET /bible/plans`, `POST /bible/plans/{plan}/enroll`, `GET /bible/reading/today`,
`POST /bible/reading/today/complete`, `GET /me/streak`, `GET|PUT /me/reminders`. Covered by
`ReadingPlanTest`, `ReadingReminderTest`, `ReadingPlansSeederTest`. Shared reading sessions
(PR 6), reading events/listeners (PR 7) and the AI reading companion (PR 8) follow.

> **Deploy note (PR 5).** Run `php artisan migrate` then seed the plan once:
> `php artisan db:seed --class=Database\\Seeders\\ReadingPlansSeeder` (idempotent).

### v1.3 — Ministry groups (Groups domain)

Churches organize into **ministry groups** (Bible study, youth, children, women, men,
choir, prayer, custom — `App\Enums\GroupType`, canonical ids, display names localized).
`groups` belong to a church; `group_memberships` mirrors `church_memberships`: the
contextual `App\Enums\GroupRole` (`member`/`leader`) lives on the membership row, so a
user can lead the choir while merely attending Bible study.

**Ministry titles are group leadership, not church roles.** "Worship leader" / "Bible
study leader" / "choir leader" = `GroupRole::LEADER` on that specific group — never new
`ChurchRole` cases — keeping the church hierarchy stable as ministries are added.
`GroupPolicy` joins the two ladders: a group's own leader manages it (`manage`), church
**elders+** oversee all groups in their church without a membership row in each, group
**deletion** is church governance (elder+), group **creation** needs church leader+, and
groups are **visible church-wide** only. Thresholds defer to `GroupRole::atLeast` /
`ChurchRole::atLeast` — the enums own the ordering, policies never hard-code it.

No groups HTTP layer yet by design: endpoints land with their first consumers (join
requests, shared Bible reading sessions — the rest of v1.3). Covered by
`tests/Feature/GroupAuthorizationTest.php`.

**Link invitations (v1.3 Phase B).** The ONE invitation model gains an open, shareable
kind — no second invitation system. `kind=link` rows have **no addressee** (`invitee_id`
NULL), carry an unguessable 48-char `token` (unique index), an optional `max_uses` with a
`use_count`, and the standard `expires_at`; `activity=group_membership` and the existing
`invitable` morph points at the **Group being joined**. Everything reuses the existing
lifecycle: **revocation is the ordinary `cancel` transition** (extended so anyone who can
`manage` the target group shares revocation authority with the creator — a rogue link
must not outlive its leader), the `invitations:expire` sweep expires stale links
unchanged, and `InvitationService` stays the sole mutator via two new methods —
`sendLink()` (minted by a group manager; no `InvitationSent`, the URL is shared out of
band and QR codes render `join_url` client-side) and `redeem()` (row-locked, idempotent:
an already-active member re-tapping the link costs no use; a rejoin **reactivates the
canonical membership row as a plain member** — leadership does not survive; each join
emits the additive `InvitationRedeemed` event carrying the redeeming `userId`, one per
member, since a link stays PENDING until revoked/expired/exhausted). An outsider joining
a group is auto-enrolled in its church as **`ChurchRole::GUEST`** (least privilege — full
membership stays a pastoral decision), and `GroupPolicy::view` admits a group's own
members alongside church members so a link-joined guest can see the group they joined.
Endpoints (rate-limited): `POST /groups/{group}/invitations` (mint, needs `manage`),
`GET /invitations/link/{token}` (preview: target group/church, usability),
`POST /invitations/link/{token}/redeem` (join). Covered by
`tests/Feature/GroupInvitationLinkTest.php`.

**Join requests (v1.3 Phase C).** A join request is the invitation flow with the roles
reversed — `kind=request`, **the requester is the inviter/creator**, the invitee is
nobody. That inversion buys the whole lifecycle for free: **withdrawal is the ordinary
inviter-cancel**, approval/denial are the ordinary `accept`/`decline` transitions
(authorized for whoever can `manage` the target group — group leader or church elder+;
the requester can never approve themselves), the 30-day expiry rides the standard sweep,
and the frozen events flow unchanged, so `NotifyInviterOfResponse` already tells the
requester their request was approved or declined verbatim. Approval creates the
membership **in the same transaction**, through the same `activateMembership()` path as
link redemption (church-GUEST auto-enroll, canonical-row reactivation as plain member) —
one way anyone becomes a group member. Requests are idempotent (asking again returns
the open request) and approving after the requester already joined via a link is a
no-op. `responded_by` (nullable FK) now records the human actor of every terminal
transition — approver, decliner, or link revoker; NULL means the system sweep — the
audit column complementing the event trail. New leaders are notified per request
(`join_request_received`, routed by the existing send listener; leaderless groups rely
on the managers' request list). Endpoints: `POST /groups/{group}/join-requests` (any
user who can `view` the group), `GET /groups/{group}/join-requests` (managers);
approve/decline/withdraw reuse the existing `/invitations/{id}/*` routes. Covered by
`tests/Feature/GroupJoinRequestTest.php`.

**Shared Bible reading (v1.3 Phase D).** A `reading_session` **coordinates a group
around an existing reading plan — it owns no reading progress** (the PR-6 governing
invariant: never a second reading model). Each `reading_participant` row references the
member's own `user_reading_plans` enrollment, created through `ReadingPlanService` —
so joining applies the **one-active-plan rule verbatim** (409 if you're mid-way through
a different plan; joining the plan you already read reuses the enrollment), progress
advances only via the existing `completeToday()`, and streaks/reminders/events keep
working with zero changes. The shared roster simply projects each member's enrollment
(`current_sequence`, `last_read_on`) — everyone reads at their own pace.
`ReadingSessionService` is the sole session mutator: one **open session per group**,
state machine `planned → active ⇄ paused → completed` (+`abandoned`) with the
`InvitationService::transition()` discipline (row lock, idempotent same-state no-op,
legality map, terminal 409). Going live emits `ReadingSessionStarted` — the session
event reserved in the frozen reading ladder — carrying the session's correlation id.
Authority reuses `GroupPolicy` end to end: `manage` creates/steers, group membership
joins, `view` reads; church elders+ steer any group's session without a membership
row. Endpoints: `POST|GET /groups/{group}/reading-sessions`,
`GET /reading-sessions/{session}` (roster), `POST /reading-sessions/{session}/join` +
`/start|pause|resume|complete|abandon`. Deferred from the PR-6 sketch: reactions and
chat-spine linkage (additive later); friend-to-friend (non-group) sessions ride the
DIRECT-invitation seam when needed. Covered by
`tests/Feature/SharedReadingSessionTest.php`, including the end-to-end proof that
`POST /bible/reading/today/complete` moves the shared roster.

**Church profile (v1.3 Phase E).** Profile completion with **zero schema growth**: all
profile fields — public `description`, freeform `address` (i18n-safe), `contact_email`/
`contact_phone`, `website`, `socials` (allow-listed platforms: facebook/instagram/
youtube/x/telegram/whatsapp), `languages` (validated against the platform registry
`config('languages.list')`) — live under `settings['profile']` in the existing JSON
column. Logo and banner are public-disk uploads (`churches/{id}/…`, jpg/png/webp,
2 MB / 4 MB caps, old file deleted on replace — the AdController pattern) whose paths
live in the same profile subtree and surface as `logo_url`/`banner_url`. Updates use
**merge semantics**: only provided keys change, operational settings outside the
profile subtree are untouched; `name`/`timezone` update too but the `slug` stays — it
is a stable identifier. Authorization reuses `ChurchPolicy`: members `view` the
profile, **elders+** (`manage`) edit it. Endpoints: `GET /churches/{church}`,
`PUT /churches/{church}/profile`, `POST /churches/{church}/logo|banner`. Covered by
`tests/Feature/ChurchProfileTest.php`.

> **Deploy note (v1.3 groups + links + requests + shared reading + profile).** Additive
> only: `php artisan migrate` (creates `groups`, `group_memberships`, `reading_sessions`,
> `reading_participants`; extends `invitations` with `kind`/`token`/`max_uses`/
> `use_count`/`responded_by` and makes `invitee_id` nullable). Phase E adds **no**
> migration; church images need the standard `php artisan storage:link` (already present
> on prod). No data step.

## Unified Conversation & Spiritual History

Every registered worshipper gets a permanent, ChatGPT-style history of every
interaction — their **Spiritual Journal**. One unified spine (`chat_sessions` + per-type
metadata tables) records Bible Study, Worship, Church Services, and the new AI Pastor
Chat, so a single left sidebar, a single search, and a single timeline cover everything.
Future AI modules drop in by writing to the same spine.

**Data model.** `chat_sessions` (UUID id, `session_type`, title, summary, mood, language,
`folder_id`, pinned/favorite/archived, soft-deletes, plus the graph columns
`root_session_id`/`parent_session_id`/`parent_node_id`/`active_node_id`) is canonical.
Conversation turns live in **`session_nodes`** (the durable truth — see *SessionStateStore*
below; content encrypted at rest). Type-specific detail lives in `bible_sessions`,
`music_sessions`, `service_sessions_meta`, `prayer_sessions`, each 1:1 with a session.
`chat_session_tags` carries auto + user tags; `chat_session_shares` stores read-only
share links (token sha256-hashed, optional bcrypt password, expiry); `folders` group
sessions in the sidebar (delete un-files, never deletes, via nullOnDelete). The existing
multi-agent `study_sessions` engine is **bridged** (1:1 link) rather than replaced, so
the live SSE study path is untouched. Backfill historical studies with
`php artisan history:backfill-study`.

**Admin deletion is a final cascade.** Deleting a worshipper (`AdminController::deleteUser`)
hard-deletes the `users` row, and every owned table (`chat_sessions`, `service_sessions`,
`study_sessions`, …) is `cascadeOnDelete` on `user_id`, so the whole profile vanishes.
Deleting a generated service (`deleteService` / `bulkDeleteServices`) needs an explicit
cascade: `service_sessions_meta.service_session_id` is `nullOnDelete`, so dropping only the
`ServiceSession` would leave an orphaned "Church Service" row in the user's history.
`purgeService()` therefore force-deletes the linked `chat_sessions` spine first (cascading
its meta/messages/tags), then the service — covered by `AdminServiceDeletionTest`.

**SessionStateStore.** A session is a graph of nodes with an explicit active pointer and
rehydratable checkpoints (design: `docs/session-state-store.md`). `session_nodes` is the
**sole durable record** of turns (a message is just `type=message`; service milestones,
music playback and study rounds are `type=system_event`); `session_checkpoints` snapshot
module state. The legacy `chat_messages` projection has been fully retired (Phases 1→4
complete). `App\Services\SessionState\SessionStateStore` is the single graph API
(`appendNode`/`fork`/`checkpoint`/`resume`/`messageDtos`); every read and write goes
through it.

**API** (all owner-scoped, `auth:sanctum`): `GET /api/history` (date-grouped:
Today / Yesterday / Previous 7 / 30 / Older, cursor-paginated, Redis-cached first page;
`?archived=true` lists archived, `?trashed=true` lists soft-deleted for restore),
`GET /api/history/{id}` (full resume payload), `POST /api/history/search`,
`PATCH /api/history/{id}` (rename / pin / favorite / archive / rate / tags),
`DELETE` + `POST .../restore` (soft delete), `.../share` + public
`GET /api/shared/{token}`, `.../export?format=md|json|pdf|docx` + `export-all`,
`GET /api/history/stats` and `/timeline` (Spiritual Journey dashboard), and
`PATCH /api/me/profile` for favorite language/version/pastor/goals + an **AI-memory
opt-in**. Ownership is validated on every request; deletes/shares are audit-logged.

**Power features.**
- **Folders** — `GET/POST /api/folders`, `PATCH/DELETE /api/folders/{id}`, file a session
  with `PATCH /api/history/{id}/folder`, list a folder with `GET /api/history?folder_id=…`.
- **Branching** — `POST /api/history/{id}/fork` opens a new branch-session at the active
  (or a given) node via `SessionStateStore::fork()`, sharing root/parent lineage; the
  parent is untouched.
- **Message-body search** — `POST /api/history/search` with `scope=all` also matches inside
  message content. Node content is encrypted at rest, so the match runs in PHP over the
  owner's nodes (bounded per user, hard-capped) and is OR-ed into the title/summary LIKE;
  `scope=meta` (default) searches title/summary only. Cross-user/global relevance search is
  reserved for a future OpenSearch backend.
- **Church analytics** — staff-gated `GET /api/admin/analytics` returns cross-user
  aggregates (users total/new/active, sessions total + by type, message turns, journal
  entries) for the admin console.

**AI Pastor Chat** (`#pastor`) is a single-assistant streaming companion. Titles,
2–5 sentence summaries, and auto-tags are generated by the worker
(`workers/plugins/history/driver.py`, queue `ai:history`, HMAC callback
`/internal/history-callback`) — the same Redis → worker → signed-webhook pattern as
Bible Study. When the worshipper opts in, the pastor may reference prior sessions
("Last week we studied Romans 8…").

**Reply language follows the one global language authority** — there is **no** per-chat
language picker and no per-session language UI state (see **Single language authority**). On
start the SPA sends `normalizeLanguage(locale)` as the default reply language; it travels
start → `PastorChatPipeline` → `PastorReplyDispatcher` → `driver.py`. The worker prompt does
**not** restrict comprehension: the pastor **understands whatever language the worshipper
types** and replies by default in the selected interface language, but **honours an explicit
in-conversation request** to switch ("reply in Burmese") for the rest of the chat — that is
conversation intent, not application state. The worker still keeps a lexical/LLM
`_detect_language` fallback for any unknown code (Myanmar script ⇒ Burmese decisively;
Tedim/Zolai-distinctive tokens — e.g. *hia*, *hoih*, *Pasian* — short-circuit to `td` so the
cloud LLM cannot mis-collapse Tedim onto Mizo), but the normal path now always arrives with a
concrete global-language code.

For the low-resource Chin languages (`td`/`cfm`/`cnh`/`lus`) the reply is generated
**local-model-first**: `driver.py` calls the native FastAPI model (`aivc-tedim-api`, `:8001`,
e.g. `POST /tedim/generate`) and falls back to the cloud LLM (replying in that language)
whenever the local model 502s on degenerate output or is unreachable. The local call has a
short 15 s read timeout — on a CPU-only / memory-pressured box the native model can stall
past a usable chat latency, so the wasted wait is capped and the cloud fallback fires fast
enough that the reply still feels live. English and Burmese go straight to the cloud LLM.
Override base URL with `LOCAL_LLM_BASE_URL`.

**Spiritual Journal.** From any session, **Save to Journal** asks the worker to distill
an AI-written reflective entry (title + scripture + insight + prayer + reflection),
stored append-only in `journal_entries` (reflective fields encrypted at rest). Entries
**outlive** their source session (`chat_session_id` nullOnDelete) — the journal is the
lasting keepsake. Endpoints: `POST /api/history/{id}/journal` (async, returns a
`pending` entry; the worker fills it via the `journal` callback mode), `GET /api/journal`,
`GET/DELETE /api/journal/{id}`. Pinning is capped at 20 per user.

**Frontend.** `HistorySidebar.vue` (resizable/collapsible rail, search, pinned + date
groups, per-type icons 📖🙏🎵⛪💬📚, **🗄 Archived / 🗑 Deleted toggles** that list
archived sessions (♻ Restore in the overlay) or soft-deleted sessions of any type
(tap to restore via `POST /history/{id}/restore`), a **☑ Select** mode for
**multi-select bulk actions** (Archive / Delete in Active, Restore / Delete in
Archived, Restore / **✖ Delete forever** in Deleted) via owner-scoped `POST /history/bulk`
(`{action: delete|archive|unarchive|untrash|purge, ids: [...]}`; `purge` force-deletes
permanently, children cascading via FK, behind a "cannot be undone" confirm), transcript overlay with
rename/pin/share/export/journal/archive/delete, mobile bottom-drawer), `PastorChat.vue` (`#pastor`), and
`SpiritualJourney.vue` (`#journey` stats + streak + timeline + 📔 journal entries).
Account settings gains the spiritual-profile fields. Rebuild with `npm run build` in
`frontend/`. *Note:* the **folders / branching / message-body search / church-analytics**
endpoints above are live on the API; their sidebar/console UI wiring is the next frontend
task.

---

## Internationalization infrastructure (Phase 1)

Beyond the *content* languages above, the platform has an **interface i18n layer** so the UI, server messages and AI can scale to new languages by adding data, not architecture.

- **Central registry — single source of truth:** [`backend/config/languages.php`](backend/config/languages.php) defines every interface locale (en, my, td, fr, de, ja, zh-CN, hi, ko, ar, th, es, ta) with `native_name`, `english_name`, `rtl`, `speech_locale`, `tts_locale`, `fallback`, `enabled`. Exposed to the SPA at **`GET /api/languages`** ([`LocaleController`](backend/app/Http/Controllers/LocaleController.php)); `LocaleController::codes()/resolve()` are reused by the middleware and validation so **no language list is hardcoded elsewhere** (the drift that previously 404'd new Bibles).
- **Locale resolution:** [`SetLocale`](backend/app/Http/Middleware/SetLocale.php) middleware (global) resolves per request — `?lang`/`X-Locale` → authenticated `fav_language` → cookie → session → `Accept-Language` → fallback — validated against the registry, persisted to session+cookie. Drives server-generated text (validation/notifications/mail via `lang/en/*`, other locales fall back to en).
- **User preference:** reuses the existing `users.fav_language` (no migration); now validated against the registry. The **Account → spiritual-profile** *Favorite language* and *Favorite worship language* selectors ([`AccountSettings.vue`](frontend/src/components/AccountSettings.vue)) are registry-driven (`getRegistry()`), so they list every enabled locale instead of the old hardcoded en/my/td trio.
- **Frontend (vue-i18n):** [`frontend/src/i18n/`](frontend/src/i18n/) loads the registry from `/api/languages` (no client-side language list), authored English strings in `locales/en.json`, others fall back. The header [`LanguageSwitcher`](frontend/src/components/layout/LanguageSwitcher.vue) is registry-driven; selecting a locale switches vue-i18n + `<html lang/dir>` + `localStorage`. **RTL** (Arabic) and **`:lang()` Noto font stacks** (ar/zh/ja/ko/th/hi/ta) live in `styles.css`; locale-aware dates/numbers via `$d()/$n()`. Phase 1 extracts header/footer/auth strings (representative set); remaining components are localized incrementally. **Gotcha:** vue-i18n treats `@` as a linked-message modifier, so any literal `@` in a message (e.g. the `you@example.com` email placeholder) must be escaped as `{'@'}` — an unescaped `@` throws `Invalid linked format` at render time, which silently collapses the whole component (the login/register card rendered as an empty box until this was fixed). Guarded by [`e2e/i18n.compile.spec.js`](frontend/e2e/i18n.compile.spec.js), which compiles every message in every locale through `@intlify/message-compiler` and fails CI on any malformed string.
- **AI:** prompts stay in the **worker** layer (no parallel `resources/ai/`). The existing "respond entirely in {language}" directive ([`prompt_engine.py`](workers/core/prompt_engine.py)) now covers every locale — backend `StudyDispatchService::languageName()` reads the registry's `english_name`, and the Pastor/history driver `_LANG_NAME` is extended — so Bible Study & Pastor reply in the chosen language automatically.
- **Bible discovery:** [`workers/tools/bible_discover.py`](workers/tools/bible_discover.py) triages a dalsuum/bible checkout by license (public-domain / free-license / restricted / review) — being on GitHub doesn't grant redistribution rights, so vendoring stays a verified, deliberate step.

**Status:** infrastructure complete and backward-compatible (English unchanged). Translation *content* for the 10 new locales (UI strings, theological vocabulary, etc.) is **Phase 2**. No migrations.

## Multilingual services (Myanmar & Tedim)

Three languages are supported. Language is chosen on the intake form and **locked per session** (like `music_source`).

| Language | Code | Bible | TTS voice | LLM | Hymns |
|----------|------|-------|-----------|-----|-------|
| English | `en` | BSB (bundled) | `en-US-AriaNeural` / `en-US-GuyNeural` | OpenRouter (`LLM_MODEL`) | Open Hymnal (instrumental/sung) |
| Myanmar | `my` | Judson 1835 (bundled) | `edge_tts` → `my-MM-NilarNeural`; `mms_tts` → `facebook/mms-tts-mya` | OpenRouter (`LLM_MODEL_MY`) | 852-song dalsuum/myanmar-hymns — sung via Suno customMode, cached |
| Tedim (Zolai) | `td` | Lai Siangtho 1932 (bundled) | `mms_tts` → `facebook/mms-tts-ctd` (native); `edge_tts` → `EDGE_TTS_VOICE_TD` | local Ollama (`OLLAMA_MODEL_TD`) | Tedim hymnal (~470 hymns) — YouTube embed → Suno → instrumental |

Myanmar and Tedim support two free narration modes: `edge_tts` (Microsoft cloud neural voices — `my-MM-NilarNeural` for Burmese, configurable for Tedim) and `mms_tts` (local Facebook MMS-TTS via `/tts/speak`). Burmese input to MMS-TTS is Myanmar Unicode only; the route rejects likely legacy-encoded Burmese.

### How language flows through the pipeline

```
IntakeForm.vue  ──language: 'my'|'td'──▶  POST /service/{token}/intake
                                           │  ServiceController validates in:en,my,td
                                           │  locks it on service_sessions.language
                                           ▼
                               DispatchServiceJob → Redis ai:intake JSON {language}
                                           │
                                           ▼
               tasks.orchestrate ── language threads into every consumer:
                 ├─ llm_engine.*        prompts pinned to the service language
                 ├─ bible_api.resolve(ref, lang)  → Judson 1835 / Lai Siangtho 1932
                 ├─ get_strategy(src, language)   → Myanmar / Tedim hymn strategy
                 └─ narration voice     → MMS-TTS mya / ctd when enabled
```

Scripture references stay English internally (`"Psalm 23:1-4"`) — they're worker contract, not
worshipper-facing text. `bible_api` parses them against a canonical 66-book index and rewrites
the on-screen heading to the translation's own book name (ဆာလံကျမ်း / Late 23…).

Worshipper names are collected at intake but are never included in any generated service
output. All spoken segments (welcome, prayer, sermon, benediction) address the worshipper
anonymously using "you" — this applies across English, Burmese (Myanmar), and Tedim/Zolai.
Auto-generated guest placeholder names remain display-only (`name_provided=false`) and are
not sent to the LLM or narrator.

### Myanmar LLM service (`workers/api.py` → `:8002`)

Mirrors the Tedim service. Scripture is an exact Judson 1835 corpus lookup; prose
is generated by the local Ollama model named by `OLLAMA_MODEL_MY` through
`BURMESE_LLM_URL`. If the local model times out or returns text that fails the
Myanmar-Unicode guard, `llm_engine.py` falls back to short, safe Myanmar service
text so the remaining pages still appear.

| Endpoint | Purpose |
|----------|---------|
| `POST /burmese/translate` | Translate English prose → Burmese (Myanmar Unicode) via Ollama. |
| `POST /burmese/generate` | Free-form Burmese devotional prose generation (injects church vocab hints). |
| `GET /burmese/verse?ref=John+3:16` | Exact Judson 1835 verse — no LLM, no network. |
| `GET /burmese/lookup?word=grace` | English→Myanmar dictionary lookup — no LLM (~22k entries, soeminnminn/EngMyanDictionary). |
| `GET /burmese/church_vocab` | Pre-extracted church vocabulary map (73 worship terms, English→Myanmar). |

**Local dictionary** (`workers/data/eng_myan_dict.db`, 34 MB SQLite): sourced from
[soeminnminn/EngMyanDictionary](https://github.com/soeminnminn/EngMyanDictionary), a 21,984-entry
English–Myanmar dictionary (Myanmar Unicode). The module `workers/myanmar_dict.py` provides
`lookup(word)` → `DictEntry` and `CHURCH_VOCAB` (73 pre-extracted worship terms). The
`/burmese/generate` endpoint injects a compact church vocabulary snippet into every system
prompt so Ollama always has verified Myanmar translations for grace, mercy, salvation, prayer,
faith, hope, peace, blessing, and other worship terms.

New Myanmar services do **not** run a post-generation localization job. The Python
worker writes Myanmar directly into `service_assets.text_payload`, and the webhook only
marks `burmese_status=ready` for older UI/admin compatibility.

### Tedim LLM service (`workers/api.py` → `:8001`)

| Endpoint | Purpose |
|----------|---------|
| `POST /tedim/translate` | Translate English prose → Tedim (Zolai) via Ollama. Redis db 2 cache, 30-day TTL. |
| `POST /tedim/generate` | Free-form Tedim devotional prose generation. |
| `GET /tedim/verse?ref=John+3:16` | Exact Lai Siangtho 1932 verse — no LLM, no network. |
| `GET /health` | Liveness check (shared by both language routers). |

New Tedim services do **not** run a post-generation localization job. The Python
worker writes Tedim directly into `service_assets.text_payload`, and the webhook only
marks `tedim_status=ready` for older UI/admin compatibility.

**Suno lyric guard:** When `music_source=suno`, `llm_engine.build_intake_plan` asks the
OpenRouter model to write Tedim/Zolai worship lyrics. Because English-first models can
drift into Mizo or other Chin dialects, `_lyrics_match_language` validates the output against
strict criteria: core Zomi theological vocabulary (Pasian, Topa, Zeisu Krist), zero English
worship words, and at least two Tedim sentence-final particles (` hi` / ` hen`) that are
grammatically impossible in any other language. Lyrics that fail are replaced with
mood-specific hardcoded Tedim fallbacks before being sent to Suno.

**Myanmar grammar guidance (from *Burmese: An Introduction to the Spoken Language*, John Okell, NIU Press):**
All system prompts for Burmese prose and lyric generation encode these rules so the model produces
natural Myanmar church Burmese:
- **Word order SOV** — the verb always comes at the end (`ဘုရားသခင်သည် ကျွန်ုပ်တို့ကို ချစ်တော်မူသည်` = God loves us).
- **Case markers**: subject `-သည်`/`-က`; object `-ကို`; location `-မှာ`/`-တွင်`; genitive `-၏`.
- **Reverential register**: God's actions use `-တော်မူသည်` (not plain `-သည်`); address God as `ကိုယ်တော်`.
- **Sentence-final particles**: `-ပါသည်` (polite declarative), `-ပါစေ` (prayer/blessing/wish), `-တော်မူပါ` (reverent request to God); Amen = `အာမင်`.
- **Pronouns**: `ကျွန်ုပ်` (I — formal), `ကျွန်ုပ်တို့` (we), `သင်` (you — to humans), `ကိုယ်တော်` (You/He — God/Jesus only).
- **Tense**: past = verb + `-ခဲ့သည်`; future = verb + `-မည်`; continuous = verb + `-နေသည်`; present = verb + `-သည်`.
- **Negation**: `မ-` + verb + `-ဘူး` (plain); `မ-` + verb (reverential, for God).
- **Required Christian terms**: `ဘုရားသခင်` God, `ယေရှုခရစ်တော်` Jesus Christ, `သန့်ရှင်းသောဝိညာဉ်တော်` Holy Spirit, `ကျေးဇူးတော်` grace, `ကရုဏာတော်` mercy, `မေတ္တာတော်` love, `ကယ်တင်ခြင်း` salvation, `မျှော်လင့်ခြင်း` hope, `ငြိမ်သက်ခြင်း` peace.

**Zolai grammar guidance (from *Paunam Khenna Leh Kampau Luanzia*):** All system prompts
for Tedim prose and lyric generation encode the following rules from the community grammar
reference so the Ollama model produces more natural Zolai sentences:
- **Word order SOV** — the verb always comes at the end of the sentence.
- **Subject marker `in`** follows the subject noun (`Pasian in` = God [as subject]).
- **Sentence-final particles**: `hi` (declarative), `hen` (prayer/blessing/wish), `ahi hi` (emphatic truth).
- **Pronouns**: `ka` (I/my), `nang` (you), `amah` (he/she), `eite` (we), `note` (you pl.), `amaute` (they).
- **Tense**: past = verb + `khin hi`; future = verb + `ding hi`; continuous = verb + `laitak hi`; present = verb + `hi`.
- **Negation**: verb + `lo hi` (e.g., `om lo hi` = there is not; `mangngilh lo hi` = does not forget).
- **Mizo→Tedim post-corrections** in `_TEDIM_CORRECTIONS` cover the most common drift words
  (`kohhran→biakinn`, `koici→biakinn`, `tawngtaina→thungetna`, `pathian→Pasian`, `lalpa→Topa`, `lalpan→Topa in`, `isua→Zeisu`, and several others).
- **Punctuation normalisation** in `_fix_tedim_vocab`: the local GPU model frequently omits spaces after commas/semicolons and merges Tedim grammatical particles (`in`, `leh`, `na`, `ka`, etc.) directly into the following capitalised word (e.g., `inKhua` → `in Khua`; `hi,Napa` → `hi, Napa`). Three regex passes fix these before the text reaches the narrator or the player.

**Concurrency:** a single `asyncio.Semaphore(1)` gate in each router ensures only one Ollama inference runs at a time — important on the shared ARM/OCI box where Gunicorn, Redis, MySQL, and Celery compete for the same CPUs.

### Initial setup (one-time)

```bash
# 1. Install Ollama
sudo snap install ollama

# 2. Add swap before pulling (prevents OOM on ≤4 GB servers)
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# 3. Pull the base model and build the custom models
ollama pull llama3.2:1b          # 1b for ≤4 GB RAM; use 3b on the 6 GB OCI box
cp /opt/ai-church/Modelfile ~/Modelfile
ollama create tedim-zolai -f ~/Modelfile
cp /opt/ai-church/BurmeseModelfile ~/BurmeseModelfile
ollama create burmese-myanmar -f ~/BurmeseModelfile

# 4. Install Python deps and seed language data
cd /opt/ai-church/workers
source .venv/bin/activate && pip install -r requirements.txt
python tools/seed_language_data.py        # Registered Bible translations + Myanmar hymns
# Note: hymns_td.json is bundled in the repo — no seed step needed
python tools/seed_tedim_midi.py           # optional: instrumental fallbacks (fluidsynth + ffmpeg)
python tools/build_tedim_dataset.py       # Build the fine-tuning dataset

# 5. (Optional but recommended) Fine-tune the Tedim model on a GPU server
# This requires a machine with a GPU (e.g., cloud instance, Google Colab).
# bash tools/setup_finetune_env.sh        # Run once to install GPU libraries
# bash tools/run_tedim_finetune.sh
# After training, update OLLAMA_MODEL_TD in workers/.env to your new model name.

# 6. Start the language APIs (two processes at different ports)
uvicorn api:app --host 127.0.0.1 --port 8001 --workers 1   # Tedim
uvicorn api:app --host 127.0.0.1 --port 8002 --workers 1   # Burmese

# 7. In production, install the systemd units
sudo cp /opt/ai-church/.systemd/prod/aivc-tedim-api.service /etc/systemd/system/
sudo cp /opt/ai-church/.systemd/prod/aivc-burmese-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now aivc-tedim-api aivc-burmese-api
```

> **Upgrading the Tedim model:** change `OLLAMA_MODEL_TD` in `workers/.env`, then `sudo systemctl restart aivc-tedim-api`.
> **Upgrading the Burmese model:** change `OLLAMA_MODEL_MY`, then `sudo systemctl restart aivc-burmese-api`.
> No code changes needed in either case.

### Known gaps before production

1. **Crisis intercept is English-keyword based** — a Burmese or Tedim prayer won't trip it. Extend `CrisisInterceptService` with Burmese and Tedim terms before promoting those language tabs.
2. **Classifier guardrail** (`classifier.review`) reviews non-English text with an English-prompted model — spot-check its behavior on Burmese and Tedim sermons. LLM quality for these languages depends heavily on the selected model; use `LLM_MODEL_MY` and `LLM_MODEL_TD` to route to stronger multilingual models without touching the English path.
3. **Player segment titles** (e.g. "Opening Prayer") are still English — the language is available on `GET /service/{token}` once `$session->language` is exposed there.
4. **Suno Burmese-vocal quality** varies by model version. If a render is poor, delete `hymns_my/<slug>.mp3` from storage and the next service re-renders it.

---

## Music: six sources + a reuse pool

Each user picks one (stored on `users.music_source`, **locked per session**). All six
implement `MusicStrategy.fetch()` and return the same `MusicResult` (`asset_type`,
`storage_key`, `provider_ref`, `title`, `lyrics`), so the orchestrator, the webhook, and
the player are all source-agnostic. Add a source by implementing one class in
[strategies/](workers/strategies/).

| Source | Strategy | How it works |
|--------|----------|-------------|
| `hymn_sung` *(default)* | `SungHymnStrategy` | Local vocal/sung MP3. For English: public-domain 78rpm recording. For Myanmar/Tedim: locally-downloaded `.sung.mp3` → English audio + localized lyrics as fallback. No AI, no provider call. |
| `hymn` | `InstrumentalHymnStrategy` | Same mood-matched hymn rendered instrumental (MIDI→MP3) with public-domain lyrics on screen. Every seeded hymn is eligible. |
| `hymn_youtube` | `HymnYouTubeStrategy` | Mood-matched hymn from YouTube. Language-aware: Tedim searches for Zomi worship songs first (prefers embedded `youtube_id` from the hymnal), Burmese searches with `ဓမ္မသီချင်း`, English searches HymnSite-style. Falls back to English choir with localized lyrics overlay. |
| `suno` | `SunoStrategy` | Original worship music **generated by AI** via Suno customMode. Generated lyrics are sent to Suno and displayed in the player. Language-fallback lyrics are used if the planning model drifts to English for a Burmese/Tedim service. |
| `musicgen` | `MusicGenStrategy` | AI-generated music via local HuggingFace `MusicGen` model (CPU-default). No cloud cost. Generation time: ~3–5 min on CPU; seconds on GPU. |
| `local_ai` | `LocalAiStrategy` | Same as `musicgen` but GPU-preferred: auto-detects CUDA; falls back to CPU. Set `MUSICGEN_DEVICE=auto` (default) or pin to `cuda`/`cpu`. Suited for servers with a GPU. |
| `youtube` | `YouTubeStrategy` | An existing modern worship track found via the YouTube Data API and embedded via the official player. **No audio is downloaded or stored.** Language-aware: Tedim and Burmese services search with native terms and apply the same sermon/music gate filters as the text sermon slot. |

The hymn library (lyrics, sung recordings, instrumental renders) is produced ahead of
time by `seed_hymns.py` and read from the same storage backend the worker uses — see
[Running locally](#running-locally).

**Opening readiness.** The countdown screen no longer opens the player on text alone.
For music-backed services (`hymn_sung`, `hymn`, `youtube`, `suno`), it waits for the
worship asset; in server-voice narration modes it also waits for the opening-prayer
audio. This is especially important for Myanmar and Zolai/Tedim, where MMS-TTS is
staggered and can arrive after the text. AI-composed (`suno`) services use a longer
Myanmar/Tedim countdown so the Suno track and first narrated prayer are ready before
worship starts, while polling continues for later narration during the song.
Once the player is open, finished text segments are shown immediately even if their
narration audio is late or unavailable, so benediction text cannot remain hidden
behind a loading state.

**Countdown content.** The same screen can rotate randomized short cards while the worshipper
waits. Admin Settings controls whether cards show and which sources to include
(`countdown_content_source`: `banners` / `testimonies` / `verses` / `both` / `all`).
Custom banners (plain text + optional source label) are only shown for English services
so non-English services never receive mixed-language slides. Testimony cards come only from
already-approved testimonies and, when a service mood/language is known, are randomly selected from
worshippers who have service history for that same mood/language. Verse cards are
**mood-matched**: the worshipper's mood is matched against a curated keyword → verse-reference
table (30+ mood keywords, ~120 mapped references), and up to four matching verses are looked
up in the bundled public-domain Bible for that service language (BSB for English, Judson 1835
for Myanmar, Lai Siangtho 1932 for Tedim). Non-English services always receive mood verses;
English services receive them when the source explicitly includes `verses`. No external
verse provider is used. Admins cannot enter arbitrary URLs. The public `/config` endpoint
returns text-only `countdown_cards` (type `'verse'`, `'testimony'`, or `'banner'`), and Vue
renders the content with normal text bindings rather than HTML to avoid script injection.

**The Suno reuse pool.** Composing fresh AI music costs money and time, so completed Suno
tracks are banked in `music_tracks`, keyed by language + mood (deduped by `provider_ref` via the
[`/internal/music-track`](#public) webhook). When the pool is enabled
(`music_reuse` setting, on by default), [DispatchServiceJob](backend/app/Jobs/DispatchServiceJob.php)
decides per service: if **this** worshipper has heard **this** mood before, it composes a
fresh song so a returning visitor never gets a repeat; if they're **new** to the mood, it
hands them a random track already composed for it — instant and free. Reuse only
selects Suno tracks that match both the service language and mood and have saved lyrics,
then performs a lightweight lyric-language check before handing a track to the worker.
This second guard prevents older or mislabeled pool rows from crossing languages, so
Burmese, Zolai/Tedim, and English songs never cross-reuse each other. Older lyric-less
or language-mismatched pool entries are skipped and a fresh lyric-backed song is
composed instead. Hymn and YouTube sources never touch the pool.

---

## Narration & avatar (optional enrichments)

Both are **entirely optional and key-gated** — with no key, the pipeline never calls in,
and the worshipper still gets every segment as text.

- **Narration (TTS)** — [narrator.py](workers/narrator.py) hits any OpenAI-compatible
  `/audio/speech` endpoint, chunking long scripts on sentence boundaries. The admin
  picks the voice in **Admin Console → Settings** (`narration_mode`), and the choice
  is threaded through on the job:
  - `browser` — the worshipper's browser reads each segment aloud via the Web Speech
    API. Free, no key, works on localhost out of the box.
  - `openai` — server-synthesized OpenAI TTS (a realistic voice). Key-gated by
    `TTS_API_KEY`; see the `TTS_*` env vars.
  - `kokoro` — server-synthesized open `hexgrad/kokoro-82m` voice via OpenRouter.
    Defaults to the `OPENROUTER_*` LLM credentials; see the `KOKORO_*` env vars.
  - `edge_tts` — Microsoft Edge TTS (cloud, free, no API key). For English:
    `EDGE_TTS_VOICE_FEMALE/MALE`; for Myanmar: `EDGE_TTS_VOICE_MY_FEMALE/MALE`
    (default `my-MM-NilarNeural`/`my-MM-ThihaNeural`); for Tedim: `EDGE_TTS_VOICE_TD`
    (default `en-US-AriaNeural` — no native Zolai Edge voice; Latin-script phonetic read).
  - `mms_tts` — Local Facebook MMS-TTS (offline, free). Myanmar: `facebook/mms-tts-mya`;
    Tedim: `facebook/mms-tts-ctd` (only native Zolai voice). Requires `aivc-mms-tts`
    container and `MMS_TTS_URL` pointing to it.
  - `voicebox` — voice-cloned narration via the local Voicebox Docker container
    (`127.0.0.1:17493`). English only — Voicebox cannot synthesise Myanmar or Chin
    scripts, so `my`/`td` sessions automatically fall back to MMS-TTS even when this
    mode is selected. See **[Voicebox TTS (optional)](#voicebox-tts-optional)** below.
  - `off` — segments stay as silent text.

  Myanmar and Tedim use MMS-TTS regardless of the global `narration_mode` setting when
  that mode is `voicebox` (which only handles English). For `edge_tts` and `mms_tts`
  both are free and configurable per-language in Admin Console → Settings.

  In a server-voice mode (`openai`/`kokoro`/`edge_tts`/`mms_tts`) the player waits for
  each segment's audio to land, then plays it. Browser Web Speech is used only when a
  matching browser voice exists; Myanmar and Tedim never fall back to an English
  browser voice, because that skips or mangles their words. MMS-TTS narration is staggered
  for non-English services; scripture, opening prayer, and benediction are prioritized,
  and the longer message audio is deferred so it cannot block the last prayer.
- **Avatar** — avatar.py renders a talking-head of the
  spoken segments. Supports **D-ID/HeyGen** (cloud, key-gated) or **LivePortrait/Wav2Lip** (local open-source container). Cloud requires provider API keys. Local requires `LOCAL_AVATAR_URL` + `LOCAL_AVATAR_IMAGE_FEMALE` + `LOCAL_AVATAR_IMAGE_MALE`, and lip-syncs to the segment's generated narration audio. Each engine has its own admin toggle (no env edits): `avatar_enabled` (D-ID) and `local_avatar_enabled` (local). When both are on and configured, the **local engine takes priority** (resolved worker-side in `avatar.select_engine()`).

**Presenter gender pairing.** Each worshipper has a `presenter_gender` preference
(`female`/`male`, default `female`) set in their profile and locked onto the session at
start. The sermon uses the chosen gender; all other spoken segments use the opposite
voice — so the preacher and the liturgical support voice are always a matched pair. The
admin can override `presenter_gender` per user in the admin console.

> See the project memory notes for the known-good local narration setup (browser speech
> works out of the box; the server S3 path needs credits + storage).

---

## Voicebox TTS (optional)

[Voicebox](https://github.com/jamiepine/voicebox) is an open-source local voice studio that
lets you **clone a pastor or narrator's voice** from a short recording and use it for all
English narration. It runs as a Docker container on the same server.

### Setup

**1. Start the container**

The compose file builds a tiny local image on top of
`ghcr.io/jamiepine/voicebox:latest` so the missing `qwen-tts` Python package is present,
then maps host port `17493` to container port `8000`. The CPU backend variant is used;
adjust `VOICEBOX_BACKEND_VARIANT` in `voicebox/docker-compose.yml` for GPU hosts.

```bash
cd /opt/ai-church/voicebox
docker compose up -d
# Wait ~60 s for the health check to pass
curl http://127.0.0.1:17493/health
```

**2. Create two voice profiles**

Open `http://localhost:17493` in a browser on the server (or SSH tunnel). In the Voicebox
UI, create two voice profiles — one female (congregation support voice) and one male
(pastor / sermon voice) — by uploading short recordings. The profiles appear in
**Admin Console → System Monitor → Voicebox TTS** with their UUIDs.

**3. Configure the worker env**

Add to `workers/.env`:

```
VOICEBOX_URL=http://127.0.0.1:17493
VOICEBOX_PROFILE_ID_FEMALE=<paste UUID from admin panel>
VOICEBOX_PROFILE_ID_MALE=<paste UUID from admin panel>
VOICEBOX_ENGINE=qwen            # Qwen3-TTS 0.6B on this CPU server
# VOICEBOX_ENGINE=qwen_1_7b      # optional, heavier 1.7B model
VOICEBOX_TIMEOUT=180
```

**4. Activate in the admin console**

Go to **Admin Console → Settings → Narration voice → Voicebox (local)**.
Choose the Qwen model size. The 0.6B model is recommended for this CPU server.
Enable English narration. Burmese/Tedim continue to use MMS-TTS unchanged.

### Monitoring

**Admin Console → System Monitor → Voicebox TTS** shows:

- Container status (running / unreachable)
- Model loaded, GPU type, VRAM used
- Generation queue depth
- All voice profiles with copy-to-clipboard UUIDs

### Engine comparison

| Engine | Speed | Quality | Notes |
|---|---|---|---|
| Kokoro | ★★★★★ | ★★★★ | 82M params, 50 preset voices, great default |
| Chatterbox | ★★★ | ★★★★ | 23-language support, best for accented English |
| Qwen3-TTS | ★★★ | ★★★★★ | Highest quality; delivery instructions supported |
| LuxTTS | ★★★★★ | ★★★ | English only, 150× realtime on CPU |
| Chatterbox Turbo | ★★★★ | ★★★ | Paralinguistic tags `[laugh]`, `[sigh]` supported |

---

## Safety: the crisis intercept

This is a **boundary, not a feature.** `CrisisInterceptService.inspect()` runs on the
intake's prayer text **before any job is queued.** A match short-circuits to a static,
vetted resource message (crisis-line pointer), marks the session `abandoned`, and logs an
audit row keyed by `sha256(session_token)` — never the raw token, never the user's text.
The LLM is never invoked for an intercepted intake, so there is no AI-generated "pivot."

The shipped keyword list and the post-generation `classifier` deny-list are deliberately
minimal and illustrative — production should back both with a maintained list and a small
dedicated classifier model.

---

## Content filter (YouTube allow/block firewall)

A second, **admin-curated** safety layer keeps non-Christian or non-worship videos out of the
worship-music and sermon segments. Keywords are grouped into **categories** (Other Religions,
Occult / New Age, Profanity, Politics, Secular Music, Off-topic Channels, Sermon-exclude,
Custom, Allowlist), each with a **scope** that decides where it applies and a **type** that
decides whether it blocks or allows:

| Type | Effect |
|------|--------|
| `block` | A match **rejects** the candidate (default policy). |
| `allow` | A match **keeps** the candidate even if a block keyword also matches — **allow wins over block**. Use it for trusted channels, artists, or ministries. |

A filter term is matched against the video **title**, **channel name**, **channel id**, and the
**video/channel URLs**. So a `block` term can be a word, a channel name, a channel id, or a pasted
YouTube channel/video URL — any hit drops the whole result before it reaches the worshipper.

| Scope | Worship/music search | Sermon search |
|-------|:--------------------:|:-------------:|
| `both`   | ✅ | ✅ |
| `music`  | ✅ | — |
| `sermon` | — | ✅ |

Managed from **Admin Console → Content Filter** (its own tab, separate from Settings). Full CRUD
on categories and keywords, per-category Block/Allow mode, plus **export to JSON/CSV** and
**restore from JSON**. Storage lives in `Setting` (key `content_filter_categories`), kept in sync
with the legacy flat `content_filter_keywords` (block keywords only) for backward compatibility.

- **Backend:** [ContentFilterController.php](backend/app/Http/Controllers/ContentFilterController.php),
  taxonomy + accessors in [Setting.php](backend/app/Models/Setting.php) (`filterCategories()`,
  `filterKeywordsForScope()`, `allowKeywordsForScope()`).
- **Delivery:** the public [`/config`](backend/app/Http/Controllers/ConfigController.php) endpoint
  surfaces `content_filter_music` / `content_filter_sermon` (block) and
  `content_filter_allow_music` / `content_filter_allow_sermon` (allow).
- **Enforcement:** [youtube_strategy.py](workers/strategies/youtube_strategy.py) fetches the
  scoped block + allow lists (cached 5 min, fails open). A candidate whose title, channel name,
  channel id, **or** video/channel URL matches a block keyword is rejected **unless** it also
  matches an allow keyword — applied as an extra gate on top of the hardcoded per-language reject
  lists. The same matching is mirrored in
  [YoutubeSongSearchService.php](backend/app/Services/YoutubeSongSearchService.php) (admin Worship
  Radio link search).

- **Worship Radio:** [MusicRecommendationService.php](backend/app/Services/MusicRecommendationService.php)
  re-applies the same block/allow firewall **at serve time** against each catalogue track's
  title/artist/URL — so blocking a channel or URL also removes tracks that were saved *before* the
  block (discovery only screens new search hits). Saving any filter change also bumps a
  `content_filter_epoch` that invalidates the cached per-mood discovery markers, so a new block
  takes effect on the **next** request rather than after the discovery TTL.

Changes take effect immediately for Worship Radio; within ~5 minutes for the live-service workers
(their `/config` cache TTL).

---

## Offering & financial ledger

The offering segment opens a **Stripe PaymentIntent** server-side
(`POST /service/{token}/offering`); the browser confirms it with Stripe.js. The
allocation (`operations` / `charity` / `missions`) is carried in the intent metadata and
**read back on the webhook**, so the ledger row is fully attributed without trusting
anything the client re-sends. Amounts are bounded (min 1.00), and
`transaction_hash` is a unique idempotency key so a replayed webhook can't double-record.
Stripe webhooks are signature-verified.

---

## Subscriptions, plans & the token economy

Three billing tiers govern access, **separate from the staff `role`** (which controls
admin/console privilege):

| Plan        | How you get it          | Ads | Tokens/month\* | Max pastors\*\* |
|-------------|-------------------------|-----|----------------|-----------------|
| **Guest**   | anonymous walk-up        | yes | — (1 free use per service) | 2 |
| **Member**  | register an account      | no  | 100            | 3 |
| **Premium** | Stripe subscription      | no  | 1000           | 7 |

\* Configurable via `config/tokens.php` / env, and overridable live by an admin
(`plan_overrides` setting). \*\* From the existing admin-editable `study_agent_tiers`.

### Single source of truth

No controller branches on a plan string. [`PlanService`](backend/app/Services/PlanService.php)
maps a plan → its rules (allowance, ads, features) from config layered with admin
overrides; [`FeatureService`](backend/app/Services/FeatureService.php) is the per-user
façade (`showsAds()`, `maxPastors()`, `monthlyAllowance()`, …). Plans, statuses and
ledger entry types are PHP enums (`app/Enums/`) — no magic strings.

### Token wallet (reserve → commit → rollback)

`users.token_balance` is the authoritative balance; every change is appended to
`token_ledger` inside the same `lockForUpdate` transaction that moves it, so concurrent
requests can't double-spend. AI calls use a **two-phase hold**
([`TokenService`](backend/app/Services/TokenService.php)): `reserve()` debits before the
fallible upstream call, then `commit()` on success or `rollback()` (refund) on failure —
a model timeout never charges a user. A `token_reservations` row carries a TTL;
`reservations:cleanup` (hourly) refunds any hold stranded by a crashed worker.

Admins can **top up a registered user's wallet** from **Admin Console → Users** (the
**Grant tokens** row action; the table also shows each user's plan + current balance).
It posts to `POST /api/admin/users/{user}/tokens` (`admin` middleware) which calls
`TokenService::grant()` and records a `LedgerType::ADJUSTMENT` entry referencing the
acting admin. Guest accounts have no wallet and are rejected.

### Guest one-use enforcement

Guests get a single free use **per service**, tracked in `guest_tracking` by salted
hashes of three signals — IP, a browser fingerprint, and a long-lived `guest_id` cookie
— so clearing cookies alone doesn't reset the quota (see
[`GuestUsageService`](backend/app/Services/GuestUsageService.php)). Enforced by the
`guest.limit:{service}` middleware; members/premium fall through to `tokens:{service}`.

#### Charge-before-enrichment execution order

Every AI service endpoint (worship `intake`, Bible `study`, Pastor chat) follows one
ordering rule: **the quota/usage write is the first irreversible side-effect, and all
best-effort enrichment runs after it, isolated.** Concretely, in
[`ServiceController::intake`](backend/app/Http/Controllers/ServiceController.php) and
[`StudyController`](backend/app/Http/Controllers/StudyController.php):

1. **Hard path** (any failure aborts the request): validate → crisis check → persist
   intake → **charge/record quota** → execute the primary action (dispatch the pipeline).
2. **Soft path** (best-effort, never affects the response or billing): the unified
   **history mirror** (`*_session_meta` + `chat_sessions`) is wrapped in `try/catch` and
   only logs on failure.

This order exists because the inverse once shipped a real bug: the history mirror sat
*ahead* of the quota write, so when its tables were missing every `intake` threw before
recording usage — leaving `guest_tracking` empty and letting guests reuse a service after
a refresh instead of getting `402 guest_limit`. **An enrichment failure must never skip
the quota write or break the user response.** See the "Degrade, never block" philosophy
above — quota/billing is the one exception that must complete *before* enrichment, not
after.

#### Concurrency guarantees

Guest usage is recorded under a row lock: `GuestUsageService::record()` wraps its
read-modify-write of the `services_used` JSON map in `DB::transaction()` +
`lockForUpdate()`, so two requests from the same visitor firing at once (e.g. study +
service) serialize instead of clobbering each other's keys. The unique
`(ip_hash, fingerprint_hash)` index is the database-level backstop against duplicate
rows; the insert race is caught and merged into the winning row rather than 500ing.
`chargeService()` is idempotent — the member token spend is guarded by the
`token_ledger` reference and guest usage is keyed per-service — so a duplicate intake on
the same session never double-charges.

### Ad suppression

Server-authoritative: `GET /ads/active` returns `[]` for any ad-free plan, so the
suppression can't be bypassed by calling the API directly.

### Subscription lifecycle (Stripe Checkout)

`POST /subscription/checkout` opens a hosted Stripe Checkout via the
[`BillingProvider`](backend/app/Services/Billing/BillingProvider.php) seam (Stripe today;
a second provider can be added without touching controllers). Premium is **only**
activated/downgraded by the signature-verified webhook
(`POST /webhooks/stripe/subscription`). `subscription_status`
(active/trial/grace/expired/cancelled) is explicit rather than date-inferred; every
transition is appended to `subscription_history` for support/audit. `subscriptions:expire`
is a daily backstop for missed deletion webhooks.

### Scheduled jobs (`routes/console.php`)

`tokens:refill-monthly` (daily, idempotent within a month) · `subscriptions:expire`
(daily) · `guests:cleanup` (daily) · `reservations:cleanup` (hourly).

### Usage logging

`usage_logs` records per-request AI usage (user, service, model, tokens, cost, latency,
status) for cost forensics — distinct from the wallet ledger.

### Account page

`AccountSettings.vue` surfaces the plan badge, a token gauge (used / remaining),
upgrade-to-premium (→ Stripe), cancel, and token history. `GET /me` now returns
`plan`, `is_premium`, `shows_ads`, `token_balance`, and `monthly_allowance`.

> **Deploy note:** run `php artisan migrate --force` (eight additive migrations) and set
> `STRIPE_PREMIUM_PRICE_ID`. Point a Stripe webhook at `/webhooks/stripe/subscription`
> for `checkout.session.completed`, `customer.subscription.updated`,
> `customer.subscription.deleted`, and `invoice.payment_failed`.

**Reference docs:** [`docs/SUBSCRIPTION_SYSTEM.md`](docs/SUBSCRIPTION_SYSTEM.md)
(architecture) · [`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md) ·
[`docs/STAGING_TEST_PLAN.md`](docs/STAGING_TEST_PLAN.md).

---

## Testimonies

`GET /testimonies` returns the **approved** wall; `POST /testimonies` submits one, held
unapproved for moderation. Testimonies are either `user_submitted` or `ai_generated`.
Approval and deletion happen in the admin console.

---

## Admin console

The console is at `/#admin`. Access is role-based:

- **Admin** — full access to everything below.
- **Moderator / Presenter** — limited access; which tabs they see is controlled by the **Permissions** matrix.

`/admin` routes are split across two middleware groups, with fine-grained permission checks inside each:

| Middleware | Who | Routes |
|---|---|---|
| `staff` | admin + moderator + presenter | Dashboard, Services, Testimonies, Donors, Prayer Requests, and **permission-checked reads** (Users, Settings, Music Pool, Permissions, Grammar Review, Voice Training, System health) |
| `admin` | admin only | All writes: Settings PATCH, User mutations, Music Pool CRUD, Permissions PATCH, CSV Export, System actions |

- **Dashboard** — sessions, worship-time totals, donations, intercept counts, and prayer-request counts (total + today). When the admin enables a removable special-day feature (**Special Day MV** and/or **Live Sticker**), a card per enabled feature shows its visitor render traffic (total + today); the card hides automatically while the feature is disabled. Each feature's own admin page also shows its traffic count with a **Reset count** button to zero the counter.
- **Services** — list + **retry** a failed/stuck service (clears existing assets first so segment count visibly drops to zero, confirming regeneration is in progress) + **delete**.
- **Testimonies** — approve / delete (moderation queue); each entry shows the user's custom mood words so the moderator has context.
- **Users** — list + **create** new users (admin generates a first-login reset link when no password is set) + **assign role** (`admin`/`moderator`/`presenter`/`member`) + **block/unblock** + **delete** + **force password reset** (generates a one-time token link the admin shares out-of-band) + set **presenter gender**.
- **Donors** — donation rollups.
- **Prayer requests** — paginated log of prayer-request intakes visible to admins.
- **Permissions** — configure which permissions each non-admin role (`moderator`, `presenter`) has in the staff console. Reads are now permission-checked per-method (non-admin staff with `users.view`/`settings.view`/`music_pool.view`/`permissions.view` can view those tabs without full admin).
- **Language Review** — per-sentence grammar review tool for Tedim and Burmese data files. Admins can browse hymn titles, hymn lyrics, sermon topics, or prayers sentence by sentence, mark them approved, or submit corrections. Corrections are saved to `workers/data/grammar_review.json` and can be applied to the data files in bulk. Filtered by language (`td`/`my`), content type, and review status (`pending`/`approved`/`corrected`).
- **Settings** — global service config persisted in the `settings` table and threaded
  onto each job: `narration_mode` (`off`/`browser`/`openai`/`kokoro`/`edge_tts`),
  per-language narration toggles (`narration_en`/`narration_my`/`narration_td`),
  countdown-card settings (`countdown_content_enabled`, `countdown_content_source` [`banners`/`testimonies`/`verses`/`both`/`all`],
  `countdown_banners`; banners are English-only; verse cards are mood-matched from bundled translations),
  `text_highlight_enabled` (word-by-word highlight on/off in the player), `music_reuse`
  (the Suno pool toggle), `storage_backend` (`local` vs `s3` for generated audio),
  `avatar_enabled` (toggle D-ID avatar rendering on/off without touching env vars),
  `local_avatar_enabled` (toggle the self-hosted open-source avatar engine; local wins over D-ID when both are on),
  **`runpod_enabled`** (enable RunPod Serverless GPU for premium music generation; stored in Redis `ai:runpod_enabled` for zero-restart hot-swap),
  **`orchestration_mode`** (`pipeline` = hard-coded Celery fan-out / `agent` = LLM agent
  with tool use — see [AI agent orchestration](#ai-agent-orchestration)), and
  **`agent_provider`** (`claude` / `gemini` / `chatgpt` — which model powers the agent;
  visible only when `orchestration_mode = agent`).
  **Ad slot** (`ad_slot_enabled` / `ad_slot_html`) — admin can paste raw HTML/embed code (Google Ads, custom banner) up to 8 000 characters; toggled on/off without a redeploy.
  Plus the worshipper-facing **intake options** an admin curates without a redeploy:
  the **moods** offered at intake (add/remove — a new mood flows through the whole
  pipeline: the prayer/sermon tone, the music prompt, and hymn matching), which
  **music sources** appear (toggle any of sung-hymn/instrumental/AI-composed/YouTube,
  at least one on), and whether **scheduling** is offered. These are served to the
  intake form via the public [`GET /config`](#public).
- **Export** — CSV of `donations` | `users` | `testimonies`.
- **System** (admin-only) — live system monitor with one-click installs and service restarts:
  - **Service health** — real-time status (active / inactive / unknown) of all AIVC systemd
    units (`aivc-workers`, `aivc-workers-music`, `aivc-workers-orchestrate`,
    `aivc-workers-avatar`, `aivc-bridge`, `aivc-queue`, `aivc-scheduler`, `aivc-tedim-api`,
    `aivc-burmese-api`) plus `redis-server` and `nginx`.
    Each restartable unit has a **Restart** button that dispatches a `RestartService` queue
    job (requires the `sudoers` entry — `/usr/bin/systemctl` — documented in
    `RestartService.php`).
  - **App version (git)** — current branch, commit hash + message, and how many commits
    behind `origin` the working tree is. A **Pull latest from origin** button dispatches a
    `RunUpdateCheck(gitPull: true)` job that runs `git pull --ff-only` then refreshes the
    cache.
  - **Python packages** — installed version vs PyPI latest for 12 key worker dependencies
    (`edge-tts`, `anthropic`, `celery`, `torch`, `transformers`, etc.). Packages with an
    available update are highlighted and have an **Upgrade** button that dispatches
    `RunPackageUpgrade` to run `pip install --upgrade` in the workers virtualenv.
  - The cache lives at `/tmp/aivc_update_status.json`, refreshed by the `aivc-update-checker`
    systemd timer (every hour, 5 min after boot) and on demand via the **Refresh now** button.
    The dashboard auto-polls every 30 s while the tab is open (4 s while a check is running).
    Because the file is written by both the queue worker (user `simon`, via
    `update_checker.py`) and the web request (`www-data`, via `markChecking()`), both writers
    keep it group-writable (`0664`, group `www-data`) and fall back gracefully so a permission
    glitch degrades the spinner hint instead of returning a 500 to **Refresh now**.
- **Ads** — full ad-campaign CRUD with multi-slide carousel, in-browser Cropper.js image editor, audience targeting (language + mood), and billing by impression/click. See [Ad Management](#ad-management) below.
- **Voice Studio** — in-browser TTS training-data recorder and automatic MMS/VITS
  fine-tune feeder. Displays Tedim and Burmese recording-script sentences one at
  a time; click **Record**, speak, review playback, optionally run **STT check**
  through local MMS-ASR, then **Accept**. The server converts each clip to 16 kHz
  mono WAV via ffmpeg, stores it under `storage/app/voice-studio/{user_id}/{lang}/`,
  and immediately refreshes `dataset/metadata.csv` + `dataset/wavs/`. **Jump to unrecorded**
  skips to the next sentence without a recording; **Go to #** lets you jump directly to
  any sentence by number, and auto-starts at the first unrecorded phrase when you open a
  language. The scheduler runs `voice-studio:train-due` every 30 minutes between
  2AM and 6AM; if the dataset has enough clips and server load is below
  `VOICE_TRAIN_MAX_LOAD`, it launches the configured MMS/VITS fine-tune command.
  **Export Dataset** remains available for inspection/debugging, but training no
  longer depends on manual export.

---

## Ad Management

Ads appear in the service player at three positions:

| Position | When |
|---|---|
| `start` | Before the first stage (start ad clears before worship begins). |
| `between` | In the content area below each stage, above Previous/Next. |
| `end` | Shown as a fixed overlay when the worshipper clicks "End service"; dismissing it exits. |

### Admin UI (`#admin` → Ads tab)

- **List view** — shows all campaigns with live impression/click/revenue totals.
- **Edit view** — two-column layout: ad settings on the left, slide manager on the right.
  - Set status (`draft` / `active` / `paused`), type (`slideshow` / `html`), locations, slide duration, billing rates, and audience targeting.
  - **Locations** — `start` / `between` / `end` (service flow), `special_day` (Special Day MV page), `sticker_ads` (Live Sticker page), and `bible_study` (the box below the AI Bible Study setup form). Tag an ad to a location to show it there; untag to hide.
  - **Image slides** — upload an image and crop it in-browser with Cropper.js (free aspect ratio, max 1200×800 WebP output at 88% quality).
  - **HTML slides** — paste any HTML (embed codes, custom banners).
  - Reorder slides with ↑/↓ buttons (persisted to `sort_order`).
- **Analytics tab** — per-campaign table: impressions, clicks, CTR, total view time, and revenue.

### Database tables

| Table | Purpose |
|---|---|
| `ads` | Campaign header — status, type, locations JSON, targeting, billing rates, slide duration. |
| `ad_slides` | Individual slides — `image_path` (stored in `storage/app/public/ads/{ad_id}/`) or `html_content`, plus per-slide `duration_seconds` override and `link_url`. |
| `ad_impressions` | One row per shown ad — `duration_ms`, `clicked`, `location`, `session_token`, `language`, `mood`. |

### API endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/ads/active` | Public | Fetch active ads for a given `?language=` + `?mood=`. |
| `POST` | `/ads/track` | Public (throttle 60/min) | Record an impression or click. |
| `GET` | `/admin/ads` | staff + `ads.view` | List ads with stats. |
| `GET` | `/admin/ads/{ad}` | staff + `ads.view` | Single ad with slides. |
| `GET` | `/admin/ads-analytics` | staff + `ads.analytics` | Per-campaign analytics. |
| `POST` | `/admin/ads` | admin | Create ad. |
| `PATCH` | `/admin/ads/{ad}` | admin | Update ad. |
| `DELETE` | `/admin/ads/{ad}` | admin | Delete ad + all slides + images. |
| `POST` | `/admin/ads/{ad}/slides` | admin | Add slide (image or HTML). |
| `PATCH` | `/admin/ads/{ad}/slides/{slide}` | admin | Update slide. |
| `DELETE` | `/admin/ads/{ad}/slides/{slide}` | admin | Delete slide + image file. |
| `POST` | `/admin/ads/{ad}/slides/{slide}/image` | admin | Upload + store cropped image (WebP). |
| `POST` | `/admin/ads/{ad}/reorder` | admin | Persist new slide order. |

### Permissions

| Permission | Default roles |
|---|---|
| `ads.view` | moderator |
| `ads.analytics` | moderator |
| `ads.manage` | admin only |

Admins always have all three. The permissions matrix in the admin console lets you grant `ads.view` / `ads.analytics` to the presenter role as well.

---

## Scheduled services

An intake may carry `scheduled_at` (a future time). Instead of dispatching immediately,
the session is parked as `scheduled`. The `services:dispatch-due` console command runs
**every minute** (`Schedule::command(...)->everyMinute()->withoutOverlapping()` in
[console.php](backend/routes/console.php)), flips due sessions to `active`, and hands them
to the **same** `DispatchServiceJob` a walk-up intake uses. Requires
`php artisan schedule:work` in dev (or a once-a-minute cron in prod).

When a scheduled service comes due for a **registered** worshipper, a queued
[ServiceReminderNotification](backend/app/Notifications/ServiceReminderNotification.php)
emails them a "your service is ready" link back to the SPA (`FRONTEND_URL`). It's queued
so the minute-ly command never blocks on mail; the running `queue:work` delivers it. Set
`MAIL_MAILER=log` locally to write the message to `storage/logs/laravel.log` instead of
sending it.

---

## Special Sundays (observance-driven sermon & worship)

During the window around a "special Sunday" (Mother's/Father's/Children's/Youth
Day, Palm Sunday, Easter, Pentecost, Reformation, Advent, Thanksgiving…) the app
**pre-biases the sermon and worship music** toward the observance and shows a
localized highlight card on the intake screen.

**Window math.** Each observance resolves to a Sunday `S`; its active window is
`[S − 2 days @ 00:00 .. S @ 23:59:59]` — i.e. **Friday 00:00 → Sunday 23:59**. A
worshipper arriving Fri/Sat/Sun of that week gets the bias + card; Mon–Thu they
do not. Overlapping windows are broken by `priority` (higher wins).

**Dates are never hardcoded** — they move every year. Each observance carries a
*rule* that [`App\Models\SpecialSunday`](backend/app/Models/SpecialSunday.php)
resolves for any year:

| `rule_type`     | `rule`                                 | Example                      |
|-----------------|----------------------------------------|------------------------------|
| `nth_weekday`   | `{month, weekday(0=Sun..6=Sat), nth}`  | Mother's Day = 2nd Sun May   |
| `easter_offset` | `{offset}` days from Western Easter     | Palm = −7, Pentecost = +49   |
| `fixed`         | `{month, day}` civil/fixed date         | Children's Day = Jun 1       |

Western Easter is computed in-house (Anonymous Gregorian algorithm); any anchor
that isn't already a Sunday is snapped to the **nearest Sunday** (±3 days).

**How it flows.**
- The catalog lives in [`config/special_sundays.php`](backend/config/special_sundays.php)
  (versioned, region-editable) and is upserted into the `special_sundays` table by
  `SpecialSundaySeeder` (my/td text NFC-normalized to Myanmar Unicode).
- [`SpecialSundayResolver`](backend/app/Services/SpecialSundayResolver.php) returns
  the active observance for a moment. It's consulted **live at dispatch time** in
  [`DispatchServiceJob`](backend/app/Jobs/DispatchServiceJob.php), which adds a
  `special_sunday` block (`sermon_tags`, `music_moods`, localized `title`/`brief`)
  to the Redis payload. The `special-sunday:evaluate` command (daily) just warms
  the cache and logs the active observance.
- Worker-side, the bias filters selection without overriding the worshipper's own
  mood/prayer: `sermon_tags` steer `generate_sermon(theme=…)` and the YouTube
  sermon query; `music_moods` fold into the hymn/worship search query. Scripture
  still bypasses the LLM, and my/td prose still passes the Zawgyi/Unicode guard.
- The SPA fetches `GET /api/special-sunday/current?language=en|my|td` and renders
  the highlight card in [IntakeForm.vue](frontend/src/components/IntakeForm.vue),
  in Myanmar Unicode fonts (Pyidaungsu/Padauk/Noto Sans Myanmar) for my/td.

**Admin console — monitor & control.** The staff console has a **Special Sundays**
tab (permissions `special_sundays.view` / `special_sundays.manage`) backed by
`GET /api/admin/special-sundays` and write routes under `/api/admin/special-sundays`:
- *Monitor* — the observance active right now, an upcoming calendar (this year +
  next), and a **bias audit** (recent services generated inside a window, with the
  observance that biased them, resolved retroactively from `created_at`).
- *Control* — enable/disable each observance, edit priority, title/brief (en/my/td,
  NFC-normalized to Myanmar Unicode), and the date rule, or **add a new observance**
  manually. Everything is auto-seeded by default; edits/additions live in the DB row.

**Curated content (manual mode).** Beyond biasing the AI, each observance can carry
a **hand-authored sermon** and **specific songs** per service language, managed from
the same admin tab (tables `special_sermons` / `special_songs`):
- Each observance has a per-language **mode** for sermon and for worship
  (`content_modes` JSON on `special_sundays`). Default **Auto** = the AI sermon /
  mood-selected worship run normally. Flip to **Manual** to serve the highest-priority
  active curated entry instead; if none is active, the worker safely falls back to Auto.
- Curated **sermons** are spoken verbatim (still classifier-reviewed, narrated, and
  avatar-rendered). Curated **songs** support four source kinds — `youtube` (id/URL),
  `hymn` (a Song-library id, reusing its audio + lyrics), `audio` (a direct hosted
  URL), and `suno` (a composition prompt rendered at service time).
- Entries are tagged by `mood` / `priority` / `region`; when several match a
  day+language, a matching mood wins, then highest priority. The worker honors this on
  both the pipeline and agent orchestration paths. my/td text is NFC-normalized.

**Preview (confirm before Sunday).** The Content panel has a read-only **preview**
(`GET /api/admin/special-sundays/{id}/preview?language=&mood=`) that resolves the exact
sermon + worship that *would* be served for a chosen language and mood — showing the
manual pick (sermon title/body, song) or, for **Auto** mode, the bias `sermon_tags` /
`music_moods` that steer the AI. Lets staff verify a manual selection ahead of time
without dispatching a real service.

**Adding a special Sunday (config).** For a permanent, version-controlled entry,
append it to
[`config/special_sundays.php`](backend/config/special_sundays.php) with a unique
`key`, a `rule_type` + `rule`, `titles`/`briefs` for `en`/`my`/`td` (Myanmar
Unicode only), `sermon_tags`, `music_moods`, optional `region`, and `priority`,
then re-seed:

```bash
php artisan db:seed --class="Database\Seeders\SpecialSundaySeeder" --force
```

No migration is needed — the seeder upserts by `key`.

---

## Special Day Music Video (Father's Day) — standalone & removable

A self-contained feature that lets a public visitor upload photo(s) of their
father and download a vertical **1080×1920 MP4** set to an admin-provided song +
lyrics. It is deliberately isolated from the worship pipeline so it can be added
for one occasion and removed cleanly afterwards.

**Two delivery modes (mutually exclusive — only one runs at a time)**
- **Manual MV library** (the original): visitors upload photos and build a video
  from your uploaded songs (below).
- **Auto YouTube song**: the system plays the **currently active Special Sunday's**
  curated YouTube song (no video creation) with a **share button only**. Songs are
  a hardcoded catalog in `backend/config/special_day_songs.php`, keyed by the same
  observance `key` as `config/special_sundays.php` (`fathers_day`, `easter_sunday`,
  …); each entry is `['title' => …, 'youtube_id' => …]`. The resolver picks today's
  active day, the page embeds its song(s), and Share hands out the raw YouTube
  watch URL plus an *aivirtual.church* invitation. Entries with no valid 11-char
  `youtube_id` are skipped; a day with no usable songs shows "not available".
  The admin panel previews exactly what Auto mode would play right now.
- Enabling one mode forces the other off (enforced in both the UI and
  `adminSave`); you cannot have both on.

**How it works**
- **Admin** (`#admin` → *Special Day MV* tab, admin role only): choose the mode
  (Manual MV library or Auto YouTube), and for manual, manage a **song
  library** — add multiple songs (MP3/WAV), each with its own lyrics, sync mode,
  detected vocal-onset and tap-to-sync. Set the default effect and **enable** the
  page. Config + assets live as plain files in `backend/storage/app/fathersday/`
  (`config.json` + `songs/<id>.<ext>`) — **no DB migration**.
- **Visitor** (`#fathers-day`, link appears only when enabled): **picks a song**,
  drops 1–6 photos, picks an effect, hits *Create video*, and the MP4
  auto-downloads when ready.
- **Effects**: `slide` (hard cut), `fade` (crossfade), `kenburns` (gentle
  zoom/pan). A single photo is held for the whole song (with optional zoom).
- **Community Original + brand audio tag** (to avoid social-media copyright
  blocks): mark a song *Community Original* (your own/cleared recording), and
  optionally upload a short **aivirtual.church audio ident** that the renderer
  overlays at the very *start* of every MV. The overlay (`amix duration=first
  normalize=0`) keeps the song's length and full volume — lyric sync stays exact
  and the song still sounds good — while making the audio identifiably yours so
  community-original songs pass Facebook/Instagram checks. (A genuinely
  copyrighted *melody* can still be flagged regardless; this protects your own
  originals and public-domain hymns, it is not a way to disguise commercial
  tracks.) **Sharing**: the done screen has a single **⬇ Save video** button —
  it downloads the MP4 to the gallery, with on-screen steps to post it **once**
  via Facebook → *Create post* → *Photo/Video* (not Stories/Reels). There is no
  "Share video" button: handing a video to the OS share sheet sends it into
  Facebook's **Stories/Reels** quick-flow, which **chops it into 15–20s
  segments** ("part 1, 2, 3…") — no website can override that, and FB removed
  programmatic video posting to personal timelines (`publish_actions`, 2018), so
  every share path just resolved to a download anyway. A manual **Feed** upload
  is the only guaranteed single post. The *Or share a link* `/v/{jobId}` page
  remains as a fallback for sharing a preview-card link.
- **Lyrics**: `[mm:ss.xx]` LRC tags drive the time-synced highlight; otherwise
  lines are split evenly across the song length. Section markers like
  `[Verse 1]` / `[Chorus]` / `[Bridge]` are recognised and **not** shown on the
  video. Lyrics are burned in as ASS subtitles using the bundled **Myanmar
  Njaun** font (`backend/resources/fonts/`), which renders Myanmar *and* Latin so
  EN/MY/TD lyrics all display — the host has no Myanmar system font. Reuses the
  LRC convention from the worship `MusicPlayer`.
- **Song**: MP3/WAV up to **50 MB**.
- **Tap-to-sync** (exact karaoke timing): in the admin panel, *Tap-to-sync
  lyrics* plays the song and the admin presses **Space** as each line is sung;
  this writes per-line `[mm:ss.xx]` LRC timestamps and turns on synced mode, so
  every line appears exactly when it's sung — reliable for any language. Without
  tapping, lines are split evenly from the vocal-onset (below).
- **Lyrics hold for the intro**: when a song is uploaded, `DetectVocalStartJob`
  runs **Demucs** (vocal source separation) on the first 90s to find when the
  singing starts, and caches `vocal_start` (seconds) in config. The renderer
  holds the lyrics through the instrumental intro and spreads them from that
  point. Admin can override the detected value. Demucs runs in an **isolated
  venv** (`workers/.venv-demucs`, gitignored) so its torch can't disturb the
  narration/avatar stack; detection is ~3 min on CPU but runs once per song.
  Setup: `python3.12 -m venv workers/.venv-demucs && workers/.venv-demucs/bin/pip
  install torch torchaudio --index-url https://download.pytorch.org/whl/cpu &&
  workers/.venv-demucs/bin/pip install demucs diffq`.
- **Rendering** runs on the existing Laravel `queue:work` worker via
  `RenderFathersDayJob` shelling out to `ffmpeg` — no new service or port.
- **Sharing**: the visitor can share the finished MV. The button is
  **duration-aware** to avoid Facebook auto-splitting a long file into many
  spammy ~90s reels — clips **≤90s** are handed straight to
  `navigator.share({files})` (native file share), while **longer** videos fall
  back to sharing a clean **main-domain** `/v/<id>` link (served with
  Open-Graph/Twitter-card meta; `SecurityHeaders` relaxes the CSP for `/v/*`
  video+poster). When the **Web Share API is unavailable** (e.g. desktop
  browsers) the button degrades gracefully to **copy-link / save-file** so the
  tap always does something. (A full-song share builds the link directly rather
  than reading blob metadata, which previously hung.)

**Security**: uploads are validated by extension + size (≤8 MB/photo, ≤6 photos),
then **re-encoded through ffmpeg**, which strips EXIF/GPS and neutralises
malformed-image payloads. Filenames are server-generated (never client-trusted),
job ids are UUIDs validated against path traversal, the render endpoint is
throttled (`10/min`), and originals are deleted after the render. The public page
only appears once a song is uploaded **and** the feature is enabled.

**Abuse / resource guards**: finished MVs are pruned after **30 days** and
abandoned uploads after **1h** by the scheduled `media:prune` command (daily
03:30) plus an opportunistic sweep on each render — so a public endpoint can't
fill the disk. A hard **5 GB** storage ceiling refuses new renders (HTTP 503) if
cleanup ever falls behind, and **idempotency** (a content hash of the uploaded
photos + effect/song/clip, kept 24h in `render_index.json`) returns the existing
job for an identical re-submit instead of re-encoding a duplicate.

**nginx note**: photo uploads can exceed the default body cap. Bump the API
server block: `client_max_body_size 60M;` then `nginx -t && systemctl reload nginx`.

**To remove the whole feature**, delete:
`FathersDayController.php`, `app/Jobs/RenderFathersDayJob.php`, the *Father's Day
MV* route block in `routes/api.php`, `backend/storage/app/fathersday/`, the
frontend `FathersDay.vue` + `FathersDayManager.vue`, and their wiring in
`App.vue` / `AdminConsole.vue` / `useApi.js`.

---

## Live Sticker Maker — standalone & removable

A self-contained fun tool that lets a public visitor upload **any** photo
(vertical or horizontal) and get **an AI watercolor die-cut sticker** — an
illustration repaint of their photo, cut out from its background with a white
sticker border + soft shadow and scattered colour-emoji (hearts / sparkles),
like a ChatGPT/Telegram sticker. An optional caption can come from
the admin **Father's Day song lyrics** (reused from the Special Day feature) or
**free text the visitor types** (lightly **auto-corrected** for English).
Isolated from the worship pipeline so it can be removed cleanly.

**How it works** (`workers/tools/sticker_render.py`)
- **Admin** (`#admin` → *Live Sticker* tab, admin only): **enable/disable** the
  page and set fallback page copy (title/subtitle). Config is a plain file at
  `backend/storage/app/stickers/config.json` — **no DB migration**. The link,
  intake banner and page only appear when enabled.
- **Special-Sunday theme**: when a Special Sunday is active (resolved by
  `SpecialSundayResolver`), the page title, caption suggestions and the AI repaint
  prompt + decorations automatically follow it (Father's Day → dad theme,
  Christmas → 🎄🎁, etc.). Outside observances the admin fallback copy is used.
- **Visitor** (`#stickers`, shown when enabled): taps the red **Create Live Sticker**
  box, picks a photo, fine-tunes the square crop (pre-centred on the detected
  face/group), optionally adds a caption, and gets a downloadable PNG sticker.
- **Face detection + crop**: **OpenCV** (Haar cascade) finds the face(s) and
  suggests a padded square box. `/stickers/detect` runs this **synchronously**
  and returns the box; the frontend shows it in **cropper.js** for manual
  adjustment. EXIF orientation is honoured so phone photos aren't sideways.
  Both `/stickers/detect` and `/stickers/render` return **404 when the feature
  is disabled**, so no upload/CPU work happens unless an admin has turned it on.
- **AI repaint (img2img)**: the sticker is repainted via **OpenRouter** using
  Google's **`google/gemini-2.5-flash-image`** model (best watercolor portraits,
  keeps the full head/pose in frame, cheapest), driven by the existing
  `OPENROUTER_API_KEY` (`workers/.env`). Override with **`STICKER_MODEL`** in
  `workers/.env` — e.g. `openai/gpt-5-image-mini` (~$0.044) or `openai/gpt-5-image`
  (~$0.19), though the OpenAI variants tend to crop the top of the head and may
  burn unwanted text into the image. **Note:** OpenRouter has no
  `openai/gpt-image-1`. The prompt **rotates through a set of
  distinct art styles** (`STYLES`) so consecutive renders look different, while
  **strongly preserving each person's facial identity/likeness** (face shape,
  eyes, nose, mouth, skin tone, glasses, hairstyle, expression) — style is
  applied to rendering/texture only, never to facial features.
  **One image per job (~$0.02–0.04)** to keep cost low — change `COUNT` in
  `sticker_render.py` + `StickerController` to make more. If the key is missing
  or a call fails, it falls back to a cutout of the **real** photo so the tool
  still works.
- **Die-cut cutout**: **rembg** (U²-Net) removes the background; **Pillow** +
  **OpenCV** dilate the silhouette into a white sticker border, add a soft drop
  shadow, and scatter 3–5 colour emoji (avoiding faces) from the bundled
  **Noto Color Emoji** font (`backend/resources/fonts/`). Output is a 768×768
  transparent PNG.
- **Auto-correct** (caption): English-only via `pyspellchecker`, conservative
  (skips proper nouns / all-caps / non-Latin) — the Burmese model is unusable
  for free text.
- **Rendering** runs on the dedicated `fathersday` queue via `RenderStickerJob`
  (reuses the existing `aivc-fathersday-render@` workers — no new service; job
  timeout 150s). Outputs/uploads live as plain files in
  `backend/storage/app/stickers/jobs/<id>/` — **no DB migration**.
  **Cross-user perms**: the web request (`www-data`) writes the job files, but the
  render worker runs as a **different user** in the `www-data` **group**, so it
  reaches them via group access. `StickerController::ensureBasePerms()` keeps the
  `stickers/` + `jobs/` dirs `02775` (setgid, group-inherited) and adds
  **group-traverse (`g+x`) to the disk root** if it's ever owner-only (least
  privilege — no group read/write, `others` stays closed).
  **Storage root is PINNED** (`backend/config/filesystems.php`): the Laravel 12
  upgrade silently moved the framework-default local disk root from
  `storage/app/` to `storage/app/private/` — a fresh `0700` dir that broke the
  worker's group access (renders hung at **"Queued"**: the worker could neither
  read `input.json` nor write `status.json`) and orphaned all pre-upgrade data
  (Father's Day songs/config, existing sticker share links, usage counters).
  The published config pins `disks.local.root` back to `storage_path('app')`
  with `serve => false` (all storage is served through controllers only), and
  `StickerPermissionsTest` guards both the pin and the perms so future
  framework upgrades can't silently repeat this. **Any future change to the
  disk root must migrate `fathersday/`, `stickers/` and `bible/` and preserve
  group perms.**
  **Diagnose**: `namei -l backend/storage/app/private/stickers/jobs` — every
  directory in the chain needs `x` for the worker's user or group; jobs frozen
  at "Queued" with no error in the Laravel log are the telltale symptom.

**Dependencies** (worker venv, one-off):
`workers/.venv/bin/pip install opencv-python-headless Pillow pyspellchecker
"rembg[cpu]" onnxruntime`. rembg downloads its U²-Net model (~176 MB) to
`~/.u2net/` on first run. The Noto Color Emoji font is committed under
`backend/resources/fonts/`. Requires `OPENROUTER_API_KEY` in `workers/.env`.

**Security**: uploads validated by extension + size (≤12 MB), stored under
server-generated names; job ids are UUIDs validated against path traversal;
`detect`/`render` throttled (`20/min`); originals deleted after the render.
**Retention is split** so shared `/s/<id>` links don't die within hours:
**finished** stickers (a `sticker_*.png` exists) are kept **~1 year**
(`KEEP_SECS` = 365 days) so links stay alive, while **abandoned** uploads
(photo detected but never rendered) are still pruned after **1h**
(`ABANDON_SECS`) for privacy; the scheduled `media:prune` command (daily 03:30)
is the backstop. The base storage dir is `setgid 02775` so the render worker
(separate OS user in the `www-data` group) can read the queued job. The photo is
sent to OpenRouter for the repaint — note this in any privacy copy.

**Abuse / resource guards**: a global **daily cap** (`DAILY_CAP` = 500 renders)
bounds paid OpenRouter cost even under distributed abuse (HTTP 429 past the cap);
**idempotency** returns the existing job for a repeat submit of the same upload
token instead of paying for a second repaint; and a hard **2 GB** storage ceiling
refuses new renders (HTTP 503) if cleanup falls behind. Public share pages
(`/s`,`/si`,`/v`,`/vi`,`/vp`) are served with `X-Robots-Tag: noindex,
noimageindex` so user photos stay shareable by direct link but out of search
results.

**Sharing**: the visitor's primary action is **📤 Share**, which hands the actual
PNG file to `navigator.share({files})` — it lands straight in WhatsApp /
Instagram / Messenger / Viber / X with **no hostname exposed**. For link-based
shares (Facebook/X/Telegram/“Copy link”) the page builds a clean **main-domain**
URL `https://aivirtual.church/s/<jobId>`, served by `ShareController` (registered
in `routes/web.php`) with **Open-Graph/Twitter-card** meta so the preview shows
the sticker on the public domain, never `api.*`. `og:image` is re-served at
`/si/<jobId>/<n>`. `SecurityHeaders` relaxes the CSP only for `/s/*`.

**nginx (main domain)**: map the share paths to php-fpm inside the
`server_name aivirtual.church` block:
```nginx
location ^~ /s/  { root /opt/ai-church/backend/public; try_files $uri @sticker_share; }
location ^~ /si/ { root /opt/ai-church/backend/public; try_files $uri @sticker_share; }
location @sticker_share {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /opt/ai-church/backend/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
}
```

**To remove the whole feature**, delete: `StickerController.php`,
`app/Jobs/RenderStickerJob.php`, `ShareController.php`, `workers/tools/sticker_render.py`,
the *Live Sticker* route block in `routes/api.php`, the `routes/web.php` share block,
the `/s//si` nginx locations, `backend/storage/app/stickers/`, the frontend
`LiveSticker.vue`, and its wiring in `App.vue` / `useApi.js`.

---

## Running locally

You need **four** long-running processes plus Redis and MySQL. None auto-restart and
there's no Procfile/supervisor — if any is down or stale, a service hangs on
"Composing your worship…".

**Prerequisites:** PHP 8.2+, Composer, Node 18+, Python 3.11+, Redis, MySQL.

```bash
# 0. Redis + MySQL must be running, and a database created (default: ai_church)

# 1. Backend (Laravel API)
cd backend
composer install
cp .env.example .env        # then edit — see Environment variables below
php artisan key:generate
php artisan migrate
php artisan storage:link    # once, so local media (narration mp3s) is served over HTTP
php artisan serve --host=127.0.0.1 --port=8000

# 2. Laravel queue worker (runs DispatchServiceJob → Redis)
cd backend
php artisan queue:work --tries=3

# 3. Python workers — deps live in a venv at workers/.venv
cd workers
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
#   Workers do NOT auto-load .env — export it into the launching shell first:
set -a; . ./.env; set +a

#   Seed the public-domain hymn library (the default `hymn_sung` source needs it).
#   Sung recordings + lyrics are plain downloads; the instrumental render additionally
#   needs fluidsynth + a soundfont + ffmpeg (skipped with a note if absent).
python seed_hymns.py

#   Seed language data (required for Myanmar/Tedim language services):
python tools/seed_language_data.py        # Registered Bible translations + Myanmar hymns
# hymns_td.json is bundled — no Tedim hymn seed step needed
python tools/seed_tedim_midi.py           # optional: Tedim instrumental fallbacks

#   3a. Bridge consumer (Redis → Celery)
python bridge.py

#   3b. Celery workers (separate shell, re-run the `set -a` env export there too)
celery -A tasks.celery_app worker -Q ai:sermon,ai:music,ai:avatar,ai:narration -c 4

# 4. Frontend (Vue SPA)
cd frontend
npm install
npm run dev

# (optional) release scheduled services on time
cd backend && php artisan schedule:work
```

## Automated tests

The backend has a **PHPUnit** suite (`backend/tests`) covering authentication,
email-verification/activation, the token ledger, monthly refill, authorization,
admin user management, and billing-disabled behaviour. Tests run against a
**dedicated MySQL test database** (the production engine, never the dev/prod data)
with `RefreshDatabase`.

```bash
# one-time: provision an isolated test database
sudo mysql -e "CREATE DATABASE ai_church_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  GRANT ALL PRIVILEGES ON ai_church_test.* TO 'ai_church'@'localhost'; FLUSH PRIVILEGES;"

cd backend
composer install                 # installs dev deps (phpunit, mockery)
cp .env.testing.example .env.testing   # then set DB_USERNAME / DB_PASSWORD
vendor/bin/phpunit               # or: vendor/bin/phpunit --testdox
```

Connection + drivers (sqlite-free, MySQL, array cache/session, sync queue, array
mailer) are pinned in [backend/phpunit.xml](backend/phpunit.xml); local DB
credentials live in a gitignored `.env.testing`. **CI** ([.github/workflows/ci.yml](.github/workflows/ci.yml))
spins up a MySQL service, runs the suite with coverage, and builds the frontend on
every push and pull request. A separate **Security** workflow
([.github/workflows/security.yml](.github/workflows/security.yml)) audits all three
dependency ecosystems (`composer audit`, `npm audit`, `pip-audit`) and scans for
secrets with gitleaks. The Python audit pins `requests`, `fastapi`/`uvicorn` and an
explicit `starlette==1.3.1` to patched releases, and `--ignore-vuln`s three
`transformers` advisories that have no fix compatible with the `<5` pin we keep to
protect the MMS-TTS narrator stack (incl. CVE-2026-4372, triaged 2026-07-09: the
config-injection RCE needs the `kernels` package — not installed — and untrusted
Hub models; the workers load only pinned official MMS-TTS weights).

### Local gotchas worth knowing

- **Redis key-prefix trap.** Laravel auto-prefixes Redis keys with
  `slug(APP_NAME)_database_`, but `bridge.py` `BLPOP`s the **bare** `ai:intake`. Set
  `REDIS_PREFIX=` (empty) in `backend/.env` so Laravel writes the bare key — otherwise
  every service hangs with intakes piling up under a prefixed key.
- **Workers don't read `.env` themselves** — always `set -a; . ./.env; set +a` in the
  shell that launches `bridge.py` / Celery. After editing worker code or `.env`, restart
  Celery (and `bridge.py` too if you changed task routing — routing is applied
  producer-side).
- **After editing `backend/.env`**, restart `php artisan serve` (env is cached at boot).
- **Don't `pkill -f celery`** — the pattern matches its own shell and kills the command
  mid-run. Kill by numeric PID, or launch detached with `setsid … & disown`.
- The default free OpenRouter model takes ~100s for the 2500-token sermon, so "complete"
  legitimately lags ~1.5 min behind intake.
- **Systemd-managed stack (alternative to the manual launch).** The whole stack also runs
  as user units grouped under `aivirtualchurch.target` (frontend, backend, bridge, workers,
  scheduler). Each service is `PartOf=` the target, so one command restarts everything:
  `systemctl --user restart aivirtualchurch.target` (e.g. after editing worker code or
  `.env`). Restart just the workers with `systemctl --user restart aivirtualchurch-workers.service`.
- **`systemctl --user` → "Failed to connect to bus: No medium found".** Your SSH shell is
  missing the per-user bus env. Export it (add to `~/.bashrc`):
  `export XDG_RUNTIME_DIR=/run/user/$(id -u)` and
  `export DBUS_SESSION_BUS_ADDRESS=unix:path=$XDG_RUNTIME_DIR/bus`. Linger is enabled, so
  the user instance keeps running across logins regardless.

---

## Deploying to production

A full, step-by-step walkthrough lives in **[DEPLOY.md](DEPLOY.md)** — a single-droplet
(DigitalOcean) deploy that puts the app at `/opt/ai-church`, owned by an unprivileged
`simon` user. The shape differs from local dev in one important way: the **HTTP layer is
nginx + php-fpm** (TLS via certbot), not `php artisan serve`, so there is no `backend`
app process. Queue workers, Celery, the Redis bridge, and the local language APIs run as services.

Those units are version-controlled as **system-level** units in
[`.systemd/prod/`](.systemd/prod/) (the local stack instead uses `--user` units under
`aivirtualchurch.target` — see [Running locally](#running-locally)):

| Unit | Process |
|------|---------|
| [`aivc-queue.service`](.systemd/prod/aivc-queue.service) | Laravel `queue:work` (runs `DispatchServiceJob` plus queued Laravel jobs/mail) |
| [`aivc-scheduler.service`](.systemd/prod/aivc-scheduler.service) | Laravel `schedule:work` (releases due services + reminder mail) |
| [`aivc-workers.service`](.systemd/prod/aivc-workers.service) | Celery workers (sermon · narration) |
| [`aivc-workers-avatar.service`](.systemd/prod/aivc-workers-avatar.service) | Celery avatar worker (`ai:avatar`), isolated so RunPod outages can't starve narration |
| [`aivc-bridge.service`](.systemd/prod/aivc-bridge.service) | bridge consumer (`ai:intake` → Celery) |
| [`aivc-tedim-api.service`](.systemd/prod/aivc-tedim-api.service) | FastAPI Tedim LLM service (Uvicorn, port 8001) |
| [`aivc-burmese-api.service`](.systemd/prod/aivc-burmese-api.service) | FastAPI Burmese LLM service (Uvicorn, port 8002) |
| [`aivc-mms-tts.service`](.systemd/prod/aivc-mms-tts.service) | Dedicated MMS speech service: TTS + STT (Uvicorn, port 8003) |
| [`aivc-nllb-api.service`](.systemd/prod/aivc-nllb-api.service) | NLLB-200 translation service — English → Burmese (Uvicorn, port 8004) |
| [`aivc-avatar-proxy.service`](.systemd/prod/aivc-avatar-proxy.service) | Avatar proxy — bridges the worker's multipart avatar call to the RunPod SadTalker endpoint (Uvicorn, port 8005) |

```bash
# on the droplet, once the units are copied to /etc/systemd/system:
sudo systemctl enable --now aivc-queue aivc-scheduler aivc-workers aivc-workers-avatar aivc-bridge aivc-tedim-api aivc-burmese-api aivc-mms-tts aivc-nllb-api
sudo systemctl status  aivc-queue aivc-scheduler aivc-workers aivc-workers-avatar aivc-bridge aivc-tedim-api aivc-burmese-api aivc-mms-tts aivc-nllb-api --no-pager

# After worker/backend code or prompt changes, restart the services that load code:
sudo systemctl restart aivc-workers aivc-bridge aivc-queue aivc-tedim-api aivc-burmese-api aivc-mms-tts aivc-nllb-api
sudo systemctl status  aivc-workers aivc-bridge aivc-queue aivc-tedim-api aivc-burmese-api aivc-mms-tts aivc-nllb-api --no-pager

# After changing OLLAMA_MODEL_TD in workers/.env:
sudo systemctl restart aivc-tedim-api

# After changing OLLAMA_MODEL_MY in workers/.env:
sudo systemctl restart aivc-burmese-api
```

For local media storage, Celery must be able to write into Laravel's served storage tree. The production worker unit should run as `User=simon` and `Group=www-data`, and the storage directory should be group-writable/setgid:

```bash
sudo chown -R simon:www-data /opt/ai-church/backend/storage/app/public
sudo find /opt/ai-church/backend/storage/app/public -type d -exec chmod 2775 {} \;
sudo find /opt/ai-church/backend/storage/app/public -type f -exec chmod 664 {} \;
sudo systemctl daemon-reload
sudo systemctl restart aivc-workers
```

> **Frontend deploy is NOT `git push`.** `frontend/dist/` is **gitignored**, so pushing
> source never updates the live site — nginx serves the compiled bundle directly. After any
> change under `frontend/src/`, you must rebuild **on the box**: `cd /opt/ai-church/frontend
> && npm run build`. Skipping this leaves users on the old bundle (missing buttons / "changes
> not reflecting"). No nginx restart is needed; the new hashed assets are picked up on reload.

The units assume `/opt/ai-church` and the `simon` user; if your app path or user differ,
edit each unit's `WorkingDirectory` and `User`/`Group` before copying. DEPLOY.md also
covers the deploy-time gotchas (the `REDIS_PREFIX=` trap, storage ownership for php-fpm,
config caching) in a troubleshooting table.

---

## Server Restart Runbook

Production restart/check procedures are documented in
[**SERVICE_RESTART_RUNBOOK.md**](SERVICE_RESTART_RUNBOOK.md).

Use it for:
- code/prompt deploy restarts
- post-reboot recovery
- queue/worker/bridge health checks
- log-based troubleshooting

## Suno Pool CRUD Manual

Manual create/read/update/delete procedures for `music_tracks` (Suno reuse pool) are in
[**SUNO_POOL_CRUD_MANUAL.md**](SUNO_POOL_CRUD_MANUAL.md).

This includes:
- safe pre-edit steps (disable pool + backup)
- SQL + Tinker CRUD commands
- post-edit validation queries
- Tedim/Burmese/English language-safety notes

## API services & billing

A complete list of every external API the platform uses, which ones are **paid/metered**,
and the **monthly admin checklist** for checking balances and recharging lives in
[**docs/API_SERVICES_AND_BILLING.md**](docs/API_SERVICES_AND_BILLING.md).

## Environment variables

> **Note:** the LLM path uses an OpenAI-compatible chat endpoint and is configured with
> `OPENROUTER_API_KEY` / `OPENROUTER_BASE_URL` / `LLM_MODEL` (any provider that speaks the
> OpenAI Chat Completions format works by swapping these). The root `.env.example` still
> shows the older `ANTHROPIC_API_KEY` name — the variable names below reflect what the
> code actually reads.

### Backend (`backend/.env`)

| Var | Purpose |
|-----|---------|
| `APP_KEY`, `APP_NAME`, `APP_URL` | Standard Laravel. `APP_NAME` affects the Redis prefix — see the trap above. |
| `DB_*` | MySQL connection (`DB_DATABASE=ai_church` by default). |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PREFIX` | Queue + bus. **Keep `REDIS_PREFIX` empty.** |
| `QUEUE_CONNECTION` | `redis` (so `DispatchServiceJob` and queued Laravel jobs/mail run via `queue:work`). |
| `WORKER_WEBHOOK_SECRET` | Shared secret the workers send as `X-Worker-Secret`. Must match the workers' value. |
| `TEDIM_LLM_URL` | Base URL of the local Tedim FastAPI service (default `http://127.0.0.1:8001`). Must match `workers/.env`. |
| `BURMESE_LLM_URL` | Base URL of the local Burmese FastAPI service (default `http://127.0.0.1:8002`). Must match `workers/.env`. |
| `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` / `STRIPE_CURRENCY` | Offering. `STRIPE_WEBHOOK_SECRET` is the `whsec_…` from `stripe listen`. |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` / … / `MAIL_FROM_*` | Scheduled-service reminder mail. Use `MAIL_MAILER=log` in dev (writes to `storage/logs/laravel.log`); `smtp` to deliver. |
| `FRONTEND_URL` | SPA origin used for links in outbound mail (the backend is a different origin from the Vite server). |
| `SANCTUM_STATEFUL_DOMAINS` | SPA auth domains. |

### Workers (`workers/.env`)

| Var | Purpose |
|-----|---------|
| `REDIS_URL` | Celery broker/backend + the `ai:intake` list. |
| `OPENROUTER_API_KEY` / `OPENROUTER_BASE_URL` / `LLM_MODEL` | Default OpenAI-compatible chat model for generation. |
| `AGENT_LLM_MODEL_CLAUDE` | OpenRouter model ID used when agent provider = Claude (default `anthropic/claude-sonnet-4-6`). |
| `AGENT_LLM_MODEL_GEMINI` | OpenRouter model ID used when agent provider = Gemini (default `google/gemini-2.5-flash`). |
| `AGENT_LLM_MODEL_CHATGPT` | OpenRouter model ID used when agent provider = ChatGPT (default `openai/gpt-4o`). |
| `LLM_MODEL_MY` | Myanmar-specific model override for OpenRouter (e.g. `WYNN747/Burmese-GPT`). Falls back to `LLM_MODEL` if unset. |
| `LLM_MODEL_TD` | Tedim-specific model override for OpenRouter. Falls back to `LLM_MODEL` if unset (note: low-resource language — a multilingual model is recommended). |
| `TEDIM_LLM_URL` | Base URL of the local Tedim FastAPI service (default `http://127.0.0.1:8001`). Must match in both `workers/.env` and `backend/.env`. |
| `BURMESE_LLM_URL` | Base URL of the local Burmese FastAPI service (default `http://127.0.0.1:8002`). Must match in both `workers/.env` and `backend/.env`. |
| `OLLAMA_MODEL_TD` | Ollama model name for Tedim (default `tedim-zolai`). Change to a fine-tuned GGUF name without any code change. |
| `OLLAMA_MODEL_MY` | Ollama model name for Burmese (default `burmese-myanmar`). |
| `OLLAMA_URL` | Ollama REST endpoint (default `http://127.0.0.1:11434/api/generate`). |
| `NLLB_HF_SPACE` | **Primary** Burmese translator — NLLB ZeroGPU Space as `owner/space` (e.g. `dalsuum/burmese-nllb`). Runs off-box on HF's GPU via `gradio_client`. Empty = skip straight to the local service. |
| `NLLB_HF_TOKEN` | Token for the Space (falls back to `HF_TOKEN`). |
| `NLLB_URL` | Local NLLB service URL used as the **fallback** when the Space is unreachable (default `http://127.0.0.1:8004`). |
| `NLLB_MODEL_ID` | NLLB model id or local path for the local service (the box uses an offline copy at `workers/local`). |
| `MMS_SPEECH_URL` / `MMS_TTS_URL` | Local MMS speech base URL used for Myanmar/Tedim narration and Voice Studio transcript checks (default `http://127.0.0.1:8003`). |
| `MMS_TTS_MODEL_TD` / `MMS_TTS_MODEL_MY` | Native VITS checkpoints for Tedim/Burmese narration (defaults `facebook/mms-tts-ctd` / `facebook/mms-tts-mya`). |
| `MMS_TTS_SEED` | Pinned VITS seed for reproducible narration (default `42`). |
| `MMS_TTS_TIMEOUT` | Per-request timeout for local MMS-TTS (default `180` seconds). Long message audio may be skipped/left text-only if the CPU model cannot finish in time. |
| `MMS_TTS_STAGGER_SECONDS` | Delay between Myanmar/Tedim MMS narration tasks (default `60`; keeps the small server from running several VITS generations at once). |
| `MMS_TTS_AUTO_ACTIVE` / `MMS_TTS_ACTIVE_MODELS_FILE` | Lets MMS-TTS automatically prefer the latest successful Voice Studio fine-tuned model from `active_models.json` before falling back to the stock checkpoints. |
| `MMS_ASR_MODEL` | MMS speech-to-text model used by Voice Studio transcript checks (default `facebook/mms-1b-all`; target languages `ctd` and `mya`). |
| `VOICE_TRAIN_ENABLED` | Enables the automatic Voice Studio trainer (default `true`). |
| `VOICE_TRAIN_WINDOW_START` / `VOICE_TRAIN_WINDOW_END` | Nightly training window (default `02:00` to `06:00`). |
| `VOICE_TRAIN_MAX_LOAD` | Maximum 1-minute load average allowed before a training run starts (default `2.0`). |
| `VOICE_TRAIN_MIN_CLIPS` / `VOICE_TRAIN_MIN_NEW_CLIPS` | Minimum dataset size and new clips since last successful model before training starts (defaults `300` / `25`). |
| `VOICE_TRAIN_COMMAND` | Shell script launched by `voice-studio:train-due` (default `workers/tools/run_mms_vits_finetune.sh`). |
| `LOCAL_LLM_TIMEOUT` | Timeout for local Myanmar/Tedim prose generation before fallback text is used (default `45` seconds). Increase if your ARM box is slow and the local model returns good text; keep low when fallback is preferable to waiting. |
| `EDGE_TTS_VOICE_MY` / `EDGE_TTS_VOICE_TD` | Legacy Edge TTS voice overrides. English still uses Edge voices; Myanmar/Tedim use MMS-TTS. |
| `BIBLE_DATA_FILE_MY` | Override the bundled Judson 1835 Burmese Bible with another same-schema translation. |
| `BIBLE_DATA_FILE_TD` | Override the bundled Lai Siangtho 1932 Tedim Bible. |
| `SUNO_MY_STYLE` | Suno style prompt for Myanmar hymn rendering (default: `traditional Burmese hymn`). |
| `SUNO_TD_STYLE` | Suno style prompt for Tedim hymn rendering (default: `Zomi choir`). |
| `SUNO_CUSTOM_MAX_LYRICS` | Maximum lyric characters sent to Suno customMode (default `2800`). |
| `LARAVEL_WEBHOOK_URL` | Where finished assets are posted (e.g. `http://localhost:8000/api/internal/asset-ready`). |
| `WORKER_WEBHOOK_SECRET` | Must match the backend's. |
| `SUNO_API_KEY` / `SUNO_API_URL` / `SUNO_MODEL` | Suno music generation. Fresh AI-composed songs (`music_source=suno`) use customMode so their generated lyrics can be displayed in the player. `SUNO_MODEL` defaults to `V5_5`; override to pin a specific model version. |
| `MUSICGEN_MODEL` | HuggingFace model for local AI music generation (default `facebook/musicgen-small`; upgrade to `musicgen-medium` or `musicgen-large` on a GPU server). |
| `MUSICGEN_DEVICE` | Device for MusicGen: `auto` (default — picks CUDA if available, else CPU), `cpu`, `cuda`, or `cuda:N`. Used by both `musicgen` and `local_ai` sources. |
| `MUSICGEN_MAX_TOKENS` | Tokens to generate; ~50 tokens ≈ 1 second (default `750` → ~15 s). |
| `MUSICGEN_LOCK_TTL` | Redis lock TTL in seconds to prevent concurrent generation (default `1800` — 30 min). |
| `YOUTUBE_API_KEY` | YouTube Data API search (only if `music_source=youtube`). |
| `TTS_API_KEY` / `TTS_BASE_URL` / `TTS_MODEL` / `TTS_VOICE` / `TTS_FORMAT` | Narration (`openai` voice). Absent ⇒ that mode off (browser speech still works). |
| `KOKORO_API_KEY` / `KOKORO_BASE_URL` / `KOKORO_MODEL` / `KOKORO_VOICE` / `KOKORO_FORMAT` | Narration (`kokoro` voice — hexgrad/kokoro-82m via OpenRouter). Defaults to the `OPENROUTER_*` LLM credentials. |
| `DID_API_KEY` / `DID_SOURCE_URL_FEMALE` / `DID_SOURCE_URL_MALE` / `DID_VOICE_ID_FEMALE` / `DID_VOICE_ID_MALE` / `DID_VOICE_PROVIDER` | Avatar (D-ID Talks API). Only `DID_API_KEY` is required to enable; source URLs, voice IDs (default `en-US-JennyNeural` / `en-US-GuyNeural`), and provider (default `microsoft`) fall back to defaults if absent. The key is the dashboard value in `base64(email):password` form and is sent verbatim as `Authorization: Basic <key>`. Legacy `D_ID_*` names are still read as a fallback. |
| `LOCAL_AVATAR_URL` / `LOCAL_AVATAR_IMAGE_FEMALE` / `LOCAL_AVATAR_IMAGE_MALE` | Free open-source local avatar generation. URL points to the avatar proxy (`http://127.0.0.1:8005/generate`), which bridges to a RunPod SadTalker endpoint (see [`workers/runpod_avatar`](workers/runpod_avatar/README.md)). Images are base portraits for male/female presenters, lip-synced to the segment's narration audio. |
| `RUNPOD_AVATAR_BASE_URL` / `AVATAR_PROXY_PORT` | RunPod serverless talking-head endpoint (`https://api.runpod.ai/v2/<id>`) used by [`avatar_proxy.py`](workers/avatar_proxy.py), and the port the proxy listens on (default 8005). Shares `RUNPOD_API_KEY` with the LLM/NLLB endpoints. |
| `LOCAL_MEDIA_DIR` / `LOCAL_MEDIA_URL` | **Set ⇒ local storage** (write into Laravel's `storage/app/public`, serve over HTTP). Unset ⇒ S3. |
| `S3_ENDPOINT` / `S3_ACCESS_KEY` / `S3_SECRET_KEY` / `S3_BUCKET` / `S3_REGION` | S3-compatible object storage (prod). |
| `BIBLE_DATA_FILE` | Override the bundled BSB with another same-schema translation. |

### Frontend (`frontend/.env`)

| Var | Purpose |
|-----|---------|
| `VITE_API_URL` | Backend API base (e.g. `http://localhost:8000/api`). |
| `VITE_STRIPE_KEY` | Stripe publishable key for the offering. |

---

## API reference

All routes are under `/api`. Authenticated routes use Sanctum SPA session-cookie auth
(HttpOnly cookie + `X-XSRF-TOKEN` header). The frontend calls `GET /sanctum/csrf-cookie`
before any state-changing request to bootstrap CSRF protection.

### Public

> Credential routes (`/guest`, `/register`, `/login`, `/forgot-password`, `/reset-password`) are rate-limited (`throttle:auth`, 5/min) keyed by **login email + IP** — so users behind a shared NAT don't lock each other out and an attacker can't drain one IP's budget by spraying unrelated accounts. The read-only `/auth/session` probe (polled on every page load) has its own generous limit (`throttle:60,1`) so it never consumes the credential bucket. Intake is throttled per user (`throttle:intake`); testimony submission per user (`throttle:testimony`).

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/config` | Intake/preparing options — `moods`, enabled `music_sources`, `scheduling_enabled`, `enabled_languages`, and text-only `countdown_cards`. Optional `mood`/`language` query params make countdown testimonies and Bible cards contextual. Read before a session exists. |
| `POST` | `/guest` | Start an anonymous guest session. |
| `POST` | `/register` | Create an account. |
| `POST` | `/login` | Log in; establishes HttpOnly session cookie. |
| `GET` | `/service/{token}/resume` | Email-link session resume — URL token acts as the credential, establishes HttpOnly session cookie. |
| `POST` | `/internal/asset-ready` | **Worker callback** — `X-Worker-Secret` header, no user auth. |
| `POST` | `/internal/music-track` | **Worker callback** — banks a fresh Suno track in the reuse pool (`X-Worker-Secret`, no user auth). |
| `POST` | `/webhooks/stripe` | **Stripe webhook** — signature-verified, no user auth. |
| `GET` | `/ads/active` | Return active ads matching `?language=`+`?mood=` (targeting filters applied server-side). |
| `POST` | `/ads/track` | Record an ad impression or click (rate-limited 60/min per IP). |

### Authenticated (`auth:sanctum`)

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/logout` | Revoke the current token. |
| `GET` | `/me` | Current user. |
| `PATCH` | `/me/music-source` | Set `hymn_sung` \| `hymn` \| `suno` \| `youtube`. |
| `PATCH` | `/me/presenter-gender` | Set `female` \| `male`. Locked onto the next session at start. |
| `PATCH` | `/me/email` | Update email address. |
| `POST` | `/me/change-password` | Change password. |
| `GET` | `/me/services` | List the current user's past services. |
| `POST` | `/service/start` | Create a session (locks music source). |
| `POST` | `/service/{token}/intake` | Submit mood + prayer + `language` (`en`/`my`/`td`, default `en`) + optional `scheduled_at`. Runs the crisis gate. |
| `GET` | `/service/{token}` | Poll session + assets (player fallback to WS). |
| `POST` | `/service/{token}/offering` | Open a Stripe PaymentIntent. |
| `GET` | `/testimonies` | Approved testimony wall. |
| `POST` | `/testimonies` | Submit a testimony (held for moderation). |

### Admin (`auth:sanctum` + `admin`)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/admin/dashboard` | Metrics (incl. prayer-request counts). |
| `GET` | `/admin/services` | List services. |
| `POST` | `/admin/services/{service}/retry` | Re-dispatch a service (clears existing assets first). |
| `DELETE` | `/admin/services/{service}` | Delete a service and all its assets. |
| `GET` | `/admin/testimonies` | Moderation queue (includes per-user custom moods). |
| `PATCH` | `/admin/testimonies/{testimony}/approve` | Approve. |
| `DELETE` | `/admin/testimonies/{testimony}` | Delete. |
| `GET` | `/admin/users` | List users. |
| `PATCH` | `/admin/users/{user}/admin` | Grant/revoke admin. |
| `PATCH` | `/admin/users/{user}/block` | Block/unblock a user. |
| `PATCH` | `/admin/users/{user}/presenter-gender` | Set presenter gender (`male`/`female`) for a user. |
| `DELETE` | `/admin/users/{user}` | Delete a user and their data. |
| `GET` | `/admin/donors` | Donation rollups. |
| `GET` | `/admin/prayer-requests` | Paginated log of prayer-request intakes. |
| `GET` | `/admin/settings` | Read global settings (permission-checked: `settings.view`). |
| `PATCH` | `/admin/settings` | (admin only) Update any of `narration_mode` / `narration_en` / `narration_my` / `narration_td` / `text_highlight_enabled` / `lang_en` / `lang_my` / `lang_td` / `countdown_content_enabled` / `countdown_content_source` / `countdown_banners` / `music_reuse` / `storage_backend` / `avatar_enabled` / `local_avatar_enabled` / `runpod_enabled` / `moods` / `music_sources` / `default_music_source` / `scheduling_enabled` / `ad_slot_enabled` / `ad_slot_html`. |
| `GET` | `/admin/music-tracks` | AI music pool (permission-checked: `music_pool.view`). Includes `suno`, `musicgen`, and `local_ai` sources. |
| `POST` | `/admin/music-tracks` | (admin only) Add a track to the pool (`source`: `suno`/`musicgen`/`local_ai`). |
| `PATCH` | `/admin/music-tracks/{id}` | (admin only) Update a pool track. |
| `DELETE` | `/admin/music-tracks/{id}` | (admin only) Delete a pool track. |
| `GET` | `/admin/grammar-review` | Language grammar review — list sentences from Tedim/Burmese data files (permission-checked: `language_review.view`). Query params: `lang` (`td`/`my`), `type` (`hymn_titles`/`hymn_lyrics`/`sermons`/`prayers`), `status` (`all`/`pending`/`approved`/`corrected`), `page`. |
| `POST` | `/admin/grammar-review` | Save an approval or correction for a sentence (`action`: `approve`/`correct`/`reset`; `key`; `correction` text). Persisted in `workers/data/grammar_review.json` (runtime-written, gitignored). The web user (`www-data`) must have write access to `workers/data/`; otherwise the endpoint returns a `500` with a clear "cannot write to data directory" message instead of saving silently. |
| `GET` | `/admin/permissions` | Role permission matrix (permission-checked: `permissions.view`). |
| `PATCH` | `/admin/permissions` | (admin only) Update the permission matrix. |
| `GET` | `/admin/export/{type}` | (admin only) CSV: `donations` \| `users` \| `testimonies`. |

---

## Project status

- **Phase 1 — Foundation:** auth, sessions, intake, migrations, Vue shell — **DONE**
- **Phase 2 — AI pipeline:** LLM engine, Celery tasks, Bible resolver, Redis bus — **DONE**
- **Phase 3 — Media:** hymn (sung + instrumental) + Suno + YouTube strategies, the
  mood-keyed Suno reuse pool, and local/S3 storage — **DONE**; narration (TTS) — **DONE**
  (browser speech locally, OpenAI-compatible server path); D-ID avatar — **DONE**
- **Phase 4 — Commerce + Safety:** crisis intercept + classifier — **DONE**; Stripe
  offering + ledger — **DONE**
- **Phase 5 — Polish:** progress tracker, testimony wall, admin console, scheduled
  services — **DONE**
- **Phase 6 — Hardening:** security headers (HSTS/CSP/X-Frame-Options), rate limiting on
  auth/intake/testimony, user blocking + deletion, custom mood free-text, prayer-request
  admin view, email-link session resume, service deletion + confirmation notifications — **DONE**;
  security audit remediation (webhook fail-closed on missing/short secret, password-reset
  tokens hashed at rest, resume endpoint throttled + short-lived Sanctum token, CSV-injection
  sanitization on admin exports, ffmpeg detail stripped from API error responses) — **DONE**;
  follow-up audit fixes (hash SHA-256 token in `createUser` path to match `resetPassword`
  lookup, add `\n` to CSV injection guard, migrate admin token from `localStorage` to
  `sessionStorage`) — **DONE**
- **Phase 7 — Multilingual:** Myanmar (`my`) and Tedim (`td`) language selection, bundled
  Judson 1835 (Myanmar) and Lai Siangtho 1932 (Tedim) Bible corpora, language-specific
  narration voices, local Ollama LLM services (FastAPI + `tedim-zolai` + `burmese-myanmar`
  models), direct target-language generation with legacy localization jobs disabled for
  new services, Myanmar 852-hymn library (Suno customMode), Tedim hymnal (YouTube embed →
  Suno → instrumental), seeder tools (`seed_language_data.py`, `seed_tedim_hymns.py`,
  `seed_tedim_midi.py`) — **DONE**
- **Phase 8 — Presenter UX:** presenter gender selection (`female`/`male`) per worshipper,
  locked per session, with sermon/support voice pairing; `avatar_enabled` admin toggle;
  `welcome` segment added to the segment enum — **DONE**
- **Phase 9 — AI Agent Orchestration:** dual-mode orchestration (`pipeline` / `agent`)
  toggled at runtime from Admin Settings with no restart; `workers/agent_orchestrator.py`
  implements a pre-dispatch + 9-tool agent pattern via OpenRouter (Claude/Gemini/ChatGPT):
  music + welcome dispatched before the agent loop so all providers reliably produce a
  full 7-page service; agent provider selector (`claude` / `gemini` / `chatgpt`) stored
  in Redis for instant hot-swap; preparation screen replaced with a live segment-progress
  checklist (doors open the instant all required steps are ready, no fixed countdown);
  dedicated `ai:orchestrate` queue (2-worker pool) so new services are never queued behind
  long content-generation tasks; LLM safe-fallbacks now cover all languages (English,
  Myanmar, Tedim) — any segment that fails at OpenRouter or local Ollama returns a
  hardcoded fallback instead of leaving the service frozen — **DONE**
- **Phase 10 — Music Unification + Admin Hardening:** unified `SungHymnStrategy` /
  `InstrumentalHymnStrategy` replaces per-language hymn strategy overrides; new `local_ai`
  music source (GPU-preferred MusicGen, `MUSICGEN_DEVICE=auto`); `HymnYouTubeStrategy`
  is now language-aware (Tedim/Burmese native search before English fallback); MMS-TTS
  number normalization (`_spell_tedim` / `_spell_burmese`) prevents silent digit output;
  agent robustness: LLM retry with exponential backoff, tolerant JSON argument parsing,
  MAX_TURNS recovery, token telemetry; `review_content` tool removed — safety classifier
  enforced inside `post_text_segment`; Language Grammar Review admin tab for Tedim/Burmese
  data QA; fine-grained permission checks on all admin reads (non-admins can view
  permitted tabs without full admin); RunPod Premium GPU toggle; ad slot settings;
  AI music pool extended to cover `musicgen` / `local_ai` sources — **DONE**

- **Phase 11 — Burmese Quality + Test Infrastructure:** Burmese output plausibility guard
  (`_is_my_plausible`) fires a safe fallback when the local Ollama model returns
  fragmentary output for any service segment (welcome/prayer/sermon/benediction); Myanmar
  lyrics corpus collector (`tools/collect_myanmar_lyrics.py` → `data/myanmar_lyrics_collection.json`)
  for richer Suno prompts and future fine-tuning; unit test suites for `llm_engine` and
  `agent_orchestrator` covering formatting, language guards, and agent loop robustness — **DONE**

**Known gaps / next steps:** real WebSocket push (Reverb/Echo wiring is stubbed; polling
works today), a production-grade crisis classifier extended to
Burmese and Tedim keywords, rights for a non-public-domain Bible translation, fine-tuned
Tedim GGUF (current `tedim-zolai` uses `llama3.2:1b` as base — quality improves
significantly with a Tedim-corpus fine-tune on a larger model), a Vue bilingual segment
component for side-by-side English plus target-language text.

---

## Acknowledgements — free AI services

This project is built almost entirely on **free and open-source AI services**. The
following providers make it possible to run a full AI worship pipeline at zero AI cost.

### Free LLM (Language Models)

| Provider | What we use it for | Why we're grateful |
|----------|-------------------|--------------------|
| **[OpenRouter](https://openrouter.ai)** | Sermon, prayer, benediction, and intake-plan generation via the free-tier OpenAI-compatible chat endpoint | Offers a rotating set of capable free models (e.g. Mistral, Gemma, Llama variants) behind a single API — no per-model keys or infra to manage |
| **[Ollama](https://ollama.com)** | Local inference host for the `tedim-zolai` and `burmese-myanmar` custom Modelfiles | Runs quantized LLaMA-family models on CPU with no GPU, no cloud cost, and no data leaving the server — essential for low-resource Chin and Burmese generation |
| **[Meta — Llama 3.2 1B](https://llama.meta.com)** | Base model for both the Tedim (`tedim-zolai`) and Burmese (`burmese-myanmar`) Ollama Modelfiles | Released under the Meta Llama Community License for free commercial and research use; small enough to run on a shared ARM/OCI box with 4 GB RAM |

### Free Speech

| Provider | What we use it for | Why we're grateful |
|----------|-------------------|--------------------|
| **[Facebook MMS-TTS](https://huggingface.co/facebook/mms-tts)** | Native narration for Myanmar (`facebook/mms-tts-mya`) and Tedim/Zolai (`facebook/mms-tts-ctd`) | Part of Meta's Massively Multilingual Speech project — one of the very few publicly available TTS systems that supports Tedim (Chin) and Burmese with a proper VITS voice, not an English voice guessing at the script |
| **[Facebook MMS-ASR](https://huggingface.co/facebook/mms-1b-all)** | Optional Voice Studio transcript checks for Tedim (`ctd`) and Burmese (`mya`) clips | Lets us catch silent/mismatched recordings before they enter a fine-tuning dataset |
| **[Microsoft Edge TTS](https://github.com/rany2/edge-tts)** | English narration (`en-US-AriaNeural` / `en-US-GuyNeural`) in `edge_tts` mode | High-quality neural voices available free via the Edge read-aloud API, with no key required for reasonable usage |
| **[Browser Web Speech API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Speech_API)** | Client-side narration (`browser` mode) — works out of the box on any modern browser, no server or key needed | Zero cost, zero setup; the default narration mode for local development |
| **[Hugging Face Hub](https://huggingface.co)** | Model distribution for MMS-TTS checkpoints (`transformers` auto-download on first use) | Hosts and serves all the MMS-VITS model weights for free, making multilingual TTS accessible without self-hosting model files |

> A special thank-you to the **Meta AI Research** team behind the
> [Massively Multilingual Speech (MMS)](https://ai.meta.com/research/publications/scaling-speech-technology-to-1000-languages/)
> project. The inclusion of Tedim (Zolai) and Burmese voices — languages spoken by a
> relatively small number of people — makes it possible for this project to serve
> Chin and Myanmar-speaking worshippers in their own language and voice.

### YouTube Content Creators

When the `youtube` music source is selected, worship tracks and sermons are surfaced via
the **YouTube Data API v3** and played through the **official YouTube embedded player** —
no audio is downloaded, re-hosted, or stripped of ads.

**Creators are credited and compensated exactly as they would be on YouTube.com:**

| What happens | Why it matters to creators |
|---|---|
| Videos play inside the official `<iframe>` embed | YouTube's ad system runs normally — pre-roll/mid-roll ads still display |
| Only `videoEmbeddable: true` videos are selected | We only ever surface content the creator has explicitly allowed to be embedded |
| Every play is counted by YouTube | Watch-time, view counts, and YouTube Premium revenue shares accrue to the channel as usual |
| Channel name is shown in the player | Worshippers can click through to subscribe or explore the creator's full library |

The system searches specifically for Christian worship channels and artists. Among those
whose content regularly appears in English, Myanmar, and Tedim/Zolai services:

**English** — Hillsong, Planetshakers, Elevation Worship, Bethel Music, Maverick City
Music, Don Moen, Chris Tomlin, Phil Wickham, and many independent worship leaders.

**Myanmar (မြန်မာ)** — Grace Full Gospel, Thang Taung, Sangpi, David Lah 100% Jesus,
Kaung Kaung, Susanna Min, Khual Pi, and Myanmar-language church channels.

**Tedim / Zolai (ဇိုမိ)** — Zomi Worship Collective, Phillip Ruth, We Worship,
FEMC Worship, ZACC Worship, Khai Pi, Cin Bawi, and Zomi community church channels.

If you are a creator whose video has been embedded here and you would prefer it not be
used, you can disable embedding on your YouTube video settings — the system filters on
`videoEmbeddable: true`, so your video will automatically be excluded from all future
searches.

### Hymn MIDI, lyrics & worship-song sources

The instrumental hymn renders (`hymn`/`hymn_sung` sources) and on-screen lyrics are built
**once per machine** by the seed scripts from the public-domain and freely published
sources below — the files are then stored locally and served from there, never
re-downloaded per service. We are grateful to each of these communities for keeping
hymns and worship lyrics freely available, and we credit them here in full.

| Source | What we use | Used by |
|--------|-------------|---------|
| **[Open Hymnal Project](http://openhymnal.org)** | Public-domain English hymn MIDI bundle (`OpenHymnal2014.06`) rendered to instrumental MP3, plus the matching public-domain verse lyrics | [seed_hymns.py](workers/seed_hymns.py) |
| **[tedimhymn.com](https://tedimhymn.com/)** | *Tedim Hymn 7th Edition* MIDI tune library, rendered to instrumental MP3 as the last-resort fallback for Tedim/Zolai hymns | [tools/seed_tedim_midi.py](workers/tools/seed_tedim_midi.py) |
| **[Nikon Ghong — Laibu Saal](https://nikonghong.com/laibu-saal/)** | Tedim/Zolai hymn lyrics and song texts | [data/hymns_td.json](workers/data/hymns_td.json) |
| **[Myanmar Praise and Worship Songs](http://myanmarpraiseandworshipsongs.com/)** | Myanmar Christian worship lyrics and song texts | [tools/collect_myanmar_lyrics.py](workers/tools/collect_myanmar_lyrics.py) |

All hymn audio is generated locally (MIDI → WAV via **fluidsynth** + the FluidR3 GM
soundfont → MP3 via **ffmpeg**); no proprietary recordings are copied or re-hosted. If you
maintain one of these collections and would prefer your material not be used, please reach
out and it will be removed.
