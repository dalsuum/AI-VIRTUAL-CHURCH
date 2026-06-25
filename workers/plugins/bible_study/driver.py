"""Bible Study plugin driver (Phase 6) — thin wiring over the Core Orchestrator.

Celery task target. Builds the real collaborators (OpenRouter LLM client, Redis
EventBus, HMAC-signed turn sink) and hands the job to core_orchestrator.run_round.
All discussion mechanics live in Core; this file only wires this module's I/O.

Provider credentials are read from the environment (OPENROUTER_API_KEY) — they never
travel through the Celery job payload (Phase 5 trust-boundary decision).
"""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import sys
import time

import requests

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))

import core_orchestrator                       # noqa: E402
from core.events import EventBus               # noqa: E402

_OPENROUTER_KEY = os.environ.get("OPENROUTER_API_KEY", "")
_OPENROUTER_URL = os.getenv("OPENROUTER_BASE_URL", "https://openrouter.ai/api/v1").rstrip("/")
_DEFAULT_MODEL  = os.getenv("BIBLE_STUDY_LLM_MODEL", "anthropic/claude-sonnet-4-6")
_TURN_WEBHOOK   = os.getenv("STUDY_TURN_WEBHOOK_URL", "")
_SUMMARY_WEBHOOK = os.getenv("STUDY_SUMMARY_WEBHOOK_URL", "")
_WORKER_SECRET  = os.environ.get("WORKER_WEBHOOK_SECRET", "")


class OpenRouterLLM:
    """OpenAI-compatible chat client. Model resolved from the job's provider config."""

    def __init__(self, model: str):
        self._model = model or _DEFAULT_MODEL

    def complete(self, *, system, messages, temperature, max_tokens, role=None):
        from core.ai_gateway import generate_response

        try:
            # We use prompt_version=role or "legacy_plugin" to track what prompt this is
            text, usage = generate_response(
                model=self._model,
                system_prompt=system,
                messages=messages,  # ai_gateway expects messages without system prompt
                base_url=_OPENROUTER_URL,
                api_key=_OPENROUTER_KEY,
                temperature=temperature,
                max_tokens=max_tokens,
                prompt_version=f"plugin_hardcoded_{role}" if role else "plugin_hardcoded"
            )
            return text, usage
        except Exception as e:
            raise RuntimeError(f"Gateway generation failed: {e}")


def _signed_post(url: str, payload: dict) -> None:
    """POST a JSON payload with an HMAC signature over '{ts}.{body}' and a timestamp
    header (Phase 5: HMAC + ±tolerance so a leaked secret can't replay old payloads)."""
    if not url or not _WORKER_SECRET:
        return
    body = json.dumps(payload, ensure_ascii=False)
    ts = str(int(time.time()))
    signature = hmac.new(_WORKER_SECRET.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
    try:
        requests.post(
            url,
            data=body.encode("utf-8"),
            headers={"Content-Type": "application/json",
                     "X-Worker-Timestamp": ts,
                     "X-Worker-Signature": signature},
            timeout=30,
        ).raise_for_status()
    except requests.exceptions.RequestException as exc:
        print(f"[bible_study] signed post to {url} failed: {exc}", flush=True)


def _make_turn_sink(session_id: int):
    return lambda turn: _signed_post(_TURN_WEBHOOK, {"session_id": session_id, **turn})


def run(job: dict) -> None:
    """Celery entry point. `job` is composed server-side by Laravel and carries the
    selected personas, role templates, provider model, prior turns, and the question."""
    session_id = job["session_id"]

    redis_client = None
    try:
        import redis as _redis
        redis_client = _redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
    except Exception as exc:  # pragma: no cover
        print(f"[bible_study] redis unavailable, events not streamed: {exc}", flush=True)

    bus = EventBus(redis_client, session_id, module="bible_study")
    model = (job.get("provider") or {}).get("model") or _DEFAULT_MODEL
    llm = OpenRouterLLM(model)

    mode = job.get("mode", "discuss")
    if mode == "summary":
        print(f"[bible_study] session {session_id} summary model={model}", flush=True)
        summary = core_orchestrator.run_summary(job=job, llm=llm)
        _signed_post(_SUMMARY_WEBHOOK, {"session_id": session_id, "summary": summary})
        if redis_client is not None:
            bus.publish("state.changed", state="summarized")
        return

    print(f"[bible_study] session {session_id} round {job.get('round_no', 1)} model={model}", flush=True)
    core_orchestrator.run_round(job=job, llm=llm, bus=bus, turn_sink=_make_turn_sink(session_id),
                                base_turn=int(job.get("base_turn", 0)))
