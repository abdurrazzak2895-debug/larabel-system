#!/bin/sh
set -e

# =========================================================
#  Railway cron service — runs the Laravel scheduler.
#  Configured in Railway as the cron "Start Command":
#      chmod +x ./railway/run-cron.sh && sh ./railway/run-cron.sh
#  This replaces a crontab entry:
#      * * * * * cd /app && php artisan schedule:run
# =========================================================

echo "==================================================="
echo "  Railway cron — Laravel scheduler"
echo "==================================================="

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force --no-interaction
fi

# Run the scheduler once per minute, forever.
while true; do
    php artisan schedule:run --no-interaction
    sleep 60
done
