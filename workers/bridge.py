"""
Bridge consumer. Laravel publishes plain JSON onto the `ai:intake` Redis list.
This process BLPOPs that list and hands each job to the Celery orchestrator, so the
two ecosystems share a queue without sharing a serializer.

Run alongside the Celery workers:
    python bridge.py
    celery -A tasks.celery_app worker -Q ai:sermon,ai:music,ai:avatar -c 4
"""

from __future__ import annotations

import json
import os

import redis

from tasks import orchestrate

r = redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))


def main() -> None:
    print("Bridge consumer listening on ai:intake ...")
    while True:
        _, raw = r.blpop("ai:intake")
        try:
            job = json.loads(raw)
            orchestrate.delay(job)
            print(f"dispatched session {job.get('session_id')}")
        except Exception as exc:  # noqa: BLE001
            print(f"failed to dispatch job: {exc}")


if __name__ == "__main__":
    main()
