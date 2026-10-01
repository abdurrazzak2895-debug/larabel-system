#!/usr/bin/env bash
#
# Nightly backup for the SVP Takamol deployment: MySQL dump + application .env.
# Install to /usr/local/bin/backup-takamol.sh and schedule with cron:
#   15 3 * * * /usr/local/bin/backup-takamol.sh >> /var/log/takamol-backup.log 2>&1
#
# Keeps the newest 7 dumps and never leaves a partial file behind.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/takamol}"
DB_NAME="${DB_NAME:-takamol}"
DB_PASS_FILE="${DB_PASS_FILE:-/root/.takamol-db-pass}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/takamol}"
RETAIN="${RETAIN:-7}"

install -d -m 700 "$BACKUP_DIR"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUT="${BACKUP_DIR}/${DB_NAME}-${STAMP}.sql.gz"

# --no-tablespaces: the app user has no PROCESS privilege, which mysqldump needs
# to read tablespace metadata; the flag keeps the dump complete without it.
mysqldump --single-transaction --quick --routines --triggers --no-tablespaces \
  -u "$DB_NAME" -p"$(cat "$DB_PASS_FILE")" "$DB_NAME" | gzip -9 > "${OUT}.part"
mv "${OUT}.part" "$OUT"
chmod 600 "$OUT"

# The .env holds the app key and database password; keep a copy alongside the dump.
tar -czf "${BACKUP_DIR}/env-${STAMP}.tar.gz" -C "$APP_DIR" .env 2>/dev/null || true
chmod 600 "${BACKUP_DIR}"/env-*.tar.gz 2>/dev/null || true

# Retention
ls -1t "${BACKUP_DIR}"/"${DB_NAME}"-*.sql.gz 2>/dev/null | tail -n +$((RETAIN + 1)) | xargs -r rm -f
ls -1t "${BACKUP_DIR}"/env-*.tar.gz 2>/dev/null | tail -n +$((RETAIN + 1)) | xargs -r rm -f

printf '%s backup written: %s (%s)\n' "$(date -u +%FT%TZ)" "$OUT" "$(du -h "$OUT" | cut -f1)"
