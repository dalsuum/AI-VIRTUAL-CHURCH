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
        # Orchestration entrypoint. Without an explicit route it lands on the
        # default "celery" queue, which the workers don't consume — so it must
        # be pinned to a queue they do listen on. (It can't go to "ai:intake":
        # that Redis list is owned by the bridge consumer's raw BLPOP.)
        "tasks.orchestrate": {"queue": "ai:sermon"},
        "tasks.generate_text_segments": {"queue": "ai:sermon"},
        "tasks.generate_welcome": {"queue": "ai:sermon"},
        "tasks.generate_music": {"queue": "ai:music"},
        "tasks.render_avatar": {"queue": "ai:avatar"},
        "tasks.narrate": {"queue": "ai:narration"},
    },
    task_acks_late=True,
    worker_prefetch_multiplier=1,
)
