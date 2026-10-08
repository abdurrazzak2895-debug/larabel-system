<?php

namespace App\Services\SvpOtp;

use App\Models\Candidate;
use App\Models\CandidateSvpSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps independent encrypted SVP bearer sessions for every connected
 * candidate profile belonging to the currently authenticated portal user.
 */
final class SvpAutoSession
{
    private const SESSION_PREFIX = 'svp:session:';
    private const LEGACY_SESSION_KEYS = ['svp_token', 'svp_csrf', 'svp_login', 'svp_user_id'];
    private const ACTIVE_CANDIDATE_FIELD = 'active_candidate_id';
    private const BLOCK_SUFFIX = ':auto-login:blocked';
    private const COOLDOWN_SUFFIX = ':auto-login:cooldown';

    public function __construct(private readonly OtpAutoVerifier $otp)
    {
    }

    public static function sessionKey(string $field, ?Request $request = null): string
    {
        $user = $request?->user('web') ?? Auth::guard('web')->user();
        $userId = $user?->getAuthIdentifier();
        $agencyId = $user?->agency_id ?? 'none';

        if ($userId === null) {
            $userId = 'guest';
        }

        return self::SESSION_PREFIX.$agencyId.':'.$userId.':'.$field;
    }

    public function activeCandidateId(Request $request): ?int
    {
        $value = $request->session()->get(self::sessionKey(self::ACTIVE_CANDIDATE_FIELD, $request));

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function setActiveCandidate(Request $request, int $candidateId): void
    {
        $candidate = $this->candidateForUser($request, $candidateId);
        if (! $candidate) {
            return;
        }

        $request->session()->put(self::sessionKey(self::ACTIVE_CANDIDATE_FIELD, $request), $candidate->id);
        $this->syncLegacySession($request, CandidateSvpSession::where('candidate_id', $candidate->id)->where('user_id', $candidate->user_id)->first());
    }

    public function token(Request $request, ?int $candidateId = null): ?string
    {
        $candidateId = $this->resolveCandidateId($request, $candidateId);
        if ($candidateId !== null) {
            $session = $this->sessionForCandidate($request, $candidateId);
            if ($session?->usable()) {
                $session->forceFill(['last_used_at' => now()])->saveQuietly();
                $this->setActiveCandidate($request, $candidateId);
                return trim((string) $session->access_token);
            }

            return null;
        }

        $token = $request->session()->get(self::sessionKey('token', $request));

        return is_string($token) && trim($token) !== '' && ! $this->expired($token) ? trim($token) : null;
    }

    public function csrf(Request $request, ?int $candidateId = null): mixed
    {
        $candidateId = $this->resolveCandidateId($request, $candidateId);
        if ($candidateId !== null) {
            return $this->sessionForCandidate($request, $candidateId)?->csrf_token;
        }

        return $request->session()->get(self::sessionKey('csrf', $request));
    }

    public function svpUserId(Request $request, ?int $candidateId = null): string
    {
        $candidateId = $this->resolveCandidateId($request, $candidateId);
        if ($candidateId !== null) {
            return trim((string) ($this->sessionForCandidate($request, $candidateId)?->svp_user_id ?? ''));
        }

        return trim((string) $request->session()->get(self::sessionKey('user_id', $request), ''));
    }

    public function hasSessionForCandidate(Request $request, int $candidateId): bool
    {
        return ($session = $this->sessionForCandidate($request, $candidateId)) !== null && $session->usable();
    }

    /** @return array<int, int> */
    public function connectedCandidateIds(Request $request): array
    {
        $userId = $request->user('web')?->getAuthIdentifier();
        if ($userId === null) {
            return [];
        }

        return CandidateSvpSession::query()
            ->where('user_id', $userId)
            ->get()
            ->filter(fn (CandidateSvpSession $session): bool => $session->usable())
            ->pluck('candidate_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

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

    public function ensure(Request $request, bool $force = false, ?int $candidateId = null): ?string
    {
        $resolvedCandidateId = $this->resolveCandidateId($request, $candidateId);
        $token = $this->token($request, $resolvedCandidateId);
        if (! $force && $token !== null) {
            return $token;
        }

        // Never silently authenticate the configured account in place of a
        // selected profile. That would send bookings through the wrong SVP user.
        if ($resolvedCandidateId !== null) {
            return null;
        }

        if ($this->cacheHas($request, self::BLOCK_SUFFIX) || ! $this->enabled($request)) {
            return null;
        }

        $fresh = $this->login($request);
        if ($fresh === null) {
            $this->cachePut($request, self::BLOCK_SUFFIX, now()->timestamp, (int) config('svp.auto_login.failure_backoff', 900));
            Log::warning('SVP auto login failed; backing off for current user session', [
                'user_id' => $request->user('web')?->getAuthIdentifier(),
                'agency_id' => $request->user('web')?->agency_id,
            ]);
            return null;
        }

        return $fresh;
    }

    /** Store a verified token in the legacy request context and, when possible, its candidate session. */
    public function store(Request $request, string $token, mixed $csrf = null, string $userId = ''): void
    {
        $candidateId = $userId !== ''
            ? Candidate::query()->where('user_id', $request->user('web')?->getAuthIdentifier())->where('svp_user_id', $userId)->value('id')
            : null;

        if ($candidateId !== null) {
            $this->storeForCandidate($request, (int) $candidateId, $token, $csrf, $userId);
            return;
        }

        $this->putLegacySession($request, $token, $csrf, $userId);
        $this->cacheForget($request, self::BLOCK_SUFFIX);
    }

    public function storeForCandidate(Request $request, int $candidateId, string $token, mixed $csrf = null, string $userId = ''): void
    {
        $candidate = $this->candidateForUser($request, $candidateId);
        if (! $candidate) {
            return;
        }

        $session = CandidateSvpSession::updateOrCreate(
            ['candidate_id' => $candidate->id],
            [
                'user_id' => $candidate->user_id,
                'agency_id' => $candidate->agency_id,
                'svp_user_id' => $userId !== '' ? $userId : $candidate->svp_user_id,
                'access_token' => $token,
                'csrf_token' => $csrf,
                'expires_at' => $this->expiry($token),
                'last_used_at' => now(),
            ],
        );

        $candidate->update(['is_active' => true]);
        $request->session()->put(self::sessionKey(self::ACTIVE_CANDIDATE_FIELD, $request), $candidate->id);
        $this->syncLegacySession($request, $session);
        $this->cacheForget($request, self::BLOCK_SUFFIX);
    }

    /** Clear the selected profile from this browser without deleting other saved sessions. */
    public function forget(Request $request): void
    {
        $this->clearActiveContext($request);
        $this->cacheForget($request, self::BLOCK_SUFFIX);
        $this->cacheForget($request, self::COOLDOWN_SUFFIX);
    }

    /** Invalidate the selected profile after an upstream 401. */
    public function forgetCurrent(Request $request): void
    {
        $candidateId = $this->activeCandidateId($request);
        if ($candidateId !== null) {
            CandidateSvpSession::where('candidate_id', $candidateId)->where('user_id', $request->user('web')?->getAuthIdentifier())->delete();
        }
        $this->clearActiveContext($request);
    }

    public function forgetCandidate(Request $request, int $candidateId): void
    {
        CandidateSvpSession::where('candidate_id', $candidateId)->where('user_id', $request->user('web')?->getAuthIdentifier())->delete();
        if ($this->activeCandidateId($request) === $candidateId) {
            $this->clearActiveContext($request);
        }
    }

    public function login(Request $request, bool $ignoreCooldown = false): ?string
    {
        $credentials = $this->credentials($request);
        if ($credentials === null) {
            return null;
        }

        if (! $ignoreCooldown && ! $this->cacheAdd($request, self::COOLDOWN_SUFFIX, now()->timestamp, (int) config('svp.auto_login.cooldown', 120))) {
            return null;
        }

        $result = $this->otp->login($credentials['email'], $credentials['password'], (string) config('svp.auto_login.otp_method', 'email'));
        $body = (array) ($result['body'] ?? []);
        $token = (string) ($this->findToken($body) ?? '');
        if ($token === '') {
            return null;
        }

        $svpUserId = (string) (data_get($body, 'data.id') ?? data_get($body, 'user.id') ?? '');
        $this->store($request, $token, data_get($body, 'access_payload.csrf'), $svpUserId);
        return $token;
    }

    private function resolveCandidateId(Request $request, ?int $candidateId = null): ?int
    {
        $candidateId ??= is_numeric($request->input('candidate_id')) ? (int) $request->input('candidate_id') : null;
        if ($candidateId !== null && $this->candidateForUser($request, $candidateId)) {
            if ($this->activeCandidateId($request) !== $candidateId) {
                $this->setActiveCandidate($request, $candidateId);
            }
            return $candidateId;
        }

        return $this->activeCandidateId($request);
    }

    private function candidateForUser(Request $request, int $candidateId): ?Candidate
    {
        $userId = $request->user('web')?->getAuthIdentifier();
        if ($userId === null) {
            return null;
        }

        return Candidate::query()->where('user_id', $userId)->find($candidateId);
    }

    private function sessionForCandidate(Request $request, int $candidateId): ?CandidateSvpSession
    {
        $userId = $request->user('web')?->getAuthIdentifier();
        if ($userId === null) {
            return null;
        }

        return CandidateSvpSession::query()->where('user_id', $userId)->where('candidate_id', $candidateId)->first();
    }

    private function putLegacySession(Request $request, string $token, mixed $csrf, string $userId): void
    {
        $request->session()->put(self::sessionKey('token', $request), $token);
        if ($csrf !== null) {
            $request->session()->put(self::sessionKey('csrf', $request), $csrf);
        }
        if ($userId !== '') {
            $request->session()->put(self::sessionKey('user_id', $request), $userId);
        }
    }

    private function syncLegacySession(Request $request, ?CandidateSvpSession $session): void
    {
        if (! $session?->usable()) {
            $request->session()->forget([
                self::sessionKey('token', $request),
                self::sessionKey('csrf', $request),
                self::sessionKey('user_id', $request),
            ]);
            return;
        }

        $this->putLegacySession($request, (string) $session->access_token, $session->csrf_token, (string) $session->svp_user_id);
    }

    private function clearActiveContext(Request $request): void
    {
        $request->session()->forget([
            self::sessionKey(self::ACTIVE_CANDIDATE_FIELD, $request),
            self::sessionKey('token', $request),
            self::sessionKey('csrf', $request),
            self::sessionKey('user_id', $request),
            self::sessionKey('login', $request),
            ...self::LEGACY_SESSION_KEYS,
        ]);
    }

    private function expiry(string $token): ?\Illuminate\Support\Carbon
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }
        $payload = json_decode($this->decodeJwtPart($parts[1]), true);
        return is_array($payload) && is_numeric($payload['exp'] ?? null)
            ? now()->setTimestamp((int) $payload['exp'])
            : null;
    }

    private function cacheKey(Request $request, string $suffix): string
    {
        return self::sessionKey('cache', $request).$suffix;
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

    private function decodeJwtPart(string $part): string
    {
        $part = strtr($part, '-_', '+/');
        $padding = strlen($part) % 4;
        if ($padding > 0) {
            $part .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($part, true);
        return is_string($decoded) ? $decoded : '';
    }

    private function expired(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) return false;
        $payload = json_decode($this->decodeJwtPart($parts[1]), true);
        return is_array($payload) && is_numeric($payload['exp'] ?? null) && (int) $payload['exp'] <= now()->timestamp;
    }
}
