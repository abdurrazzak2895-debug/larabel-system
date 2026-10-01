<?php

namespace App\Services\SvpOtp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the OTP from the DakBox temporary-mail API.
 *
 * DakBox exposes `GET /api/otp/get?email=<username>&website=<site>` guarded by a
 * Bearer API token (the same endpoint the DakBox browser extension uses for its
 * SVP auto-fill). The token comes from the DakBox dashboard, so this driver
 * needs no mailbox password.
 */
final class DakBoxOtpMailbox implements OtpMailbox
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly ?string $username,
        private readonly int $timeout = 45,
    ) {}

    public function describe(): string
    {
        return 'dakbox api (' . ($this->username ?: 'no username') . ')';
    }

    public function fetchLatestCode(?int $since = null): ?array
    {
        if (! $this->token || ! $this->username) {
            Log::warning('SVP OTP: DakBox driver is missing its token or username');

            return null;
        }

        $url = rtrim($this->baseUrl, '/') . '/api/otp/get';

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($this->timeout)
                // DakBox is intermittently slow (observed: 30s with 0 bytes), so a
                // connection timeout is retried; a 429 is not, because the poll
                // loop already treats it as "fetch in progress".
                ->retry(2, 800, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
                ->get($url, [
                    'email' => $this->username,
                    'website' => 'svp',
                ]);
        } catch (\Throwable $exception) {
            Log::warning('SVP OTP: DakBox request failed', ['error' => $exception->getMessage()]);

            return null;
        }

        // 429 means DakBox is already fetching for this mailbox; the caller's
        // poll loop simply tries again, so keep it out of the warning channel.
        if ($response->status() === 429) {
            Log::debug('SVP OTP: DakBox fetch already in progress', [
                'retry_after' => $response->json('retry_after'),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('SVP OTP: DakBox returned a non-2xx response', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        // DakBox reports the message itself under `data`, together with its
        // age and expiry. Reject a code that expired or predates this attempt so
        // a stale OTP can never be replayed into SVP.
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        if (! empty($data['expired'])) {
            Log::debug('SVP OTP: DakBox reported the code as expired');

            return null;
        }

        $receivedAt = isset($data['date_utc'])
            ? (int) strtotime((string) $data['date_utc'] . ' UTC')
            : time();

        if ($since !== null && $receivedAt < $since) {
            Log::debug('SVP OTP: DakBox code is older than this login attempt', [
                'received_at' => $receivedAt,
                'since' => $since,
            ]);

            return null;
        }

        $code = $this->extractCode($payload);

        if ($code === null) {
            return null;
        }

        return [
            'code' => $code,
            'subject' => (string) ($data['subject'] ?? $payload['subject'] ?? 'DakBox OTP'),
            'received_at' => $receivedAt,
            'source' => 'dakbox',
        ];
    }

    /**
     * DakBox has returned both `{"otp": "123456"}` and nested message payloads
     * over time, so accept either shape and fall back to a regex over the body.
     */
    private function extractCode(array $payload): ?string
    {
        foreach (['otp', 'code', 'otp_code', 'verification_code'] as $key) {
            if (isset($payload[$key]) && preg_match('/\d{4,8}/', (string) $payload[$key], $m) === 1) {
                return $m[0];
            }
        }

        foreach (['data', 'message', 'mail'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $nested = $this->extractCode($payload[$key]);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        $body = (string) ($payload['body'] ?? $payload['text'] ?? $payload['message'] ?? '');

        return preg_match('/(?:otp|code|verification)[^0-9]{0,20}(\d{4,8})/i', $body, $m) === 1 ? $m[1] : null;
    }
}
