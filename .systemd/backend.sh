#!/usr/bin/env bash
# Laravel API + queue worker. systemd owns the cgroup, so the backgrounded
# queue:work is killed when the unit stops (KillMode=control-group, the default).
set -euo pipefail
cd /home/simon/ai-church/backend
php artisan queue:work --tries=3 &
exec php artisan serve --host=127.0.0.1 --port=8000
