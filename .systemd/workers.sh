#!/usr/bin/env bash
# Bridge consumer + Celery workers. They do NOT read .env themselves, so we
# load it into the environment here first. systemd's cgroup reaps the
# backgrounded bridge.py when the unit stops.
set -euo pipefail
cd /home/simon/ai-church/workers
set -a; . ./.env; set +a
.venv/bin/python bridge.py &
exec .venv/bin/celery -A tasks.celery_app worker -Q ai:sermon,ai:music,ai:avatar,ai:narration -c 4
