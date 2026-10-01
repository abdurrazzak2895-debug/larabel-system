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
        private readonly int $timeout = 30,
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
                ->timeout($this->timeout)
                ->get($url, [
                    'email' => $this->username,
                    'website' => 'svp',
                ]);
        } catch (\Throwable $exception) {
            Log::warning('SVP OTP: DakBox request failed', ['error' => $exception->getMessage()]);

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

        $code = $this->extractCode($payload);

        if ($code === null) {
            return null;
        }

        return [
            'code' => $code,
            'subject' => (string) ($payload['subject'] ?? 'DakBox OTP'),
            'received_at' => isset($payload['received_at']) ? (int) strtotime((string) $payload['received_at']) : time(),
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
