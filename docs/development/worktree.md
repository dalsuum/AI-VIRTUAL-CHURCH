# Developing in a git worktree

**The checkout at `/opt/ai-church` is production.** nginx serves `frontend/dist` and
`backend/public` directly from it — there is no separate deploy directory. It must
stay on `main` at all times; it only moves forward as an intentional deploy step
(merge → `git pull` → `php artisan migrate --force` → `optimize:clear` →
`queue:restart`, plus `composer install` when PHP deps changed and `npm run build`
in `frontend/` when the frontend changed).

All feature work therefore happens in a **worktree** on a feature branch.

## Creating a worktree

```bash
cd /opt/ai-church
git worktree add .claude/worktrees/<name> -b <branch> main
```

## Wiring the gitignored runtime files

A fresh checkout lacks everything gitignored. Symlink these from the main checkout
(the deps are identical as long as your branch doesn't change them):

```bash
WT=/opt/ai-church/.claude/worktrees/<name>
ln -s /opt/ai-church/backend/vendor        $WT/backend/vendor
ln -s /opt/ai-church/backend/.env          $WT/backend/.env
ln -s /opt/ai-church/backend/.env.testing  $WT/backend/.env.testing
ln -s /opt/ai-church/frontend/node_modules $WT/frontend/node_modules
```

**`.env.testing` is the one people forget.** PHPUnit sets `APP_ENV=testing`, which
makes Laravel prefer `.env.testing` over `.env`. Without the symlink the suite
silently falls back to prod-ish `.env` values (file sessions instead of array,
different stateful domains, …) and ~7 unrelated auth/session tests fail with
`Session store not set on request`. If you see that error in a worktree, this is
why.

If your branch **changes composer/npm dependencies**, replace the relevant symlink
with a real `composer install` / `npm ci` inside the worktree.

## Running things

```bash
cd $WT/backend  && ./vendor/bin/phpunit     # hits the isolated ai_church_test DB
cd $WT/frontend && npm run build            # dist/ stays local to the worktree
cd $WT/frontend && npm run icons:gen        # after editing scripts/gen-icons.mjs
```

The test database (`ai_church_test`, from `phpunit.xml` + `.env.testing`) is shared
with the main checkout — don't run two suites concurrently.

Never run `php artisan migrate` from a worktree without `--env` thought: the
symlinked `.env` points at the **production** database. Migrations run on prod only
as part of the deploy step in the main checkout.

## Cleaning up

```bash
git worktree remove .claude/worktrees/<name>   # after the branch merges
```
