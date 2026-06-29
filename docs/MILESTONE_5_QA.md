# Milestone 5 — System QA and Regression Results

Branch: `release/v1.0-multilingual` (from `c2c6c635`).
Run date: 2026-06-29.

This records the automated QA executed for Milestone 5 of the multilingual v1.0
release. No product features were added; scope was QA and confirmed-regression
fixes only. No regressions were found, so no code changes were required.

## Automated suites

| Suite | Command | Result |
|---|---|---|
| Frontend Playwright (desktop + mobile) | `npx playwright test` | 56 passed |
| Backend PHPUnit | `vendor/bin/phpunit` | 285 passed, 1 skipped |
| Worker pytest | `python3 -m pytest -q` | 70 passed |
| Frontend production build | `npm run build` | built OK |

## Coverage notes

- Playwright covers the layout shell, mobile bottom navigation, scroll
  restoration, Bible reader, Songs, and Worship Radio mobile layout — including
  no-horizontal-overflow assertions at 390px (mobile responsive regression).
- PHPUnit and worker suites pass without new failures relative to `c2c6c635`.
- Manual/visual checklist items (RTL surface walkthroughs, native-speaker
  translation review, security/pylint/dependency audits) remain tracked in
  [`RELEASE_CHECKLIST_V1.md`](RELEASE_CHECKLIST_V1.md) and are not covered by the
  automated suites above.
