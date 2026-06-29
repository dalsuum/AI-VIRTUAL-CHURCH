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

- [ ] Milestone 4: Arabic, Hebrew, and RTL completed and committed.
- [ ] Milestone 5: system QA and regression completed and committed.
- [ ] Milestone 6: language intelligence and vocabulary completed and committed.
- [ ] Milestone 7: production readiness completed and committed.
- [ ] Production readiness report reviewed and approved.

## Automated Checks

- [ ] CI is green on the release branch.
- [ ] Backend tests pass.
- [ ] Frontend build passes.
- [ ] Worker tests pass.
- [ ] Security checks pass.
- [ ] Pylint passes.
- [ ] Dependency audits pass.
- [ ] Secret scanning passes.
- [ ] Static analysis and formatting checks pass where applicable.

## Multilingual Verification

- [ ] Supported language matrix reviewed and current.
- [ ] Language registry is consistent across backend, frontend, workers, and tests.
- [ ] No hardcoded language lists remain in release-critical flows.
- [ ] Locale files have no missing release-blocking keys.
- [ ] Duplicate translation keys reviewed.
- [ ] English fallback behavior verified.
- [ ] Unicode rendering verified for all supported scripts.
- [ ] Native-speaker or reviewer notes captured for known translation limitations.

## RTL Verification

- [ ] Arabic UI verified.
- [ ] Hebrew UI verified.
- [ ] Global `<html dir>` behavior verified.
- [ ] Logical CSS migration reviewed.
- [ ] Header mirrors correctly.
- [ ] Bottom navigation mirrors correctly.
- [ ] Carousel and directional icons mirror only when appropriate.
- [ ] Church Service verified in RTL.
- [ ] Pastor Chat verified in RTL.
- [ ] Bible Study verified in RTL.
- [ ] Worship Radio verified in RTL.
- [ ] Special Day verified in RTL.
- [ ] Admin Console verified in RTL.
- [ ] Mixed English/Bible references remain readable inside Arabic/Hebrew text.
- [ ] PDF generation preserves RTL order.
- [ ] Printing preserves RTL order.
- [ ] Existing LTR languages remain visually unchanged.

## Product Surface Verification

- [ ] Church Service verified across supported service languages.
- [ ] Pastor Chat verified across supported service languages.
- [ ] Bible Study verified across supported study languages.
- [ ] Worship Radio verified across supported service languages.
- [ ] Special Day verified across supported service languages.
- [ ] Bible Reader verified across supported Bible languages.
- [ ] Admin Console settings verified.
- [ ] Language selection verified.
- [ ] Narration and TTS voice selection verified.
- [ ] Knowledge Library and RAG retrieval verified unaffected.
- [ ] Authentication and account flows verified unaffected.

## Device And Browser Verification

- [ ] Desktop layout verified.
- [ ] Tablet layout verified.
- [ ] Mobile layout verified.
- [ ] Mobile RTL verified.
- [ ] No horizontal overflow in core flows.
- [ ] No uncaught browser console errors in release-critical flows.
- [ ] No unexpected API errors in release-critical flows.

## Performance And Reliability

- [ ] Locale loading reviewed.
- [ ] Vue bundle size reviewed.
- [ ] Worker startup reviewed.
- [ ] Database query behavior reviewed.
- [ ] Cache behavior reviewed.
- [ ] Logging and error handling reviewed.
- [ ] Queue and worker services verified.

## Security

- [ ] Input validation reviewed.
- [ ] Authorization reviewed.
- [ ] API exposure reviewed.
- [ ] Upload validation reviewed.
- [ ] Localization injection risks reviewed.
- [ ] Production release gate security items completed in `RELEASE_GATE_v1.md`.

## Documentation

- [ ] Release notes prepared.
- [ ] Administrator guide completed or updated.
- [ ] Developer guide completed or updated.
- [ ] Supported language matrix completed.
- [ ] AI capability matrix completed.
- [ ] Known limitations documented.
- [ ] Upgrade notes documented.
- [ ] Future roadmap remains post-v1.0 only.

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
