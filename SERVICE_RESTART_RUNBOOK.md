# Server Restart & Health Check Runbook

Use this when deploying changes, after a droplet reboot, or when the service feels stuck.

## 1) Fast restart after code/prompt/env changes

Run from `/opt/ai-church` unless noted.

```bash
# Laravel queue worker (graceful reload of queue:work process)
cd /opt/ai-church/backend
php artisan queue:restart

# Production system services that execute changed code/prompts
sudo systemctl restart aivc-workers aivc-bridge aivc-queue aivc-tedim-api aivc-burmese-api

# If schedule logic changed, restart scheduler too
sudo systemctl restart aivc-scheduler
```

## 2) Full recovery after server reboot

```bash
# Core infra
sudo systemctl enable --now redis-server mysql nginx php8.3-fpm

# AI Church stack
sudo systemctl enable --now aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api
```

## 3) Status checks (all critical services)

```bash
sudo systemctl status redis-server mysql nginx php8.3-fpm --no-pager
sudo systemctl status aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api --no-pager
```

## 4) App and worker health checks

```bash
# Public API
curl -i https://api.example.com/api/config

# Local language API health
curl -sS http://127.0.0.1:8001/health
curl -sS http://127.0.0.1:8002/health

# Celery ping
cd /opt/ai-church/workers
bash -lc 'set -a; . ./.env; set +a; .venv/bin/celery -A tasks.celery_app inspect ping'

# Intake queue should rise briefly then drain
redis-cli LLEN ai:intake
```

## 5) Log checks (when debugging)

```bash
sudo journalctl -u aivc-workers -u aivc-bridge -u aivc-queue -u aivc-scheduler -u aivc-tedim-api -u aivc-burmese-api -n 120 --no-pager
sudo journalctl -u aivc-workers -f
sudo journalctl -u aivc-bridge -f

# Laravel + nginx
sudo tail -n 120 /opt/ai-church/backend/storage/logs/laravel.log
sudo tail -n 120 /var/log/nginx/error.log
```

## 6) When to restart what

- Changed `workers/*` Python code, prompts, Suno logic, Tedim/Burmese generation logic:
  restart `aivc-workers` and `aivc-bridge`.
- Changed `workers/.env` values used by workers:
  restart `aivc-workers` and `aivc-bridge`.
- Changed `OLLAMA_MODEL_TD`:
  restart `aivc-tedim-api`.
- Changed `OLLAMA_MODEL_MY`:
  restart `aivc-burmese-api`.
- Changed Laravel queue jobs / job payload / worker webhook controller:
  run `php artisan queue:restart` and restart `aivc-queue`.
- Changed Laravel scheduled tasks:
  restart `aivc-scheduler`.
- Changed nginx config:
  `sudo nginx -t && sudo systemctl reload nginx`.
- Changed php-fpm pool/php ini:
  restart `php8.3-fpm`.

## 7) One-command restart (AI Church services only)

```bash
sudo systemctl restart aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api
sudo systemctl status  aivc-queue aivc-scheduler aivc-workers aivc-bridge aivc-tedim-api aivc-burmese-api --no-pager
```
