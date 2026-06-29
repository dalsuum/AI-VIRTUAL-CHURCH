# AI Virtual Church v1.0 — Multilingual Production Readiness Report

Milestone 7 (production readiness / release candidate). Generated 2026-06-29 on
branch `release/v1.0-multilingual` at the Milestone 6 commit `6efaed50`.

This report consolidates the verification evidence, the supported-language and
AI-capability matrices, release notes, known limitations, and remaining future
work for the `v1.0.0-multilingual` release candidate.

**Scope of this milestone:** verification and documentation only. No features,
architecture, API, or database-schema changes were made.

**Verification method:** "verified" below means backed by the automated test
suites, the production build, and static presence of the relevant surface in the
codebase — not live, click-through native-speaker QA. Visual/native-speaker
review of generated wording remains a tracked limitation (see below).

---

## 1. Verdict

**Go for release candidate**, conditional on the human sign-off rows in
[`RELEASE_CHECKLIST_V1.md`](RELEASE_CHECKLIST_V1.md) (native-speaker translation
review and a live RTL visual pass). All automated gates are green and the
roadmap milestones 1–7 are complete.

## 2. Automated verification (this run)

| Gate | Command | Result |
|---|---|---|
| Backend (PHPUnit) | `vendor/bin/phpunit` | 290 passed, 1 skipped |
| Worker (pytest) | `python3 -m pytest -q` | 71 passed |
| Frontend build | `npm run build` | built OK (chunk-size warning only) |
| Playwright (desktop + mobile) | `npx playwright test` | 56 passed |

The multilingual milestone test suite (`MultilingualMilestoneOne…SixTest`,
`LocaleTest`, `MoodExpansionServiceTest`) runs inside the backend suite and
covers: intake acceptance for every service language, RTL flags and `dir`
behavior, Unicode custom moods, mood→concept expansion and synonym routing,
duplicate-trigger integrity, native-phrase language detection, and locale-key
English-fallback coverage.

## 3. Supported Language Matrix

17 supported languages. 14 are full UI/interface locales in the central registry
(`backend/config/languages.php`); Falam/Hakha/Lushai are Bible + Bible-Study
languages (worker `bible_api`) without a UI locale in v1.0.

| Code | Language | UI / Service | Bible Reader | Bible Study | TTS / speech locale | Direction | Status |
|---|---|---|---|---|---|---|---|
| `en` | English | Yes | Yes | Yes | en-US | LTR | Complete |
| `my` | Burmese | Yes | Yes | Yes | my-MM | LTR | Complete |
| `td` | Tedim (Zolai) | Yes | Yes | Yes | en-US* | LTR | Complete |
| `cfm` | Falam | Bible only | Yes | Yes | — | LTR | Bible/Study only |
| `cnh` | Hakha | Bible only | Yes | Yes | — | LTR | Bible/Study only |
| `lus` | Lushai | Bible only | Yes | Yes | — | LTR | Bible/Study only |
| `fr` | French | Yes | Yes | Yes | fr-FR | LTR | Complete |
| `de` | German | Yes | Yes | Yes | de-DE | LTR | Complete |
| `es` | Spanish | Yes | Yes | Yes | es-ES | LTR | Complete |
| `ja` | Japanese | Yes | Yes | Yes | ja-JP | LTR | Complete |
| `zh-CN` | Chinese (Simplified) | Yes | Yes | Yes | zh-CN | LTR | Complete |
| `ko` | Korean | Yes | Yes | Yes | ko-KR | LTR | Complete |
| `hi` | Hindi | Yes (partial UI) | Yes | Yes | hi-IN | LTR | Complete |
| `ta` | Tamil | Yes (partial UI) | Yes | Yes | ta-IN | LTR | Complete |
| `th` | Thai | Yes (partial UI) | Yes | Yes | th-TH | LTR | Complete |
| `ar` | Arabic | Yes | Yes | Yes | ar-SA | RTL | Complete |
| `he` | Hebrew | Yes | Yes (Tanakh 1–39) | Yes | he-IL | RTL | Complete |

\* Tedim has no native Edge TTS voice; it uses the dedicated MMS-TTS narrator in
the worker pipeline (never removed — release invariant) and an `en-US` speech
locale fallback for STT.

"Partial UI" = the locale's UI string file is intentionally incomplete and falls
back to English per the i18n fallback policy; service/Bible content is full.

## 4. AI Capability Matrix

| Capability | Engine / surface | Languages | Notes |
|---|---|---|---|
| Church Service generation | `WorshipServicePipeline` + worker LLMs | All service languages | Prompts instruct native phrasing; no worshipper name in output (invariant) |
| Pastor Chat | `PastorChatController` + prompt engine | All service languages | Heuristic language detection (script + fr/de/es signals); native Christian vocabulary in prompts |
| Bible Study | `StudySessionPipeline` + `bible_study` plugin | All study languages | Expanded English book aliases/abbreviations; SSE streaming |
| Worship Radio | `MoodExpansionService` + `MusicRecommendationService` | 14 label languages | Six universal moods; per-language search seeds; deterministic (no LLM) expansion |
| Special Day | `SpecialSundayController` + service pipeline | All service languages | Greetings/devotional wording via service prompts |
| Bible Reader | `BibleController` / `bible_api` | All 17 (incl. Chin) | Native headings + English aliases for search; partial-canon padding for Hebrew |
| Narration / TTS | Edge TTS + MMS-TTS narrator | Per TTS-locale column | Tedim/Burmese use MMS-TTS narrator (invariant) |
| RAG / Knowledge | `HybridKnowledgeRetriever` + Qdrant | Language-neutral | Unchanged in v1.0 multilingual program |

## 5. Release Notes — v1.0.0-multilingual

**Headline:** AI Virtual Church now speaks 17 languages, including full
right-to-left (Arabic, Hebrew) support.

- **New interface languages:** French, German, Spanish, Japanese, Chinese
  (Simplified), Korean, Hindi, Tamil, Thai, Arabic, Hebrew — alongside English,
  Burmese, and Tedim.
- **Right-to-left support:** native Arabic and Hebrew UI with logical CSS,
  mirrored header/navigation, and RTL-preserving PDF/print output.
- **Bible coverage:** public-domain / CC world-language Bibles for all UI
  languages plus Falam, Hakha, and Lushai (Bible + Bible Study).
- **Language intelligence (Milestone 6):** native Christian/liturgical phrasing
  in AI prompts, enriched Pastor-Chat language detection, expanded Bible book
  abbreviations, and richer Worship-Radio mood synonyms with duplicates removed.
- **Central language registry:** one source of truth
  (`config/languages.php`) drives selectors, locale middleware, and validation;
  no hardcoded language lists in release-critical flows.

## 6. Performance review

- **Locale loading:** UI locale files are statically globbed and bundled by Vite;
  missing keys fall back to English with no runtime fetch per key.
- **Bundle size:** main chunk exceeds Vite's 500 kB advisory (gzip ≈ 297 kB).
  Acceptable for v1.0; code-splitting is logged as future work, not a blocker.
- **Worker startup / routing:** language routing verified by the worker suite;
  no new services introduced.
- **DB / cache:** no schema or query changes in this program; mood dictionary and
  registry are config-driven and cacheable.

## 7. Security review

- **Input validation & authorization:** unchanged and covered by the backend
  suite (intake, chat, study, admin endpoints).
- **Localization-injection safety:** prompt engine keeps trusted vs. untrusted
  content channel-separated with invariant ASCII fences; untrusted text is
  neutralized (covered by `test_prompt_engine`).
- **No new attack surface:** Milestone 7 added documentation only; no new
  endpoints, inputs, or dependencies.
- **Secrets:** worker webhook secret and provider keys remain env-driven; none
  committed.

## 8. Known Limitations

- Several UI locale files are intentionally partial (notably `hi`, `ta`, `th`,
  and the `my`/`td` subsets); untranslated keys fall back to English.
- Native-speaker review of AI-generated wording and translations is still
  required for final quality sign-off.
- Falam, Hakha, and Lushai are Bible / Bible-Study languages only (no UI/service
  locale) in v1.0.
- Tedim has no native Edge TTS voice; it relies on the MMS-TTS narrator.
- Live, visual RTL QA on physical devices is a human checklist item, not covered
  by the automated suites.
- Frontend main bundle exceeds the build-tool size advisory (non-blocking).

## 9. Remaining Future Work (post-v1.0)

- v1.1: Promote selected Bible/Bible-Study-only languages (Falam/Hakha/Lushai) to
  full service locales.
- v1.1: Complete partial UI locales (`hi`, `ta`, `th`, …) with native-speaker
  review and translation provenance tracking.
- v1.2: Expand per-language worship libraries and Bible alias coverage.
- v1.2: Per-language QA dashboards for admins/maintainers.
- Engineering: bundle code-splitting to clear the chunk-size advisory.
