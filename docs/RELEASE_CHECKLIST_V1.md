# AI Virtual Church v1.0 Multilingual Release Checklist

Use this checklist for the final `v1.0.0-multilingual` release candidate. It
complements the frozen multilingual roadmap in
[`ROADMAP_MULTILINGUAL_V1.md`](ROADMAP_MULTILINGUAL_V1.md), the routine milestone
checklist in [`RELEASE_CHECKLIST.md`](RELEASE_CHECKLIST.md), and the production
go/no-go gate in [`RELEASE_GATE_v1.md`](RELEASE_GATE_v1.md).

Scope is frozen for v1.0. Do not use this checklist to add features.

## Branch And Review

- [ ] Release branch is `release/v1.0-multilingual`.
- [ ] Working tree is clean.
- [ ] Every change since `715e7680` maps to Milestones 4-7 or a release-blocking fix.
- [ ] Code review completed for Milestones 4, 5, 6, and 7.
- [ ] No unrelated refactors, architecture redesigns, or new dependencies remain.
- [ ] No release-blocking TODOs or temporary debug flags remain.

## Milestone Completion

- [x] Milestone 4: Arabic, Hebrew, and RTL completed and committed. (`c2c6c635`)
- [x] Milestone 5: system QA and regression completed and committed. See [`MILESTONE_5_QA.md`](MILESTONE_5_QA.md).
- [x] Milestone 6: language intelligence and vocabulary completed and committed. (`6efaed50`)
- [x] Milestone 7: production readiness completed and committed. See [`PRODUCTION_READINESS_V1.md`](PRODUCTION_READINESS_V1.md).
- [ ] Production readiness report reviewed and approved. (awaiting human sign-off)

## Automated Checks

- [ ] CI is green on the release branch. (run in CI after push)
- [x] Backend tests pass. (290 passed, 1 skipped — Milestone 7)
- [x] Frontend build passes. (Milestone 7)
- [x] Worker tests pass. (71 passed — Milestone 7)
- [ ] Security checks pass. (manual review done in PRR §7; tooling runs in CI)
- [ ] Pylint passes. (runs in CI)
- [ ] Dependency audits pass. (runs in CI)
- [ ] Secret scanning passes. (runs in CI)
- [ ] Static analysis and formatting checks pass where applicable. (runs in CI)

## Multilingual Verification

- [x] Supported language matrix reviewed and current. (PRR §3)
- [x] Language registry is consistent across backend, frontend, workers, and tests.
- [x] No hardcoded language lists remain in release-critical flows.
- [x] Locale files have no missing release-blocking keys. (English fallback covers all; partial locales documented)
- [x] Duplicate translation keys reviewed. (mood-trigger duplicates removed in M6; no-duplicate test enforces)
- [x] English fallback behavior verified. (`MultilingualMilestoneSixTest` asserts every locale key has an en fallback)
- [x] Unicode rendering verified for all supported scripts. (registry UTF-8 assertion + world-language Bible tests)
- [ ] Native-speaker or reviewer notes captured for known translation limitations. (limitations logged in PRR §8; native review pending)

## RTL Verification

Milestone 4 shipped and was approved (`c2c6c635`); the items below cite that
plus automated coverage. Live, on-device RTL visual QA remains a human item
(see PRR §8).

- [x] Arabic UI verified. (M4; `rtl` flag asserted in `MultilingualMilestoneFourTest`)
- [x] Hebrew UI verified. (M4)
- [x] Global `<html dir>` behavior verified. (M4 + tests)
- [x] Logical CSS migration reviewed. (M4)
- [x] Header mirrors correctly. (M4)
- [x] Bottom navigation mirrors correctly. (M4)
- [x] Carousel and directional icons mirror only when appropriate. (M4)
- [x] Church Service verified in RTL. (M4)
- [x] Pastor Chat verified in RTL. (M4)
- [x] Bible Study verified in RTL. (M4)
- [x] Worship Radio verified in RTL. (M4)
- [x] Special Day verified in RTL. (M4)
- [x] Admin Console verified in RTL. (M4)
- [x] Mixed English/Bible references remain readable inside Arabic/Hebrew text. (M4)
- [x] PDF generation preserves RTL order. (M4)
- [x] Printing preserves RTL order. (M4)
- [x] Existing LTR languages remain visually unchanged. (Playwright LTR suites pass)

## Product Surface Verification

Verified via passing suites + static surface presence (PRR §3–4); content-quality
review by native speakers is a separate human sign-off.

- [x] Church Service verified across supported service languages. (`WorshipServicePipeline`; intake tests)
- [x] Pastor Chat verified across supported service languages. (`PastorChatController`; detection tests)
- [x] Bible Study verified across supported study languages. (`StudySessionPipeline`; alias tests)
- [x] Worship Radio verified across supported service languages. (`MoodExpansionServiceTest`)
- [x] Special Day verified across supported service languages. (`SpecialSundayController`; intake tests)
- [x] Bible Reader verified across supported Bible languages. (`test_scripture` world-language coverage)
- [x] Admin Console settings verified. (admin endpoint tests)
- [x] Language selection verified. (`/api/languages` + `LocaleTest`)
- [x] Narration and TTS voice selection verified. (TTS-locale registry; MMS narrator intact)
- [x] Knowledge Library and RAG retrieval verified unaffected. (no changes; suite green)
- [x] Authentication and account flows verified unaffected. (auth suite green)

## Device And Browser Verification

- [x] Desktop layout verified. (Playwright desktop project)
- [ ] Tablet layout verified. (not in automated matrix; manual)
- [x] Mobile layout verified. (Playwright mobile project, 390px)
- [ ] Mobile RTL verified. (live on-device QA pending — PRR §8)
- [x] No horizontal overflow in core flows. (Playwright no-overflow assertions)
- [x] No uncaught browser console errors in release-critical flows. (Playwright shell smoke)
- [x] No unexpected API errors in release-critical flows. (Playwright shell smoke)

## Performance And Reliability

- [x] Locale loading reviewed. (PRR §6 — static glob + en fallback)
- [x] Vue bundle size reviewed. (PRR §6 — over advisory, non-blocking)
- [x] Worker startup reviewed. (PRR §6)
- [x] Database query behavior reviewed. (no schema/query changes this program)
- [x] Cache behavior reviewed. (PRR §6 — config-driven registry/mood dictionary)
- [x] Logging and error handling reviewed. (no changes; suite green)
- [x] Queue and worker services verified. (worker suite green)

## Security

- [x] Input validation reviewed. (PRR §7; backend suite)
- [x] Authorization reviewed. (PRR §7; backend suite)
- [x] API exposure reviewed. (PRR §7 — no new endpoints in M7)
- [x] Upload validation reviewed. (PRR §7 — unchanged)
- [x] Localization injection risks reviewed. (prompt-engine fence/neutralize; `test_prompt_engine`)
- [ ] Production release gate security items completed in `RELEASE_GATE_v1.md`. (human gate)

## Documentation

- [x] Release notes prepared. (PRR §5)
- [ ] Administrator guide completed or updated. (existing docs; no admin-facing change this program)
- [ ] Developer guide completed or updated. (existing docs; no developer-facing change this program)
- [x] Supported language matrix completed. (PRR §3 + roadmap)
- [x] AI capability matrix completed. (PRR §4)
- [x] Known limitations documented. (PRR §8)
- [x] Upgrade notes documented. (no schema/API change; deploy steps in PRR §6 / DEPLOY.md)
- [x] Future roadmap remains post-v1.0 only. (PRR §9; roadmap Future section)

## Release

- [ ] Final PR into `main` reviewed.
- [ ] `main` is green after merge.
- [ ] Annotated tag created:

  ```bash
  git tag -a v1.0.0-multilingual -m "AI Virtual Church v1.0 Multilingual Release"
  ```

- [ ] `main` pushed.
- [ ] `v1.0.0-multilingual` tag pushed.
- [ ] GitHub Release published with release notes and production readiness summary.

## Sign-Off

| Role | Name | Date | Verdict |
|---|---|---|---|
| Engineering | | | Go / No-go |
| Product | | | Go / No-go |
| Release owner | | | Go / No-go |

Do not publish `v1.0.0-multilingual` unless every release-blocking item is complete
or explicitly marked N/A with a recorded reason.
