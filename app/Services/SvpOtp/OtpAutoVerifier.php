<?php

namespace App\Services\SvpOtp;

use App\Services\SvpApiService;
use Illuminate\Support\Facades\Log;

/**
 * Drives the email OTP step of the SVP login without a human.
 *
 * Flow: SVP /sessions/login -> OTP lands in the mailbox -> read it -> SVP
 * /sessions/otp -> bearer token. Only the email channel is automated; the SMS
 * channel still shows the manual form.
 */
final class OtpAutoVerifier
{
    public function __construct(private readonly SvpApiService $svp) {}

    public function enabled(): bool
    {
        return (bool) config('svp.otp_auto_verify') && $this->mailbox() !== null;
    }

    public function mailbox(): ?OtpMailbox
    {
        $driver = (string) config('svp.otp_mailbox.driver', 'none');

        if ($driver === 'dakbox') {
            return new DakBoxOtpMailbox(
                (string) config('svp.otp_mailbox.dakbox.base_url'),
                config('svp.otp_mailbox.dakbox.token'),
                config('svp.otp_mailbox.dakbox.username'),
                (int) config('svp.otp_mailbox.dakbox.timeout', 30),
            );
        }

        if ($driver === 'imap') {
            $imap = (array) config('svp.otp_mailbox.imap');

            if (empty($imap['host']) || empty($imap['username'])) {
                return null;
            }

            return new ImapOtpMailbox(
                (string) $imap['host'],
                (int) ($imap['port'] ?? 993),
                (string) $imap['username'],
                (string) ($imap['password'] ?? ''),
                (string) ($imap['folder'] ?? 'INBOX'),
                (bool) ($imap['verify_peer'] ?? false),
                $imap['fingerprint'] ?? null,
            );
        }

        return null;
    }

    /**
     * Poll the mailbox until a fresh code shows up.
     *
     * @return array{code: string, subject: string, received_at: ?int, source: string}|null
     */
    public function awaitCode(?int $since = null, ?int $timeoutSeconds = null): ?array
    {
        $mailbox = $this->mailbox();

        if ($mailbox === null) {
            return null;
        }

        $timeout = $timeoutSeconds ?? (int) config('svp.otp_auto_timeout', 90);
        $interval = max(2, (int) config('svp.otp_auto_poll_seconds', 5));
        $deadline = time() + max(1, $timeout);
        $attempt = 0;

        do {
            $attempt++;
            $code = $mailbox->fetchLatestCode($since);

            if ($code !== null) {
                Log::info('SVP OTP: code retrieved', [
                    'source' => $code['source'],
                    'subject' => $code['subject'],
                    'attempt' => $attempt,
                ]);

                return $code;
            }

            if (time() >= $deadline) {
                break;
            }

            sleep($interval);
        } while (time() < $deadline);

        Log::warning('SVP OTP: no code arrived in time', [
            'mailbox' => $mailbox->describe(),
            'timeout' => $timeout,
            'attempts' => $attempt,
        ]);

        return null;
    }

    /**
     * Log in and, when the email OTP is automated, verify it in the same call.
     *
     * @return array{status: int, body: array, otp: ?string, otp_source: ?string, auto_verified: bool}
     */
    public function login(string $email, string $password, string $otpMethod = 'email'): array
    {
        $startedAt = time() - 60; // tolerate a small clock skew between mail and server
        $result = $this->svp->login($email, $password, $otpMethod);
        $status = (int) ($result['status'] ?? 0);
        $body = (array) ($result['body'] ?? []);
        $out = [
            'status' => $status,
            'body' => $body,
            'otp' => null,
            'otp_source' => null,
            'auto_verified' => false,
        ];

        if (! $this->enabled() || $otpMethod !== 'email') {
            return $out;
        }

        $needsOtp = (bool) ($body['required_2fa'] ?? false) || ($body['data']['required_2fa'] ?? false);

        if ($status !== 200 || ! $needsOtp) {
            return $out;
        }

        $code = $this->awaitCode($startedAt);

        if ($code === null) {
            return $out;
        }

        $out['otp'] = $code['code'];
        $out['otp_source'] = $code['source'];

        $verified = $this->svp->verifyOtp($email, $password, $code['code'], $otpMethod);
        $out['status'] = (int) ($verified['status'] ?? 0);
        $out['body'] = (array) ($verified['body'] ?? []);
        $out['auto_verified'] = $out['status'] === 200;

        return $out;
    }
}
