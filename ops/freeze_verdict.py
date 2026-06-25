#!/usr/bin/env python3
"""
Autonomous freeze-gate decision layer. Runs hourly (cron); self-gates on window
age so it does nothing until the 24h freeze has elapsed, then renders a verdict
over the whole window and acts on it:

  PASS  -> all green + adequate coverage -> write verdict, snapshot summary, and
           run teardown (removes cron incl. this job, deletes synthetic accounts).
  WARN  -> mostly green but coverage gaps (missed cycles) -> write verdict, notify,
           keep running (no teardown) so a human can look.
  FAIL  -> any 5xx / failed check / balance regression / browser failure ->
           preserve state, snapshot the logs to a timestamped dir, keep running.

Flags:
  --dry-run   evaluate current data and print the verdict WITHOUT acting or gating
  --force     act now regardless of window age (for an explicit early close)

Config via env (.freeze_env): FREEZE_LOG, FREEZE_BROWSER_LOG. Window length and
coverage thresholds below.
"""
import json, os, sys, shutil, subprocess, datetime

DIR = os.path.dirname(os.path.abspath(__file__))
API_LOG = os.environ.get("FREEZE_LOG", os.path.join(DIR, "logs", "freeze.jsonl"))
BRW_LOG = os.environ.get("FREEZE_BROWSER_LOG", os.path.join(DIR, "logs", "freeze_browser.jsonl"))
VERDICT_FILE = os.path.join(DIR, "logs", "freeze_verdict.txt")
TEARDOWN = os.path.join(DIR, "freeze_teardown.sh")

WINDOW_HOURS = 24
API_PER_HOUR = 6           # */10
BRW_PER_HOUR = 1           # hourly
COVERAGE_MIN = 0.80        # below this => WARN (missed cycles)

DRY = "--dry-run" in sys.argv
FORCE = "--force" in sys.argv


def load(path):
    out = []
    if os.path.exists(path):
        with open(path) as f:
            for line in f:
                line = line.strip()
                if line:
                    try: out.append(json.loads(line))
                    except Exception: pass
    return out


def ts_of(r):
    try:
        return datetime.datetime.fromisoformat(r["ts"].replace("Z", "+00:00"))
    except Exception:
        return None


def main():
    now = datetime.datetime.now(datetime.timezone.utc)
    api = load(API_LOG)
    brw = load(BRW_LOG)

    starts = [t for t in (ts_of(r) for r in api) if t]
    if not starts:
        print("PENDING: no API cycles yet — gate not armed.")
        return 0
    start = min(starts)
    age_h = (now - start).total_seconds() / 3600.0

    if not DRY and not FORCE and age_h < WINDOW_HOURS:
        print(f"PENDING: freeze running {age_h:.1f}h / {WINDOW_HOURS}h — verdict not due yet.")
        return 0

    # ---- evaluate the window ----
    api_total = len(api)
    api_fail  = sum(1 for r in api if not r.get("ok"))
    api_5xx   = sum(r.get("err5xx", 0) for r in api)
    fails = {}
    for r in api:
        for f in r.get("fails", []):
            fails[f] = fails.get(f, 0) + 1
    bals = [r.get("balance") for r in api if isinstance(r.get("balance"), int)]
    regressions = sum(1 for a, b in zip(bals, bals[1:]) if b < a)

    brw_total = len(brw)
    brw_fail  = sum(1 for r in brw if not r.get("ok"))
    brw_console = sum(r.get("console_issues", 0) for r in brw)

    exp_api = max(1, int(age_h * API_PER_HOUR))
    exp_brw = max(1, int(age_h * BRW_PER_HOUR))
    api_cov = api_total / exp_api
    brw_cov = brw_total / exp_brw

    hard_fail = (api_5xx > 0 or api_fail > 0 or brw_fail > 0 or regressions > 0)
    coverage_gap = (api_cov < COVERAGE_MIN or brw_cov < 0.5)

    if hard_fail:
        verdict = "FAIL"
    elif coverage_gap:
        verdict = "WARN"
    else:
        verdict = "PASS"

    lines = [
        f"FREEZE VERDICT: {verdict}",
        f"rendered: {now.isoformat()}",
        f"window: {start.isoformat()} -> {now.isoformat()}  ({age_h:.1f}h)",
        f"API: {api_total - api_fail}/{api_total} ok  (coverage {api_cov*100:.0f}% of ~{exp_api})  5xx={api_5xx}",
        f"Browser: {brw_total - brw_fail}/{brw_total} ok  (coverage {brw_cov*100:.0f}% of ~{exp_brw})  console_issues={brw_console}",
        f"balance: {bals[0] if bals else '?'} -> {bals[-1] if bals else '?'}  regressions={regressions}",
    ]
    if fails:
        lines.append("failing checks: " + ", ".join(f"{k}×{v}" for k, v in sorted(fails.items(), key=lambda x: -x[1])))
    report = "\n".join(lines)
    print(report)

    if DRY:
        print("(dry-run: no action taken)")
        return 0

    os.makedirs(os.path.dirname(VERDICT_FILE), exist_ok=True)
    with open(VERDICT_FILE, "w") as f:
        f.write(report + "\n")

    if verdict == "FAIL":
        snap = os.path.join(DIR, "logs", f"freeze_FAIL_{now.strftime('%Y%m%dT%H%M%SZ')}")
        os.makedirs(snap, exist_ok=True)
        for p in (API_LOG, BRW_LOG, VERDICT_FILE):
            if os.path.exists(p):
                shutil.copy2(p, snap)
        print(f"FAIL: state preserved, logs snapshotted -> {snap}. Cron left running for inspection.")
        return 1

    if verdict == "WARN":
        print("WARN: coverage gap — notify only, no teardown. Re-evaluates next hour.")
        return 0

    # PASS -> snapshot summary then auto-teardown
    print("PASS: freeze clean. Running teardown (cron + synthetic accounts)...")
    try:
        subprocess.run(["bash", TEARDOWN], check=False, timeout=120)
    except Exception as e:
        print(f"  teardown error: {e} — remove cron/accounts manually.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
