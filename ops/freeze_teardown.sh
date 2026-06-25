#!/usr/bin/env bash
# Tear down the freeze harness: remove cron entries and delete the synthetic
# accounts. Logs are kept (under ops/logs) for the record; pass --purge-logs to
# also delete them.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$(cd "$DIR/.." && pwd)/backend"
ENVF="$DIR/.freeze_env"

echo "Removing cron entries..."
( crontab -l 2>/dev/null | grep -v '# freeze-harness' ) | crontab - || true

echo "Deleting synthetic accounts..."
cat > /tmp/freeze_rm.php <<PHP
<?php
require '$BACKEND/vendor/autoload.php';
\$app = require '$BACKEND/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
\$n = User::where('email','like','freeze_member_%@healthcheck.local')
        ->orWhere('email','like','freeze_admin_%@healthcheck.local')->get();
foreach(\$n as \$u){ \$u->tokenLedger()->delete(); \$u->forceDelete(); }
echo "deleted ".\$n->count()." synthetic users\n";
PHP
php /tmp/freeze_rm.php; rm -f /tmp/freeze_rm.php

rm -f "$ENVF"
if [ "${1:-}" = "--purge-logs" ]; then
  rm -f "$DIR"/logs/freeze*.jsonl "$DIR"/logs/cron.log
  echo "logs purged"
fi
echo "Teardown complete."
