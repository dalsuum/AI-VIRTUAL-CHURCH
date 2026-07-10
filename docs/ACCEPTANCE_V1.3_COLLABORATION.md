# v1.3 Collaboration — Acceptance Test Script

Run after deploying PR #75 (frontend) on top of `v1.3.0-backend-collaboration`.
Requires **two real accounts in two browsers** (or one browser + one private
window): a **Leader** with church role `leader`+ and a **Newcomer** with no
account yet. Uses production email activation, so have the newcomer's inbox open.

Deploy first (main checkout): `git pull` → `npm run build` in `frontend/` →
`php artisan optimize:clear`. No migrations for Phase F.

> **Smoke-testing note:** use the **API host** (`api.aivirtual.church`) for API
> checks — the SPA host intentionally serves `index.html` for every path
> (hash-router fallback), so it answers 200 even for unknown API routes.
> Expected signatures on the API host: gated routes → 401 unauthenticated,
> unknown invitation token → 404 (the preview is public), unknown route → 404.

## Part 0 — Bootstrap the leadership (one-time, on the box)

Fresh deployments have no church leaders (the backfill assigns `member`, and
promotion is itself elder+-gated). Assign the Leader account's **church** role
first — this is `church_memberships.role`, not the Admin Console's platform role:

```bash
cd /opt/ai-church/backend
php artisan church:assign-role <leader-email> pastor
```

## Part 1 — Leader sets the table

- [ ] Log in; the header/bottom nav shows **My Church**.
- [ ] Open `#church`: profile card, groups grid, member preview and Recent
      Activity render without errors.
- [ ] Create a group (New group form) — it appears in the grid; the church feed
      gains "New group created: …".
- [ ] Open the group page from its card.
- [ ] Start a reading session (choose a plan → create → **Start**) — status
      panel flips to "Reading session active"; group feed gains
      "Shared reading started: …".
- [ ] Mint an invitation link (set max uses) — copy the `join_url`.

## Part 2 — Newcomer joins via the link

- [ ] Open the `join_url` **signed out** — the preview shows church, group,
      ministry type, member count, inviter; no login wall.
- [ ] Click **Create an account** → register → activate via email → log in.
- [ ] Confirm you are returned to the invitation preview **automatically**
      (intent preservation), with no rescanning/re-pasting.
- [ ] Join — the success screen offers Go to the group / Start today's reading /
      Return to dashboard.
- [ ] Go to the group; join the reading session.
- [ ] Complete today's reading (Bible → today's passages → complete, or the
      existing reading-plan flow).

## Part 3 — Leader verifies the loop closed

- [ ] Group roster shows the newcomer with a ✓ read-today tick and Day counter.
- [ ] Status panel counts them under "completed today's reading".
- [ ] Church feed shows the newcomer joining the church (as guest) and the group.
- [ ] Group feed shows the join.
- [ ] Member Directory (`#members`) lists the newcomer, searchable, with the
      group badge.
- [ ] Revoke the invitation link, then reopen the `join_url` in a third private
      window: preview reports it unusable / redemption refuses.

## Edge checks (5 minutes)

- [ ] A second tap on an already-used personal link is a no-op (no extra use).
- [ ] Signed-out visit to `#church` or `#group?id=…` bounces to login and
      returns to the intended page after auth.
- [ ] A church member (not group member) sees the group and can request to
      join; the leader sees and approves the request; membership appears.

**When everything passes:** tag the release on the merge commit —
`git tag -a v1.3.0-collaboration -m "v1.3 Collaboration: backend + UI" && git push origin v1.3.0-collaboration`.

## Observation period (before starting v1.4)

Deliberately collect answers before opening the next milestone:

1. Do churches understand the invitation flow without explanation?
2. Do leaders naturally use Groups as intended?
3. Is the Activity Feed useful or too noisy?
4. Do users discover Shared Reading organically?
5. Are there friction points in the authentication return flow?
