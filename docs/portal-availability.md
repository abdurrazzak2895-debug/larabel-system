# Portal Availability Adapter

This repository contains a **separate, read-only Portal Availability adapter** for the authenticated portal domain `https://svp-international.xyz`. It is intentionally isolated from the existing official SVP/Takamol booking provider and uses only the following upstream calls:

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/occupations` | Load occupation, category, and language metadata. |
| `POST` | `/api/search_dates` | Load available dates grouped by district/city. |
| `POST` | `/api/centers` | Load available test-center slots for a selected city and date. |

The adapter does not call booking, payment, reservation, hold, OTP, login, token-refresh, deletion, profile-edit, or other account-changing endpoints. Selecting a center in the dashboard changes only local browser state; it does not submit a reservation upstream.

## Requirements

The three portal endpoints require an **authorized authenticated session**. A clean unauthenticated request returns `401 not_authenticated`, so the application cannot use the adapter without a valid portal session credential. The repository does not contain a username, password, session cookie, browser token, or bypass mechanism.

For durable production use, prefer a supported server-to-server credential or session mechanism provided by the portal operator. A manually copied browser cookie is temporary and must be rotated when it expires. Do not paste credentials into JavaScript, commit them to Git, place them in `.env.example`, or include them in screenshots and logs.

## Installation and deployment

Run the migration in the target environment:

```bash
php artisan migrate --force
```

Configure the following non-secret variables in the application, worker, and scheduler services when using Railway or another multi-process deployment:

| Variable | Example | Description |
|---|---|---|
| `PORTAL_AVAILABILITY_BASE_URL` | `https://svp-international.xyz` | Portal origin. |
| `PORTAL_AVAILABILITY_TIMEOUT` | `15` | Maximum upstream request time in seconds. |
| `PORTAL_AVAILABILITY_CONNECT_TIMEOUT` | `5` | Maximum connection setup time in seconds. |
| `PORTAL_AVAILABILITY_CACHE_TTL` | `30` | Short cache duration for live lookup responses. |
| `PORTAL_AVAILABILITY_CREDENTIAL_CACHE_TTL` | `60` | Reserved credential-cache setting for deployment consistency. |
| `PORTAL_AVAILABILITY_DELAY_MS` | `250` | Reserved request pacing setting; no background poller is enabled. |

The application must have a persistent `APP_KEY`, and all application processes must use the same key because session cookies are encrypted before database storage. Do not rotate `APP_KEY` casually after saving portal credentials; rotating it makes existing encrypted credentials unreadable until they are entered again.

## Admin setup

After signing in as an administrator with the existing `manage_agencies` permission, open **Portal Availability** from the admin sidebar. Create a credential with a descriptive label, the portal `account_id` required by the authenticated API chain, the full authorized `Cookie` header value, and an optional expiry time. The cookie is encrypted at rest and is never returned to the browser after saving.

Select the ready credential, occupation, language, and start date. The page then calls `search_dates` and displays the returned dates and district counts. Selecting a date calls `centers` and displays the available center name, center ID, test time, and seat count. Clicking a center marks it as **selected locally** only; there is no booking or payment action on this page.

The optional **Auto-refresh 60s** control runs in the browser and repeats only the read-only lookup chain for the currently selected filters. It is disabled by default. There is no queue job, scheduler task, webhook, or server-side background poller in this feature.

## Security and operational behavior

The provider has a hard allowlist for the three approved paths. Authentication failures are reported to the admin UI as an expired or unauthorized session without exposing the cookie. Center normalization intentionally removes upstream `payable_id` and `user_id` fields because they are not needed for availability or local selection.

Each credential can be activated or deactivated locally. A credential with an expiry within the configured safety skew is treated as unavailable. When an upstream lookup fails, the failure timestamp and a bounded, non-secret error message are recorded for the selected credential; the session cookie is not logged or rendered.

## Validation

The repository includes focused tests for the exact HTTP methods and payloads, the approved endpoint paths, unauthorized-session handling, encrypted credential storage, removal of sensitive center fields, and unauthenticated admin-route protection:

```bash
php artisan test tests/Unit/PortalAvailabilityProviderTest.php tests/Feature/PortalAvailabilityServiceTest.php
```

The tests use HTTP fakes and do not contact the live portal or contain a real session cookie. A live smoke test should be performed only with an authorized, temporary session in the deployed admin UI.
