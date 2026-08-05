#!/bin/sh
set -e

# =========================================================
#  Railway pre-deploy hook — runs BEFORE the app starts.
#  Configured in Railway as the "Pre-Deploy Command":
#      chmod +x ./railway/init-app.sh && sh ./railway/init-app.sh
# =========================================================

echo "==================================================="
echo "  Railway pre-deploy — init-app.sh"
echo "==================================================="

# 1. Ensure writable Laravel storage directories exist
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
chmod -R 775 storage bootstrap/cache

# 2. Generate APP_KEY if it is missing (idempotent)
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "==> APP_KEY is empty — generating one..."
    php artisan key:generate --force --no-interaction
fi

# 3. Create the public/storage -> storage/app/public symlink
php artisan storage:link --no-interaction || echo "==> storage:link skipped"

# 4. Run database migrations (creates all tables, including
#    sessions, jobs, cache, job_batches, failed_jobs)
echo "==> Running migrations..."
php artisan migrate --force --no-interaction

# 5. Seed roles + permissions + platform admin account.
#    This seeder is idempotent (firstOrCreate / sync), so it
#    is safe to run on every deploy. The admin credentials come
#    from the ADMIN_EMAIL / ADMIN_PASSWORD environment variables.
echo "==> Seeding roles, permissions and platform admin..."
php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction

# 6. Seed the full demo dataset ONLY on the very first deploy.
#    DemoSeeder uses ->create() for transactions/bookings, so
#    running it twice would duplicate data. We guard it by
#    checking whether agencies already exist.
if php artisan tinker --execute="exit(\App\Models\Agency::query()->count() > 0 ? 0 : 1);" >/dev/null 2>&1; then
    echo "==> Agencies already exist — skipping demo data."
else
    echo "==> First deploy detected — seeding demo data..."
    php artisan db:seed --force --no-interaction
fi

echo "==> init-app.sh finished OK"
