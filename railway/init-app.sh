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

# 2. Require a persistent APP_KEY. The portal session-cookie credential is
#    encrypted at rest, so generating a new key during a redeploy would make
#    existing credentials unreadable and invalidate application sessions.
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "==> ERROR: APP_KEY is required and must be persistent across deploys."
    echo "    Generate it once with: php artisan key:generate --show"
    exit 1
fi

# 3. Create the public/storage -> storage/app/public symlink
php artisan storage:link --no-interaction || echo "==> storage:link skipped"

# 4. Clear caches from any previous Railway deployment. This is
#    important when storage is persisted between releases; otherwise
#    an older compiled Blade view or config cache can remain active.
echo "==> Clearing Laravel deployment caches..."
php artisan optimize:clear --no-interaction

# 5. Run database migrations (creates all tables, including
#    sessions, jobs, cache, job_batches, failed_jobs)
echo "==> Running migrations..."
php artisan migrate --force --no-interaction

# 6. Seed roles + permissions + platform admin account.
#    This seeder is idempotent (firstOrCreate / sync), so it
#    is safe to run on every deploy. The admin credentials come
#    from the ADMIN_EMAIL / ADMIN_PASSWORD environment variables.
echo "==> Seeding roles, permissions and platform admin..."
php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction

# 7. Keep the official SVP test-center reference data current.
#    This seeder is idempotent and contains no portal demo accounts.
echo "==> Seeding official SVP test centers..."
php artisan db:seed --class=TestCenterSeeder --force --no-interaction

# 8. Permanently remove the confirmed seeded demo dataset. The command
#    is agency-code scoped, idempotent, and never deletes admin accounts
#    or non-demo agencies. Running it on every deploy is intentional:
#    it prevents old demo rows from surviving while remaining a no-op
#    after the first successful cleanup.
echo "==> Purging confirmed demo data..."
php artisan app:purge-demo-data --force --no-interaction

echo "==> init-app.sh finished OK"
