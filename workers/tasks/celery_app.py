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
        # Unified history jobs (pastor_reply | title_summary). Conversational LLM
        # work, so it shares the ai:study worker pool. NOTE: it must NOT use the
        # "ai:history" name — that key is the raw Laravel->bridge intake list, and
        # reusing it would make the bridge and this worker race on the same key.
        "tasks.history_job": {"queue": "ai:study"},
    },
    task_acks_late=True,
    worker_prefetch_multiplier=1,
)

from celery.signals import task_prerun, task_postrun

@task_prerun.connect
def setup_telemetry(task_id, task, args, kwargs, **_):
    import sys, os
    sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    from core.telemetry import set_correlation_id, set_session_id, trace_id_var, span_id_var, Span
    
    if args and isinstance(args[0], dict):
        job = args[0]
        cid = job.get("correlation_id")
        set_correlation_id(cid)
        trace_id_var.set(cid)
        set_session_id(str(job.get("session_id", "")))
        
        parent_span_id = job.get("parent_span_id")
        if parent_span_id:
            # We seed the current context so the new Span thinks of it as the parent
            span_id_var.set(parent_span_id)
        
        # Instantiate Root Span
        span = Span(
            component="celery",
            layer_hint="orchestration",
            decision_source="system",
            metadata={"task_name": task.name}
        )
        task.request.span_instance = span
        span.__enter__()

@task_postrun.connect
def teardown_telemetry(task_id, task, args, kwargs, retval, state, **_):
    span = getattr(task.request, "span_instance", None)
    if span:
        exc_type = type(retval) if isinstance(retval, Exception) else None
        exc_val = retval if isinstance(retval, Exception) else None
        span.__exit__(exc_type, exc_val, None)
