# Definition of Done — Language Intelligence (v0.9.x)

Every PR under the v0.9.x language-intelligence milestone must satisfy all of the
following before merge. Companion to `BIBLE_KNOWLEDGE_MODEL_INVARIANTS.md`.

## Scope
- One logical change only. No unrelated refactoring. No opportunistic cleanup.

## Architecture
- Conforms to `BIBLE_KNOWLEDGE_MODEL_INVARIANTS.md`.
- No duplicate sources of truth. No new framework layers. Per-consumer ownership preserved.

## Compatibility
- Existing APIs unchanged unless explicitly documented.
- Existing search behavior unchanged unless intentionally improved.
- Existing locale behavior unchanged.
- Existing AI behavior unchanged except for the feature being introduced.

## Data
- Stable canonical IDs only. Display strings never become identifiers.
- Metadata additions are additive. Missing optional fields handled gracefully.

## Localization
- English fallback preserved.
- No machine-generated doctrinal content merged without native-speaker review.
- Locale resources remain data-only.

## AI
- Prompt behavior separated from factual knowledge.
- No theological facts duplicated inside prompts.
- AI consumes the canonical knowledge model where appropriate.

## Testing
- New tests for new behavior. Existing tests remain green. No test removals without justification.

## Documentation
- If the PR changes the knowledge model's *architectural rules*, update the invariants doc — otherwise leave it untouched.

## Review (PR description must include)
- Purpose · Non-goals · Compatibility statement · Test summary · Future work (if any).

## Philosophy
Prefer small, reviewable improvements. Avoid large mixed-purpose PRs.
Knowledge grows incrementally; architecture stays stable.
