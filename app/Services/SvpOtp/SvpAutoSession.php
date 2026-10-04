<?php

namespace App\Services\SvpOtp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the SVP bearer token alive for the currently authenticated portal user.
 *
 * SVP authentication is deliberately isolated by agency + portal user. No
 * bearer token is stored in or read from a global/shared cache, so one user or
 * agency can never reuse another user's SVP session.
 */
final class SvpAutoSession
{
    private const SESSION_PREFIX = 'svp:session:';
    private const LEGACY_SESSION_KEYS = ['svp_token', 'svp_csrf', 'svp_login', 'svp_user_id'];
    private const BLOCK_SUFFIX = ':auto-login:blocked';
    private const COOLDOWN_SUFFIX = ':auto-login:cooldown';

    public function __construct(private readonly OtpAutoVerifier $otp)
    {
    }

    /** Return the scoped session key used for the current portal identity. */
    public static function sessionKey(string $field, ?Request $request = null): string
    {
        $user = $request?->user('web') ?? Auth::guard('web')->user();
        $userId = $user?->getAuthIdentifier();
        $agencyId = $user?->agency_id ?? 'none';

        if ($userId === null) {
            // SVP login is only allowed for an authenticated portal user. This
            // fallback prevents accidental collisions before authentication.
            $userId = 'guest';
        }

        return self::SESSION_PREFIX . $agencyId . ':' . $userId . ':' . $field;
    }

    public function token(Request $request): ?string
    {
        $token = $request->session()->get(self::sessionKey('token', $request));
        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    public function csrf(Request $request): mixed
    {
        return $request->session()->get(self::sessionKey('csrf', $request));
    }

    public function svpUserId(Request $request): string
    {
        return trim((string) $request->session()->get(self::sessionKey('user_id', $request), ''));
    }

    /** @return array{email: string, password: string}|null */
    public function credentials(?Request $request = null): ?array
    {
        $email = trim((string) config('svp.auto_login.email'));
        $password = (string) config('svp.auto_login.password');
        if ($email === '' || $password === '') {
            $stored = $request?->session()->get(self::sessionKey('login', $request));
            if (is_array($stored)) {
                $email = trim((string) ($stored['email'] ?? ''));
                $password = (string) ($stored['password'] ?? '');
            }
        }
        return ($email !== '' && $password !== '') ? ['email' => $email, 'password' => $password] : null;
    }

    public function enabled(?Request $request = null): bool
    {
        return (bool) config('svp.auto_login.enabled', true)
            && (string) config('svp.auto_login.otp_method', 'email') === 'email'
            && $this->otp->enabled()
            && $this->credentials($request) !== null;
    }

    public function ensure(Request $request, bool $force = false): ?string
    {
        $token = $this->token($request);

        if (! $force && $token !== null && ! $this->expired($token)) {
            return $token;
        }

        if ($this->cacheHas($request, self::BLOCK_SUFFIX)) {
            return null;
        }

        if (! $this->enabled($request)) {
            return null;
        }

        $fresh = $this->login($request);
        if ($fresh === null) {
            $this->cachePut($request, self::BLOCK_SUFFIX, now()->timestamp, (int) config('svp.auto_login.failure_backoff', 900));
            Log::warning('SVP auto login failed; backing off for current user session', [
                'user_id' => $request->user('web')?->getAuthIdentifier(),
                'agency_id' => $request->user('web')?->agency_id,
                'backoff_seconds' => (int) config('svp.auto_login.failure_backoff', 900),
            ]);
            return null;
        }

        return $fresh;
    }

    /** Store a manually verified token in the current user's scoped session. */
    public function store(Request $request, string $token, mixed $csrf = null, string $userId = ''): void
    {
        $request->session()->put(self::sessionKey('token', $request), $token);
        if ($csrf !== null) {
            $request->session()->put(self::sessionKey('csrf', $request), $csrf);
        }
        if ($userId !== '') {
            $request->session()->put(self::sessionKey('user_id', $request), $userId);
        }
        $this->cacheForget($request, self::BLOCK_SUFFIX);
    }

    public function login(Request $request, bool $ignoreCooldown = false): ?string
    {
        $credentials = $this->credentials($request);
        if ($credentials === null) {
            return null;
        }

        if (! $ignoreCooldown && ! $this->cacheAdd($request, self::COOLDOWN_SUFFIX, now()->timestamp, (int) config('svp.auto_login.cooldown', 120))) {
            Log::info('SVP auto login skipped: cooldown active for current user session');
            return null;
        }

        $result = $this->otp->login($credentials['email'], $credentials['password'], (string) config('svp.auto_login.otp_method', 'email'));
        $body = (array) ($result['body'] ?? []);
        $token = (string) ($this->findToken($body) ?? '');
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
        $this->store($request, $token, data_get($body, 'access_payload.csrf'), (string) (data_get($candidate, 'id') ?? data_get($body, 'user.id') ?? ''));
        Log::info('SVP auto login succeeded for current user session', [
            'user_id' => $request->user('web')?->getAuthIdentifier(),
            'agency_id' => $request->user('web')?->agency_id,
            'auto_verified' => (bool) ($result['auto_verified'] ?? false),
            'otp_source' => $result['otp_source'] ?? null,
        ]);
        return $token;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([
            self::sessionKey('token', $request),
            self::sessionKey('csrf', $request),
            self::sessionKey('login', $request),
            self::sessionKey('user_id', $request),
            ...self::LEGACY_SESSION_KEYS,
        ]);
        $this->cacheForget($request, self::BLOCK_SUFFIX);
        $this->cacheForget($request, self::COOLDOWN_SUFFIX);
    }

    private function cacheKey(Request $request, string $suffix): string
    {
        return self::sessionKey('cache', $request) . $suffix;
    }

    private function cacheHas(Request $request, string $suffix): bool
    {
        return Cache::has($this->cacheKey($request, $suffix));
    }

    private function cachePut(Request $request, string $suffix, mixed $value, int $ttl): void
    {
        Cache::put($this->cacheKey($request, $suffix), $value, $ttl);
    }

    private function cacheAdd(Request $request, string $suffix, mixed $value, int $ttl): bool
    {
        return Cache::add($this->cacheKey($request, $suffix), $value, $ttl);
    }

    private function cacheForget(Request $request, string $suffix): void
    {
        Cache::forget($this->cacheKey($request, $suffix));
    }

    private function findToken(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['token', 'access_token', 'access'], true) && is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value) && ($nested = $this->findToken($value)) !== null) {
                return $nested;
            }
        }
        return null;
    }

    private function expired(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) return false;
        $part = strtr($parts[1], '-_', '+/');
        $padding = strlen($part) % 4;
        if ($padding > 0) $part .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($part, true);
        if (! is_string($decoded)) return false;
        $payload = json_decode($decoded, true);
        return is_array($payload) && is_numeric($payload['exp'] ?? null) && (int) $payload['exp'] <= now()->timestamp;
    }
}
