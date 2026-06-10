#!/usr/bin/env bash
# Celery workers only. The bridge consumer runs as its OWN unit
# (aivirtualchurch-bridge.service) so systemd monitors and restarts it
# independently — backgrounding it here meant its death went unnoticed
# (celery was the main process), which is what kept silently breaking the
# pipeline. Celery does NOT read .env itself, so we load it first.
set -euo pipefail
cd /home/simon/ai-church/workers
set -a; . ./.env; set +a
exec .venv/bin/celery -A tasks.celery_app worker -Q ai:sermon,ai:music,ai:avatar,ai:narration -c 4
