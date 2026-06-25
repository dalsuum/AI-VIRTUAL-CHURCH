#!/usr/bin/env bash
# One-time setup for the 24h freeze monitoring harness.
#   - creates two persistent synthetic accounts (admin + member) in the DB
#   - writes ops/.freeze_env (chmod 600) with their credentials + ids
#   - installs playwright into ops/node_modules (browser already cached)
#   - installs cron entries: API probe every 10 min, browser probe hourly
# Idempotent-ish: re-running creates fresh synthetic accounts; run teardown first
# to avoid orphans. Requires no sudo (uses the invoking user's crontab).
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$(cd "$DIR/.." && pwd)/backend"
ENVF="$DIR/.freeze_env"
PY=/usr/bin/python3
NODE=/usr/bin/node
NPM=/usr/bin/npm

API="${FREEZE_API:-https://api.aivirtual.church}"
ORIGIN="${FREEZE_ORIGIN:-https://aivirtual.church}"
TS="$(date +%s)"
MEMBER_EMAIL="freeze_member_${TS}@healthcheck.local"
ADMIN_EMAIL="freeze_admin_${TS}@healthcheck.local"
MEMBER_PW="Frz!m${TS}aa"
ADMIN_PW="Frz!a${TS}bb"

echo "Creating synthetic accounts..."
MEMBER_ID="$(cat > /tmp/freeze_mk.php <<PHP
<?php
require '$BACKEND/vendor/autoload.php';
\$app = require '$BACKEND/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use Illuminate\Support\Facades\Hash;
\$m = User::create(['name'=>'Freeze Member','email'=>'$MEMBER_EMAIL','password'=>Hash::make('$MEMBER_PW'),'role'=>'member','subscription_plan'=>'member','token_balance'=>0,'name_provided'=>true,'timezone'=>'UTC','music_source'=>'hymn_sung']);
User::create(['name'=>'Freeze Admin','email'=>'$ADMIN_EMAIL','password'=>Hash::make('$ADMIN_PW'),'role'=>'admin','is_admin'=>true,'subscription_plan'=>'member','token_balance'=>0,'name_provided'=>true,'timezone'=>'UTC','music_source'=>'hymn_sung']);
echo \$m->id;
PHP
php /tmp/freeze_mk.php)"
rm -f /tmp/freeze_mk.php
echo "  member id=$MEMBER_ID"

echo "Writing $ENVF (chmod 600)..."
umask 077
cat > "$ENVF" <<EOF
# Synthetic freeze-test credentials. DO NOT COMMIT. Remove with freeze_teardown.sh.
export FREEZE_API="$API"
export FREEZE_ORIGIN="$ORIGIN"
export FREEZE_LOG="$DIR/logs/freeze.jsonl"
export FREEZE_BROWSER_LOG="$DIR/logs/freeze_browser.jsonl"
export FREEZE_MEMBER_EMAIL="$MEMBER_EMAIL"
export FREEZE_MEMBER_PW="$MEMBER_PW"
export FREEZE_MEMBER_ID="$MEMBER_ID"
export FREEZE_ADMIN_EMAIL="$ADMIN_EMAIL"
export FREEZE_ADMIN_PW="$ADMIN_PW"
EOF
chmod 600 "$ENVF"

echo "Installing playwright into ops/node_modules..."
( cd "$DIR" && [ -f package.json ] || echo '{"name":"freeze-ops","private":true}' > package.json
  "$NPM" i playwright >/dev/null 2>&1 ) && echo "  playwright ok" || echo "  playwright install failed (browser probe will skip)"

# Start a clean window: truncate prior logs so coverage is measured from arm time.
echo "Resetting logs for a fresh window..."
: > "$DIR/logs/freeze.jsonl"; : > "$DIR/logs/freeze_browser.jsonl"; : > "$DIR/logs/cron.log"
rm -f "$DIR/logs/freeze_verdict.txt"

echo "Installing cron entries..."
CRON_API="*/10 * * * * . $ENVF; $PY $DIR/freeze_api_probe.py >> $DIR/logs/cron.log 2>&1 # freeze-harness"
CRON_BRW="7 * * * * . $ENVF; $NODE $DIR/freeze_browser_probe.mjs >> $DIR/logs/cron.log 2>&1 # freeze-harness"
# The autonomous gate: self-gates on window age, renders a verdict at T+24h.
CRON_VERDICT="17 * * * * . $ENVF; $PY $DIR/freeze_verdict.py >> $DIR/logs/cron.log 2>&1 # freeze-harness"
# Build the new crontab in a temp file (set -e safe: crontab -l exits non-zero
# when empty, and grep exits 1 on no match — neither is an error here).
set +e
CRONTMP="$(mktemp)"
crontab -l 2>/dev/null | grep -v '# freeze-harness' > "$CRONTMP"
printf '%s\n%s\n%s\n' "$CRON_API" "$CRON_BRW" "$CRON_VERDICT" >> "$CRONTMP"
crontab "$CRONTMP"; CRON_RC=$?
rm -f "$CRONTMP"
set -e
if [ "$CRON_RC" -eq 0 ]; then
  echo "  cron installed:"; crontab -l | grep '# freeze-harness' || true
else
  echo "  WARNING: crontab install failed (rc=$CRON_RC) — run probes manually or fix cron access"
fi

echo
echo "Done. First manual probe:"
( . "$ENVF"; "$PY" "$DIR/freeze_api_probe.py" )
echo
echo "Tail results:  tail -f $DIR/logs/freeze.jsonl"
echo "Summarize:     $PY $DIR/freeze_summary.py"
echo "Teardown:      bash $DIR/freeze_teardown.sh"
