"""Event Bus (Phase 2.1/6) — seq-stamped pub/sub over Redis.

Producers (the orchestrator) publish envelopes to channel `study:{session_id}:stream`;
the Laravel SSE relay and any analytics listeners subscribe. Every envelope carries a
per-session monotonic `seq` (Redis INCR) so the client can order and dedupe, making
reconnect replay idempotent. No server-only data (system prompts, tradition tags,
providers) is ever placed in an event payload.
"""

from __future__ import annotations

import json
import time


class EventBus:
    # Durable event log cap + TTL. The log is the source of truth for the SSE relay
    # (which polls by seq) AND for reconnect replay, so a missed pub/sub message is
    # never load-bearing.
    _LOG_CAP = 5000
    _LOG_TTL = 3600

    def __init__(self, redis_client, session_id: int, module: str = "bible_study"):
        self._redis = redis_client
        self._session_id = session_id
        self._module = module
        self._channel = f"{module}:{session_id}:stream"
        self._seq_key = f"{module}:{session_id}:seq"
        self._log_key = f"{module}:{session_id}:events"
        self._local_seq = 0  # used when no redis (tests)

    def _next_seq(self) -> int:
        if self._redis is not None:
            return int(self._redis.incr(self._seq_key))
        self._local_seq += 1
        return self._local_seq

    def publish(self, event: str, *, turn: int | None = None, **payload) -> dict:
        envelope = {
            "seq": self._next_seq(),
            "event": event,
            "session_id": self._session_id,
            "turn": turn,
            "ts": round(time.time(), 3),
            **payload,
        }
        if self._redis is not None:
            blob = json.dumps(envelope, ensure_ascii=False)
            # Durable, ordered log for SSE polling + replay …
            self._redis.rpush(self._log_key, blob)
            self._redis.ltrim(self._log_key, -self._LOG_CAP, -1)
            self._redis.expire(self._log_key, self._LOG_TTL)
            # … plus pub/sub for any push-style subscribers.
            self._redis.publish(self._channel, blob)
        return envelope


class RecordingBus(EventBus):
    """Test double: records every published envelope, no Redis."""

    def __init__(self, session_id: int = 1, module: str = "bible_study"):
        super().__init__(None, session_id, module)
        self.events: list[dict] = []

    def publish(self, event: str, *, turn: int | None = None, **payload) -> dict:
        env = super().publish(event, turn=turn, **payload)
        self.events.append(env)
        return env
