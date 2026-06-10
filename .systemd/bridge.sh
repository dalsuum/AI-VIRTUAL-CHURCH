#!/usr/bin/env bash
# Bridge consumer: BLPOPs the Redis `ai:intake` list (pushed by Laravel) and
# hands each job to Celery. Runs as its own systemd unit so a crash here is
# seen and restarted — the whole spoken/music pipeline is dead without it.
# Does NOT read .env itself, so we load it first.
set -euo pipefail
cd /home/simon/ai-church/workers
set -a; . ./.env; set +a
exec .venv/bin/python bridge.py
