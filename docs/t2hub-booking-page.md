# Booking + reschedule pages now read the live T2Hub catalogue

**Status:** live and verified on https://takamol.choice-pc-sv.xyz (2026-10-02).

The existing **New Booking** (`/user/bookings/create`) and **Reschedule** pages are
unchanged visually. Their lookup chain is now served from the T2Hub agent portal
instead of the read-only Portal Availability mirror, so every dropdown carries the
real portal data: occupation → category → city → available exam date → test centre
(with seats and time) → verified exam session.

## How the switch works

| Piece | Role |
|---|---|
| `BOOKING_DATA_SOURCE` (config `t2hub.data_source`) | `t2hub` = live agent portal, anything else = the old Portal Availability mirror |
| `App\Services\T2Hub\T2HubBookingData` | Maps T2Hub payloads into the exact row shapes the pages already consume |
| `App\Services\PortalAvailabilityService` | `bookingOccupations()`, `bookingDateRows()`, `bookingCentersForDate()`, `bookingCenters()` return the T2Hub rows when the switch is on |
| `User\BookingController::lookupSessions` / `Agency\BookingController::lookupSessions` | Return T2Hub sessions when the switch is on (previously required an SVP token and answered `401 SVP session expired`) |

`bookingLanguages()` and `bookingCategories()` derive from `bookingOccupations()`,
so they follow the same source automatically. The agency booking pages and the
reschedule page call the same service, so they switch too.

Field mapping:

```
occupation  id 50 / english_name "Barber"     → occupation_id 50, category_id 50, name "Barber"
occupation  language_code "BRBBB"             → languages [{code: BRBBB, name: Bengali}]
available_dates[].test_center.city + date     → bookingDates rows {city, date}
pacc-exam-sessions sessions                   → centre rows {test_center_id, name, date, seats, time}
pacc-exam-sessions sessions                   → session rows {exam_session_id, centre, exam_date, time, seats}
```

`exam_time` from T2Hub is written to every alias the page reads
(`test_time`, `time`, `exam_time`, `session_name`), because the renderer only
looks at `test_time` / `start_time` / `time`.

## Verification (live, signed-in user session)

| Page call | Result |
|---|---|
| `GET /user/bookings/lookup/occupations` | 200 — 31 live occupations |
| `GET …/lookup/languages?occupation_id=50` | 200 — `[{code: BRBBB, name: Bengali}]` |
| `GET …/lookup/categories?occupation_id=50` | 200 — `[{id: 50, name: Barber}]` |
| `GET …/lookup/cities?category_id=50` | 200 — Rajshahi, Dhaka |
| `GET …/lookup/dates?category_id=50&city=Dhaka` | 200 — live T2Hub dates |
| `GET …/lookup/test-centers?…&date=2026-10-07` | 200 — Bangladesh German TTC, 6 seats, 9:30 AM |
| `GET …/lookup/sessions?…&test_center_id=45` | 200 — 1 verified session, 9:30 AM, 6 seats |
| `GET /user/bookings/create` | 200 — page renders |

Browser run on the real page (Barber → Dhaka):

```
Occupation search "barber"   → Barber
category_id                  → 50 : Barber
city_id                      → Rajshahi, Dhaka
language_code                → BRBBB
available_session_date       → 2026-10-08, 2026-10-13, 2026-10-18, 2026-10-19, 2026-10-25
center slots (2026-10-08)    → Bangladesh German TTC · ID 45 · 8 seats · 2:30 PM · 1 session
verified sessions            → 2:30 PM · 2026-10-08 · 8 seats · session id G6ryehrjRw--…
```

## Switching back

```bash
# /var/www/takamol/.env
BOOKING_DATA_SOURCE=portal     # anything other than "t2hub"
php artisan config:clear && php artisan config:cache
```

The Portal Availability mirror code path is untouched, so this reverts instantly.

## Still to do (needs a real SVP candidate account)

Creating the actual hold/booking (`temporary-hold`, `confirm`) still runs against
SVP itself and needs a candidate login (`/svp/login`). The catalogue half is what
these pages needed from T2Hub; the booking half stays on SVP.
