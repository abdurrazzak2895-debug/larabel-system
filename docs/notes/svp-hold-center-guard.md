# Why the hold button said "unknown center"

## Symptom

Selecting a real live T2Hub session (for example Bogura Technical Training
Centre) and pressing **Create Hold** returned:

> Blocked before hold: live SVP session belongs to "unknown center" instead of
> "Bogura Technical Training Centre" or its date/metadata is not valid.

## Root cause

The booking wizard reads cities, dates, centres, and sessions from T2Hub, then
asks SVP to confirm the chosen session before creating a temporary hold.
`GET /exam_sessions/{id}` on SVP returns only:

```
id, category, start_date_in_browser_time_zone, start_date_in_tc_time_zone,
status, test_center { city, country_code, country_id }, time_zone_name
```

There is **no session time** and **no centre id or name** in that payload. The
verifier compared SVP's (absent) time against the T2Hub slot time (`10:00 AM`),
so `time_match` was `false` and the request was rejected — while the UI, having
no actual centre name to show, printed "unknown center".

Measured live (Rajshahi, category 50, 2026-10-06):

```
before: checks={"center_match":true,"city_match":true,"date_match":true,"time_match":false}
        verified=false
after:  checks={"center_match":true,"city_match":true,"date_match":true,"time_match":null}
        verified=true
```

## Fix

1. **Missing upstream data no longer counts as a contradiction.** `time_match`
   is `null` when SVP exposes no time; only a time SVP really reports can fail
   the check. Same for the centre: SVP's detail carries no centre at all.
2. **Provenance is confirmed server-side.** `T2HubBookingData::sessionSnapshot()`
   re-reads the live centre-scoped session list for the requested
   category/city/date and confirms the submitted `exam_session_id` is present.
   That keeps the guard strict: a caller cannot point a hold at another centre.
3. **City comparison tolerates spelling variants** (`Bogura`/`Bogra`,
   `Cumilla`/`Comilla`, `Chattogram`/`Chittagong`, ...) and also accepts the
   centre's own city from the snapshot when the wizard is scoped to a division
   city.
4. **SVP session is renewed automatically.** The wizard is T2Hub-driven, so the
   hold click can arrive after the SVP bearer token expired. `SvpAutoSession`
   signs the configured candidate in and verifies the e-mail OTP through the
   configured mailbox driver (token lives at `access_payload.access`). The
   booking page warms that token on load.
5. **Honest block messages.** When a check does fail, the UI now reports the
   real reason (city, date, time, or centre) instead of "unknown center".
   A missing SVP login redirects to the SVP sign-in page instead of showing a
   misleading centre error.

## Verified

* real live session: `verified=true`, upstream `200`, snapshot confirmed
* wrong city: `verified=false` (`city_match=false`)
* wrong date: `verified=false` (`date_match=false`)

Read-only probes used for this investigation:

* `probes/t2hub-svp-one.php` - pairs one live T2Hub session with its SVP detail
* `probes/t2hub-session-shape.php`, `probes/t2hub-centre-cities.php` - payload shape
* `probes/svp-login-shape.php` - login/OTP envelope with masked values
