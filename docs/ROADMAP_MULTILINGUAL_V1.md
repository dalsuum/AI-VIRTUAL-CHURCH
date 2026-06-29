# AI Virtual Church v1.0 Multilingual Roadmap

This document is the source of truth for the v1.0 multilingual release plan.
The program is feature-locked: until `v1.0.0-multilingual`, every change should
clearly belong to one of the remaining milestones below.

**Roadmap Status:** Frozen for v1.0. Changes require review and should only be
made for release-blocking issues.

Current checkpoint:

- `6394abbd` — `feat: multilingual milestone 1-3 (fr,de,es,ja,zh-CN,ko,hi,ta,th)`

## Roadmap

| Milestone | Scope | Status | Release rule |
|---|---|---|---|
| 1 | French, German, Spanish | Complete | Included in `6394abbd` |
| 2 | Japanese, Chinese Simplified, Korean | Complete | Included in `6394abbd` |
| 3 | Hindi, Tamil, Thai | Complete | Included in `6394abbd` |
| 4 | Arabic, Hebrew, RTL | Pending | Commit after review |
| 5 | System QA and regression | Pending | Commit after review |
| 6 | Language intelligence and vocabulary | Pending | Commit after review |
| 7 | Production readiness and release candidate | Pending | Commit after review |

Do not add new milestones before v1.0 unless a release-blocking production issue
requires it.

## Milestone 4: Arabic, Hebrew, RTL

Goal: complete Arabic and Hebrew support and validate RTL behavior across the
multilingual product.

Acceptance criteria:

- Native Arabic UI.
- Native Hebrew UI.
- Logical CSS preferred: `margin-inline`, `padding-inline`, `inset-inline`,
  `text-align: start`, and `text-align: end`.
- No hardcoded left/right layout assumptions in scoped multilingual surfaces.
- Church Service verified.
- Pastor Chat verified.
- Bible Study verified.
- Worship Radio verified.
- Special Day verified.
- Admin Console verified for language, narration, settings, and Special Day flows.
- Mobile RTL verified separately from desktop RTL.
- Mixed English/Bible references inside Arabic/Hebrew remain readable.
- Icons mirror only where directional meaning requires mirroring.
- Native Edge TTS voices verified.
- PDF and print output preserve RTL order.
- Existing LTR languages remain visually unchanged.

Do not modify RAG, embeddings, Qdrant, authentication, billing, user accounts,
database schema, APIs, worker architecture, or Bible data unless a verified
Milestone 4 bug requires it.

## Milestone 5: System QA and Regression

Goal: prove the multilingual implementation works end to end without regressions.

Scope:

- Backend tests.
- Frontend build.
- Worker tests.
- Integration tests.
- Cross-language regression.
- Mobile responsive verification.
- Performance regression checks.
- Translation audit.
- Missing-key audit.
- RTL regression tests.

Do not add new product features during this milestone.

## Milestone 6: Language Intelligence and Vocabulary

Goal: improve language quality without changing the core architecture.

Scope:

- Christian terminology.
- Church vocabulary.
- Pastor Chat natural language.
- Worship terminology.
- Bible aliases.
- Holiday vocabulary.
- Prayer vocabulary.
- Search keywords.
- Synonyms.
- Natural prompts.

Changes must reuse existing localization, language registry, and translation
helpers. Avoid duplicate prompt logic or parallel language registries.

## Milestone 7: Production Readiness

Goal: prepare the multilingual implementation as a release candidate.

Verify:

- Church Service.
- Pastor Chat.
- Bible Study.
- Worship Radio.
- Special Day.
- Knowledge Library.
- Bible Reader.
- Authentication.
- Admin Console.
- Settings.
- Worker services.
- RAG retrieval.
- Narration.
- Embeddings.
- Qdrant.
- Logging.
- Error handling.

Review:

- Security: validation, permissions, API exposure, upload handling, localization
  injection risks.
- Performance: locale loading, bundle size, worker startup, database queries,
  cache behavior.
- Documentation: administrator guide, developer guide, supported language matrix,
  AI capability matrix, release notes, and upgrade notes.

Milestone 7 ends with a production readiness report. Do not create the release tag
until that report is reviewed and approved.

## Supported Language Matrix

| Code | Language | UI/service | Bible Reader | Bible Study | Direction | v1.0 status |
|---|---|---:|---:|---:|---|---|
| `en` | English | Yes | Yes | Yes | LTR | Complete |
| `my` | Burmese | Yes | Yes | Yes | LTR | Complete |
| `td` | Tedim | Yes | Yes | Yes | LTR | Complete |
| `cfm` | Falam | No | Yes | Yes | LTR | Bible/Bible Study only |
| `cnh` | Hakha | No | Yes | Yes | LTR | Bible/Bible Study only |
| `lus` | Lushai | No | Yes | Yes | LTR | Bible/Bible Study only |
| `fr` | French | Yes | Yes | Yes | LTR | Complete |
| `de` | German | Yes | Yes | Yes | LTR | Complete |
| `es` | Spanish | Yes | Yes | Yes | LTR | Complete |
| `ja` | Japanese | Yes | Yes | Yes | LTR | Complete |
| `zh-CN` | Chinese Simplified | Yes | Yes | Yes | LTR | Complete |
| `ko` | Korean | Yes | Yes | Yes | LTR | Complete |
| `hi` | Hindi | Yes | Yes | Yes | LTR | Complete |
| `ta` | Tamil | Yes | Yes | Yes | LTR | Complete |
| `th` | Thai | Yes | Yes | Yes | LTR | Complete |
| `ar` | Arabic | Pending | Yes | Pending | RTL | Milestone 4 |
| `he` | Hebrew | Pending | Yes | Pending | RTL | Milestone 4 |

Falam, Hakha, and Lushai remain Bible/Bible Study languages for v1.0. Promoting
them to full Church Service locales is future work.

## Definition of Done

The v1.0 multilingual implementation is complete only when all items below are true.

### Language Coverage

Supported languages:

- English.
- Burmese.
- Tedim.
- Falam, Hakha, and Lushai for Bible/Bible Study.
- French.
- German.
- Spanish.
- Japanese.
- Chinese Simplified.
- Korean.
- Hindi.
- Tamil.
- Thai.
- Arabic.
- Hebrew.

### Functional Coverage

Every supported service language works correctly in:

- Church Service.
- Pastor Chat.
- Bible Study.
- Worship Radio.
- Special Day.
- Bible Reader.
- Admin Console.
- Narration.
- Settings.
- Language selection.

### AI Quality

- Natural prompts.
- Native vocabulary.
- Context-aware responses.
- Correct Christian terminology.
- Natural greetings.
- Native prayer wording.
- Native worship terminology.
- Native Bible terminology.

### Technical Quality

- No duplicated localization logic.
- No hardcoded language lists.
- Centralized language registry.
- Unicode-safe implementation.
- RTL fully supported.
- Native Edge TTS voices.
- Worker language routing verified.

### Testing

Pass:

- Backend tests.
- Frontend build.
- Worker tests.
- Integration tests.
- RTL regression tests.
- Mobile responsive tests.
- Cross-language regression tests.

### Performance

- Locale loading optimized.
- Bundle size reviewed.
- Worker startup reviewed.
- Cache behavior verified.

### Security

- Input validation verified.
- Authorization verified.
- Localization safety reviewed.
- Upload validation reviewed.
- API validation reviewed.

### Documentation

Complete:

- Administrator guide.
- Developer guide.
- Supported language matrix.
- AI capability matrix.
- Release notes.
- Upgrade notes.

## Release Criteria

After Milestone 7 passes:

1. Review the production readiness report.
2. Approve release.
3. Create the annotated tag:

   ```bash
   git tag -a v1.0.0-multilingual -m "AI Virtual Church v1.0 Multilingual Release"
   git push origin v1.0.0-multilingual
   ```

4. Publish release notes.

Only then is the multilingual implementation considered complete.

## Known Limitations

- Falam, Hakha, and Lushai are Bible/Bible Study languages only in v1.0.
- Arabic and Hebrew full UI/service support is pending Milestone 4.
- Native-speaker review is still required for final translation quality.
- Some AI-generated wording may require Milestone 6 vocabulary and terminology
  tuning before release candidate approval.
- Milestone 7 must verify that RAG, Bible retrieval, workers, authentication,
  billing, and existing LTR layouts remain unaffected.

## Future Roadmap

Potential post-v1.0 work:

- v1.1: Promote selected Bible/Bible Study-only languages to full service locales.
- v1.1: Add deeper native-speaker review workflows and translation provenance.
- v1.2: Expand language-specific worship libraries and Bible alias coverage.
- v1.2: Add richer per-language QA dashboards for admins and maintainers.

Future work must be planned after `v1.0.0-multilingual` ships.
