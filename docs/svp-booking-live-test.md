# SVP booking — live hold + confirm test (2026-10-02)

Run against the real tenant with the candidate account, using the automatic
email OTP (no manual step).

```
$ php artisan svp:live-booking-test --password=***
1) SVP login with automatic email OTP
   token acquired (auto OTP via dakbox)
2) Occupations          Administrative Assistant (id 2492)
3) Categories           Administrative Assistant (id 181)
4) Available dates      first date: 2026-10-05 (Dhaka)
5) Test centres         centre 403 -> session <live session id>
6) HOLD                 POST /individual_labor_space/temporary_seats
                        -> {"id":5759185,"exam_session_id":0,"expired_at":"01/10/2026 21:42"}
7) CONFIRM              POST /individual_labor_space/exam_reservations
                        -> 422 {"errors":{"reservation_exam_engine_snapshot":"Exam engine code not found"}}
```

## Hold — works

`POST /individual_labor_space/temporary_seats` with

```json
{ "exam_session_id": "<session id>", "test_center_id": "403" }
```

returns the hold id and its expiry (~20 minutes). SVP allows **one active hold
per candidate**: a second call answers
`{"errors":{"temporaryseat":{"labor_id":["has already been taken"]}}}` — the
reference flow then reads `GET /exam_reservations?locale=en` and reuses the
existing hold.

`TakamolProvider::temporarySeats()` and `BookingService::createTemporaryHold()`
were added for this; the app previously kept only a browser-session "hold" and
never asked SVP for a real seat.

## Confirm — payload corrected, one upstream value outstanding

The working reference payload (verified against the repo at
`frontend/src/lib/booking-utils.ts` + `BookingPage.tsx`) is:

```json
{
  "exam_session_id": "<id>", "occupation_id": 2492, "methodology": "in_person",
  "language_code": "LOABB", "site_id": null, "site_city": null,
  "hold_id": <hold id>, "country_id": 78, "test_center_id": "403",
  "accept_declaration": true, "info_confirmation": true, "practical_confirmation": true
}
```

`BookingService::reservationPayload()` used to send `site_id = test_center_id`
and `site_city = city`, which makes SVP resolve a site that has no exam engine.
It now sends `test_center_id` and leaves `site_id`/`site_city` out.

Remaining blocker: SVP still rejects the confirm with
`reservation_exam_engine_snapshot: Exam engine code not found`. The code is not
part of the reference payload either — SVP derives it from the session's
category `exam_engine_codes` entry that matches the chosen language +
methodology. For category 181 the category engine is **tep** while the Bengali
(`LOABB`, `in_person`) entry carries `exam_engine_id: 1` (**prometric**), so the
pair does not resolve. Two candidate fixes to be confirmed against the SVP UI:

1. send the category's matching `exam_engine_codes` entry as
   `reservation_exam_engine_snapshot`, or
2. let the user pick a language whose engine matches the category engine, so the
   existing payload resolves unchanged.

Capturing the exact request the SVP website itself sends for one real booking
settles this in a single step.

## Notes

- The test hold expires by itself; no real reservation was created.
- The account's existing reservations are untouched (list still shows the same
  rows before and after the test).
