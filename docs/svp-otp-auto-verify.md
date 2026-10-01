# SVP email OTP — automatic verification

**Status:** implemented and deployed on `takamol.choice-pc-sv.xyz` (46.224.89.43).
Live login with the real account already returns `{"required_2fa": true}`; the
last missing piece is mailbox access (see *Enable*).

## What it does

SVP answers `POST /api/v1/sessions/login` with `{"required_2fa": true}` and mails
a one-time code. Until now a human had to open the mailbox, read the code and
paste it into `/svp/otp`.

With this subsystem the code is read from the mailbox and submitted to
`POST /api/v1/sessions/otp` inside the same request, so the SVP bearer token —
and therefore the booking, reschedule and hold/confirm chain — is obtained with
no manual step. **Only the email channel is automated**; SMS still shows the
manual form.

```
login form ──► SVP /sessions/login ──► required_2fa
                                          │
                       mailbox read ◄──────┘        (DakBox API or IMAP)
                                          │
                     SVP /sessions/otp ───┴──► bearer token ──► booking chain
```

## Files

| File | Purpose |
|---|---|
| `app/Services/SvpOtp/OtpMailbox.php` | contract: return only codes newer than `$since` |
| `app/Services/SvpOtp/DakBoxOtpMailbox.php` | reads `dakbox.net/api/otp/get` with a Bearer token |
| `app/Services/SvpOtp/ImapOtpMailbox.php` | pure-PHP IMAP over TLS (no `php-imap` extension needed) |
| `app/Services/SvpOtp/OtpAutoVerifier.php` | poll loop, login + verify, driver factory |
| `app/Console/Commands/SvpOtpTestCommand.php` | `php artisan svp:otp-test` |
| `app/Http/Controllers/Auth/SvpLoginController.php` | `login()` completes the OTP step when enabled |

The mailbox read never logs the code itself, only its source and subject. If no
code arrives within `SVP_OTP_AUTO_TIMEOUT`, the user is sent to the manual OTP
form exactly as before — the automation can never lock anyone out.

## Enable

```dotenv
SVP_OTP_AUTO_VERIFY=true

# Option A — DakBox API (no mailbox password; token from the dakbox.net dashboard)
SVP_OTP_MAILBOX_DRIVER=dakbox
SVP_OTP_DAKBOX_URL=https://dakbox.net
SVP_OTP_DAKBOX_TOKEN=            # <-- required
SVP_OTP_DAKBOX_USERNAME=mdforidmmmmm

# Option B — IMAP (any mailbox)
SVP_OTP_MAILBOX_DRIVER=imap
SVP_OTP_IMAP_HOST=mail.example.com
SVP_OTP_IMAP_PORT=993
SVP_OTP_IMAP_USER=user@example.com
SVP_OTP_IMAP_PASS=secret
SVP_OTP_IMAP_FINGERPRINT=        # optional SHA-256 pin for hosts without a CA chain
```

Then `php artisan config:clear`.

## Test

```bash
php artisan svp:otp-test --code-only          # read the mailbox only
php artisan svp:otp-test --timeout=120        # read + complete the SVP login
```

Current state on the server:

```
$ php artisan svp:otp-test --code-only --timeout=12
Mailbox: dakbox api (mdforidmmmmm)
Auto verify: disabled (SVP_OTP_AUTO_VERIFY=false)
No OTP found in the mailbox.
```

That is the expected output while `SVP_OTP_DAKBOX_TOKEN` is empty.

## Why the token is required

`dakbox.net` is a temporary-mail service. Its inbox API
(`GET /api/otp/get?email=<username>&website=svp`) — the same endpoint the DakBox
browser extension uses for its SVP auto-fill — is guarded by a Bearer API token
created in the DakBox dashboard. The public `/go/<username>` page needs a captcha
and the account password is *not* the SVP password:

| Credential | Result |
|---|---|
| `mdforidmmmmm@dakbox.net` + `Hannan@1234` on **SVP** | ✅ `200 {"required_2fa": true}` |
| same pair on **dakbox.net** login | ❌ redirect back to `/login` |
| same pair on **IMAP** `mail.dakbox.net:993` | ❌ `AUTHENTICATIONFAILED` |
| public inbox `/go/mdforidmmmmm` | ⚠ loads but shows `Inbox 0` |

So either a DakBox API token, the DakBox account password, or IMAP credentials
unlock the automation. Once the token is in `.env`, every SVP login (including
the live hold/confirm test) runs unattended.
