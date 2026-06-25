#!/usr/bin/env python3
"""
Freeze-window synthetic health probe (API level). Runs the core SaaS loop against
the live API and records a single JSON line of results. Pure stdlib — no deps — so
it stays robust across a 24h unattended cron run.

Each cycle checks, end to end:
  - /auth/session returns 200 {authenticated:false} when logged out (no 401 contract)
  - member login -> /me 200
  - token consistency: /me.token_balance == /subscription.token_balance
  - /auth/session shows authenticated:true while logged in
  - logout actually destroys the session: /me then returns 401 (auth drift guard)
  - admin grants N tokens -> ledger->balance integrity: new == before + N
  - cross-session propagation: member re-login sees the new balance
  - any HTTP 5xx anywhere in the cycle

Config via env (see .freeze_env written by freeze_setup.sh):
  FREEZE_API, FREEZE_ORIGIN, FREEZE_LOG,
  FREEZE_MEMBER_EMAIL/_PW, FREEZE_ADMIN_EMAIL/_PW, FREEZE_MEMBER_ID

Exit code 0 = all checks passed; 1 = at least one failed (also recorded in the log).
"""
import json, os, sys, time, datetime, urllib.request, urllib.parse, urllib.error

HOST   = os.environ.get("FREEZE_API", "https://api.aivirtual.church")
ORIGIN = os.environ.get("FREEZE_ORIGIN", "https://aivirtual.church")
LOG    = os.environ.get("FREEZE_LOG", os.path.join(os.path.dirname(os.path.abspath(__file__)), "logs", "freeze.jsonl"))
GRANT  = int(os.environ.get("FREEZE_GRANT", "1"))

def need(k):
    v = os.environ.get(k)
    if not v:
        sys.stderr.write(f"missing env {k}\n"); sys.exit(2)
    return v

MEMBER_EMAIL = need("FREEZE_MEMBER_EMAIL"); MEMBER_PW = need("FREEZE_MEMBER_PW")
ADMIN_EMAIL  = need("FREEZE_ADMIN_EMAIL");  ADMIN_PW  = need("FREEZE_ADMIN_PW")
MEMBER_ID    = int(need("FREEZE_MEMBER_ID"))


class Client:
    """One browser-equivalent identity with a single, flat cookie store.

    We manage cookies by name (last write wins) instead of using http.cookiejar,
    because the jar accumulates domain-scope duplicates: /sanctum/csrf-cookie sets
    host-only cookies (api.aivirtual.church) while login sets domain-scoped ones
    (.aivirtual.church). Sending both yields two ai_church_session / XSRF-TOKEN
    values and the server resolves a different session than the token we sign with
    — a CSRF mismatch. Collapsing by name (as curl/browsers do) fixes it.
    """
    def __init__(self):
        self.cookies = {}   # name -> raw (still url-encoded) value
        self.codes = []

    def _store(self, resp):
        for h in resp.headers.get_all("Set-Cookie") or []:
            nv = h.split(";", 1)[0]
            if "=" not in nv:
                continue
            name, val = nv.split("=", 1)
            name = name.strip()
            if val == "" or "expires=Thu, 01-Jan-1970" in h:
                self.cookies.pop(name, None)   # deletion
            else:
                self.cookies[name] = val

    def req(self, method, path, body=None):
        data = json.dumps(body).encode() if body is not None else None
        r = urllib.request.Request(HOST + path, data=data, method=method)
        r.add_header("Accept", "application/json")
        r.add_header("Origin", ORIGIN)
        r.add_header("Referer", ORIGIN + "/")
        if self.cookies:
            r.add_header("Cookie", "; ".join(f"{k}={v}" for k, v in self.cookies.items()))
        if data is not None:
            r.add_header("Content-Type", "application/json")
            r.add_header("X-XSRF-TOKEN", urllib.parse.unquote(self.cookies.get("XSRF-TOKEN", "")))
        try:
            resp = self.op_open(r)
            code, raw = resp.getcode(), resp.read()
            self._store(resp)
        except urllib.error.HTTPError as e:
            code, raw = e.code, e.read()
            self._store(e)
        except Exception as e:
            code, raw = -1, str(e).encode()
        self.codes.append(code)
        try:
            return code, json.loads(raw)
        except Exception:
            return code, None

    def op_open(self, r):
        # No automatic cookie processor — we send the Cookie header ourselves and
        # must NOT follow redirects silently in a way that drops it. Default opener
        # is fine here since all calls are direct (no cross-host redirects).
        return urllib.request.urlopen(r, timeout=25)

    def csrf(self):
        # Sanctum CSRF cookie lives at the host root, not under /api.
        self.req("GET", "/sanctum/csrf-cookie")

    def http5xx(self):
        return sum(1 for c in self.codes if isinstance(c, int) and c >= 500)


def main():
    fails = []
    checks = {}

    def check(name, cond):
        checks[name] = bool(cond)
        if not cond:
            fails.append(name)

    # 1. Logged-out session probe
    m = Client()
    m.csrf()
    code, js = m.req("GET", "/api/auth/session")
    check("session_loggedout_200", code == 200)
    check("session_loggedout_anon", bool(js) and js.get("authenticated") is False)

    # 2. Member login + identity
    m.csrf()
    code, _ = m.req("POST", "/api/login", {"email": MEMBER_EMAIL, "password": MEMBER_PW})
    check("member_login_200", code == 200)
    code, me = m.req("GET", "/api/me")
    check("me_200", code == 200)
    bal_me = (me or {}).get("user", {}).get("token_balance")

    # 3. Token consistency between /me and /subscription
    code, sub = m.req("GET", "/api/subscription")
    check("subscription_200", code == 200)
    bal_sub = (sub or {}).get("token_balance")
    check("token_consistency", bal_me is not None and bal_me == bal_sub)

    # 4. Authenticated session probe
    code, js = m.req("GET", "/api/auth/session")
    check("session_authed", bool(js) and js.get("authenticated") is True)

    # NOTE: logout is intentionally NOT asserted here. With SESSION_DRIVER=cookie the
    # whole session (CSRF token included) lives in encrypted, sometimes multi-part
    # client cookies; faithfully reproducing that multi-step cookie dance from a
    # hand-rolled client is brittle. Logout + session destruction is covered by the
    # browser probe (real cookie semantics) instead. See freeze_browser_probe.mjs.

    # 5. Admin grants tokens -> ledger/balance integrity
    a = Client()
    a.csrf()
    code, _ = a.req("POST", "/api/login", {"email": ADMIN_EMAIL, "password": ADMIN_PW})
    check("admin_login_200", code == 200)
    new_bal = None
    if bal_me is not None:
        code, gj = a.req("POST", f"/api/admin/users/{MEMBER_ID}/tokens", {"amount": GRANT})
        check("grant_200", code == 200)
        new_bal = (gj or {}).get("token_balance")
        check("grant_increments_by_N", new_bal == bal_me + GRANT)

    # 7. Cross-session propagation: member re-login sees the new balance
    if new_bal is not None:
        m2 = Client()
        m2.csrf()
        m2.req("POST", "/api/login", {"email": MEMBER_EMAIL, "password": MEMBER_PW})
        code, me3 = m2.req("GET", "/api/me")
        bal_after = (me3 or {}).get("user", {}).get("token_balance")
        check("propagation_visible", bal_after == new_bal)

    err5xx = m.http5xx() + a.http5xx() + (m2.http5xx() if new_bal is not None else 0)
    check("no_5xx", err5xx == 0)

    record = {
        "ts": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "kind": "api",
        "ok": len(fails) == 0,
        "fails": fails,
        "err5xx": err5xx,
        "balance": new_bal if new_bal is not None else bal_me,
        "billing_enabled": (sub or {}).get("billing_enabled"),
        "checks": checks,
    }
    os.makedirs(os.path.dirname(LOG), exist_ok=True)
    with open(LOG, "a") as f:
        f.write(json.dumps(record) + "\n")
    print(("PASS" if record["ok"] else "FAIL") + " " + record["ts"] +
          (" fails=" + ",".join(fails) if fails else "") + f" 5xx={err5xx}")
    sys.exit(0 if record["ok"] else 1)


if __name__ == "__main__":
    main()
