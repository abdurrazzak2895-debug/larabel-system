# Laravel (SVP Takamol) — Railway Deployment Guide

> **Goal:** Take the SVP Takamol exam-booking Laravel system live on [Railway](https://railway.app) using a PostgreSQL database, a queue worker, and the Laravel scheduler.

---

## 1. What this repository already contains

The Railway setup ships with these files:

| File | Purpose |
|---|---|
| `railway/init-app.sh` | Pre-deploy hook: storage setup, `APP_KEY` generation, `migrate`, seeding |
| `railway/run-worker.sh` | Start command for the **worker** service (processes the job queue) |
| `railway/run-cron.sh` | Start command for the **cron** service (runs the Laravel scheduler) |
| `railway.json` | Railway config-as-code: Nixpacks builder + health check + restart policy |
| `.env.railway.example` | Production environment-variable template (Postgres, logging, PACC, admin) |

No Dockerfile is required. Railway uses **Nixpacks**, which detects this as a Laravel app
(`composer.json` + `artisan`), installs PHP **8.3** (from `composer.json`), runs
`composer install --ignore-platform-reqs`, `npm install`, `npm run build` (Vite), and then
serves the app with **nginx + PHP-FPM** rooted at `/app/public`.

---

## 2. Architecture on Railway

Four services are created inside one Railway project:

```
┌──────────────────────────────────────────────────────────┐
│  Railway Project: svp-takamol                             │
│                                                          │
│  ┌─────────────┐   ┌─────────────┐   ┌─────────────┐    │
│  │  App        │   │  Worker     │   │  Cron       │    │
│  │  (web)      │   │  (queue)    │   │  (schedule) │    │
│  │ nginx+php   │   │ queue:work  │   │ schedule:run│    │
│  └──────┬──────┘   └──────┬──────┘   └──────┬──────┘    │
│         │                 │                 │           │
│         └─────────────────┼─────────────────┘           │
│                           ▼                            │
│                  ┌─────────────┐                        │
│                  │  PostgreSQL │                        │
│                  │  (service)  │                        │
│                  └─────────────┘                        │
└──────────────────────────────────────────────────────────┘
```

- **App** — the public website. The only service with a public domain.
- **Worker** — runs `php artisan queue:work` for the database-backed job queue.
- **Cron** — runs `php artisan schedule:run` every minute.
- **PostgreSQL** — shared database (sessions, cache, jobs and app data all live here).

---

## 3. Prerequisites

1. A [Railway account](https://railway.app/login) (free trial works).
2. This repository on GitHub:
   **`abdurrazzak2895-debug/larabel-system`** (repo root = the `svp-app` folder).
3. A local copy with PHP available, to generate the `APP_KEY`:
   ```bash
   php artisan key:generate --show
   ```

> ⚠️ Do **not** commit your `.env` file — it is git-ignored. All production settings are
> entered directly in the Railway dashboard (or via the `railway` CLI).

---

## 4. Step-by-step deployment

### Step 1 — Create a Railway project

1. Go to [railway.app](https://railway.app), sign in, and click **New Project**.
2. Choose **Empty Project** and name it (e.g. `svp-takamol`).

### Step 2 — Add the PostgreSQL database

1. Click **New** → **Database** → **Add PostgreSQL** (or **New** → **PostgreSQL**).
2. Name the service `Postgres` (the name matters if you use `${{Postgres.DATABASE_URL}}`
   variable references).
3. Wait until the database is provisioned (green **Deployments** badge).
4. Open the `Postgres` service → **Variables** tab and copy `DATABASE_URL`
   (something like `postgresql://postgres:xxxx@...railway.internal:5432/railway`).
   You can also find it under **Connect** → **Connection URL**.

### Step 3 — Add the App service (web)

1. Click **New** → **GitHub Repo** and select
   **`abdurrazzak2895-debug/larabel-system`**.
2. Name the service `App`.
3. In **Settings**:
   - **Source** → the repo is connected (auto-deploys on every push to `main`).
   - The builder, build command, pre-deploy command, health check and restart policy are committed in `railway.json`, so Railway should show these settings with the config-file icon.
   - Leave the custom start command blank for the App service so Nixpacks can use its default Laravel nginx + PHP-FPM start command.

### Step 4 — Add environment variables (App, Worker and Cron)

Open the **Variables** tab of the `App` service and add every variable from
`.env.railway.example`. The essentials:

| Variable | Value | Notes |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Turn debugging off in production |
| `APP_KEY` | output of `php artisan key:generate --show` | **Required** — a random 32-byte base64 key |
| `APP_URL` | `https://<your-app>.up.railway.app` | Your generated Railway domain (see **Settings → Networking → Generate Domain**) |
| `DB_CONNECTION` | `pgsql` | Use PostgreSQL, not SQLite |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` | Referenced from the Postgres service — or paste the URL directly |
| `SESSION_DRIVER` | `database` | Already the app default |
| `QUEUE_CONNECTION` | `database` | Already the app default |
| `CACHE_STORE` | `database` | Already the app default |
| `SESSION_SECURE_COOKIE` | `true` | Enforce cookies over HTTPS |
| `LOG_CHANNEL` | `stderr` | Send logs to Railway's console |
| `LOG_STDERR_FORMATTER` | `\Monolog\Formatter\JsonFormatter` | Structured JSON logs |
| `ADMIN_EMAIL` | `admin@takamol.com` | Platform admin login (seeded) |
| `ADMIN_PASSWORD` | `ChangeMe123!` | Change to a strong password |
| `PACC_BASE_URL` / `PACC_CLIENT_ID` / `PACC_CLIENT_SECRET` / `PACC_AGENCY_CODE` | your PACC/SVP values | External service integration |
| `PORTAL_AVAILABILITY_BASE_URL` | `https://svp-international.xyz` | Read-only portal availability origin |
| `PORTAL_AVAILABILITY_TIMEOUT` | `15` | Portal lookup timeout in seconds |
| `PORTAL_AVAILABILITY_CONNECT_TIMEOUT` | `5` | Portal connection timeout in seconds |
| `PORTAL_AVAILABILITY_CACHE_TTL` | `30` | Short live-availability cache in seconds |
| `PORTAL_AVAILABILITY_CREDENTIAL_CACHE_TTL` | `60` | Credential-cache setting |
| `PORTAL_AVAILABILITY_DELAY_MS` | `250` | Request pacing setting; no background poller is enabled |

> 💡 **Tip:** add these once at the **Project → Environment → Variables** level so the
> Worker and Cron services inherit them automatically.

### Step 5 — Generate and preserve the APP_KEY

Generate this once on a trusted local machine and paste the output into the Railway Project/Environment variables. **Do not generate a new key on every deploy.** The portal availability session cookie is encrypted with this key, and changing it makes saved portal credentials unreadable and invalidates application sessions:

```bash
php artisan key:generate --show
```

It prints a `base64:...` value. The pre-deploy script now fails fast when `APP_KEY` is missing instead of generating a transient deployment-specific key.

### Step 6 — Deploy

1. Click **Deploy** on the `App` service.
2. Watch the deployment logs:
   - Nixpacks builds (composer install → configured `npm run build`).
   - The pre-deploy hook runs migrations and seeds roles/admin.
   - nginx + PHP-FPM starts.
3. In **Settings → Networking**, click **Generate Domain** to get a public URL
   (e.g. `https://svp-takamol-production.up.railway.app`).
4. Set `APP_URL` to that URL and redeploy once.

**You are live!** Visit the domain, log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

---

## 5. Add the Worker service (job queue)

1. In the same project, click **New** → **GitHub Repo** and select the same repo.
2. Name the service `Worker`.
3. In **Settings → Deploy → Start Command**:
   ```
   chmod +x ./railway/run-worker.sh && sh ./railway/run-worker.sh
   ```
4. Add the **same environment variables** as the App service (or inherit them from the
   environment level).
5. Click **Deploy**.

The worker runs `php artisan queue:work` against the database queue. Bookings,
notifications, PACC calls, and other queued jobs are processed here.

---

## 6. Add the Cron service (scheduler)

1. Click **New** → **GitHub Repo** and select the same repo.
2. Name the service `Cron`.
3. In **Settings → Deploy → Start Command**:
   ```
   chmod +x ./railway/run-cron.sh && sh ./railway/run-cron.sh
   ```
4. Add the same environment variables.
5. Click **Deploy**.

The cron service loops `php artisan schedule:run` every 60 seconds — the same as a
`* * * * *` crontab entry. Any tasks registered in `routes/console.php`
(`Schedule::command(...)`) will fire here.

---

## 7. Custom domain

1. Open the **App** service → **Settings → Networking → Custom Domain**.
2. Enter your domain (e.g. `takamol.example.com`) and press **Add**.
3. Railway shows a **DNS record** to create at your registrar (usually a `CNAME`
   pointing to `...up.railway.app`, or an `A` record to Railway's IP).
4. Once DNS propagates, Railway provisions the TLS certificate automatically.
5. Update `APP_URL` to `https://takamol.example.com` and redeploy.

---

## 8. Deploying future changes

Because the repo is connected as the source, **every `git push` to `main` triggers a new
deployment automatically**:

```bash
git add .
git commit -m "my change"
git push origin main
```

The App service rebuilds, runs `init-app.sh` again (migrations + idempotent seeds), and
restarts. The Worker and Cron services redeploy too (without touching the database).

---

## 9. Environment variable reference (complete)

See `.env.railway.example` for the full annotated template. Summary:

| Group | Variables |
|---|---|
| App | `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL` |
| Database | `DB_CONNECTION=pgsql`, `DB_URL` |
| Session | `SESSION_DRIVER=database`, `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE=true` |
| Queue | `QUEUE_CONNECTION=database` |
| Cache | `CACHE_STORE=database` |
| Logging | `LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER=\Monolog\Formatter\JsonFormatter` |
| Filesystem | `FILESYSTEM_DISK=local` (or S3 in production) |
| PACC/SVP | `PACC_BASE_URL`, `PACC_CLIENT_ID`, `PACC_CLIENT_SECRET`, `PACC_AGENCY_CODE` |
| Seeder | `ADMIN_EMAIL`, `ADMIN_PASSWORD` |

---

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| `SQLSTATE[08006]` / DB connection refused | Check `DB_CONNECTION=pgsql` and `DB_URL`. If using `${{Postgres.DATABASE_URL}}`, confirm the DB service is named exactly `Postgres`. |
| **500 error: No application encryption key** | Set `APP_KEY` (from `php artisan key:generate --show`) and redeploy. |
| `PDOException: could not find driver` | Nixpacks includes `pdo_pgsql` by default. If it is still missing, add `"ext-pdo_pgsql": "*"` to `composer.json` `require` and run `composer update --lock` locally, then commit both files. |
| White page / `storage` permissions | The pre-deploy hook runs `chmod -R 775 storage` already; confirm you added the Pre-Deploy Command in **Settings → Deploy**. |
| Migrations not running | Ensure the **Pre-Deploy Command** is set; it runs `php artisan migrate --force`. |
| Vite assets 404 (blanks CSS/JS) | The build runs `npm run build`. Confirm **Build Command = `npm run build`** and that `public/build` is not in `.gitignore` (it is ignored locally, but built on Railway). |
| Node version too old for Vite 8 | Nixpacks picks a recent Node automatically. If you hit a Node error, set `NIXPACKS_NODE_VERSION=22` (or `20.19+`) as a build variable. |
| `healthcheck failed` | `railway.json` healthchecks `/`. If your `/` route redirects to `/login`, that still counts as healthy. If it returns 500, check the app logs for the underlying error. |
| Demo data duplicated after redeploy | `init-app.sh` only seeds demo data when the `agencies` table is empty; roles/admin are seeded idempotently. |
| Worker dies / `attempted to access a missing personal access token`-style errors | Check the Worker service variables — it needs all variables including `APP_KEY` and `DB_URL`. |
| Logs not visible in Railway | `LOG_CHANNEL=stderr` sends Laravel logs to Railway's console. |

---

## 11. Notes & limitations

- **Filesystem:** Railway containers are ephemeral. Files uploaded to `storage/app/public`
  are lost on redeploy. Use **Railway Volumes** (mount a volume at `/app/storage/app/public`)
  or an S3-compatible bucket (`FILESYSTEM_DISK=s3`) for persistent uploads.
- **Sessions & cache:** stored in the PostgreSQL database (tables `sessions`, `cache`),
  so they survive redeploys.
- **Scale:** the App service scales horizontally; `SESSION_DRIVER=database` keeps sessions
  shared across instances.
- **Cost:** Railway bills by usage (vCPU/RAM/metered egress + database). The free/usage-based
  trial is enough to validate this setup.


