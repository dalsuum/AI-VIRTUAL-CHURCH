# Freeze monitoring harness (`ops/`)

Instruments the **24h staging/production freeze** (gate §9 in
[../docs/RELEASE_GATE_v1.md](../docs/RELEASE_GATE_v1.md)): instead of passively waiting,
synthetic probes exercise the core SaaS loop on a schedule and record results so you can
see whether the system **stays correct over time** under real conditions.

## What it checks

**API probe** (`freeze_api_probe.py`, every 10 min — pure stdlib, no deps):
- `/auth/session` → `200 {authenticated:false}` logged out (no 401 contract holds)
- member login → `/me` 200; **token consistency** `/me` vs `/subscription`
- `/auth/session` shows authenticated while logged in
- **logout truly destroys the session** (`/me` → 401 after) — auth-drift guard
- admin grant N tokens → **ledger→balance integrity** (`new == before + N`)
- **cross-session propagation**: member re-login sees the new balance
- any HTTP **5xx** in the cycle

**Browser probe** (`freeze_browser_probe.mjs`, hourly — Playwright/Chromium):
- logged-out home load is **console-clean** (no error/warning noise)
- login → account page renders the token gauge
- **logout → nav clears + guard redirects `#account`→`#login`** (auth-drift check;
  logout is browser-owned because `SESSION_DRIVER=cookie` makes a hand-rolled
  multi-cookie logout replay brittle)
- captures console errors, Vue warnings, and 5xx that only appear in a browser

**Verdict gate** (`freeze_verdict.py`, hourly): the autonomous decision layer. It
self-gates on window age (does nothing until 24h elapse), then evaluates the whole
window and acts:
- **PASS** (all green + coverage ≥80%) → writes `logs/freeze_verdict.txt`, then runs
  `freeze_teardown.sh` automatically (removes cron + synthetic accounts).
- **WARN** (mostly green but missed cycles / coverage gap) → writes the verdict,
  notifies, keeps running so a human can look; re-evaluates next hour.
- **FAIL** (any 5xx / failed check / balance regression / browser failure) →
  **preserves** state and snapshots the logs to `logs/freeze_FAIL_<ts>/`; no teardown.

Inspect early without acting: `python3 ops/freeze_verdict.py --dry-run`.
Force an explicit early close: `python3 ops/freeze_verdict.py --force`.

## Usage

```bash
bash ops/freeze_setup.sh        # create synthetic accounts, install cron + playwright
tail -f ops/logs/freeze.jsonl   # watch API cycles
python3 ops/freeze_summary.py   # health score + drift (exit 0 = all green)
bash ops/freeze_teardown.sh     # remove cron + delete synthetic accounts (keeps logs)
#   add --purge-logs to also delete the logs
```

## Notes

- **Synthetic accounts** `freeze_member_*@healthcheck.local` / `freeze_admin_*@healthcheck.local`
  are created once and reused; teardown deletes them and their ledger rows.
- Credentials live in `ops/.freeze_env` (chmod 600, git-ignored). Cron sources it.
- Each API cycle grants the member **1 token**, so its balance climbs by ~144 over 24h —
  expected and harmless; `freeze_summary.py` treats a *decrease* as a drift regression.
- `ops/logs/`, `ops/.freeze_env`, and `ops/node_modules/` are git-ignored; the scripts
  are committed.
- Cron uses the invoking user's crontab (no sudo). Verify with
  `crontab -l | grep freeze-harness`.
