<?php

namespace App\Services\SvpOtp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the SVP bearer token alive without asking the candidate to log in again.
 *
 * The booking wizard reads live T2Hub data (cities, centres, sessions) without
 * an SVP login, but the hold/verification step talks to SVP itself. When that
 * token is missing or its JWT has expired the pre-hold check used to die with a
 * bare validation error, which the UI reported as "unknown center". This
 * service provisions the token automatically (candidate credentials from
 * config, else the credentials of the last manual SVP login) and verifies the
 * e-mail OTP through the configured mailbox driver.
 */
final class SvpAutoSession
{
    /** @var array<int, string> */
    private const SESSION_KEYS = ['svp_token', 'svp_csrf', 'svp_user_id'];

    public function __construct(private readonly OtpAutoVerifier $otp)
    {
    }

    /**
     * @return array{email: string, password: string}|null
     */
    public function credentials(?Request $request = null): ?array
    {
        $email = trim((string) config('svp.auto_login.email'));
        $password = (string) config('svp.auto_login.password');

        if ($email === '' || $password === '') {
            $stored = $request?->session()->get('svp_login');
            if (is_array($stored)) {
                $email = trim((string) ($stored['email'] ?? ''));
                $password = (string) ($stored['password'] ?? '');
            }
        }

        if ($email === '' || $password === '') {
            return null;
        }

        return ['email' => $email, 'password' => $password];
    }

    public function enabled(?Request $request = null): bool
    {
        return (bool) config('svp.auto_login.enabled', true)
            && (string) config('svp.auto_login.otp_method', 'email') === 'email'
            && $this->otp->enabled()
            && $this->credentials($request) !== null;
    }

    /**
     * Return a usable SVP token, logging in (with automatic OTP verification)
     * when the current one is missing or expired.
     */
    public function ensure(Request $request, bool $force = false): ?string
    {
        $token = $request->session()->get('svp_token');
        $token = is_string($token) ? trim($token) : '';

        if (! $force && $token !== '' && ! $this->expired($token)) {
            return $token;
        }

        if (! $this->enabled($request)) {
            return null;
        }

        return $this->login($request);
    }

    /**
     * Perform a fresh candidate login and store the resulting token in the
     * session. Protected by a short cooldown so a page that retries in a loop
     * cannot flood the mailbox with OTP e-mails.
     */
    public function login(Request $request, bool $ignoreCooldown = false): ?string
    {
        $credentials = $this->credentials($request);
        if ($credentials === null) {
            return null;
        }

        $lockKey = 'svp:auto-login:cooldown';
        if (! $ignoreCooldown && ! Cache::add($lockKey, now()->timestamp, (int) config('svp.auto_login.cooldown', 120))) {
            Log::info('SVP auto login skipped: cooldown active');

            return null;
        }

        $method = (string) config('svp.auto_login.otp_method', 'email');
        $result = $this->otp->login($credentials['email'], $credentials['password'], $method);
        $body = (array) ($result['body'] ?? []);
        $token = trim((string) data_get($body, 'access_token', ''));

        if ($token === '') {
            Log::warning('SVP auto login did not return an access token', [
                'status' => (int) ($result['status'] ?? 0),
                'auto_verified' => (bool) ($result['auto_verified'] ?? false),
                'otp_source' => $result['otp_source'] ?? null,
                'required_2fa' => (bool) (data_get($body, 'required_2fa') ?? data_get($body, 'data.required_2fa') ?? false),
                'message' => (string) data_get($body, 'message', ''),
            ]);

            return null;
        }

        $candidate = (array) data_get($body, 'data', []);
        $request->session()->put('svp_token', $token);
        $request->session()->put('svp_csrf', data_get($body, 'access_payload.csrf'));
        $request->session()->put('svp_user_id', (string) (data_get($candidate, 'id') ?? data_get($body, 'user.id') ?? ''));

        Log::info('SVP auto login succeeded', [
            'auto_verified' => (bool) ($result['auto_verified'] ?? false),
            'otp_source' => $result['otp_source'] ?? null,
        ]);

        return $token;
    }

    /**
     * Drop a token that SVP has already expired so the next call logs in again.
     */
    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEYS);
    }

    private function expired(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return false;
        }

        $part = strtr($parts[1], '-_', '+/');
        $padding = strlen($part) % 4;
        if ($padding > 0) {
            $part .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($part, true);
        if (! is_string($decoded)) {
            return false;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ! is_numeric($payload['exp'] ?? null)) {
            return false;
        }

        return (int) $payload['exp'] <= now()->timestamp;
    }
}