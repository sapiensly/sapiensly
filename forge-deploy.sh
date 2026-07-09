#!/usr/bin/env bash
#
# Graceful Forge deploy: drain long-running AI jobs BEFORE swapping code.
#
# Why: Forge deploys in place (git pull into the live directory). A Horizon
# worker mid-job during the swap either fatals on half-replaced vendor files
# or gets SIGKILLed by a daemon restart — and a hard-killed job sits invisibly
# reserved in Redis for retry_after (900s) before it is even marked failed.
# That is a silent 15 minutes for the user whose dashboard build died
# (observed on run plr_01kx1kd7sz, 2026-07-08).
#
# Sequence: pause Horizon (in-flight jobs keep running, nothing new starts) →
# wait for the long-job queues to empty (bounded) → swap code → terminate
# Horizon so supervisord boots it fresh on the new code.
#
# Wire-up in Forge (site → Deployments → Deploy Script), replace the whole
# script with:
#
#     cd $FORGE_SITE_PATH
#     bash forge-deploy.sh
#
# Two one-time Forge settings that this script cannot set for you:
#   1. The Horizon daemon (`php artisan horizon`): set "Stop Wait Seconds" to
#      330+ (default is 10 — a manual daemon restart SIGKILLs a 300s job).
#   2. Do NOT list the Horizon daemon under "Restart daemons on deploy";
#      the horizon:terminate below already restarts it gracefully.

set -euo pipefail

PHP_BIN="${FORGE_PHP:-php}"
BRANCH="${FORGE_SITE_BRANCH:-main}"
COMPOSER_BIN="${FORGE_COMPOSER:-composer}"

cd "${FORGE_SITE_PATH:-$(cd "$(dirname "$0")" && pwd)}"

# If anything below fails after the pause, un-pause so a broken deploy does
# not leave the queues frozen.
resume_horizon() { "$PHP_BIN" artisan horizon:continue >/dev/null 2>&1 || true; }
trap resume_horizon ERR

# 1) Stop feeding workers; let in-flight AI/builder jobs finish on the old code.
"$PHP_BIN" artisan horizon:pause || true
"$PHP_BIN" artisan queue:drain --timeout=330 \
    || echo "queue:drain timed out — proceeding; an in-flight job may be interrupted."

# 2) Swap code only once the long-job workers are idle.
git pull origin "$BRANCH"
"$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 3) Frontend build (Vite manifest must match the pulled pages).
if [ -f package.json ]; then
    npm ci --no-audit --no-fund
    npm run build
fi

# 4) Migrations + caches.
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

# 5) Reload FPM (Forge convention) so web requests pick up the new code.
if [ -n "${FORGE_PHP_FPM:-}" ]; then
    ( flock -w 10 9 || exit 1
      echo 'Reloading PHP FPM…'
      sudo -S service "$FORGE_PHP_FPM" reload ) 9>/tmp/fpmlock
fi

# 6) Boot workers on the new code: the old (paused, idle) master exits and
# supervisord restarts Horizon fresh — unpaused, new code.
"$PHP_BIN" artisan horizon:terminate

echo "Deploy complete."
