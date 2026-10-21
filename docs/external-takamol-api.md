# External Takamol Availability API

This read-only API is served by the Laravel app at `https://choice-pc-sv.xyz`. Its public name and route use **Takamol**. Internally, it reuses the app's existing server-side agent client and `T2HubProvider` integration; it does not use `CandidateSvpSession`, the separate SVP bearer-token gateway, or the Portal Availability cookie adapter. All consumer keys share the same app-configured agent session, which is kept server-side and never returned to the consumer.

## Issue and revoke a consumer key

1. Sign in as a Super Admin with the `manage_agencies` permission.
2. Open **External Takamol API Access** at `/admin/takamol-api-access`.
3. Enter a key name, optional expiry, and per-minute request limit; generate the key.
4. Copy the plaintext key immediately. It is shown once; the database stores only its SHA-256 hash. Revoke an exposed or obsolete key from the same page.

Store the consumer key in the consuming website's server-side secret configuration, for example as `TAKAMOL_API_KEY`. Never place it in browser JavaScript, a URL, logs, source control, or chat. These are server-to-server endpoints; browser JavaScript should call the consuming website's backend instead.

## Authentication

Send the key in `X-Takamol-API-Key`:

```bash
curl 'https://choice-pc-sv.xyz/api/external/takamol-availability/v1/occupations' \
  -H "X-Takamol-API-Key: $TAKAMOL_API_KEY" \
  -H 'Accept: application/json'
```

An invalid, missing, revoked, or expired key returns HTTP 401. Invalid query parameters return HTTP 422. Upstream availability failures return a generic HTTP 502 JSON response; a key's configured per-minute limit returns HTTP 429.

## Read-only endpoints

All endpoints require the key and use GET only:

| Endpoint | Query parameters | Result |
| --- | --- | --- |
| `/api/external/takamol-availability/v1/occupations` | optional `search` | Occupation catalogue |
| `/api/external/takamol-availability/v1/cities` | required `category_id` | Cities with dates for the category (provider fallback may list cities with centers) |
| `/api/external/takamol-availability/v1/dates` | required `category_id`, `city` | Normalized available dates |
| `/api/external/takamol-availability/v1/centers` | optional `city` | Centers in one city, or all centers when omitted |
| `/api/external/takamol-availability/v1/sessions` | required `category_id`, `city`, `exam_date` (`YYYY-MM-DD`); optional `test_center_id` | Exam sessions and centers for the city/date, optionally narrowed to one center |

Example date lookup:

```bash
curl -G 'https://choice-pc-sv.xyz/api/external/takamol-availability/v1/dates' \
  -H "X-Takamol-API-Key: $TAKAMOL_API_KEY" \
  --data-urlencode 'category_id=50' \
  --data-urlencode 'city=Dhaka'
```

Successful responses use `{ "success": true, "data": ... }`. The gateway exposes lookup data only; it has no route for login, session refresh, booking, reservation, seat holds, OTP, payment, cancellation, rescheduling, or account changes. The existing agent credentials and session configuration remain on the Laravel server. The backend may refresh its shared session according to the app's existing integration settings.
