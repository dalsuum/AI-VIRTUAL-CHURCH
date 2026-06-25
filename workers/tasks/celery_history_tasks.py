"""Celery task for the Unified Conversation & Spiritual History feature.

Thin shim: routes a composed `ai:history` job (pastor_reply | title_summary) to the
history plugin driver, which calls the LLM and posts results back to Laravel over the
HMAC-signed /internal/history-callback webhook. Kept separate from the worship
pipeline like the other module task files.
"""

from __future__ import annotations

from plugins.history import driver
from tasks.celery_app import app


@app.task(name="tasks.history_job")
def history_job(job: dict) -> None:
    """Run one unified-history job. `job` is composed server-side by Laravel."""
    driver.run(job)
