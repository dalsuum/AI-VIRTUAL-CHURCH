# Multilingual Integration — AI Virtual Church
## English (main) · မြန်မာ (Myanmar) · Zolai (Tedim Chin)

Three language tabs on the intake form; the choice locks per session and the
whole service follows it:

| | **my — Burmese** | **td — Tedim Chin (Zolai)** |
|---|---|---|
| Prayers / sermon / benediction | LLM writes Burmese (Myanmar Unicode only) | LLM writes Tedim (Latin script, Pasian/Topa/Zeisu register) |
| Scripture | **Judson 1835 သမ္မာကျမ်း** (public domain) | **Lai Siangtho 1932** (public domain) |
| Hymns | 852-song dalsuum/myanmar-hymns library, sung via Suno customMode, cached | Tedim hymnal (~470 hymns, seeded at deploy): YouTube embed of real Tedim singing preferred → Suno render cached → instrumental fallback |
| Narration | Native MMS-TTS `facebook/mms-tts-mya` when Admin enables Myanmar narration | Native MMS-TTS `facebook/mms-tts-ctd` when Admin enables Tedim narration |
| UI | Padauk / Noto Sans Myanmar | Latin script |

English services are completely unchanged.

## How the language flows

```
IntakeForm.vue ──language: 'my'|'td'──▶  POST /service/{token}/intake
                                          │  ServiceController validates in:en,my,td,
                                          │  locks it on service_sessions.language
                                          ▼
                              DispatchServiceJob → Redis ai:intake JSON {language}
                                          │
                                          ▼
              tasks.orchestrate ── language threads into every consumer:
                ├─ llm_engine.*        prompts pinned to the service language
                ├─ bible_api.resolve(ref, lang)  → Judson 1835 / Lai Siangtho 1932
                ├─ get_strategy(src, language)   → Myanmar/Tedim hymn strategy
                └─ narration voice     → local MMS-TTS mya / ctd when enabled
```

Design notes:
- **Scripture references stay English** ("Psalm 23:1-4") — they're worker
  contract, not worshipper-facing text. `bible_api` parses them against a
  vendored canonical English book index (`data/books_en.json`) and reads the
  verses from the translation file by canonical 1-66 book number; the on-screen
  heading is rewritten to that translation's own book name (ဆာလံကျမ်း / Late 23…).
  Adding another dalsuum/bible translation is one line in `_LANG_FILES`.
- **Mood values stay English** (Grateful, Anxious…) — the worker's mood
  matching and the hymn tags use that vocabulary; the form shows Burmese labels.
- **Burmese hymns are lyrics-only** in the source repo (no MP3s exist), so
  `MyanmarHymnStrategy` sings the selected hymn's *actual verses* through Suno
  customMode and caches the render under `hymns_my/<slug>.mp3` — first service
  pays the credit, every later selection of that hymn plays free.
- **Tedim hymns are bundled** (`data/hymns_td.json`, 467 hymns, committed to the
  repo). No seed step needed at deploy. Each entry carries the Tedim title, the
  English original it translates (the mood-tagging signal), the verses, and a
  YouTube embed id when present (~84% of hymns). Run `tools/seed_tedim_hymns.py`
  only if you want to refresh the data with newly added hymns.
- **Tedim playback chain**: YouTube embed (real Tedim singing, zero AI cost) →
  Suno customMode render cached under `hymns_td/<slug>.mp3` → instrumental
  render of the matching Tedim Hymn 7th Edition tune (optional
  `tools/seed_tedim_midi.py`, fluidsynth) → music segment skipped gracefully.
- **Myanmar/Tedim narration is controlled per language in Admin Settings.** Keep
  `narration_mode=edge_tts`; English uses Edge voices, while Myanmar/Tedim are
  routed to the local MMS-TTS service (`facebook/mms-tts-mya` / `facebook/mms-tts-ctd`).
  English browser voices are never used as a fallback for Myanmar/Tedim because they
  skip or mangle the text. Non-English MMS jobs are staggered; message audio is
  deferred until after benediction so the last prayer is not blocked by a long sermon.

## What's in this bundle

```
workers/
  data/judson1835.json        Judson 1835 Burmese Bible (12.7 MB, dalsuum/bible)
  data/tedim1932.json         Lai Siangtho 1932 Tedim Bible (5.2 MB, dalsuum/bible)
  data/books_en.json          canonical 66-book English index (name/abbr → number)
  data/hymns_my.json          852 Burmese songs, cleaned + mood-tagged (~1.7 MB)
  data/hymns_td.json          467 Tedim hymns, bundled (394 with YouTube embeds)
  tools/import_myanmar_hymns.py  regenerates hymns_my.json from the source repo
  tools/seed_tedim_hymns.py   OPTIONAL — refresh hymns_td.json with new hymns
  tools/seed_tedim_midi.py    OPTIONAL — instrumental renders (MIDI, fluidsynth)
  bible_api.py                REPLACES — language registry ('en'|'my'|'td')
  hymns_my.py                 NEW — Burmese library loader + mood selection
  hymns_td.py                 NEW — Tedim library loader + mood selection
  llm_engine.py               REPLACES — every generator takes language
  tasks/__init__.py           REPLACES — threads language through the pipeline
  strategies/__init__.py      REPLACES — get_strategy(source, language)
  strategies/_suno_custom.py  NEW — shared "sing these exact verses" helper
  strategies/hymn_my_strategy.py  NEW — Burmese hymn sung via Suno, cached
  strategies/tedim_hymn_strategy.py NEW — YouTube embed → Suno → instrumental
backend/
  database/migrations/2026_06_12_000001_add_language_to_service_sessions.php
  app/Jobs/DispatchServiceJob.php   REPLACES — adds 'language' to the Redis payload
  PATCHES.md                  two small hand-edits (ServiceSession, ServiceController)
frontend/
  src/components/IntakeForm.vue  REPLACES — English | မြန်မာ | Zolai tabs, full
                                 Burmese + Tedim UI strings, Myanmar Unicode
                                 fonts, sends language with the intake
```

> The Tedim UI strings are best-effort Zolai — please review them as a native
> speaker (marked with a NOTE in the component).

## Install

Bible corpora and Myanmar hymns are gitignored (large, generated) — seed them at deploy.
The Tedim hymnal (`hymns_td.json`) is bundled in the repo — no seed step needed.

```bash
python workers/tools/seed_language_data.py   # Judson 1835 + Tedim 1932 bibles, book index, Myanmar hymns
python workers/tools/seed_tedim_midi.py      # optional: instrumental fallbacks (fluidsynth + ffmpeg)
```


```bash
# 1. Copy worker files over the repo (data/ is new, the rest replace 1:1)
cp -r bundle/workers/* AI-VIRTUAL-CHURCH/workers/

# 2. Backend
cp bundle/backend/database/migrations/*.php AI-VIRTUAL-CHURCH/backend/database/migrations/
cp bundle/backend/app/Jobs/DispatchServiceJob.php AI-VIRTUAL-CHURCH/backend/app/Jobs/
#    then apply the two edits in backend/PATCHES.md
cd AI-VIRTUAL-CHURCH/backend && php artisan migrate

# 3. Frontend
cp bundle/frontend/src/components/IntakeForm.vue AI-VIRTUAL-CHURCH/frontend/src/components/
cd ../frontend && npm run build

# 4. Optional: instrumental fallback renders (needs fluidsynth + ffmpeg)
#    hymns_td.json is bundled — no Tedim hymn seed step needed
python workers/tools/seed_tedim_midi.py

# 5. Restart the Celery workers + bridge so the new modules load
```

## Configuration (all optional)

| Env var | Default | Purpose |
|---|---|---|
| `BIBLE_DATA_FILE_MY` | `workers/data/judson1835.json` | swap the Burmese translation |
| `BIBLE_DATA_FILE_TD` | `workers/data/tedim1932.json` | swap the Tedim translation |
| `MMS_TTS_URL` | `http://127.0.0.1:8003` | local MMS-TTS base URL used for Myanmar/Tedim narration |
| `MMS_TTS_MODEL_MY` / `MMS_TTS_MODEL_TD` | `facebook/mms-tts-mya` / `facebook/mms-tts-ctd` | native VITS checkpoints |
| `MMS_TTS_TIMEOUT` | `180` | per-request MMS timeout so long message audio cannot hang forever |
| `MMS_TTS_STAGGER_SECONDS` | `60` | delay between non-English narration jobs |
| `LOCAL_LLM_TIMEOUT` | `45` | local prose-generation timeout before safe fallback text |
| `EDGE_TTS_VOICE_MY` / `EDGE_TTS_VOICE_TD` | legacy overrides | English still uses Edge voices; Myanmar/Tedim prefer MMS-TTS |
| `SUNO_MY_STYLE` / `SUNO_TD_STYLE` | traditional hymn / choir | Suno style prompt per language |
| `SUNO_CUSTOM_MAX_LYRICS` | `2800` | lyric chars sent to Suno customMode |

`SUNO_API_KEY` is required only when a path actually renders new Suno audio
(`music_source=suno`, or an uncached Myanmar/Tedim hymn fallback that has no local/
YouTube option). Hymn/YouTube paths remain free at service time when a local/cached
or embeddable hymn is available.

## Verified in this environment

- `bible_api.resolve(..., 'my')` correct for John 3:16, Psalm 23 (whole chapter
  and ranges), Psalms/Psalm both, 1 John, 2 Corinthians, Proverbs, Song of
  Solomon, Isaiah, Matthew, Philippians, Revelation — output is Judson Unicode.
- `hymns_my.json`: 852 songs, 0 duplicate slugs, chord lines stripped from
  modern songs, every entry mood-tagged (+ "default").
- `get_strategy('hymn_sung', language='my')` → `MyanmarHymnStrategy`;
  `language='en'` unchanged → `HymnStrategy`. `tasks` imports cleanly with the
  replacements in place.
- `IntakeForm.vue` compiles with @vue/compiler-sfc; no hardcoded English left in
  the template; Burmese strings are Myanmar Unicode.

## Verified for Tedim in this environment

- `bible_api.resolve(..., 'td')` correct for John 3:16, Psalm 23 ranges,
  Matthew, Philippians, 1 John — output is Lai Siangtho 1932; Burmese and
  English paths unaffected.
- Seeder smoke-tested: 12/12 hymn pages parsed, 10 with YouTube embeds, titles
  + English originals + mood tags extracted; full corpus collected at deploy time.
- `get_strategy('hymn_sung', language='td')` → `TedimHymnStrategy`; a live
  fetch returned a YouTube-embed MusicResult with the verses riding along.
- MIDI index parsed: 448 tunes discoverable by the seeder.
- IntakeForm with three tabs compiles under @vue/compiler-sfc.

## RBAC / Permission System (2026-06-12)

Five roles: `guest < member < presenter < moderator < admin`.

Staff roles (`presenter`, `moderator`, `admin`) can access the console at `#admin`.
The **Permissions** tab (admin-only) shows a matrix to configure what each
configurable role (`moderator`, `presenter`) can do:

| Feature | Actions configurable |
|---|---|
| Dashboard | View |
| Services | View, Retry, Delete |
| Testimonies | View, Approve, Delete |
| Prayer Requests | View |
| Donors | View |
| Voice Studio | View (tab in admin console) |

Admin always has all permissions. User management, Settings, Permissions, and
CSV exports are **admin-only** and do not appear in the matrix.

Permissions are stored as JSON in the `settings` table key `role_permissions`.
Default: moderators get full read + testimony moderation + service retry;
presenters get dashboard + voice studio only.

Backend enforcement: `EnsureStaff` middleware on staff routes; each controller
method calls `PermissionService::require($user, 'feature.action')`.

## Known gaps to close before production

1. **Crisis intercept is English-keyword based.** A Burmese or Tedim prayer
   won't trip it. Extend `CrisisInterceptService` with Burmese and Tedim terms
   (see PATCHES.md note) before promoting the tabs.
2. **Classifier guardrail** (`classifier.review`) reviews non-English text with
   an English-prompted model — spot-check its behavior on Burmese and Tedim
   sermons. **LLM Myanmar/Tedim quality depends heavily on the selected model**:
   the current local models can be slow or low quality. `llm_engine.py` now falls
   back to short safe Myanmar/Tedim text if a local prose request times out or
   fails validation, so pages still appear, but native-speaker review is required
   before production.
3. **Player chrome** (ServicePlayer segment titles like "Opening Prayer") is
   still English; same STRINGS-dict pattern applies if you want it localized.
   The service API already exposes `language` and `text_highlight_enabled`.
4. Suno's Burmese-vocal quality varies by model version; if a render is poor,
   delete `hymns_my/<slug>.mp3` from storage and the next service re-renders it.
