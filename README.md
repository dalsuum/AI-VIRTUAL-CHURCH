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
- [Music: four sources + a reuse pool](#music-four-sources--a-reuse-pool)
- [Narration & avatar (optional enrichments)](#narration--avatar-optional-enrichments)
- [Safety: the crisis intercept](#safety-the-crisis-intercept)
- [Offering & financial ledger](#offering--financial-ledger)
- [Testimonies](#testimonies)
- [Admin console](#admin-console)
- [Scheduled services](#scheduled-services)
- [Running locally](#running-locally)
- [Environment variables](#environment-variables)
- [API reference](#api-reference)
- [Project status](#project-status)

---

## Architecture at a glance

```
┌────────────┐    HTTPS/JSON     ┌──────────────────┐   rpush JSON    ┌─────────────┐
│  Vue 3 SPA │ ───────────────▶  │  Laravel 11 API  │ ──────────────▶ │ Redis list  │
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
                                                          └─────────────────┘   └──────────────────┘
```

**Why a Redis list and not a shared Celery broker?** Laravel and Python don't share a
task serializer. `DispatchServiceJob` just `rpush`es a language-agnostic JSON blob onto
`ai:intake`; `bridge.py` `BLPOP`s it and hands it to the Celery orchestrator. The
contract between the two worlds is "a JSON object on a list," nothing more.

---

## The service flow, end to end

1. **Auth.** The worshipper registers, logs in, or starts as a **guest** (an anonymous
   `*@guest.local` account is minted). Auth is Laravel Sanctum bearer tokens.
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
   **talking-head avatar video** (HeyGen) and/or **narration audio** (TTS).
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

Laravel 11 (PHP 8.2+), Sanctum auth, Stripe PHP SDK. Owns everything stateful and
everything that must be trustworthy: users, sessions, the safety gate, the money ledger,
moderation, and the admin console.

| Area | File |
|------|------|
| Auth (register / login / guest / me / music-source) | [AuthController.php](backend/app/Http/Controllers/AuthController.php) |
| Service lifecycle (start / intake / show) | [ServiceController.php](backend/app/Http/Controllers/ServiceController.php) |
| Laravel→Python dispatch | [DispatchServiceJob.php](backend/app/Jobs/DispatchServiceJob.php) |
| Worker callback (asset-ready) | [WebhookController.php](backend/app/Http/Controllers/WebhookController.php) |
| Crisis safety gate | [CrisisInterceptService.php](backend/app/Services/CrisisInterceptService.php) |
| Offering / Stripe | [OfferingController.php](backend/app/Http/Controllers/OfferingController.php), [OfferingService.php](backend/app/Services/OfferingService.php) |
| Testimonies | [TestimonyController.php](backend/app/Http/Controllers/TestimonyController.php) |
| Admin console | [AdminController.php](backend/app/Http/Controllers/AdminController.php), [EnsureAdmin.php](backend/app/Http/Middleware/EnsureAdmin.php) |
| Scheduled-service release | [DispatchDueServices.php](backend/app/Console/Commands/DispatchDueServices.php) |
| Routes | [api.php](backend/routes/api.php) |

### Workers (Python / Celery)

A single Celery app with one Redis broker and **named queues that mirror the work**:

| Queue | Task | Does |
|-------|------|------|
| `ai:sermon` | `orchestrate`, `generate_text_segments`, `generate_welcome` | LLM plan + prayer / sermon / benediction + welcome greeting |
| `ai:music` | `generate_music` | Hymn, Suno, or YouTube, resolved per session |
| `ai:avatar` | `render_avatar` | HeyGen talking-head video |
| `ai:narration` | `narrate` | text-to-speech of the spoken segments |

| Module | Responsibility |
|--------|----------------|
| [bridge.py](workers/bridge.py) | `BLPOP ai:intake` → `orchestrate.delay()`. The Laravel↔Python seam. |
| [tasks/\_\_init\_\_.py](workers/tasks/__init__.py) | The orchestrator and all generation tasks; posts assets back to Laravel. |
| [tasks/celery_app.py](workers/tasks/celery_app.py) | Celery config + queue routing. |
| [llm_engine.py](workers/llm_engine.py) | Intake plan + spoken-segment generation via an OpenAI-compatible chat endpoint (OpenRouter by default). Strips markdown / stage directions to clean spoken prose. |
| [bible_api.py](workers/bible_api.py) | Resolves a scripture *reference* to verse *text* from a bundled public-domain Berean Standard Bible (no key, no network). The model never writes scripture. |
| [classifier.py](workers/classifier.py) | Post-generation deny-list guardrail (`review() → (ok, reason)`). |
| [strategies/](workers/strategies/) | `MusicStrategy` interface + `HymnStrategy` / `SunoStrategy` / `YouTubeStrategy`, returning a normalized `MusicResult`. |
| [hymns.py](workers/hymns.py) / [seed_hymns.py](workers/seed_hymns.py) | Public-domain hymn library (lyrics + recordings) and the one-time seeder that renders/downloads it into storage. |
| [avatar.py](workers/avatar.py) | HeyGen render (submit → poll → store → URL). Key-gated. |
| [narrator.py](workers/narrator.py) | OpenAI-compatible `/audio/speech` TTS, chunked on sentence boundaries. Key-gated. |
| [storage.py](workers/storage.py) | Object storage with two interchangeable backends: **local dir** (dev) or **S3** (prod). |

### Frontend (Vue 3)

Vue 3 + Vite, Stripe.js for the offering. A thin SPA whose job is to walk through the
service one stage at a time.

| Component | Role |
|-----------|------|
| [App.vue](frontend/src/App.vue) | Stage machine: `intake` → `preparing` → `service`; routes `/admin` to the console. |
| [IntakeForm.vue](frontend/src/components/IntakeForm.vue) | Mood + prayer + (optional) schedule + music-source pick. Moods, the offered music sources, and whether scheduling shows are all driven by `GET /config` so the admin can curate them. |
| [PreparingView.vue](frontend/src/components/PreparingView.vue) | Countdown screen; shows the welcome-back greeting while segments compose. |
| [ServicePlayer.vue](frontend/src/components/ServicePlayer.vue) | The full-screen, one-stage-at-a-time player. Auto-reads each segment (server video → server audio → browser Web Speech), auto-advances. |
| [MusicPlayer.vue](frontend/src/components/MusicPlayer.vue) | Plays the worship track: stored audio, or an embedded YouTube `<iframe>`. |
| [OfferingForm.vue](frontend/src/components/OfferingForm.vue) | Stripe PaymentIntent confirmation. |
| [TestimonyWall.vue](frontend/src/components/TestimonyWall.vue) | The approved testimony wall + submit-your-own. |
| [AdminConsole.vue](frontend/src/components/AdminConsole.vue) | Dashboard, service retries, moderation, users, donors, CSV export. |
| [useApi.js](frontend/src/composables/useApi.js) / [useTheme.js](frontend/src/composables/useTheme.js) | API client + light/dark theme. |

---

## Data model

| Table | Purpose | Notable columns |
|-------|---------|-----------------|
| `users` | Worshippers (incl. guests) | `music_source` enum (`hymn_sung`/`hymn`/`suno`/`youtube`, default `hymn_sung`), `name_provided` (false ⇒ display-only placeholder, kept out of the spoken service), `is_admin`, `timezone` |
| `service_sessions` | One worship visit | `session_token` (64), `status` (`initializing`/`active`/`completed`/`abandoned`/`scheduled`), `music_source` (locked), `scheduled_at` |
| `service_intakes` | The user's input + the plan | `mood`, `prayer_text`, `scripture_ref`, `music_prompt`, `music_query` (1:1 with session) |
| `service_assets` | Generated segments | `segment` enum, `asset_type` (`video`/`audio`/`text`/`url`/`youtube`), `storage_key`, `audio_key`, `provider_ref`, `text_payload`, `lyrics` (hymn verses for on-screen display), `status` |
| `music_tracks` | Mood-keyed reuse pool | `mood`, `provider_ref` (unique — dedupes), `storage_key`, `title`, `source`. Populated by the worker after each fresh Suno generation; drawn from when a worshipper is new to a mood. |
| `settings` | Global admin key/value | `key` (PK) / `value` (string). Holds `narration_mode`, `music_reuse`, `storage_backend`, plus the admin-curated intake options: `moods` and `music_sources` (JSON lists) and `scheduling_enabled`. |
| `prayer_requests` | Raw intake log + token accounting | `raw_input`, `extracted_mood`, `tokens_used` |
| `testimonies` | Shared testimonies | `content`, `source` (`user_submitted`/`ai_generated`), `approved` |
| `financial_ledger` | Offerings | `amount`, `currency`, `transaction_hash` (idempotency), `allocation_type` (`operations`/`charity`/`missions`) |
| `crisis_intercepts` | Safety audit log | `session_hash` (sha256, not the raw token), `trigger_keyword`, `resource_served` |

**Segments:** `worship`, `opening_prayer`, `scripture`, `sermon`, `testimony`,
`offering`, `closing_hymn`, `benediction`.

---

## The segment pipeline in detail

`generate_text_segments` is where the service takes shape:

1. **Scripture** — the model picked a reference in the plan; `bible_api.resolve()`
   supplies the words from the bundled BSB. If the reference is unparseable, the
   worshipper still gets the *reference* rather than an aborted segment. Scripture is
   shown as written (gets narration, but **no avatar**).
2. **Opening prayer / sermon / benediction** — each generated from name + mood
   (+ prayer text / scripture ref), then run through `classifier.review()`. Blocked
   content is replaced with `"(content withheld pending review)"`. Surviving text is
   posted as the segment, and — if enabled — fanned out to `render_avatar` and `narrate`.
3. **Music** (`generate_music`) — resolves the locked `music_source` to a strategy and
   posts the result to **both** the `worship` and `closing_hymn` segments.

The webhook is **idempotent per (session, segment)**: text arrives first, then a later
narration pass fills `audio_key` and a later avatar pass flips `asset_type` to `video`
— each pass falls back to existing values for fields it doesn't carry, so nothing is
erased.

---

## Music: four sources + a reuse pool

Each user picks one (stored on `users.music_source`, **locked per session**). All four
implement `MusicStrategy.fetch()` and return the same `MusicResult` (`asset_type`,
`storage_key`, `provider_ref`, `title`, `lyrics`), so the orchestrator, the webhook, and
the player are all source-agnostic. Add a source by implementing one class in
[strategies/](workers/strategies/).

- **hymn_sung** *(default)* — a real **sung** public-domain recording (Internet Archive
  78rpm, ≤1925) of a hymn matched to the worshipper's mood. No AI, no provider call, no
  cost at service time. Only the ~10 hymns with a recording are eligible here, so the
  worshipper always hears voices.
- **hymn** — the same mood-matched hymn rendered **instrumental** (MIDI→MP3) with the
  **public-domain lyrics shown on screen**. Every seeded hymn is eligible.
- **suno** — original worship music **generated by AI** from a text prompt, stored in
  object storage and served as an audio file.
- **youtube** — an existing worship track found via the YouTube Data API and embedded via
  the official player (`videoEmbeddable` + `videoSyndicated`, Music category, strict
  safe-search). **No audio is downloaded or stored** — downloading would violate YouTube's
  ToS, so we only ever embed.

The hymn library (lyrics, sung recordings, instrumental renders) is produced ahead of
time by `seed_hymns.py` and read from the same storage backend the worker uses — see
[Running locally](#running-locally).

**The Suno reuse pool.** Composing fresh AI music costs money and time, so completed Suno
tracks are banked in `music_tracks`, keyed by mood (deduped by `provider_ref` via the
[`/internal/music-track`](#public) webhook). When the pool is enabled
(`music_reuse` setting, on by default), [DispatchServiceJob](backend/app/Jobs/DispatchServiceJob.php)
decides per service: if **this** worshipper has heard **this** mood before, it composes a
fresh song so a returning visitor never gets a repeat; if they're **new** to the mood, it
hands them a random track already composed for it — instant and free. Hymn and YouTube
sources never touch the pool.

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
  - `off` — segments stay as silent text.

  In a server-voice mode (`openai`/`kokoro`) the player waits for each segment's mp3 to
  land, then plays it — the browser Web Speech voice is used **only** in `browser` mode,
  never as a fallback for the realistic voices. Within a stage the player still falls
  back through **server video → server audio → browser speech** based on what's present.
- **Avatar** — [avatar.py](workers/avatar.py) renders a HeyGen talking-head of the
  spoken segments (submit → poll → store → URL). Requires `HEYGEN_API_KEY` +
  `HEYGEN_AVATAR_ID` + `HEYGEN_VOICE_ID`. **Stub-level** in the current build.

> See the project memory notes for the known-good local narration setup (browser speech
> works out of the box; the server S3 path needs credits + storage).

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

## Offering & financial ledger

The offering segment opens a **Stripe PaymentIntent** server-side
(`POST /service/{token}/offering`); the browser confirms it with Stripe.js. The
allocation (`operations` / `charity` / `missions`) is carried in the intent metadata and
**read back on the webhook**, so the ledger row is fully attributed without trusting
anything the client re-sends. Amounts are bounded (min 1.00), and
`transaction_hash` is a unique idempotency key so a replayed webhook can't double-record.
Stripe webhooks are signature-verified.

---

## Testimonies

`GET /testimonies` returns the **approved** wall; `POST /testimonies` submits one, held
unapproved for moderation. Testimonies are either `user_submitted` or `ai_generated`.
Approval and deletion happen in the admin console.

---

## Admin console

`/admin` routes require an `is_admin` account (`admin` middleware → [EnsureAdmin.php](backend/app/Http/Middleware/EnsureAdmin.php)):

- **Dashboard** — sessions, worship-time totals, donations, intercept counts.
- **Services** — list + **retry** a failed/stuck service (re-dispatches the pipeline).
- **Testimonies** — approve / delete (moderation queue).
- **Users** — list + grant/revoke admin.
- **Donors** — donation rollups.
- **Settings** — global service config persisted in the `settings` table and threaded
  onto each job: `narration_mode` (`off`/`browser`/`openai`/`kokoro`), `music_reuse`
  (the Suno pool toggle), and `storage_backend` (`local` vs `s3` for generated audio).
  Plus the worshipper-facing **intake options** an admin curates without a redeploy:
  the **moods** offered at intake (add/remove — a new mood flows through the whole
  pipeline: the prayer/sermon tone, the music prompt, and hymn matching), which
  **music sources** appear (toggle any of sung-hymn/instrumental/AI-composed/YouTube,
  at least one on), and whether **scheduling** is offered. These are served to the
  intake form via the public [`GET /config`](#public).
- **Export** — CSV of `donations` | `users` | `testimonies`.

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
| `QUEUE_CONNECTION` | `redis` (so `DispatchServiceJob` runs via `queue:work`). |
| `WORKER_WEBHOOK_SECRET` | Shared secret the workers send as `X-Worker-Secret`. Must match the workers' value. |
| `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` / `STRIPE_CURRENCY` | Offering. `STRIPE_WEBHOOK_SECRET` is the `whsec_…` from `stripe listen`. |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` / … / `MAIL_FROM_*` | Scheduled-service reminder mail. Use `MAIL_MAILER=log` in dev (writes to `storage/logs/laravel.log`); `smtp` to deliver. |
| `FRONTEND_URL` | SPA origin used for links in outbound mail (the backend is a different origin from the Vite server). |
| `SANCTUM_STATEFUL_DOMAINS` | SPA auth domains. |

### Workers (`workers/.env`)

| Var | Purpose |
|-----|---------|
| `REDIS_URL` | Celery broker/backend + the `ai:intake` list. |
| `OPENROUTER_API_KEY` / `OPENROUTER_BASE_URL` / `LLM_MODEL` | The LLM. |
| `LARAVEL_WEBHOOK_URL` | Where finished assets are posted (e.g. `http://localhost:8000/api/internal/asset-ready`). |
| `WORKER_WEBHOOK_SECRET` | Must match the backend's. |
| `SUNO_API_KEY` / `SUNO_API_URL` | Suno music generation (only if `music_source=suno`). |
| `YOUTUBE_API_KEY` | YouTube Data API search (only if `music_source=youtube`). |
| `TTS_API_KEY` / `TTS_BASE_URL` / `TTS_MODEL` / `TTS_VOICE` / `TTS_FORMAT` | Narration (`openai` voice). Absent ⇒ that mode off (browser speech still works). |
| `KOKORO_API_KEY` / `KOKORO_BASE_URL` / `KOKORO_MODEL` / `KOKORO_VOICE` / `KOKORO_FORMAT` | Narration (`kokoro` voice — hexgrad/kokoro-82m via OpenRouter). Defaults to the `OPENROUTER_*` LLM credentials. |
| `HEYGEN_API_KEY` / `HEYGEN_AVATAR_ID` / `HEYGEN_VOICE_ID` / `HEYGEN_API_BASE` | Avatar. All three IDs required to enable. |
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

All routes are under `/api`. Authenticated routes use a Sanctum bearer token.

### Public

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/config` | Intake options — `moods`, enabled `music_sources`, `scheduling_enabled`. Read by the intake form before a session exists. |
| `POST` | `/guest` | Start an anonymous guest session. |
| `POST` | `/register` | Create an account. |
| `POST` | `/login` | Log in, returns a token. |
| `POST` | `/internal/asset-ready` | **Worker callback** — `X-Worker-Secret` header, no user auth. |
| `POST` | `/internal/music-track` | **Worker callback** — banks a fresh Suno track in the reuse pool (`X-Worker-Secret`, no user auth). |
| `POST` | `/webhooks/stripe` | **Stripe webhook** — signature-verified, no user auth. |

### Authenticated (`auth:sanctum`)

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/logout` | Revoke the current token. |
| `GET` | `/me` | Current user. |
| `PATCH` | `/me/music-source` | Set `hymn_sung` \| `hymn` \| `suno` \| `youtube`. |
| `POST` | `/service/start` | Create a session (locks music source). |
| `POST` | `/service/{token}/intake` | Submit mood + prayer (+ optional `scheduled_at`). Runs the crisis gate. |
| `GET` | `/service/{token}` | Poll session + assets (player fallback to WS). |
| `POST` | `/service/{token}/offering` | Open a Stripe PaymentIntent. |
| `GET` | `/testimonies` | Approved testimony wall. |
| `POST` | `/testimonies` | Submit a testimony (held for moderation). |

### Admin (`auth:sanctum` + `admin`)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/admin/dashboard` | Metrics. |
| `GET` | `/admin/services` | List services. |
| `POST` | `/admin/services/{service}/retry` | Re-dispatch a service. |
| `GET` | `/admin/testimonies` | Moderation queue. |
| `PATCH` | `/admin/testimonies/{testimony}/approve` | Approve. |
| `DELETE` | `/admin/testimonies/{testimony}` | Delete. |
| `GET` | `/admin/users` | List users. |
| `PATCH` | `/admin/users/{user}/admin` | Grant/revoke admin. |
| `GET` | `/admin/donors` | Donation rollups. |
| `GET` | `/admin/settings` | Read global settings (narration mode, music reuse, storage backend, moods, music sources, scheduling). |
| `PATCH` | `/admin/settings` | Update any of `narration_mode` / `music_reuse` / `storage_backend` / `moods` / `music_sources` / `scheduling_enabled`. |
| `GET` | `/admin/export/{type}` | CSV: `donations` \| `users` \| `testimonies`. |

---

## Project status

- **Phase 1 — Foundation:** auth, sessions, intake, migrations, Vue shell — **DONE**
- **Phase 2 — AI pipeline:** LLM engine, Celery tasks, Bible resolver, Redis bus — **DONE**
- **Phase 3 — Media:** hymn (sung + instrumental) + Suno + YouTube strategies, the
  mood-keyed Suno reuse pool, and local/S3 storage — **DONE**; narration (TTS) — **DONE**
  (browser speech locally, OpenAI-compatible server path); HeyGen avatar — **STUB**
- **Phase 4 — Commerce + Safety:** crisis intercept + classifier — **DONE**; Stripe
  offering + ledger — **DONE**
- **Phase 5 — Polish:** progress tracker, testimony wall, admin console, scheduled
  services — **DONE**

**Known gaps / next steps:** real WebSocket push (Reverb/Echo wiring is stubbed; polling
works today), HeyGen avatar beyond stub, a production-grade crisis classifier (vs. the
illustrative keyword list), and rights for a non-public-domain Bible translation.
