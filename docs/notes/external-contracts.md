# External contracts discovered (2026-10-02)

## DakBox temporary-mail API (OTP reading)
- `GET https://dakbox.net/api/otp/get?email=<username>&website=svp`
- Auth: `Authorization: Bearer <api token>` (token from the DakBox dashboard)
- Behaviour: long-polls (~up to 70s+) for the newest message; concurrent calls for
  the same mailbox return `429 {"success":false,"error":"A request for this email is
  already in progress","retry_after":5}`.
- Success body:
  `{"success":true,"data":{"subject":"Two-Factor Authentication","from":"no-reply@pacc.sa",
  "to":"mdforidmmmmm@dakbox.net","date":"02-10-2026 03:17:38 AM",
  "date_utc":"2026-10-01 21:17:38","timezone":"Asia/Dhaka","otp":"001887",
  "otp_expired_at":null,"expired":false,"has_expiry":false,"age_seconds":8,
  "remaining_seconds":null,"extracted_by":"pattern"}}`
- Registration OTP variant: `GET /api/otp/get/verification?email=<username>`
- Public inbox page (needs captcha, no API): `https://dakbox.net/go/<username>`
- IMAP host `mail.dakbox.net:993` exists but rejects the SVP account password.
- Source: extension `github.com/techtwice/dakbox-extension` (`core/background.js`,
  `modules/otp-api.js`, `content-scripts/svp-otp-content.js`).

## SVP reservations (hold + confirm)
- HOLD: `POST /individual_labor_space/exam_reservations`
- CONFIRM validation: `GET /individual_labor_space/exam_reservations/validate`
- Details: `GET /individual_labor_space/exam_reservations/{id}`
- Cancel: `DELETE /individual_labor_space/exam_reservations/{id}?locale=en`
- Reschedule: `POST /individual_labor_space/exam_reservations/{id}/reschedule`
- Credit: `/individual_labor_space/reservation_credits/use`
- Response shapes seen: occupations -> `{"occupations":[...]}`
- Access token path: `body.access_payload.access`

## SVP account used for the live test
- `mdforidmmmmm@dakbox.net` / SVP password confirmed working (password kept in .env only)

## SVP hold / confirm payload (live-verified 2026-10-02)
- HOLD: `POST /temporary_seats` `{"exam_session_id":"<id>","test_center_id":"403"}`
  -> `{"id":5759185,"exam_session_id":0,"expired_at":"01/10/2026 21:42"}`
- One active hold per candidate: second call ->
  `{"errors":{"temporaryseat":{"labor_id":["has already been taken"]}}}`
- CONFIRM: `POST /exam_reservations` with `site_id:null`, `site_city:null`,
  `test_center_id`, `hold_id`, `country_id:78`, declarations = true.
  Sending `site_id`/`site_city` instead -> 422 `Exam engine code not found`.
- Category payload carries `exam_engine` (e.g. `tep`) and `exam_engine_codes[]`
  entries `{code, exam_engine_id, exam_engine_name, language_code, methodology,
  question_count}`; Bengali = `LOABB` with `exam_engine_id: 1` (prometric).
- Sessions: `exam_sessions` under `data.sessions`; session ids are opaque
  strings; each session carries `test_center_id`, `test_center_city`,
  `exam_date`, `status`.
- Dates: `available_dates[].start_date_in_browser_time_zone`.

## Official SVP frontend confirm payload (captured network trace)
Source: `ch` repo `frontend/src/pages/exam/BookingPage.reservation-payload.test.ts`
("Captured from a real network trace of svp-international.pacc.sa", session
1554447, occupation 2061, Rajshahi):

```json
{ "exam_session_id": 1554447, "occupation_id": 2061, "language_code": "LOABB",
  "methodology": "in_person", "site_id": null, "site_city": null, "hold_id": null }
```

- The official UI sends **no** exam-engine field, **site_id/site_city null**, and
  **hold_id null** — SVP derives the centre and the exam engine from
  `exam_session_id`.
- Sending `site_id`/`site_city` (UI values) made SVP confirm a different centre
  in the same city (documented bug in the reference app).
- Session ids can be encrypted strings, e.g.
  `L-iQDqXgIA---sS9xLf-Zor-lAFV--NtHYoNAduM7E8CLkoTMALQ` — pass them through
  untouched.
- `language_code` must match the session/category exam engine (our live 422 was
  the category engine `tep` vs the Bengali `LOABB` prometric entry).

## Exam-engine resolution — live findings (2026-10-02, verified)

`GET /individual_labor_space/categories` returns each category **with its exam
engine**:

| category | name | exam_engines |
|---|---|---|
| 181 | Administrative Assistant | `tep` |
| 59 | Tailoring | `prometric` |
| 37 | Builders | `prometric` |
| 55 | Heavy Truck Drivers | `prometric` |
| 28 | Mechanical | `prometric` |
| 160 | Offices and Facilities Cleaning | `prometric` |

`GET /individual_labor_space/exam_engines` → `[{"id":1,"name":"prometric"},{"id":2,"name":"tep"}]`.

Live confirm attempts with the **official-parity payload** (7 fields, nulls):

| attempt | result |
|---|---|
| category 181 (`tep`), 4 sessions across Rajshahi + Dhaka | 422 `reservation_exam_engine_snapshot: Exam engine code not found` |
| category 55 / 37 (`prometric`), session found | **past the engine check** — 422 only for `occupation does not belong to the exam session category` |

=> **The engine error is a category/centre-engine mismatch, not a payload
problem.** `tep` categories reject every centre/session tried, while
`prometric` categories pass the engine check with the identical payload.

Also verified (all rejected): `language_code` values
LOABB/LOANN/TDEE2/BRBBB/prometric/tep/en/EN/ar/AR/LOENB/LOB2B, and the field
shapes `exam_engine_code`, `exam_engine_id`, `exam_engine_snapshot{exam_engine_code|exam_engine_id}`.
`language_code` is mandatory (422→400 `param is missing: language_code` without it).

`GET /individual_labor_space/occupations` returns 0 rows for
`category_id` 181/55 alone — the occupation list needs its own query contract
(see provider `categoriesForOccupation()`), and **an occupation must belong to
the session's category** (server-side rule).

Official SVP website automated login is not possible from this environment:
the sign-in button triggers no request (reCAPTCHA is present on `/auth/login`).

### What this means for the booking wizard

1. The occupation chosen by the user **must belong to the category of the
   selected exam session** — SVP rejects the mix with
   `occupation does not belong to the exam session category`.
2. The session's category carries the exam engine. Pairing a session from a
   `tep` category with the default `LOABB` language keeps returning
   `Exam engine code not found`, whatever centre or date is chosen, while
   `prometric` categories pass the engine check with the identical payload.
3. `GET /individual_labor_space/occupations` returned 0 rows for
   `category_id`, for `page`+`per_page`, and with city/centre/date filters —
   the occupation list must be fetched through the route the portal UI uses
   (see `PortalAvailabilityService::occupations()`), not by guessing params.
4. Live confirm reached **past the engine check** only for `prometric`
   categories; no reservation could be created for the test candidate because
   no occupation belonging to a `prometric` category was reachable from this
   environment.

**Next step to finish the live booking:** capture one confirm from a real
browser session on svp-international.pacc.sa (DevTools → Network → the
`exam_reservations` POST). That single payload settles the engine value, since
automated login there is blocked by reCAPTCHA.
