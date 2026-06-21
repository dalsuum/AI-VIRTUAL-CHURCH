"""
Celery application. Single Redis broker, named queues that mirror the Laravel side:

  ai:intake  -> orchestration entrypoint (also BLPOP'd by the bridge consumer)
  ai:sermon  -> LLM sermon/prayer/benediction generation
  ai:music   -> Suno or YouTube, resolved per session
  ai:avatar  -> HeyGen avatar render
  ai:narration -> text-to-speech narration of the spoken segments
"""

from __future__ import annotations

import os

from celery import Celery

REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379/0")

app = Celery("ai_church", broker=REDIS_URL, backend=REDIS_URL)

app.conf.update(
    task_serializer="json",
    accept_content=["json"],
    result_serializer="json",
    task_routes={
        # Orchestration entrypoint on its own queue so it is never blocked by a
        # long-running generate_text_segments task (3-5 min). The dedicated worker
        # in aivc-workers-orchestrate.service consumes only ai:orchestrate, so
        # every new service starts within seconds of submission.
        "tasks.orchestrate": {"queue": "ai:orchestrate"},
        "tasks.generate_text_segments": {"queue": "ai:sermon"},
        "tasks.generate_welcome": {"queue": "ai:sermon"},
        "tasks.generate_music": {"queue": "ai:music"},
        # AI background music for the online Bible reader — shares the music
        # worker pool and its MusicGen Redis lock so generations never overlap.
        "tasks.generate_bible_bg": {"queue": "ai:music"},
        "tasks.render_avatar": {"queue": "ai:avatar"},
        "tasks.narrate": {"queue": "ai:narration"},
        "tasks.repair_missing_narration": {"queue": "ai:narration"},
        # Tedim localization: paragraph-by-paragraph Ollama inference.
        # Runs on ai:sermon so the same worker pool handles it — inference
        # is serialized by the semaphore in tedim_router.py anyway.
        "tasks.localize_segment_tedim": {"queue": "ai:sermon"},
        "tasks.narrate_tedim": {"queue": "ai:narration"},
        # Burmese (Myanmar) localization: same Ollama-backed pattern as Tedim.
        # Serialized by the semaphore in burmese_router.py.
        "tasks.localize_segment_burmese": {"queue": "ai:sermon"},
        "tasks.narrate_burmese": {"queue": "ai:narration"},
        # AI Bible Study multi-agent discussion rounds — own queue so a long round
        # never blocks the worship pipeline.
        "tasks.study_discuss": {"queue": "ai:study"},
    },
    task_acks_late=True,
    worker_prefetch_multiplier=1,
)
