#!/usr/bin/env bash
# Laravel task scheduler. `schedule:work` runs `schedule:run` once a minute, which
# fires services:dispatch-due (see backend/routes/console.php) — releasing scheduled
# services the moment they come due and emailing the worshipper a reminder. This is
# its own systemd unit (not backgrounded inside the backend unit) so a crash is
# visible to systemd and gets restarted, same reasoning as the bridge unit.
set -euo pipefail
cd /home/simon/ai-church/backend
exec php artisan schedule:work
