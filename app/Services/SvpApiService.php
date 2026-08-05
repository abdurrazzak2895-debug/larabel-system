<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin client for the real SVP / Takamol API.
 *
 * Auth flow:
 *   1. POST /api/v1/sessions/login   (email + password -> sends OTP)
 *   2. POST /api/v1/sessions/otp     (email + password + otp_attempt -> Bearer token)
 */
class SvpApiService
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $tenantName = null,
        protected ?int $timeout = null,
    ) {
        $this->baseUrl   = $baseUrl   ?? (string) config('svp.base_url');
        $this->tenantName = $tenantName ?? (string) config('svp.tenant_name');
        $this->timeout   = $timeout   ?? (int) config('svp.timeout', 30);
    }

    /**
     * Step 1 — send login + OTP method to SVP.
     *
     * @return array{status: int, body: array}
     */
    public function login(string $email, string $password, string $otpMethod = 'email'): array
    {
        $payload = [
            'user' => [
                'login'    => $email,
                'password' => $password,
                'otp_method' => $otpMethod,
                'fe_app'   => 'legislator',
                'recaptcha_response' => '',
            ],
        ];

        return $this->post('/api/v1/sessions/login', $payload);
    }

    /**
     * Step 2 — verify OTP and obtain the Bearer token.
     *
     * @return array{status: int, body: array}
     */
    public function verifyOtp(string $email, string $password, string $otpCode, string $otpMethod = 'email'): array
    {
        $payload = [
            'user' => [
                'login'      => $email,
                'password'   => $password,
                'otp_attempt'=> $otpCode,
                'otp_method' => $otpMethod,
                'fe_app'     => 'legislator',
            ],
        ];

        return $this->post('/api/v1/sessions/otp', $payload);
    }

    /**
     * Perform the POST request with the tenant header.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array}
     */
    protected function post(string $path, array $payload): array
    {
        try {
            $request = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Tenant-Name' => $this->tenantName,
                ])
                ->retry(
                    (int) config('svp.retry_times', 3),
                    (int) config('svp.retry_delay', 1000),
                    fn ($exception) => $exception instanceof ConnectionException,
                );

            if (config('svp.log_requests', false)) {
                Log::channel(config('svp.log_channel', 'daily'))->info('SVP API request', [
                    'method' => 'POST',
                    'url'    => $this->baseUrl . $path,
                    'body'   => $payload,
                ]);
            }

            $response = $request->post($path, $payload);

            $body = $response->json() ?? [];

            if (config('svp.log_requests', false)) {
                Log::channel(config('svp.log_channel', 'daily'))->info('SVP API response', [
                    'status' => $response->status(),
                    'body'   => $body,
                ]);
            }

            return [
                'status' => $response->status(),
                'body'   => $body,
            ];
        } catch (\Throwable $e) {
            Log::channel(config('svp.log_channel', 'daily'))->error('SVP API exception', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('SVP API request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
