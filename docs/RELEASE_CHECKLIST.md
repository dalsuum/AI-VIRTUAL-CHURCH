# Release Checklist (per milestone)

Run before tagging every milestone release (v0.9.x onward). This is the routine
per-milestone discipline; the **v1.0 public launch** additionally clears the
go/no-go gate in [`RELEASE_GATE_v1.md`](RELEASE_GATE_v1.md), and feature deploys
follow [`DEPLOYMENT_CHECKLIST.md`](DEPLOYMENT_CHECKLIST.md). Companion to
[`BIBLE_KNOWLEDGE_MODEL_INVARIANTS.md`](BIBLE_KNOWLEDGE_MODEL_INVARIANTS.md) and
[`LANGUAGE_INTELLIGENCE_DOD.md`](LANGUAGE_INTELLIGENCE_DOD.md).

## Code
- [ ] All PRs merged · main green · no uncommitted changes.
- [ ] No temporary feature flags · no release-blocking TODOs.

## Tests
- [ ] Backend tests pass · frontend build passes · worker tests pass.
- [ ] Search regression passes · AI smoke tests pass.

## Localization
- [ ] English fallback verified · new locale resources validated.
- [ ] Missing-translation report reviewed · RTL regression checked.

## Bible
- [ ] Registry loads · metadata validates against schema · alias search regression passes.
- [ ] Canonical IDs unchanged · new metadata fields optional.

## AI
- [ ] Prompt templates validated · language + persona selection verified · knowledge model loads.

## Performance
- [ ] Startup time unchanged · search latency unchanged · metadata loading benchmarked.

## Documentation
- [ ] Changelog updated · architecture docs updated only if rules changed · migration/upgrade notes if any.

## Release tag
- [ ] Create annotated tag · push tag · publish release notes.

## Philosophy
Every release should be understandable, reviewable, reproducible, reversible.
Prefer small, predictable releases over large milestone drops.
