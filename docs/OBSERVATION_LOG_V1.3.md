# v1.3 Collaboration — Observation Log

A lightweight, dated list of **real user observations** collected during the
post-release observation period. Not a bug tracker, not a wishlist — what people
actually did, where they hesitated, what they invented. Patterns emerging here
are the input for v1.4 planning; the roadmap is a catalog of possibilities, not
a list of commitments.

**Opening questions for the next planning session** (answer from this log, not
from the roadmap):

1. Which collaboration features were used most?
2. Which ones were ignored?
3. Where did users hesitate or ask for help?
4. What workarounds did they invent?
5. Which support requests repeated?

**Entry format:** `- YYYY-MM-DD — who (role) — what happened / what they said`
Add entries under the closest category; don't agonize over placement.

**When reading this log, don't skip steps:**
`Observation → Pattern → Principle → Implementation`.
One request is an observation; three churches asking is a pattern; deciding
where the capability *belongs* (e.g. membership administration is church
governance, not the invitation system) is a principle; only then design the
implementation — as an extension of an existing domain.

---

## Onboarding
<!-- first contact, registration, activation email, guest→member conversion -->

## Invitations
<!-- links, QR codes, previews, auth-return flow, revocations, join requests -->

- 2026-07-10 — owner (acceptance run, Part 2) — **BUG, fixed:** minted `join_url`
  pointed at the API host (`api.aivirtual.church/#join?…` → plain 404) because
  two builders used `config('app.url')` instead of the existing
  `config('church.frontend_url')` that mail links already use. Fixed same day;
  test now pins the host. Lesson: any URL destined for a browser must be built
  from `church.frontend_url`, never `APP_URL`.
- 2026-07-10 — owner (acceptance run) — **Enhancement candidate:** invitation
  delivery is copy/paste-only; churches expect "enter an email address → send
  invitation" (mail with church/group/leader + Join button, QR optional).
  Direct in-app invitations already email existing users; what's missing is
  emailing a join link to someone with no account. v1.4 candidate.

## Collaboration
<!-- groups: creation, discovery, leadership, membership, directory -->

## Reading
<!-- shared sessions, individual plans, completions, streaks, reminders -->

## Activity Feeds
<!-- useful vs noise, what people look for, what's missing -->

## Performance & Errors
<!-- slow pages, 4xx/5xx seen by users, log findings -->

## Administration
<!-- what leaders tried to do and couldn't (e.g. remove members, edit roles) -->

- 2026-07-10 — owner (pre-acceptance) — No UI exists for assigning **church**
  roles: the Admin Console dropdown edits the platform role (`users.role`),
  while collaboration checks `church_memberships.role`; the backfill left
  everyone as `member`, and promotion is itself elder+-gated, so a fresh
  deployment has no one able to appoint the first leadership (bootstrap
  deadlock — blocked the acceptance run). Unblocked with the break-glass
  `php artisan church:assign-role` command. **Pattern candidate:** a church-run
  Members governance page (role assignment with explicit escalation rules —
  who may assign PASTOR, no self-promotion) extending Church/ChurchPolicy.

## Surprises
<!-- anything nobody anticipated — often the most valuable section -->
