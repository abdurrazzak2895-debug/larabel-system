# T2Hub booking-data integration

**Status:** implemented and deployed on `46.224.89.43` (https://takamol.choice-pc-sv.xyz).
Waiting on one input to go live: the **T2Hub agent account** (`T2HUB_EMAIL` / `T2HUB_PASSWORD`).

The Laravel app now reads the live booking catalogue — occupation → city →
available date → test centre → exam session (with time) — from the T2Hub agent
portal (`https://takamol.t2hub.app`), instead of only from the SVP/PACC API.

## What was added

| File | Purpose |
| --- | --- |
| `config/t2hub.php` | Portal URL, credentials, timeouts, session store, data-source switch |
| `app/Services/T2Hub/T2HubClient.php` | Livewire login, session cookie + AES key handling, encrypted JSON fetch |
| `app/Services/T2Hub/T2HubSessionStore.php` | Encrypted session snapshot (cookie + key + CSRF) between requests |
| `app/Services/T2Hub/T2HubProvider.php` | Booking chain: occupations, centres, dates, sessions (with centre resolution) |
| `app/Http/Controllers/T2HubController.php` | Authenticated JSON endpoints for the booking page |
| `app/Console/Commands/T2HubSessionCommand.php` | `php artisan t2hub:session status\|refresh\|probe\|forget` |

### Routes (all require a signed-in user)

| Route | Returns |
| --- | --- |
| `GET /booking-data/status` | session + configuration health (no secrets) |
| `POST /booking-data/session` | forces a fresh portal login |
| `GET /booking-data/occupations` | PACC occupation catalogue (carries the category id the chain needs) |
| `GET /booking-data/cities?category_id=` | cities with availability for the category |
| `GET /booking-data/dates?category_id=&city=` | available exam dates |
| `GET /booking-data/centers?city=` | test centres in a city |
| `GET /booking-data/sessions?category_id=&city=&exam_date=[&test_center_id=]` | sessions **with times and seat counts**, limited to centres that actually hold sessions |
| `GET\|POST /booking-data/bootstrap` | one call for the whole chain (`category_id`, `city`, `exam_date`, `resource`) |

## How the portal client works

1. **Login** — the portal is a Filament/Livewire app. The client fetches
   `/takamol/agent/login`, reads the `csrf-token` meta tag and the
   `wire:snapshot` attribute, then posts the credentials to `/livewire/update`
   calling `authenticate` with `data.mobile`, `data.password`, `data.remember`
   (verified against the live login page — those are the real field names).
2. **Session material** — the login response rotates cookies; the client merges
   them, follows the redirect and captures the page AES key `window.__sk`.
   When the portal only publishes that key to browser JavaScript, the
   pre-captured `T2HUB_SESSION_KEY` is used instead.
3. **API calls** — every booking call goes to
   `https://takamol.t2hub.app/takamol/api/…` with the session cookie and
   `x-session-key`. Responses arrive as `x-encrypted: 1` with the body
   `{"p":"<base64(iv[12] ‖ ciphertext ‖ tag[16])>"}`, decrypted with
   **AES-256-GCM** using the base64-decoded `window.__sk` as the raw key.
4. **Session rotation** — a `401/403/419` answer drops the stored session and
   logs in once more before the request is retried.
5. The session snapshot is stored with Laravel's encrypter at
   `storage/app/t2hub/session.json`; the cookie and key never leave the server.

## Verified against the live portal (2026-10-02)

| Check | Result |
| --- | --- |
| `https://takamol.t2hub.app/takamol/agent/login` reachable from the server | **200** in 0.6 s, 34.5 KB |
| Login form contract (`csrf-token`, `wire:snapshot`, `data.mobile`/`data.password`/`data.remember`) | **matches the implementation** |
| `window.__sk` on the public login page | absent — it is emitted only for an authenticated session, so a real login (or a captured key) is required |
| `/takamol/api/pacc/occupations`, `/test-centers?division=Dhaka`, `/exam-available-dates?category_id=50&city=Dhaka` | **200** with `x-encrypted: 1` and a `{"p":"…"}` envelope |
| `php artisan t2hub:session status` | reports `credentials: MISSING (T2HUB_EMAIL / T2HUB_PASSWORD)` |
| `GET /booking-data/status` (authenticated) | **200** with the configuration/session summary |
| `GET /booking-data/occupations` (authenticated) | **502** with a clear "credentials not configured" detail |

## Going live

Add the agent account to `/var/www/takamol/.env`:

```ini
T2HUB_EMAIL=agent@example.com
T2HUB_PASSWORD=…
```

then:

```bash
cd /var/www/takamol
php artisan config:cache
php artisan t2hub:session refresh            # logs in, stores cookie + __sk
php artisan t2hub:session probe --city=Dhaka --date=2026-10-05
```

`probe` prints the occupation count, the first occupation, the resolved
category/city/date, the session count, the centres that hold sessions, and the
first few session times with seat counts.

If the portal refuses server-side login (OTP/2FA or a key emitted only in the
browser), capture the values once from a logged-in browser session and set
`T2HUB_SESSION_COOKIE`, `T2HUB_SESSION_KEY` and `T2HUB_SESSION_CSRF` instead —
the client will use them directly.

## Real SVP booking (after the catalogue)

The actual hold + reservation still runs through the SVP account flow that is
already implemented in the app:

1. `GET /svp/login` → candidate email + password (`/api/v1/sessions/login`)
2. `GET /svp/otp` → OTP verification (`/api/v1/sessions/otp`) → Bearer token
3. Booking page → temporary seat hold → reservation → payment page

That path needs a **real SVP candidate account** (email + password + OTP
delivery). It does not use the T2Hub agent account.

## Remaining wiring

The existing booking pages (`resources/views/user/bookings/create.blade.php`,
`reschedule.blade.php`) still read their lookups from the portal-availability
adapter. Pointing those lookups at `/booking-data/*` is the next step once the
credentials are in place, so the page shows exactly what T2Hub reports.
