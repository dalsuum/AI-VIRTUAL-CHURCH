#!/usr/bin/env python3
"""Summarize the freeze logs into a single health score + drift view.
Usage: freeze_summary.py [api_log] [browser_log]"""
import json, os, sys

DIR = os.path.dirname(os.path.abspath(__file__))
API_LOG = sys.argv[1] if len(sys.argv) > 1 else os.path.join(DIR, "logs", "freeze.jsonl")
BRW_LOG = sys.argv[2] if len(sys.argv) > 2 else os.path.join(DIR, "logs", "freeze_browser.jsonl")


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


def report(name, rows):
    if not rows:
        print(f"{name}: no cycles recorded yet"); return
    n = len(rows)
    passed = sum(1 for r in rows if r.get("ok"))
    err5xx = sum(r.get("err5xx", 0) for r in rows)
    fail_names = {}
    for r in rows:
        for f in r.get("fails", []):
            fail_names[f] = fail_names.get(f, 0) + 1
    score = round(100.0 * passed / n, 1)
    print(f"{name}: {passed}/{n} cycles passed  (health {score}%)  total 5xx={err5xx}")
    print(f"  window: {rows[0].get('ts')}  ->  {rows[-1].get('ts')}")
    if fail_names:
        print("  failing checks: " + ", ".join(f"{k}×{v}" for k, v in sorted(fail_names.items(), key=lambda x: -x[1])))
    # Balance drift: should increase monotonically by the grant amount each cycle.
    bals = [r.get("balance") for r in rows if isinstance(r.get("balance"), int)]
    if len(bals) >= 2:
        regressions = sum(1 for a, b in zip(bals, bals[1:]) if b < a)
        print(f"  balance: {bals[0]} -> {bals[-1]}  (monotonic increases; regressions={regressions})")


api = load(API_LOG)
brw = load(BRW_LOG)
print("=== Freeze health summary ===")
report("API   ", api)
report("Browser", brw)
overall_ok = api and all(r.get("ok") for r in api) and all(r.get("ok") for r in brw)
print("=== " + ("ALL GREEN" if overall_ok else "ATTENTION: failures recorded — inspect logs") + " ===")
sys.exit(0 if overall_ok else 1)
