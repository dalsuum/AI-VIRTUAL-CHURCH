"""
Bridge consumer. Laravel publishes plain JSON onto the `ai:intake` Redis list.
This process BLPOPs that list and hands each job to the Celery orchestrator, so the
two ecosystems share a queue without sharing a serializer. It also listens for
`ai:narration-repair`, which Laravel uses to backfill missing audio for completed
services.

Run alongside the Celery workers:
    python bridge.py
    celery -A tasks.celery_app worker -Q ai:sermon,ai:music,ai:avatar -c 4
"""

from __future__ import annotations

import json
import os

import redis

from tasks import history_job, orchestrate, repair_missing_narration, study_discuss

r = redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))


def main() -> None:
    print("Bridge consumer listening on ai:intake, ai:narration-repair, ai:study, ai:history ...")
    while True:
        queue, raw = r.blpop(["ai:intake", "ai:narration-repair", "ai:study", "ai:history"])
        try:
            job = json.loads(raw)
            queue_name = queue.decode() if isinstance(queue, bytes) else str(queue)
            if queue_name == "ai:narration-repair":
                repair_missing_narration.delay(job)
                print(f"dispatched narration repair for session {job.get('session_token', job.get('session_id'))}")
            elif queue_name == "ai:study":
                study_discuss.delay(job)
                print(f"dispatched study discussion for session {job.get('session_id')}")
            elif queue_name == "ai:history":
                history_job.delay(job)
                print(f"dispatched history job ({job.get('mode')}) for session {job.get('session_id')}")
            else:
                orchestrate.delay(job)
                print(f"dispatched session {job.get('session_token', job.get('session_id'))}")
        except Exception as exc:  # noqa: BLE001
            print(f"failed to dispatch job: {exc}")


if __name__ == "__main__":
    main()
