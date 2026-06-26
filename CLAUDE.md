# Operating Contract — Ponytail++

These rules are **mandatory** for every coding task in this repo. They override default behavior.
The best code is the code that never needed to be written. Correctness > simplicity. Security > cleverness. Smallest safe diff wins.

## Decision ladder (complete BEFORE writing code)

1. Can this feature be deleted instead?
2. Does the project already implement this? (search first)
3. Can the language standard library solve it? (PHP / Python / JS stdlib)
4. Can the framework solve it? (Laravel, Vue)
5. Can an already-installed dependency solve it? (`composer.json`, `package.json`, workers' `requirements`)
6. Can configuration solve it instead of code?
7. Can it be done in one or a few lines?
8. Only if every answer is NO, write new custom code.

## Before every implementation, output:

```
## Decision
Need new code? YES / NO
Reason:
Reuse: existing project / stdlib / framework / dependency
Chosen solution:
Expected LOC added:
```

Then implement.

## Implementation rules

- Produce the smallest correct diff; modify existing files instead of creating new ones.
- Prefer composition over abstraction. Avoid wrappers, helper classes, utility files, factories, builders.
- No new service layer / custom hook / interface unless one already exists and is reused.
- No duplicate logic. No premature optimization. No future-proofing. YAGNI is mandatory.
- Never create code "for possible future use."
- **Max 1 new file per task.** Max 0 new abstractions unless it removes duplication in ≥3 places.

## Security (never remove to shorten code)

validation · authentication · authorization · logging · audit · rate limiting · security checks · tests.
All code OWASP top-10 clean, no hardcoded secrets, sanitize inputs, least privilege.

## Repo-specific conventions

- **Stack:** Laravel 12 backend (`backend/`), Vue frontend (`frontend/`), Python AI workers (`workers/`), nginx-served prod.
- **Deploy:** committed `vendor/` is incomplete — deploys that change deps MUST run `composer install` on the box.
- **Frontend deploy:** no dev server — after any frontend change run `npm run build` in `frontend/`.
- **After any code change:** update `README.md` and push to GitHub.
- **Service output:** never include the worshipper's name in generated service text (any language).
- **Never remove** the Tedim MMS-TTS narrator or worship/closing_hymn segments in pipeline changes.
- Test-first: add/extend tests with behavior changes.

## When reviewing code

Act as a ruthless Staff Engineer. Find dead code, duplication, needless abstractions/files/wrappers/helpers,
unnecessary dependencies/inheritance. **Recommend deletions first.** Add only what is absolutely required.
