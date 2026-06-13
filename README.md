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
- [Multilingual services (Myanmar & Tedim)](#multilingual-services-myanmar--tedim)
- [Music: four sources + a reuse pool](#music-four-sources--a-reuse-pool)
- [Narration & avatar (optional enrichments)](#narration--avatar-optional-enrichments)
- [Safety: the crisis intercept](#safety-the-crisis-intercept)
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
                                                          │ my/td direct    │   └──────────────────┘
                                                          └────────┬────────┘
                                                                   │ HTTP (localhost)
                                                          ┌────────▼────────┐
                                                          │  Language APIs  │   ┌──────────────────┐
                                                          │  FastAPI :8001  │──▶│  Ollama          │
                                                          │  /tedim/* (td)  │   │  tedim-zolai 1b  │
                                                          │  FastAPI :8002  │──▶│  burmese-myanmar │
                                                          │  /burmese/* (my)│   └──────────────────┘
                                                          └─────────────────┘
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

### Workers (Python / Celery)

A single Celery app with one Redis broker and **named queues that mirror the work**:

| Queue | Task | Does |
|-------|------|------|
| `ai:sermon` | `orchestrate`, `generate_text_segments`, `generate_welcome` | LLM plan + direct target-language prayer / sermon / benediction + welcome greeting. Legacy `localize_segment_*` tasks remain importable but are not dispatched for new services. |
| `ai:music` | `generate_music` | Hymn, Suno, or YouTube, resolved per session |
| `ai:avatar` | `render_avatar` | HeyGen talking-head video |
| `ai:narration` | `narrate`, `narrate_tedim`, `narrate_burmese` | text-to-speech of the spoken segments (English Edge/OpenAI/Kokoro; Myanmar/Tedim via local MMS-TTS) |

| Module | Responsibility |
|--------|----------------|
| [bridge.py](workers/bridge.py) | `BLPOP ai:intake` → `orchestrate.delay()`. The Laravel↔Python seam. |
| [tasks/\_\_init\_\_.py](workers/tasks/__init__.py) | The orchestrator and all generation tasks; posts assets back to Laravel. |
| [tasks/celery_app.py](workers/tasks/celery_app.py) | Celery config + queue routing. |
| [tasks/celery_tedim_tasks.py](workers/tasks/celery_tedim_tasks.py) | Legacy Tedim localization/narration tasks kept for compatibility. New services use `generate_text_segments(..., language='td')` and `tasks.narrate(..., language='td')`. |
| [tasks/celery_burmese_tasks.py](workers/tasks/celery_burmese_tasks.py) | Legacy Myanmar localization/narration tasks kept for compatibility. New services use `generate_text_segments(..., language='my')` and `tasks.narrate(..., language='my')`. |
| [tedim_router.py](workers/tedim_router.py) | FastAPI router: `POST /tedim/translate`, `POST /tedim/generate`, `GET /tedim/verse?ref=`. Redis db 2 cache (30-day TTL). Single-inference semaphore. `_validate_tedim()` rejects model output that is too short (<60 chars) or lacks Tedim markers (returns HTTP 502 so `llm_engine` falls back to hardcoded Tedim content instead of storing degenerate output). |
| [burmese_router.py](workers/burmese_router.py) | FastAPI router: `POST /burmese/translate`, `POST /burmese/generate`, `GET /burmese/verse?ref=`. Redis db 3 cache (30-day TTL). Shares the same semaphore pattern as the Tedim router. Myanmar Unicode only — no Zawgyi. |
| [api.py](workers/api.py) | Unified FastAPI app mounting Tedim, Burmese, and `/tts/speak` MMS-TTS routers plus `/health`. Typically run as two separate uvicorn instances: port 8001 (`aivc-tedim-api`) for Tedim/MMS requests, port 8002 (`aivc-burmese-api`) for Burmese. |
| [hymns_my.py](workers/hymns_my.py) | Loader for the 852-song `data/hymns_my.json` Burmese library; mood-based selection for `MyanmarHymnStrategy`. |
| [hymns_td.py](workers/hymns_td.py) | Loader for the seeded `data/hymns_td.json` ZBC Labu Lui Tedim library; mood selection + YouTube-embed priority for `TedimHymnStrategy`. |
| [strategies/hymn_my_strategy.py](workers/strategies/hymn_my_strategy.py) | Burmese hymn strategy: sings the selected hymn's actual verses through Suno customMode, caches under `hymns_my/<slug>.mp3`. Used for `hymn_sung`, `hymn`, `hymn_youtube`, and `youtube` sources in Myanmar services — `youtube` mode is redirected here because generic YouTube search cannot reliably filter Myanmar Christian content from pop music. |
| [strategies/tedim_hymn_strategy.py](workers/strategies/tedim_hymn_strategy.py) | Tedim hymn strategy: YouTube embed (real Tedim singing) → Suno customMode render (cached) → instrumental fallback. |
| [strategies/_suno_custom.py](workers/strategies/_suno_custom.py) | Shared helper that builds and calls Suno customMode with exact lyrics for a given language/style. **Lyric sanitization rules (must be kept up to date when the hymn data format changes):** (1) `ထပ်ဆိoရန်[။]` on its own line → `[Chorus]` — this is the classic Burmese hymnal "Repeat/Chorus" marker, not a lyric; (2) Burmese numeral verse prefixes `၁ lyric text` → `[Verse 1]\nlyric text`; standalone `၁` → `[Verse 1]`. Suno treats `[Verse N]`/`[Chorus]` as structural metatags and never sings them. Any new non-lyric patterns found in `hymns_my.json` must be added to `_MY_SECTION_TAGS` or the verse-number substitution in `sanitize_lyrics()` — never leave raw structural markers reaching the Suno prompt. |
| [tools/seed_language_data.py](workers/tools/seed_language_data.py) | One-time seeder: downloads Judson 1835 (Myanmar) and Lai Siangtho 1932 (Tedim) Bibles, book index, and Myanmar hymns into `workers/data/`. |
| [tools/seed_tedim_hymns.py](workers/tools/seed_tedim_hymns.py) | Collects the ZBC Labu Lui hymnal from labusaal.com (~470 entries with YouTube IDs and mood tags) into `data/hymns_td.json`. Polite delay; run once per deploy. |
| [tools/seed_tedim_midi.py](workers/tools/seed_tedim_midi.py) | Optional: instrumental fallback renders from tedimhymn.com MIDI index (needs fluidsynth + ffmpeg). |
| [tools/import_myanmar_hymns.py](workers/tools/import_myanmar_hymns.py) | Regenerates `data/hymns_my.json` from the upstream dalsuum/myanmar-hymns source repo. |
| [llm_engine.py](workers/llm_engine.py) | Intake plan via OpenRouter; spoken prose generated directly in English/Myanmar/Tedim. Myanmar/Tedim prose is routed to the local FastAPI/Ollama services when configured, with short safe fallbacks if the local model times out or returns unusable text. Strips markdown / stage directions to clean spoken prose. |
| [bible_api.py](workers/bible_api.py) | Resolves a scripture *reference* to verse *text* from bundled public-domain translations: BSB (English), Judson 1835 (Myanmar), Lai Siangtho 1932 (Tedim). The model never writes scripture. |
| [classifier.py](workers/classifier.py) | Post-generation deny-list guardrail (`review() → (ok, reason)`). |
| [strategies/](workers/strategies/) | `MusicStrategy` interface + `HymnStrategy` / `SunoStrategy` / `YouTubeStrategy`, returning a normalized `MusicResult`. YouTube filtering is config-driven via `_LANG_CONFIG` in `youtube_strategy.py`: **Burmese** (`my`) requires Myanmar Unicode (U+1000–U+109F) in the title and forces `ခရစ်ယာန် တရားဟောချက်` in the query; **Tedim** (`td`) requires "Zomi"/"Tedim"/"Zolai" in the title and query; both reject South Asian language channels (Telugu, Tamil, Kannada, …). Adding a new language requires only a new `_LANG_CONFIG` entry — no code changes elsewhere. |
| [hymns.py](workers/hymns.py) / [seed_hymns.py](workers/seed_hymns.py) | Public-domain hymn library (lyrics + recordings) and the one-time seeder that renders/downloads it into storage. |
| [avatar.py](workers/avatar.py) | HeyGen render (submit → poll → store → URL). Key-gated. |
| [narrator.py](workers/narrator.py) | OpenAI/Kokoro/Edge/MMS narration. `edge_tts` uses real Microsoft cloud TTS for all languages (Myanmar: `my-MM-NilarNeural`/`ThihaNeural`; Tedim: `EDGE_TTS_VOICE_TD`); `mms_tts` routes to local `facebook/mms-tts-mya`/`mms-tts-ctd`. MMS calls have a bounded timeout. |
| [storage.py](workers/storage.py) | Object storage with two interchangeable backends: **local dir** (dev) or **S3** (prod). |

### Frontend (Vue 3)

Vue 3 + Vite, Stripe.js for the offering. A thin SPA whose job is to walk through the
service one stage at a time.

| Component | Role |
|-----------|------|
| [App.vue](frontend/src/App.vue) | Stage machine: `intake` → `preparing` → `service`; routes `#admin` to the console, `#vocabulary` to the Zolai vocabulary page. |
| [ZolaiVocabulary.vue](frontend/src/components/ZolaiVocabulary.vue) | Searchable Zolai↔English reference at `#vocabulary`. Edit `frontend/src/data/zolai_vocabulary.json` to add or correct words. |
| [IntakeForm.vue](frontend/src/components/IntakeForm.vue) | Mood picker (first question) + **language tab** (English / မြန်မာ / Zolai). For first-time visitors, name/email/prayer/music-source/scheduling are collapsed behind an "Add a prayer request or schedule" toggle so the main path is one-tap. Returning users always see the full form. Passes `language` and `mood` to the preparing screen so countdown verses load in the right Bible translation immediately. Moods, music sources, and scheduling toggle are all driven by `GET /config`. |
| [PreparingView.vue](frontend/src/components/PreparingView.vue) | Countdown screen; accepts `language` and `mood` props from the intake event so mood-matched Scripture cards load in the correct Bible translation before the server poll returns. Card type is `'verse'` (labelled "Scripture"); label `'banner'` shows admin text; `'testimony'` shows a worshipper story. Opens immediately via `nextTick` when `mediaReady` arrives — no longer waits for the next 1-second tick. |
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
| `users` | Worshippers (incl. guests) | `music_source` enum (`hymn_sung`/`hymn`/`suno`/`youtube`, default `hymn_sung`), `presenter_gender` enum (`female`/`male`, default `female` — controls avatar and TTS voice pairing), `name_provided` (false ⇒ display-only placeholder, kept out of the spoken service), `is_admin`, `is_blocked`, `timezone` |
| `service_sessions` | One worship visit | `session_token` (64), `status` (`initializing`/`active`/`completed`/`abandoned`/`scheduled`), `music_source` (locked), `language` (`en`/`my`/`td`), `presenter_gender` (locked from user preference at start), `tedim_status` / `burmese_status` (legacy readiness markers kept for older UI/admin paths), `scheduled_at` |
| `service_intakes` | The user's input + the plan | `mood`, `custom_mood` (free-text when the worshipper selects "other"), `prayer_text`, `scripture_ref`, `music_prompt`, `music_query` (1:1 with session) |
| `service_assets` | Generated segments | `segment` enum (`welcome`/`worship`/`opening_prayer`/`scripture`/`sermon`/`testimony`/`offering`/`closing_hymn`/`benediction`), `asset_type` (`video`/`audio`/`text`/`url`/`youtube`), `storage_key`, `audio_key`, `provider_ref`, `text_payload` (already in the service language for new `my`/`td` sessions), legacy `tedim_text` / `burmese_text`, `lyrics` (hymn verses or AI-composed lyrics for on-screen display), `status` |
| `music_tracks` | Language-and-mood-keyed reuse pool | `mood`, `language`, `provider_ref` (unique — dedupes), `storage_key`, `title`, `lyrics`, `source`. Populated by the worker after each fresh Suno generation; drawn from when a worshipper is new to a mood. |
| `settings` | Global admin key/value | `key` (PK) / `value` (string). Holds `narration_mode`, per-language narration toggles (`narration_en`/`narration_my`/`narration_td`), `text_highlight_enabled`, language-tab toggles (`lang_en`/`lang_my`/`lang_td`), countdown-card controls (`countdown_content_enabled`, `countdown_content_source`, `countdown_banners`), `music_reuse`, `storage_backend`, `avatar_enabled`, plus admin-curated intake options: `moods`, `music_sources`, `default_music_source`, and `scheduling_enabled`. |
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
   (+ prayer text / scripture ref), then run through `classifier.review()`. Blocked
   content is replaced with `"(content withheld pending review)"`. Surviving text is
   posted as the segment, and — if enabled — fanned out to `render_avatar` and `narrate`.
   The **sermon** is generated *without* a name: the prompt forbids addressing the
   listener by name, and `llm_engine._strip_name()` is a belt-and-suspenders safety net
   that scrubs any literal name (and repairs the leftover vocative punctuation) for the
   free models that slip one in anyway.
3. **Music** (`generate_music`) — resolves the locked `music_source` to a strategy and
   posts the result to **both** the `worship` and `closing_hymn` segments.

The webhook is **idempotent per (session, segment)**: text arrives first, then a later
narration pass fills `audio_key` and a later avatar pass flips `asset_type` to `video`
— each pass falls back to existing values for fields it doesn't carry, so nothing is
erased.

---

## Multilingual services (Myanmar & Tedim)

Three languages are supported. Language is chosen on the intake form and **locked per session** (like `music_source`).

| Language | Code | Bible | TTS voice | LLM | Hymns |
|----------|------|-------|-----------|-----|-------|
| English | `en` | BSB (bundled) | `en-US-AriaNeural` / `en-US-GuyNeural` | OpenRouter (`LLM_MODEL`) | Open Hymnal (instrumental/sung) |
| Myanmar | `my` | Judson 1835 (bundled) | `edge_tts` → `my-MM-NilarNeural`; `mms_tts` → `facebook/mms-tts-mya` | OpenRouter (`LLM_MODEL_MY`) | 852-song dalsuum/myanmar-hymns — sung via Suno customMode, cached |
| Tedim (Zolai) | `td` | Lai Siangtho 1932 (bundled) | `mms_tts` → `facebook/mms-tts-ctd` (native); `edge_tts` → `EDGE_TTS_VOICE_TD` | local Ollama (`OLLAMA_MODEL_TD`) | ZBC Labu Lui (~470 hymns) — YouTube embed → Suno → instrumental |

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
python tools/seed_language_data.py        # Judson 1835 + Tedim 1932 Bibles + Myanmar hymns
python tools/seed_tedim_hymns.py          # ZBC Labu Lui Tedim hymnal (~470 entries, polite delay)
python tools/seed_tedim_midi.py           # optional: instrumental fallbacks (fluidsynth + ffmpeg)

# 5. Start the language APIs (two processes at different ports)
uvicorn api:app --host 127.0.0.1 --port 8001 --workers 1   # Tedim
uvicorn api:app --host 127.0.0.1 --port 8002 --workers 1   # Burmese

# 6. In production, install the systemd units
sudo cp /opt/ai-church/aivc-tedim-api.service /etc/systemd/system/
sudo cp /opt/ai-church/aivc-burmese-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now aivc-tedim-api aivc-burmese-api
```

> **Upgrading the Tedim model:** change `OLLAMA_MODEL_TD` in `workers/.env`, then `sudo systemctl restart aivc-tedim-api`.
> **Upgrading the Burmese model:** change `OLLAMA_MODEL_MY`, then `sudo systemctl restart aivc-burmese-api`.
> No code changes needed in either case.

### Known gaps before production

1. **Crisis intercept is English-keyword based** — a Burmese or Tedim prayer won't trip it. Extend `CrisisInterceptService` with Burmese and Tedim terms before promoting those language tabs.
2. **Classifier guardrail** (`classifier.review`) reviews non-English text with an English-prompted model — spot-check its behavior on Burmese and Tedim sermons. LLM quality for these languages depends heavily on the selected model; use `LLM_MODEL_MY` and `LLM_MODEL_TD` to route to stronger multilingual models without touching the English path.
3. **ZBC Labu Lui licensing** — `seed_tedim_hymns.py` collects the hymnal for your deployment. The generated `data/hymns_td.json` carries a `license_note`; confirm permission with ZBC / labusaal.com before production.
4. **Player segment titles** (e.g. "Opening Prayer") are still English — the language is available on `GET /service/{token}` once `$session->language` is exposed there.
5. **Suno Burmese-vocal quality** varies by model version. If a render is poor, delete `hymns_my/<slug>.mp3` from storage and the next service re-renders it.

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
- **suno** — original worship music **generated by AI** in Suno customMode. The
  worker sends generated lyrics to Suno, stores the MP3 in object storage, and
  shows the same lyrics in the player for English, Burmese, and Zolai/Tedim services.
  If the planning model returns English-looking lyrics for a Burmese or Zolai/Tedim
  service, the worker replaces them with service-language fallback lyrics before
  calling Suno.
- **youtube** — an existing worship track found via the YouTube Data API and embedded via
  the official player (`videoEmbeddable` + `videoSyndicated`, Music category, strict
  safe-search). **No audio is downloaded or stored** — downloading would violate YouTube's
  ToS, so we only ever embed.

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
    (`127.0.0.1:17493`). See **[Voicebox TTS (optional)](#voicebox-tts-optional)** below.
  - `off` — segments stay as silent text.

  Myanmar defaults to `edge_tts` (cloud); Tedim defaults to `mms_tts` (native local).
  Both are free and configurable per-language in Admin Console → Settings.

  In a server-voice mode (`openai`/`kokoro`/`edge_tts`/`mms_tts`) the player waits for
  each segment's audio to land, then plays it. Browser Web Speech is used only when a
  matching browser voice exists; Myanmar and Tedim never fall back to an English
  browser voice, because that skips or mangles their words. MMS-TTS narration is staggered
  for non-English services; scripture, opening prayer, and benediction are prioritized,
  and the longer message audio is deferred so it cannot block the last prayer.
- **Avatar** — [avatar.py](workers/avatar.py) renders a HeyGen talking-head of the
  spoken segments (submit → poll → store → URL). Requires `HEYGEN_API_KEY` +
  `HEYGEN_AVATAR_ID` + `HEYGEN_VOICE_ID`. Can be toggled without touching env vars via
  the `avatar_enabled` admin setting. **Stub-level** in the current build.

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

The compose file pulls the pre-built image (`ghcr.io/jamiepine/voicebox:latest`) and
maps host port `17493` to container port `8000`. The CPU backend variant is used; adjust
`VOICEBOX_BACKEND_VARIANT` in `voicebox/docker-compose.yml` for GPU hosts.

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
VOICEBOX_ENGINE=kokoro          # or: qwen, luxtts, chatterbox, chatterbox_turbo
VOICEBOX_TIMEOUT=180
```

**4. Activate in the admin console**

Go to **Admin Console → Settings → Narration voice → Voicebox (local)**.
Choose the engine (Kokoro is the fastest; Qwen3-TTS gives the best quality).
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

The console is at `/#admin`. Access is role-based:

- **Admin** — full access to everything below.
- **Moderator / Presenter** — limited access; which tabs they see is controlled by the **Permissions** matrix.

`/admin` routes are split across two middleware groups:

| Middleware | Who | Routes |
|---|---|---|
| `staff` | admin + moderator + presenter | Dashboard, Services, Testimonies, Donors, Prayer Requests |
| `admin` | admin only | Users, Settings, Export, Voice Studio, Permissions |

- **Dashboard** — sessions, worship-time totals, donations, intercept counts, and prayer-request counts (total + today).
- **Services** — list + **retry** a failed/stuck service (clears existing assets first so segment count visibly drops to zero, confirming regeneration is in progress) + **delete**.
- **Testimonies** — approve / delete (moderation queue); each entry shows the user's custom mood words so the moderator has context.
- **Users** — list + **create** new users (admin generates a first-login reset link when no password is set) + **assign role** (`admin`/`moderator`/`presenter`/`member`) + **block/unblock** + **delete** + **force password reset** (generates a one-time token link the admin shares out-of-band) + set **presenter gender**.
- **Donors** — donation rollups.
- **Prayer requests** — paginated log of prayer-request intakes visible to admins.
- **Permissions** — configure which permissions each non-admin role (`moderator`, `presenter`) has in the staff console.
- **Settings** — global service config persisted in the `settings` table and threaded
  onto each job: `narration_mode` (`off`/`browser`/`openai`/`kokoro`/`edge_tts`),
  per-language narration toggles (`narration_en`/`narration_my`/`narration_td`),
  countdown-card settings (`countdown_content_enabled`, `countdown_content_source` [`banners`/`testimonies`/`verses`/`both`/`all`],
  `countdown_banners`; banners are English-only; verse cards are mood-matched from bundled translations),
  `text_highlight_enabled` (word-by-word highlight on/off in the player), `music_reuse`
  (the Suno pool toggle), `storage_backend` (`local` vs `s3` for generated audio), and
  `avatar_enabled` (toggle HeyGen avatar rendering on/off without touching env vars).
  Plus the worshipper-facing **intake options** an admin curates without a redeploy:
  the **moods** offered at intake (add/remove — a new mood flows through the whole
  pipeline: the prayer/sermon tone, the music prompt, and hymn matching), which
  **music sources** appear (toggle any of sung-hymn/instrumental/AI-composed/YouTube,
  at least one on), and whether **scheduling** is offered. These are served to the
  intake form via the public [`GET /config`](#public).
- **Export** — CSV of `donations` | `users` | `testimonies`.
- **System** (admin-only) — live system monitor with one-click installs and service restarts:
  - **Service health** — real-time status (active / inactive / unknown) of all AIVC systemd
    units (`aivc-workers`, `aivc-workers-music`, `aivc-bridge`, `aivc-queue`,
    `aivc-scheduler`, `aivc-tedim-api`, `aivc-burmese-api`) plus `redis-server` and `nginx`.
    Each restartable unit has a **Restart** button that dispatches a `RestartService` queue
    job (requires the `sudoers` entry documented in `RestartService.php`).
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
- **Voice Studio** — in-browser TTS training-data recorder. Displays sentences from the
  Tedim (1,500) and Burmese (1,500) bible corpora one at a time; click **Record**, speak,
  review playback, then **Accept**. The server converts each clip to 16 kHz mono WAV via
  ffmpeg and stores it under `storage/app/voice-studio/{lang}/`. **Jump to unrecorded**
  skips to the next sentence without a recording; **Go to #** lets you jump directly to
  any sentence by number, and auto-starts at the first unrecorded phrase when you open a
  language. When ready, **Export Dataset** downloads a zip with `metadata.csv` + `wavs/`
  — the exact format expected by the [MMS-VITS fine-tuning guide](TRAIN_CUSTOM_VOICE.md)
  for Colab training.

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

#   Seed language data (required for Myanmar/Tedim language services):
python tools/seed_language_data.py        # Judson 1835 + Tedim 1932 Bibles + Myanmar hymns
python tools/seed_tedim_hymns.py          # ZBC Labu Lui Tedim hymnal (~470 entries)
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
| [`aivc-workers.service`](.systemd/prod/aivc-workers.service) | Celery workers (sermon · music · avatar · narration) |
| [`aivc-bridge.service`](.systemd/prod/aivc-bridge.service) | bridge consumer (`ai:intake` → Celery) |
| [`aivc-tedim-api.service`](.systemd/prod/aivc-tedim-api.service) | FastAPI Tedim LLM + MMS-TTS service (Uvicorn, port 8001) |
| [`aivc-burmese-api.service`](.systemd/prod/aivc-burmese-api.service) | FastAPI Burmese LLM service (Uvicorn, port 8002) |

```bash
# on the droplet, once the units are copied to /etc/systemd/system:
sudo systemctl enable --now aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api
sudo systemctl status  aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api --no-pager

# After worker/backend code or prompt changes, restart the services that load code:
sudo systemctl restart aivc-workers aivc-bridge aivc-queue aivc-tedim-api aivc-burmese-api
sudo systemctl status  aivc-workers aivc-bridge aivc-queue aivc-tedim-api aivc-burmese-api --no-pager

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
| `LLM_MODEL_MY` | Myanmar-specific model override for OpenRouter (e.g. `WYNN747/Burmese-GPT`). Falls back to `LLM_MODEL` if unset. |
| `LLM_MODEL_TD` | Tedim-specific model override for OpenRouter. Falls back to `LLM_MODEL` if unset (note: low-resource language — a multilingual model is recommended). |
| `TEDIM_LLM_URL` | Base URL of the local Tedim FastAPI service (default `http://127.0.0.1:8001`). Must match in both `workers/.env` and `backend/.env`. |
| `BURMESE_LLM_URL` | Base URL of the local Burmese FastAPI service (default `http://127.0.0.1:8002`). Must match in both `workers/.env` and `backend/.env`. |
| `OLLAMA_MODEL_TD` | Ollama model name for Tedim (default `tedim-zolai`). Change to a fine-tuned GGUF name without any code change. |
| `OLLAMA_MODEL_MY` | Ollama model name for Burmese (default `burmese-myanmar`). |
| `OLLAMA_URL` | Ollama REST endpoint (default `http://127.0.0.1:11434/api/generate`). |
| `MMS_TTS_URL` | Local MMS-TTS base URL used for Myanmar/Tedim narration (default `http://127.0.0.1:8003`). |
| `MMS_TTS_MODEL_TD` / `MMS_TTS_MODEL_MY` | Native VITS checkpoints for Tedim/Burmese narration (defaults `facebook/mms-tts-ctd` / `facebook/mms-tts-mya`). |
| `MMS_TTS_SEED` | Pinned VITS seed for reproducible narration (default `42`). |
| `MMS_TTS_TIMEOUT` | Per-request timeout for local MMS-TTS (default `180` seconds). Long message audio may be skipped/left text-only if the CPU model cannot finish in time. |
| `MMS_TTS_STAGGER_SECONDS` | Delay between Myanmar/Tedim MMS narration tasks (default `60`; keeps the small server from running several VITS generations at once). |
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

> Auth routes (`/guest`, `/register`, `/login`) are rate-limited per IP (`throttle:auth`) to slow credential stuffing. Intake is throttled per user (`throttle:intake`); testimony submission per user (`throttle:testimony`).

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/config` | Intake/preparing options — `moods`, enabled `music_sources`, `scheduling_enabled`, `enabled_languages`, and text-only `countdown_cards`. Optional `mood`/`language` query params make countdown testimonies and Bible cards contextual. Read before a session exists. |
| `POST` | `/guest` | Start an anonymous guest session. |
| `POST` | `/register` | Create an account. |
| `POST` | `/login` | Log in, returns a token. |
| `GET` | `/service/{token}/resume` | Email-link session resume — token acts as the credential, no bearer required. |
| `POST` | `/internal/asset-ready` | **Worker callback** — `X-Worker-Secret` header, no user auth. |
| `POST` | `/internal/music-track` | **Worker callback** — banks a fresh Suno track in the reuse pool (`X-Worker-Secret`, no user auth). |
| `POST` | `/webhooks/stripe` | **Stripe webhook** — signature-verified, no user auth. |

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
| `GET` | `/admin/settings` | Read global settings (narration mode, per-language narration, text highlighting, language tabs, countdown cards, music reuse, storage backend, avatar enabled, moods, music sources, scheduling). |
| `PATCH` | `/admin/settings` | Update any of `narration_mode` / `narration_en` / `narration_my` / `narration_td` / `text_highlight_enabled` / `lang_en` / `lang_my` / `lang_td` / `countdown_content_enabled` / `countdown_content_source` / `countdown_banners` / `music_reuse` / `storage_backend` / `avatar_enabled` / `moods` / `music_sources` / `default_music_source` / `scheduling_enabled`. |
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
  new services, Myanmar 852-hymn library (Suno customMode), Tedim ZBC Labu Lui hymnal (YouTube embed →
  Suno → instrumental), seeder tools (`seed_language_data.py`, `seed_tedim_hymns.py`,
  `seed_tedim_midi.py`) — **DONE**
- **Phase 8 — Presenter UX:** presenter gender selection (`female`/`male`) per worshipper,
  locked per session, with sermon/support voice pairing; `avatar_enabled` admin toggle;
  `welcome` segment added to the segment enum — **DONE**

**Known gaps / next steps:** real WebSocket push (Reverb/Echo wiring is stubbed; polling
works today), HeyGen avatar beyond stub, a production-grade crisis classifier extended to
Burmese and Tedim keywords, rights for a non-public-domain Bible translation, fine-tuned
Tedim GGUF (current `tedim-zolai` uses `llama3.2:1b` as base — quality improves
significantly with a Tedim-corpus fine-tune on a larger model), a Vue bilingual segment
component if you want bilingual side-by-side English plus target-language text,
and ZBC Labu Lui licensing confirmation before production.

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

### Free TTS (Text-to-Speech)

| Provider | What we use it for | Why we're grateful |
|----------|-------------------|--------------------|
| **[Facebook MMS-TTS](https://huggingface.co/facebook/mms-tts)** | Native narration for Myanmar (`facebook/mms-tts-mya`) and Tedim/Zolai (`facebook/mms-tts-ctd`) | Part of Meta's Massively Multilingual Speech project — one of the very few publicly available TTS systems that supports Tedim (Chin) and Burmese with a proper VITS voice, not an English voice guessing at the script |
| **[Microsoft Edge TTS](https://github.com/rany2/edge-tts)** | English narration (`en-US-AriaNeural` / `en-US-GuyNeural`) in `edge_tts` mode | High-quality neural voices available free via the Edge read-aloud API, with no key required for reasonable usage |
| **[Browser Web Speech API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Speech_API)** | Client-side narration (`browser` mode) — works out of the box on any modern browser, no server or key needed | Zero cost, zero setup; the default narration mode for local development |
| **[Hugging Face Hub](https://huggingface.co)** | Model distribution for MMS-TTS checkpoints (`transformers` auto-download on first use) | Hosts and serves all the MMS-VITS model weights for free, making multilingual TTS accessible without self-hosting model files |

> A special thank-you to the **Meta AI Research** team behind the
> [Massively Multilingual Speech (MMS)](https://ai.meta.com/research/publications/scaling-speech-technology-to-1000-languages/)
> project. The inclusion of Tedim (Zolai) and Burmese voices — languages spoken by a
> relatively small number of people — makes it possible for this project to serve
> Chin and Myanmar-speaking worshippers in their own language and voice.
