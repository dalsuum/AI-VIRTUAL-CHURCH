# DEPLOYMENT — read before running migrations

The one rule that prevents the "never-ending debugging" loop:

> **Migrations are a release-only operation. Never run them during feature or
> verification work.**

## The freeze rule

- ❌ No `php artisan migrate --force` during development, debugging, or while
  verifying behaviour.
- ❌ No "aligning prod schema" mid-task. If a missing table shows up during
  debugging, that is a *release-sequencing* bug — fix the timing, don't migrate.
- ✅ Migrations run **only** in the deploy step below, **exactly once per release**,
  by **one** designated executor (a person or CI) — never two sessions at once.

Why: applying schema during active debugging changes runtime behaviour mid-loop,
which spawns more debugging and more "alignment" — an illusion of an unfinished
system. Moving migrations out of the debug loop stops it.

## Separate code work from schema work

- **Feature branch/commit:** pipeline logic, controllers, services, tests — no migrations.
- **Migration commit:** `database/migrations/*` **only**, reviewed and deployed as a unit.
- Do not mix the two in one commit. (Optional CI guard: fail if a commit touches
  `database/migrations/*` **and** non-migration code.)

## Deterministic deploy sequence

```
1. git pull   (release tag / main)
2. php artisan migrate --force      # once, by the designated executor
3. deploy code  (build frontend: cd frontend && npm run build)
4. run smoke tests
```

Never interleave step 2 with debugging or live verification.

## Schema safety (current state)

Migrations are **additive + nullable + drop-safe**, and every one has a working
`down()`, so `migrate:rollback` is symmetric and old code tolerates the new schema.
No feature-flagged schema system or rollback redesign is needed.

For the full feature-promotion checklist (env vars, backfills, spot-checks), see
[`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md).
