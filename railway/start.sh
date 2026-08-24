#!/bin/sh
set -e

case "${RAILWAY_SERVICE_NAME:-}" in
    Cron)
        exec sh railway/run-cron.sh
        ;;
    Worker)
        exec sh railway/run-worker.sh
        ;;
    *)
        exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
        ;;
esac

