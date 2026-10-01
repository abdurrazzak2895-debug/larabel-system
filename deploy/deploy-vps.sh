#!/usr/bin/env bash
#
# SVP Takamol — deploy / update on a single Ubuntu VPS (nginx + php-fpm + MySQL).
#
# First run provisions everything (database, .env, migrations, seed, assets,
# nginx vhost, queue workers). Later runs are safe to repeat: they install
# dependencies, migrate, rebuild assets and reload the app.
#
# Usage:
#   sudo bash deploy/deploy-vps.sh                       # deploy/update
#   DOMAIN=example.com DB_NAME=takamol sudo -E bash deploy/deploy-vps.sh
#
# Optional environment variables:
#   APP_DIR     application path            (default /var/www/takamol)
#   DOMAIN      public hostname             (default takamol.choice-pc-sv.xyz)
#   PHP_SOCK    php-fpm socket              (default /usr/run/php/php8.5-fpm.sock)
#   SKIP_SEED   set to 1 to skip seeding
#
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1

APP_DIR="${APP_DIR:-/var/www/takamol}"
DOMAIN="${DOMAIN:-takamol.choice-pc-sv.xyz}"
DB_NAME="${DB_NAME:-takamol}"
DB_USER="${DB_USER:-takamol}"
PHP_SOCK="${PHP_SOCK:-/var/run/php/php8.5-fpm.sock}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@${DOMAIN}}"

log() { printf '\n== %s\n' "$*"; }

log "database"
DB_PASS_FILE="/root/.${DB_NAME}-db-pass"
if [ -f "$DB_PASS_FILE" ]; then
  DB_PASS=$(cat "$DB_PASS_FILE")
else
  DB_PASS=$(openssl rand -hex 16)
  printf '%s' "$DB_PASS" > "$DB_PASS_FILE"
  chmod 600 "$DB_PASS_FILE"
fi
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
echo "  ${DB_NAME} ready (password stored in ${DB_PASS_FILE})"

log "environment"
ADMIN_PASS_FILE="/root/.${DB_NAME}-admin-pass"
if [ -f "$ADMIN_PASS_FILE" ]; then
  ADMIN_PASS=$(cat "$ADMIN_PASS_FILE")
else
  ADMIN_PASS="Tk$(openssl rand -base64 12 | tr -d '/+=' | head -c 12)9"
  printf '%s' "$ADMIN_PASS" > "$ADMIN_PASS_FILE"
  chmod 600 "$ADMIN_PASS_FILE"
fi
if [ ! -f "$APP_DIR/.env" ]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
  sed -i \
    -e "s|^APP_ENV=.*|APP_ENV=production|" \
    -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
    -e "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" \
    -e "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" \
    -e "s|^# *DB_HOST=.*|DB_HOST=127.0.0.1|" \
    -e "s|^# *DB_PORT=.*|DB_PORT=3306|" \
    -e "s|^# *DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" \
    -e "s|^# *DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" \
    -e "s|^# *DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" \
    -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" \
    -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
    -e "s|^ADMIN_EMAIL=.*|ADMIN_EMAIL=${ADMIN_EMAIL}|" \
    -e "s|^ADMIN_PASSWORD=.*|ADMIN_PASSWORD=${ADMIN_PASS}|" \
    "$APP_DIR/.env"
  php "$APP_DIR/artisan" key:generate --force
  echo "  .env created for ${DOMAIN}"
else
  echo "  .env already present (kept)"
fi
chown root:www-data "$APP_DIR/.env" && chmod 640 "$APP_DIR/.env"

log "dependencies"
cd "$APP_DIR"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet
npm ci --no-audit --no-fund --silent
npm run build

log "database schema"
php artisan migrate --force
if [ "${SKIP_SEED:-0}" != "1" ]; then
  php artisan db:seed --force
fi

log "caches and permissions"
php artisan storage:link || true
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" "$APP_DIR/public/build"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
find "$APP_DIR/storage" -type f -exec chmod 664 {} \;

log "nginx"
sed -e "s|server_name .*;|server_name ${DOMAIN} api.${DOMAIN#*.} 46.224.89.43;|" \
    -e "s|root .*;|root ${APP_DIR}/public;|" \
    -e "s|fastcgi_pass .*;|fastcgi_pass unix:${PHP_SOCK};|" \
    "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/takamol
ln -sf /etc/nginx/sites-available/takamol /etc/nginx/sites-enabled/takamol
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

log "queue workers and scheduler"
install -m 644 "$APP_DIR/deploy/supervisor.conf" /etc/supervisor/conf.d/takamol.conf
supervisorctl reread >/dev/null
supervisorctl update >/dev/null
supervisorctl restart takamol-queue:* takamol-scheduler >/dev/null 2>&1 || true

log "health check"
for path in / /login; do
  printf '  GET %-8s -> HTTP %s\n' "$path" \
    "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" --max-time 20 "http://127.0.0.1${path}")"
done
supervisorctl status | sed 's/^/  /'
echo "  admin: https://${DOMAIN}/login  (${ADMIN_EMAIL})"
