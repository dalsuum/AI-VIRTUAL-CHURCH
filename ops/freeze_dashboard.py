#!/usr/bin/env python3
"""
Live terminal dashboard for the freeze harness. Self-refreshing (stdlib only).

  python3 ops/freeze_dashboard.py            # live, refreshes every 5s (Ctrl-C to quit)
  python3 ops/freeze_dashboard.py --once     # print a single snapshot and exit
  python3 ops/freeze_dashboard.py -n 10      # custom refresh interval (seconds)

Reads the same logs/env the probes use; purely read-only (never mutates state).
"""
import json, os, re, sys, time, subprocess, datetime

DIR = os.path.dirname(os.path.abspath(__file__))
ENVF = os.path.join(DIR, ".freeze_env")
API_LOG = os.path.join(DIR, "logs", "freeze.jsonl")
BRW_LOG = os.path.join(DIR, "logs", "freeze_browser.jsonl")
VERDICT_FILE = os.path.join(DIR, "logs", "freeze_verdict.txt")
WINDOW_HOURS = 24
API_PER_HOUR, BRW_PER_HOUR = 6, 1
WIDTH = 120
COL = WIDTH // 2

# ---- ANSI helpers -----------------------------------------------------------
RESET = "\033[0m"
CODES = {"cyan": "36", "green": "32", "yellow": "33", "red": "31", "dim": "2", "bold": "1", "blue": "34"}
ANSI = re.compile(r"\033\[[0-9;]*m")
def c(s, *styles):
    pre = "".join(f"\033[{CODES[x]}m" for x in styles)
    return f"{pre}{s}{RESET}" if pre else s
def vlen(s):
    return len(ANSI.sub("", str(s)))
def pad(s, w):
    return str(s) + " " * max(0, w - vlen(s))
def clip(s, w):
    raw = ANSI.sub("", str(s))
    return raw if len(raw) <= w else raw[: w - 1] + "…"


# ---- data -------------------------------------------------------------------
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

def env():
    d = {}
    if os.path.exists(ENVF):
        with open(ENVF) as f:
            for line in f:
                m = re.match(r'\s*export\s+(\w+)="?([^"\n]*)"?', line)
                if m: d[m.group(1)] = m.group(2)
    return d

def crontab_lines():
    try:
        out = subprocess.run(["crontab", "-l"], capture_output=True, text=True, timeout=10).stdout
    except Exception:
        out = ""
    return [l for l in out.splitlines() if "freeze-harness" in l]

def ts_of(r):
    try: return datetime.datetime.fromisoformat(r["ts"].replace("Z", "+00:00"))
    except Exception: return None

def next_tick(now, minutes):
    """Next time the minute is in `minutes` set (e.g. {0,10,..} or {7})."""
    t = now.replace(second=0, microsecond=0) + datetime.timedelta(minutes=1)
    for _ in range(0, 24 * 60):
        if t.minute in minutes:
            return t
        t += datetime.timedelta(minutes=1)
    return now

def fmt_delta(d, secs=True):
    s = int(d.total_seconds())
    if s < 0: return "now"
    h, s = divmod(s, 3600); m, s = divmod(s, 60)
    if not secs:
        return (f"{h}h " if h else "") + f"{m:02d}m"
    return (f"{h}h " if h else "") + f"{m:02d}m {s:02d}s"


# ---- box rendering ----------------------------------------------------------
def box(title, rows, w):
    lines = ["┌" + "─" * (w - 2) + "┐"]
    tt = c(title, "cyan", "bold")
    lines.append("│" + pad(" " * ((w - 2 - vlen(title)) // 2) + tt, w - 2) + "│")
    lines.append("├" + "─" * (w - 2) + "┤")
    for r in rows:
        lines.append("│ " + pad(r, w - 4) + " │")
    lines.append("└" + "─" * (w - 2) + "┘")
    return lines

def side_by_side(a, b):
    out = []
    for i in range(max(len(a), len(b))):
        la = a[i] if i < len(a) else " " * vlen(a[0])
        lb = b[i] if i < len(b) else ""
        out.append(la + " " + lb)
    return out

def kv(k, v, kw=18):
    return pad(c(k, "dim"), kw) + str(v)


# ---- panels -----------------------------------------------------------------
def panel_summary(now, api, start):
    end = start + datetime.timedelta(hours=WINDOW_HOURS)
    age = (now - start).total_seconds() / 3600.0
    pct = max(0.0, min(100.0, age / WINDOW_HOURS * 100))
    verdict = read_verdict()
    armed = bool(api)
    status = c("PENDING", "yellow") + c(" (freeze running)", "dim") if armed or now < start else c("PENDING", "yellow")
    nA = next_tick(now, set(range(0, 60, 10)))
    nB = next_tick(now, {7})
    nV = next_tick(now, {17})
    rows = [
        kv("Window Start", c(start.strftime("%Y-%m-%dT%H:%M:%SZ"), "green")),
        kv("Window End", c(end.strftime("%Y-%m-%dT%H:%M:%SZ"), "green") + c(f"  (in {fmt_delta(end-now, secs=False)})", "dim")),
        kv("Now (UTC)", c(now.strftime("%Y-%m-%dT%H:%M:%SZ"), "green")),
        kv("Window Age", c(f"{int(age*60)//60}h {int(age*60)%60:02d}m ({pct:.1f}%)", "yellow")),
        "",
        kv("Status", status),
        kv("Verdict", verdict),
        "",
        kv("Next API tick", c(nA.strftime('%H:%M:%SZ'), "green") + c(f"  (in {fmt_delta(nA-now)})", "dim")),
        kv("Next Browser tick", c(nB.strftime('%H:%M:%SZ'), "green") + c(f"  (in {fmt_delta(nB-now)})", "dim")),
        kv("Next Verdict tick", c(nV.strftime('%H:%M:%SZ'), "green") + c(f"  (in {fmt_delta(nV-now)})", "dim")),
    ]
    return box("FREEZE HARNESS — STATUS SUMMARY", rows, COL)

def read_verdict():
    if os.path.exists(VERDICT_FILE):
        try:
            first = open(VERDICT_FILE).readline().strip()
            v = first.split(":", 1)[1].strip() if ":" in first else first
            return c(v, "green" if v == "PASS" else "yellow" if v == "WARN" else "red")
        except Exception: pass
    return c("TBD", "yellow") + c(" (gate runs hourly at :17)", "dim")

def panel_cron():
    lines = crontab_lines()
    rows = [pad(c("Schedule", "dim"), 14) + pad(c("Command", "dim"), 0)]
    rows.append(c("─" * (COL - 4), "dim"))
    if not lines:
        rows.append(c("no freeze-harness cron jobs installed", "red"))
    for l in lines:
        body = l.split("#", 1)[0].strip()
        sched = " ".join(body.split()[:5])
        cmd = body.split(";")[-1].strip() if ";" in body else body
        cmd = cmd.split(">>")[0].strip()
        rows.append(pad(c(sched, "yellow"), 14) + pad(clip(cmd, COL - 22), COL - 22) + c("OK", "green"))
    return box("CRON JOBS (VERIFIED)", rows, COL)

def panel_accounts(e):
    me = e.get("FREEZE_MEMBER_EMAIL", "—"); mid = e.get("FREEZE_MEMBER_ID", "?")
    ad = e.get("FREEZE_ADMIN_EMAIL", "—")
    active = bool(e)
    rows = [
        kv("Member", c(clip(me, COL - 24), "green") + c(f"  (id {mid})", "dim"), 10),
        kv("Admin", c(clip(ad, COL - 24), "green"), 10),
        kv("Status", c("ACTIVE", "green") if active else c("NOT ARMED", "red"), 10),
    ]
    return box("SYNTHETIC ACCOUNTS", rows, COL)

def cycle_table(title, rows_data, cols, w):
    header = "".join(pad(c(h, "dim"), cw) for h, cw in cols)
    body = [header, c("─" * (w - 4), "dim")]
    if not rows_data:
        body += ["", c("   no cycles recorded yet.", "dim")]
    else:
        for r in rows_data[-10:]:
            body.append("".join(pad(cell, cw) for cell, (_, cw) in zip(r, cols)))
    return box(title, body, w)

def panel_api(api):
    cols = [("Time (UTC)", 11), ("Status", 9), ("5xx", 6), ("Checks", 9), ("Bal", 7), ("Fails", 18)]
    data = []
    for r in api:
        t = ts_of(r)
        ok = c("PASS", "green") if r.get("ok") else c("FAIL", "red")
        nch = len(r.get("checks", {}))
        passed = sum(1 for v in r.get("checks", {}).values() if v)
        fails = ",".join(r.get("fails", [])) or c("—", "dim")
        data.append([t.strftime("%H:%M:%S") if t else "?", ok, str(r.get("err5xx", 0)),
                     f"{passed}/{nch}", str(r.get("balance", "?")), clip(fails, 17)])
    return cycle_table("API PROBE — LAST 10 CYCLES", data, cols, WIDTH)

def panel_browser(brw):
    cols = [("Time (UTC)", 11), ("Status", 9), ("Console", 9), ("Note", 35)]
    data = []
    for r in brw:
        t = ts_of(r)
        ok = c("PASS", "green") if r.get("ok") else c("FAIL", "red")
        ci = r.get("console_issues", 0)
        cic = c(str(ci), "green" if ci == 0 else "red")
        data.append([t.strftime("%H:%M:%S") if t else "?", ok, cic, clip(r.get("note", ""), 34)])
    return cycle_table("BROWSER PROBE — LAST 10 CYCLES", data, cols, WIDTH)

def panel_health(now, api, brw, start):
    age = max(0.0, (now - start).total_seconds() / 3600.0)
    exp_api = max(0, int(age * API_PER_HOUR)); exp_brw = max(0, int(age * BRW_PER_HOUR))
    five = sum(r.get("err5xx", 0) for r in api)
    fails = sum(len(r.get("fails", [])) for r in api)
    bals = [r.get("balance") for r in api if isinstance(r.get("balance"), int)]
    regr = sum(1 for a, b in zip(bals, bals[1:]) if b < a)
    authd = sum(1 for b in brw if not b.get("ok")) + sum(1 for r in api for f in r.get("fails", []) if f.startswith(("session", "logout")))
    cov = (len(api) / exp_api * 100) if exp_api else 0.0
    if not api and not brw:
        overall = c("PENDING", "yellow")
    elif five or fails or regr or any(not b.get("ok") for b in brw):
        overall = c("FAIL", "red", "bold")
    elif cov < 80:
        overall = c("WARN", "yellow", "bold")
    else:
        overall = c("GREEN", "green", "bold")

    CW = 13
    def cell(label, val, sub, color):
        return [pad(c(clip(label, CW), "cyan"), CW), pad(c(str(val), color, "bold"), CW), pad(c(clip(sub, CW), "dim"), CW)]
    g = lambda n: "green" if n == 0 else "red"
    cells = [
        cell("API Cycles", len(api), f"exp ~{exp_api}", "yellow"),
        cell("Browser", len(brw), f"exp ~{exp_brw}", "yellow"),
        cell("Coverage", f"{cov:.0f}%", "target ≥80%", "yellow" if cov < 80 else "green"),
        cell("5xx Errors", five, "all good" if not five else "CHECK", g(five)),
        cell("Failed Chk", fails, "all good" if not fails else "CHECK", g(fails)),
        cell("Bal Drift", regr, "all good" if not regr else "REGRESS", g(regr)),
        cell("Auth Drift", authd, "all good" if not authd else "DRIFT", g(authd)),
        cell("Overall", overall, "no data yet" if not api else "live", "yellow"),
    ]
    rows = []
    for line_i in range(3):
        rows.append(" ".join(cl[line_i] for cl in cells))
    return box("HEALTH SCORE (so far)", rows, WIDTH)


def render():
    now = datetime.datetime.now(datetime.timezone.utc)
    api, brw, e = load(API_LOG), load(BRW_LOG), env()
    starts = [t for t in (ts_of(r) for r in api) if t]
    start = min(starts) if starts else next_tick(now, set(range(0, 60, 10)))
    top = side_by_side(panel_summary(now, api, start), panel_cron() + panel_accounts(e))
    parts = side_by_side(panel_api(api), panel_browser(brw))
    blocks = ["\n".join(top), "\n".join(parts), "\n".join(panel_health(now, api, brw, start))]
    note = c("This is read-only. ", "dim") + c(f"Window {start.strftime('%H:%MZ')}→+24h. ", "green") + \
           c("Gate evaluates hourly at :17, renders verdict at ~24h.", "dim")
    blocks.append(note)
    return "\n".join(blocks)


def main():
    once = "--once" in sys.argv
    interval = 5
    for i, a in enumerate(sys.argv):
        if a in ("-n", "--interval") and i + 1 < len(sys.argv):
            try: interval = max(1, int(sys.argv[i + 1]))
            except Exception: pass
    if once:
        print(render()); return
    try:
        while True:
            sys.stdout.write("\033[2J\033[H")  # clear + home
            sys.stdout.write(render() + "\n")
            sys.stdout.flush()
            time.sleep(interval)
    except KeyboardInterrupt:
        sys.stdout.write("\n")


if __name__ == "__main__":
    main()
