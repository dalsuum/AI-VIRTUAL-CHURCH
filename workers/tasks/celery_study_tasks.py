"""Celery task for AI Bible Study discussions (Phase 6).

Thin shim: routes a composed job to the Bible Study plugin driver, which wires the
Core Orchestrator. Kept in its own module (like the Tedim/Burmese task files) so the
large tasks/__init__.py stays focused on the worship pipeline.
"""

from __future__ import annotations

from plugins.bible_study import driver
from tasks.celery_app import app


@app.task(name="tasks.study_discuss")
def study_discuss(job: dict) -> None:
    """Run one Bible Study discussion round. `job` is composed server-side by Laravel
    (selected personas, role templates, provider model, prior turns, question)."""
    driver.run(job)
