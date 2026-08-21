<?php

namespace App\Services;

use App\Models\SvpAvailabilityAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class SvpAvailabilityTokenResolver
{
    private const CACHE_KEY = 'svp:availability:active-account';
    private const LOCK_KEY = 'svp:availability:account-lock';

    public function resolve(?int $accountId = null): string
    {
        $account = $accountId !== null
            ? SvpAvailabilityAccount::query()->find($accountId)
            : $this->cachedAccount();

        if (! $account instanceof SvpAvailabilityAccount || ! $account->hasUsableToken()) {
            $account = $this->selectUsableAccount($accountId);
        }

        if (! $account instanceof SvpAvailabilityAccount || ! $account->hasUsableToken()) {
            throw new RuntimeException('No usable backend SVP availability token is configured.');
        }

        $account->forceFill([
            'last_used_at' => now(),
            'last_error' => null,
        ])->saveQuietly();

        Cache::put(self::CACHE_KEY, $account->getKey(), now()->addMinutes(5));

        return (string) $account->access_token;
    }

    /**
     * Return all active, non-expired backend tokens in least-recently-used order.
     * Portal user/session tokens are intentionally never considered here.
     *
     * @return array<int, string>
     */
    public function resolvePool(): array
    {
        $tokens = SvpAvailabilityAccount::query()
            ->where('active', true)
            ->whereNotNull('access_token')
            ->where(function ($query): void {
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '>', now()->addSeconds(60));
            })
            ->orderBy('last_used_at')
            ->get()
            ->map(static fn (SvpAvailabilityAccount $account): string => (string) $account->access_token)
            ->filter(static fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();

        if ($tokens !== []) {
            SvpAvailabilityAccount::query()
                ->where('active', true)
                ->whereNotNull('access_token')
                ->whereIn('access_token', $tokens)
                ->update(['last_used_at' => now()]);
        }

        return $tokens;
    }

    public function rememberToken(
        SvpAvailabilityAccount $account,
        string $token,
        ?string $refreshToken = null,
        $expiresAt = null,
    ): void {
        $account->forceFill([
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $expiresAt,
            'last_refreshed_at' => now(),
            'last_error' => null,
            'active' => true,
        ])->save();

        Cache::put(self::CACHE_KEY, $account->getKey(), now()->addMinutes(5));
    }

    public function invalidate(?int $accountId = null, ?string $error = null): void
    {
        $account = $accountId !== null
            ? SvpAvailabilityAccount::query()->find($accountId)
            : $this->cachedAccount();

        if ($account instanceof SvpAvailabilityAccount) {
            $account->forceFill(['last_error' => $error])->saveQuietly();
        }

        Cache::forget(self::CACHE_KEY);
    }

    public function accounts(): array
    {
        return SvpAvailabilityAccount::query()
            ->where('active', true)
            ->orderByDesc('last_refreshed_at')
            ->get()
            ->map(fn (SvpAvailabilityAccount $account): array => [
                'id' => $account->getKey(),
                'name' => $account->name,
                'email' => $account->email,
                'active' => $account->active,
                'has_token' => filled($account->access_token),
                'expires_at' => optional($account->token_expires_at)?->toIso8601String(),
                'last_used_at' => optional($account->last_used_at)?->toIso8601String(),
                'last_error' => $account->last_error,
            ])->all();
    }

    private function cachedAccount(): ?SvpAvailabilityAccount
    {
        $id = Cache::get(self::CACHE_KEY);

        return $id ? SvpAvailabilityAccount::query()->find($id) : null;
    }

    private function selectUsableAccount(?int $accountId): ?SvpAvailabilityAccount
    {
        return Cache::lock(self::LOCK_KEY, 10)->get(function () use ($accountId) {
            if ($accountId !== null) {
                return SvpAvailabilityAccount::query()->find($accountId);
            }

            return SvpAvailabilityAccount::query()
                ->where('active', true)
                ->whereNotNull('access_token')
                ->where(function ($query): void {
                    $query->whereNull('token_expires_at')
                        ->orWhere('token_expires_at', '>', now()->addSeconds(60));
                })
                ->orderBy('last_used_at')
                ->first();
        });
    }
}
