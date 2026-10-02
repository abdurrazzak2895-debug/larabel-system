# Confirm Booking: why it did not reach the SVP payment page, and what was fixed

Scope: the user-portal wizard on `https://takamol.choice-pc-sv.xyz/user/bookings/create`
(T2Hub live lookups + real SVP reservation). Evidence was collected from live runs on
2026-10-02 between 01:00 and 02:25 UTC against the production box `46.224.89.43`.

## Symptom

Clicking **Complete booking** submitted the form, the server answered `302`, and the
wizard came back with the generic banner **"Booking failed."** without reaching the
SVP card-checkout page.

## Root causes found (in the order they were hit) and the fixes applied

### 1. `test_center_time` was not validated, so the hold never matched

`User\BookingController::store()` validated every hidden field except
`test_center_time`. The controller then compared the *validated* array against the
stored temporary hold, so the comparison always used `null` twice and rejected every
confirm:

```
production.WARNING: Booking confirm rejected: no matching temporary hold
{"temporary_hold_id":"5759441", ..., "test_center_id":"54","test_center_time":null}
```

Fix: `'test_center_time' => ['required','string','max:80']` added to the validated
field list (the agency variant already had it).

### 2. The occupation id was sent in the wrong id space (HTTP 422)

SVP rejected the reservation with:

```
{"reservation":{"occupation_id":["Can't reserve exam, selected occupation does not belong to the exam session category"]}}
```

The wizard speaks **T2Hub occupation ids** (`50` = Barber), while SVP expects its own
occupation id that belongs to the category of the selected session. Live evidence for
the Rajshahi session (category 50, Raja... category `{id:50, english_name:"Barber"}`):

```
SVP occupations in the session category (50): 1
  id=2008 name="Barber" engine=prometric
```

Fix: new `App\Services\Svp\SvpOccupationResolver` reads the authoritative session
detail for its category, lists the (cached) SVP occupation catalogue, keeps only the
occupations inside that category and prefers an exact name match with the operator's
T2Hub occupation; a single-candidate category is accepted as unambiguous. The resolved
id is used for the reservation, the credit-status lookup and the credit consumption,
and the swap is audited as `svp_occupation_resolved`. Ambiguity leaves the submitted id
untouched so SVP's own message still reaches the operator.

After the change SVP **created a real reservation** (`id 5848894`, category 50 Barber),
proving the payload is now accepted.

### 3. SVP returns no test-centre metadata for this exam type

The reservation response for `cbt_and_practical` carries no centre block, and the
strict guard therefore cancelled the freshly created reservation:

```
center_validation: {"valid":false,"metadata_present":false,"returned_center_id":null,
                    "selected_center_id":"54","selected_center_name":"Rajshahi Technical Training Centre"}
```

Fix: when (and only when) SVP echoes **no** centre metadata, the guard now accepts the
reservation if `T2HubBookingData::confirmsSessionCenter()` proves the exact session id
against the centre-scoped live snapshot for that city/date/centre. The strict
comparison is unchanged whenever SVP does return a centre, and the decision is recorded
as `center_validation.confirmed_by = centre_scoped_live_session_snapshot`.

### 4. SVP bearer tokens are single-session

SVP answers `401 JWTSessions::Errors::Unauthorized` as soon as any other sign-in
happens for the same candidate (including our own renewal or a parallel portal login),
which made the lookup chain flaky:

```
production.WARNING: SVP API request failed {"method":"GET","path":"/api/v1/users/1337578/balance","status":401}
production.WARNING: SVP API request failed {"method":"GET","path":".../exam_sessions/FJEOHLO...","status":401}
```

Fix: `User\BookingController::ensureSvpToken()` now renews through `SvpAutoSession`
instead of returning null, the sessions and credit lookups retry once with a renewed
token, and the booking confirm retries once when the failure looks like an auth
failure.

### 5. Upstream errors were hidden

`BookingService::describeReservationFailure()` now turns SVP's field-level `errors`
object into a short operator-facing reason, and failed upstream calls log
`response_errors`, so a rejected reservation no longer appears as "Booking failed."

## Remaining external blocker (not a code defect)

SVP rate-limits repeated booking attempts on the same session:

```
{"reservation":{"exam_session_id":["You cannot proceed with booking now, please try again in 16 minutes."]}}
```

This appeared after several create/cancel cycles performed during this investigation.
The reserved seat and the confirmed selection stay valid; only the confirm has to wait
out the cooldown. No payment was confirmed and no paid booking was completed.

## Probes kept for repeatable checks

- `probes/svp-occupation-map.php` — prints the session category and the SVP occupations
  inside it (used to prove `50 -> 2008`).
- `probes/t2hub-svp-one.php` — pairs one live T2Hub session with its SVP detail and the
  pre-hold verifier decision (positive and negative controls).
- `probes/check-resolver.php` — verifies the resolver/service graph boots.

## Deployment notes

- The production install uses an optimised composer classmap: a new class needs
  `composer dump-autoload -o` before it can be resolved (this bit the first deploy of
  `SvpOccupationResolver`).
- `SvpOccupationResolver` deliberately depends only on the provider interface; injecting
  `BookingService` (which injects the resolver) would create a circular container
  dependency.
## Speed work on the T2Hub booking chain (2026-10-02, second pass)

Measured per-call latency of the upstream portal (production box, same box serving users):

| call | cold | cached |
| --- | --- | --- |
| occupations / cities | ~1.3s | 2-18ms |
| test centres (`/test-centers`) | 0.5s | 2ms |
| available dates (`/exam-available-dates`) | 0.3-0.5s | 3ms |
| sessions (`/pacc-exam-sessions`) | 0.5s, **3.2s when the date has no sessions** | 4ms |

So a "slow" click is one or more *cold* upstream calls (0.3-3.2s each) that the
wizard issues sequentially; the same call served from cache costs 2-5ms.

Changes shipped to remove the cold calls from the user's click path:

1. Catalogue TTLs raised: centres 300s -> 900s, dates 90s -> 600s, sessions 45s ->
   240s (occupations/cities 600s). Holds, confirmations and payments are never
   cached.
2. `lookupDates` now prewarms, after the response is flushed (`defer()`), the centre
   list plus the next four dates for that city/category, so the operator's first
   clicks are cache hits. The warm is throttled to once per city/category per 120s.
3. `BookingService::examSession()` caches the SVP session detail for 90s, because the
   wizard verifies each session card (SVP round trip per card before).
4. Measured after the change: centre slots render in ~600ms and sessions in ~600ms on
   the first click of a warmed city; repeated clicks are 2-5ms.

## Blocking issue found at the same time: the OTP mailbox helper is over its limit

The automatic SVP sign-in cannot complete right now:

```
SVP OTP: DakBox returned a non-2xx response {"status":403,
  "body":"{\"success\":false,\"error\":\"You have reached your OTP Helper limit for this billing period.\"}"}
```

Without a mailbox source the bearer token cannot be renewed, so the hold/confirm
step is bounced to the SVP login page. This is an external plan limit, not an
application defect. Two mitigations were added meanwhile:

- The bearer token is now shared through the cache (`svp:shared_token`) instead of
  living only in each browser session. SVP allows a single active session per
  account, so sharing it removes the login churn (and the 401 storms) completely.
- A failed automatic sign-in now arms a 15 minute backoff, so a page that retries in
  a loop cannot flood the mailbox helper or pay the polling latency again.

Unblock options (need one of them):

1. Raise/reset the DakBox *OTP Helper* limit, or
2. switch the mailbox reader to IMAP (`SVP_OTP_MAILBOX_DRIVER=imap` plus host, port,
   username, password of the SVP mailbox) - direct mailbox reads have no helper
   plan limit, or
3. inject a fresh SVP bearer token so the portal runs until it expires.
