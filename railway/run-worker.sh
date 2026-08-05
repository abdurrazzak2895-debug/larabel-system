#!/bin/sh
set -e

# =========================================================
#  Railway worker service — processes the job queue.
#  Configured in Railway as the worker "Start Command":
#      chmod +x ./railway/run-worker.sh && sh ./railway/run-worker.sh
#  Uses QUEUE_CONNECTION=database (the jobs table).
# =========================================================

echo "==================================================="
echo "  Railway worker — queue processing"
echo "==================================================="

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force --no-interaction
fi

# Process the database-backed job queue (long-running loop).
php artisan queue:work database --sleep=3 --tries=3 --timeout=90 --max-time=3600
