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
