# VPS deployment — SVP Takamol (live)

**Status: live** — provisioned and verified on 2026-10-02 (UTC).

| Item | Value |
| --- | --- |
| Frontend / app | **https://takamol.choice-pc-sv.xyz** |
| API host | **https://api.choice-pc-sv.xyz** |
| Server | `46.224.89.43` (Hetzner, hostname `vps`) · Ubuntu 26.04.1 LTS · 2 vCPU / 3.7 GB RAM / 38 GB |
| App path | `/var/www/takamol` (owner `www-data`, `.env` mode `640 root:www-data`) |
| Stack | PHP **8.5.4** (php-fpm socket `/var/run/php/php8.5-fpm.sock`) · Laravel **13.23.0** · MySQL **8.4.11** · nginx **1.28.3** · Node 22 (Vite build) |
| Database | MySQL `takamol`, user `takamol` — password in `/root/.takamol-db-pass` |
| Admin account | `admin@takamol.choice-pc-sv.xyz` — password in `/root/.takamol-admin-pass` |
| TLS | Let's Encrypt, both hostnames on one certificate, auto-renew via certbot timer, HTTP→HTTPS redirect |
| Background work | supervisor: `takamol-queue` (2 workers) + `takamol-scheduler`, logs in `storage/logs/` |

## Verified

| Check | Result |
| --- | --- |
| `GET /` and `GET /login` over HTTPS | **200** |
| `POST /login` with the admin account | **302 → /admin/dashboard** |
| `GET /admin/dashboard` with the session | **200** (`Admin Dashboard · SVP Takamol`) |
| `http://` request | **301 → https://** |
| Certificate | CN `takamol.choice-pc-sv.xyz`, valid until 2026-12-30 |
| Queue workers / scheduler | `RUNNING` under supervisor |

## Deploying an update

```bash
ssh -i ~/.ssh/j8bon-vps2-ed25519 root@46.224.89.43
cd /var/www/takamol && git pull            # or re-sync the working tree
bash deploy/deploy-vps.sh                  # idempotent: deps, migrate, assets, caches, reload
```

`deploy/deploy-vps.sh` performs the whole first-time provisioning as well
(database, `.env`, migrations, seed, Vite build, nginx vhost, supervisor).
Override `APP_DIR`, `DOMAIN`, `DB_NAME`, `PHP_SOCK` or `SKIP_SEED=1` as needed.

## MySQL compatibility fixes

Two migrations used generated identifiers longer than MySQL's 64-character
limit and failed with error 1059 (they only ever ran on SQLite/PostgreSQL):

- `2026_08_23_000002_create_portal_availability_api_keys_table.php`
  - foreign key → explicit `pa_api_keys_credential_fk` (generated name was 70 chars)
  - composite index → explicit `pa_api_keys_credential_revoked_idx` (generated name was 79 chars)

Both fixes are portable and are already merged on `main`.

## Still to configure

1. **External credentials** — `PACC_CLIENT_ID`, `PACC_CLIENT_SECRET`,
   `PACC_AGENCY_CODE` and `PACC_BASE_URL` are empty, so the SVP/Takamol booking
   and portal-availability features cannot reach the upstream service yet.
   Add the real values to `/var/www/takamol/.env` and run
   `php artisan config:cache`.
2. **Mail** — `MAIL_MAILER=log`; set real SMTP values for OTP and notification mail.
3. **Uploads** — `FILESYSTEM_DISK=local`; switch to S3/public disk if files should
   survive a server rebuild.
4. **Backups** — a nightly MySQL dump runs via `/usr/local/bin/backup-takamol.sh`
   (cron 03:15, 7-day retention in `/var/backups/takamol`). Copy them off-server
   for real disaster recovery.

## Notes

- `SESSION_SECURE_COOKIE=true` — the app is HTTPS-only; logins over plain HTTP
  will not hold a session.
- The nginx vhost is the `default_server` for port 80/443 on this host and also
  answers on the bare IP, so the app is reachable before DNS is pointed.
- `www.takamol.choice-pc-sv.xyz` has no DNS record; add one if it is needed.
