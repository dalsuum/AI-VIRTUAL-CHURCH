# Coding Rules

_Reflects the actual stack as of 2026-06-16. Keep in sync with `.ai/architecture.md`._

Backend (Laravel 11, PHP)
- Service Pattern — all business logic lives in Services.
- Thin controllers.
- Repository Pattern only if needed.
- Never change the database without a migration.

Frontend (Vue 3 SPA, JavaScript)
- Reusable components; never duplicate code.
- No dev server in deploy: after any frontend change run `npm run build` in `frontend/`.

AI workers (Python / Celery)
- `agent_orchestrator.py` must never import back from `tasks/__init__.py`
  (circular import) — dispatch via `app.send_task()`.
- All generated text passes `classifier.py` `review()` before leaving for Laravel.
- Generated service text never includes the worshipper's name (all languages).
- Never remove the Tedim MMS-TTS narrator or worship/closing-hymn segments.
- Burmese song lyrics use the curated library only — the `burmese-myanmar` model
  is not called for lyrics.

Database
- Every schema change requires a migration.
- Never modify production data directly.

API
- RESTful naming, JSON responses, proper validation.

Security
- OWASP top-10 clean: validate all input, escape output, never expose secrets,
  no hardcoded secrets, least privilege.
- Rate limit AI endpoints.

Documentation
- Update `README.md` and push to GitHub after any code change.
