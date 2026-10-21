# External SVP Availability API

This gateway is served by this Laravel app (production host: `https://choice-pc-sv.xyz`). A partner website calls this host with a generated `X-Portal-API-Key`. Each key is mapped server-side to one existing `CandidateSvpSession`; the session's bearer token is encrypted at rest and is never returned to the partner.

The gateway calls the SVP/Takamol provider already configured by the app (`SVP_BASE_URL`) using that saved bearer token. It does not use the separate `PORTAL_AVAILABILITY_BASE_URL` cookie adapter or `svp-international.xyz`. Do not set `SVP_BASE_URL` to `choice-pc-sv.xyz`; that would make the app call itself.

## Issue a key

1. Sign in to the app and complete the existing SVP/Takamol login flow for the candidate. The app stores the encrypted bearer session in `candidate_svp_sessions`.
2. As an administrator, open **External SVP API Access**.
3. Select a session marked usable, enter a key name, choose an optional expiry and request limit, then generate the key.
4. Copy the plaintext key once and store it as a server-side secret on the partner website. The database stores only its SHA-256 hash. Never put the key or the upstream bearer token in browser JavaScript, a URL, logs, source control, or chat.

A key stops working if it is revoked, expires, or its mapped SVP session expires. To rotate a key, issue a replacement and then revoke the old key.

## Authentication

Send the key in the `X-Portal-API-Key` request header. Never send the candidate's SVP bearer token from the partner website.

```bash
curl 'https://choice-pc-sv.xyz/api/external/svp-availability/v1/occupations' \
  -H "X-Portal-API-Key: $PORTAL_AVAILABILITY_API_KEY" \
  -H 'Accept: application/json'
```

## Read-only endpoints

All endpoints require a valid key and use GET only:

| Endpoint | Query | Behavior |
| --- | --- | --- |
| `/api/external/svp-availability/v1/occupations` | none | SVP occupation catalog |
| `/api/external/svp-availability/v1/cities` | optional `category_id` | SVP city list |
| `/api/external/svp-availability/v1/dates` | required `category_id`, `city`; optional `exam_date` (`YYYY-MM-DD`) | SVP available dates |
| `/api/external/svp-availability/v1/centers` | optional `city`, `category_id` | SVP test-center catalog |
| `/api/external/svp-availability/v1/sessions` | required `category_id`, `city`, `test_center_id`, `exam_date` (`YYYY-MM-DD`) | SVP sessions for one center/date |

Example date lookup:

```bash
curl -G 'https://choice-pc-sv.xyz/api/external/svp-availability/v1/dates' \
  -H "X-Portal-API-Key: $PORTAL_AVAILABILITY_API_KEY" \
  --data-urlencode 'category_id=159' \
  --data-urlencode 'city=Dhaka'
```

The API key controls access to lookup data only. There are no external API routes for OTP, temporary seat holds, reservations, booking, payment, cancellation, rescheduling, or account changes. Upstream errors and HTTP statuses are passed through as JSON responses where possible; a missing/invalid/expired key or expired mapped session returns HTTP 401. Requests are rate-limited per key.

The legacy `/api/external/portal-availability/v1` API is separate and remains for compatibility with existing cookie-backed integrations. New consumers should use the SVP-session endpoints above; keys generated in **External SVP API Access** are not accepted by the legacy routes.
