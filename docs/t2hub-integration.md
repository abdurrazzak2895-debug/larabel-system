# T2Hub booking-data integration

**Status: LIVE and verified with real data** on `46.224.89.43`
(https://takamol.choice-pc-sv.xyz) — signed in as the T2Hub agent
`01778300054`, reading the real booking catalogue from `https://t2hub.app/takamol`.

The Laravel app now serves the live booking chain — occupation → city →
available date → test centre → exam session (with time and seats) — from the
T2Hub agent portal.

## Live verification (2026-10-02)

| Endpoint | Result |
| --- | --- |
| `GET /booking-data/status` | **200** — session stored, key captured, cookie 1254 B |
| `GET /booking-data/occupations` | **200** — **40 occupations** (e.g. `Barber` → category `50`, `Bus Driver` → `51`) |
| `GET /booking-data/cities?category_id=50` | **200** — `["Dhaka", "Rajshahi"]` |
| `GET /booking-data/dates?category_id=50&city=Dhaka` | **200** — 6 dates (`2026-10-07`, `08`, `13`, `18`, `19`, `25`) |
| `GET /booking-data/centers?city=Dhaka` | **200** — 6 centres (e.g. *Arkan Al-Taameer for professional classification - Dhaka*) |
| `GET /booking-data/sessions?category_id=50&city=Dhaka&exam_date=2026-10-07` | **200** — 1 session: **9:30 AM @ Bangladesh German TTC, 6 seats**, `site_id=45` |
| `POST /booking-data/bootstrap` (whole chain in one call) | **200** — sessions + the centres that hold them |

```
$ php artisan t2hub:session probe
  occupations: 40
  probe: category=50 city=Dhaka date=2026-10-04
  cities: 2 with dates for this category
  dates:  2026-10-07, 2026-10-08, 2026-10-13, 2026-10-18, 2026-10-19, 2026-10-25
```

## What was added

| File | Purpose |
| --- | --- |
| `config/t2hub.php` | Portal URL, credentials, timeouts, session store, data-source switch, debug flag |
| `app/Services/T2Hub/T2HubClient.php` | Livewire login, cookie/AES-key session, encrypted JSON fetch, one-shot re-login + retry |
| `app/Services/T2Hub/T2HubSessionStore.php` | Encrypted session snapshot (cookie + key + CSRF) between requests |
| `app/Services/T2Hub/T2HubProvider.php` | Booking chain: occupations, cities, centres, dates, sessions with centre resolution |
| `app/Http/Controllers/T2HubController.php` | Authenticated JSON endpoints for the booking pages |
| `app/Console/Commands/T2HubSessionCommand.php` | `php artisan t2hub:session status\|refresh\|probe\|forget` |
| `routes/console.php` | `t2hub:session refresh` every 15 minutes (keeps the session warm) |
| `bootstrap/app.php` | `booking-data/*` is a read-only JSON API, so it skips CSRF |

## How the portal client works

1. **Login** — the portal is a Filament/Livewire app. The client fetches
   `/takamol/agent/login`, reads the `csrf-token` meta tag and the
   `wire:snapshot` attribute, then POSTs the credentials to `/livewire/update`
   calling `authenticate` with `data.mobile`, `data.password`, `data.remember`
   (verified against the live form).
2. **Session material** — the login response rotates cookies; the client merges
   them and then reads `window.__sk` from the app root (`/takamol`), which is the
   page that publishes it. `T2HUB_SESSION_KEY` can be set as a fallback for
   deployments that only expose the key to browser JavaScript.
3. **API calls** — `https://t2hub.app/takamol/api/…` with the session cookie and
   `x-session-key`. Encrypted responses arrive with `x-encrypted: 1` as
   `{"p":"<base64(ciphertext‖tag)>","iv":"<base64(12-byte nonce)>"}` and are
   decrypted with **AES-256-GCM** using the base64-decoded `window.__sk` as the
   raw key.
4. **Rotation** — a `401/403/419` answer drops the stored session, logs in again
   and retries once; a failed login is retried once after a short pause.
5. The snapshot is stored with Laravel's encrypter at
   `storage/app/t2hub/session.json` (`www-data`, 0660); the cookie and key never
   leave the server and are never exposed by the API (`/booking-data/status`
   reports only lengths and expiry).

## Routes (signed-in users)

| Route | Returns |
| --- | --- |
| `GET /booking-data/status` | session + configuration health (no secrets) |
| `POST /booking-data/session` | forces a fresh portal login |
| `GET /booking-data/occupations` | PACC occupation catalogue (`id` is the category id used by the chain) |
| `GET /booking-data/cities?category_id=` | cities that publish dates for the category |
| `GET /booking-data/dates?category_id=&city=` | `available_dates[]` + a normalised `dates[]` list |
| `GET /booking-data/centers?city=` | test centres in a city (all centres without `city`) |
| `GET /booking-data/sessions?category_id=&city=&exam_date=[&test_center_id=]` | sessions with times and seats, limited to centres that hold sessions |
| `GET\|POST /booking-data/bootstrap` | the whole chain in one call (`category_id`, `city`, `exam_date`, `resource`) |

## Operating notes

- Credentials live in `/var/www/takamol/.env` (`T2HUB_EMAIL`, `T2HUB_PASSWORD`,
  file mode 640). **Rotate the agent password** now that it has been shared in
  chat, then update `.env` and run `php artisan t2hub:session refresh`.
- The Laravel scheduler must be running (`* * * * * php artisan schedule:run`)
  for the 15-minute session refresh; it was installed on this server.
- `T2HUB_DEBUG=true` prints each login/fetch step to stderr and the Laravel log.

## Next step — booking pages

The catalogue API is live; the remaining work is pointing the booking and
reschedule pages (`resources/views/user/bookings/create.blade.php`,
`reschedule.blade.php`) at `/booking-data/*` so occupation → city → date →
centre → session (with time) is driven by exactly what T2Hub reports.

The **real booking** (temporary seat hold → reservation → payment) keeps using
the SVP candidate flow that is already implemented — `GET /svp/login` →
`GET /svp/otp` → Bearer token — and needs a real SVP candidate account
(email + password, OTP by email). The T2Hub agent account is only for the
catalogue.

## Booking + reschedule pages

The New Booking and Reschedule pages read this same catalogue. See
[`t2hub-booking-page.md`](t2hub-booking-page.md) for the switch
(`BOOKING_DATA_SOURCE`) and the live verification results.
