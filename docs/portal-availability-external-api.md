# External Portal Availability API

This project exposes a separate API-key gateway for an external website that needs read-only portal availability. The gateway uses the encrypted portal session configured in the admin dashboard and calls only the authenticated portal chain:

> `GET https://svp-international.xyz/api/occupations` → `POST /api/search_dates` → `POST /api/centers`

The external website must call the Laravel application, not the portal domain directly. The API key is accepted only in the `X-Portal-API-Key` request header and must be stored server-side in the consuming website’s environment. Do not place the key in browser JavaScript, a public repository, URLs, screenshots, or logs.

## Endpoints

The deployed Laravel base URL in this project is `https://takamol.choice-pc-sv.xyz`.

| Method | URL | Body | Purpose |
|---|---|---|---|
| `GET` | `/api/external/portal-availability/v1/occupations` | None | Returns normalized occupation, category, and language metadata. |
| `POST` | `/api/external/portal-availability/v1/search_dates` | `{"category_id":159,"start_from":"2026-08-22"}` | Returns available dates and district counts. |
| `POST` | `/api/external/portal-availability/v1/centers` | `{"category_id":159,"city":"Khulna","date":"2026-08-25","occupation_id":2061,"language_code":"LOABB"}` | Returns available test-center slots. |

Every request requires this header:

```http
X-Portal-API-Key: pav_live_REPLACE_WITH_THE_KEY_SHOWN_ONCE
Accept: application/json
Content-Type: application/json
```

The dates and centers routes use the portal session mapped to the API key. The consumer does not send `account_id` or `credential_id`; those values remain server-side. This prevents one consumer from selecting another stored portal session.

## Response shapes

An occupations response has this shape:

```json
{
  "success": true,
  "data": [
    {
      "name": "Load and Unload Worker",
      "occupation_id": 2061,
      "category_id": 159,
      "languages": [
        {"code": "LOABB", "name": "Bengali"}
      ]
    }
  ],
  "fetched_at": "2026-08-22T22:33:00+00:00"
}
```

A date response has this shape:

```json
{
  "success": true,
  "data": {
    "dates": [{"city": "Khulna", "date": "2026-08-25"}],
    "district_counts": {"Khulna": 6, "Nilphamari": 6}
  },
  "fetched_at": "2026-08-22T22:33:00+00:00"
}
```

A center response has this shape:

```json
{
  "success": true,
  "data": {
    "centers": [
      {
        "test_center_name": "Narail Technical Training Centre",
        "test_center_id": 181,
        "test_time": "02:30 PM",
        "available_seats": 10
      }
    ],
    "center_count": 1
  },
  "fetched_at": "2026-08-22T22:33:00+00:00"
}
```

The gateway deliberately removes upstream `payable_id` and `user_id` fields. It exposes only data needed to display availability and select a center locally.

## Consumer example

A server-side JavaScript consumer can call the Laravel gateway as follows:

```js
const baseUrl = process.env.PORTAL_AVAILABILITY_GATEWAY_URL;
const apiKey = process.env.PORTAL_AVAILABILITY_API_KEY;

async function getOccupations() {
  const response = await fetch(`${baseUrl}/api/external/portal-availability/v1/occupations`, {
    headers: {
      'X-Portal-API-Key': apiKey,
      'Accept': 'application/json',
    },
  });

  if (!response.ok) throw new Error(`Occupations request failed: ${response.status}`);
  return response.json();
}

async function getDates(categoryId, startFrom) {
  const response = await fetch(`${baseUrl}/api/external/portal-availability/v1/search_dates`, {
    method: 'POST',
    headers: {
      'X-Portal-API-Key': apiKey,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({category_id: categoryId, start_from: startFrom}),
  });

  if (!response.ok) throw new Error(`Dates request failed: ${response.status}`);
  return response.json();
}

async function getCenters(categoryId, city, date, occupationId, languageCode) {
  const response = await fetch(`${baseUrl}/api/external/portal-availability/v1/centers`, {
    method: 'POST',
    headers: {
      'X-Portal-API-Key': apiKey,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      category_id: categoryId,
      city,
      date,
      occupation_id: occupationId,
      language_code: languageCode,
    }),
  });

  if (!response.ok) throw new Error(`Centers request failed: ${response.status}`);
  return response.json();
}
```

For a browser-based consumer, route requests through that website’s own backend. Do not expose the API key to the browser. If direct browser calls are unavoidable, configure a strict allowlist of origins and a separate short-lived gateway design rather than enabling permissive CORS for a long-lived key.

## Admin setup and rotation

Run the new migrations in the deployed Laravel environment, keep one persistent `APP_KEY`, and confirm the encrypted portal session is Ready in **Admin → Portal Availability**. In the **External website API access** section, create a consumer name, select a Ready portal session, choose a rate limit, and generate a key. The raw key is shown once only. Copy it directly into the external website’s server-side environment.

To rotate a key, generate a replacement first, update the external website, test the three read-only calls, and then revoke the old key from the same dashboard. A revoked, expired, or session-unusable key returns `401` and does not call the portal.

## Security and non-goals

The external gateway is not a general proxy. It has no route for portal login, password, OTP, token refresh, booking, hold, reservation, payment, deletion, account editing, or any other state-changing upstream operation. It also does not accept a caller-supplied portal account ID or stored credential ID.

The default rate limit is 60 requests per key per minute and can be changed with the non-secret `PORTAL_AVAILABILITY_EXTERNAL_RATE_LIMIT` environment variable. API keys are stored as SHA-256 hashes; only the key prefix and metadata are shown later. The mapped portal session cookie remains encrypted at rest and is never included in an API response.

If the upstream session expires or is rejected, replace it through the encrypted admin form. Do not add the cookie to `.env`, GitHub, JavaScript, logs, or support messages.
