<?php

namespace App\Services;

use App\Models\PortalAvailabilityCredential;
use App\Contracts\PortalAvailabilityProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

final class PortalAvailabilityService
{
    public function __construct(
        private readonly PortalAvailabilityProviderInterface $provider,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function credentials(): array
    {
        return PortalAvailabilityCredential::query()
            ->orderByDesc('active')
            ->orderBy('name')
            ->get()
            ->map(fn (PortalAvailabilityCredential $credential): array => [
                'id' => $credential->id,
                'name' => $credential->name,
                'portal_account_id' => $credential->portal_account_id,
                'active' => $credential->active,
                'expires_at' => $credential->expires_at?->toIso8601String(),
                'last_used_at' => $credential->last_used_at?->toIso8601String(),
                'last_checked_at' => $credential->last_checked_at?->toIso8601String(),
                'last_error' => $credential->last_error,
                'has_session' => filled($credential->session_cookie),
                'usable' => $credential->hasUsableSession(),
            ])
            ->all();
    }

    public function credential(?int $credentialId = null): PortalAvailabilityCredential
    {
        $query = PortalAvailabilityCredential::query()->usable()->orderBy('last_used_at');
        $credential = $credentialId !== null
            ? $query->whereKey($credentialId)->first()
            : $query->first();

        if (! $credential instanceof PortalAvailabilityCredential || ! $credential->hasUsableSession()) {
            throw new RuntimeException('No usable portal availability session is configured.');
        }

        return $credential;
    }

    /** @return array{credential: array<string, mixed>, data: array<int, array<string, mixed>>, fetched_at: string} */
    public function occupations(?int $credentialId = null): array
    {
        $credential = $this->credential($credentialId);
        $cacheKey = 'portal:availability:occupations:v1:'.$credential->id;

        return $this->remember($cacheKey, function () use ($credential): array {
            $data = $this->provider->occupations((string) $credential->session_cookie);
            $credential->forceFill([
                'last_used_at' => now(),
                'last_checked_at' => now(),
                'last_error' => null,
            ])->saveQuietly();

            return [
                'credential' => $this->credentialSummary($credential),
                'data' => $this->normalizeOccupations($data),
                'fetched_at' => now()->toIso8601String(),
            ];
        }, $credential);
    }

    /** @return array{credential: array<string, mixed>, data: array<string, mixed>, fetched_at: string} */
    public function searchDates(
        int $credentialId,
        int|string $categoryId,
        string $startFrom,
    ): array {
        $credential = $this->credential($credentialId);
        $categoryId = trim((string) $categoryId);
        $startFrom = trim($startFrom);
        if ($categoryId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startFrom)) {
            throw new RuntimeException('A valid category and start date are required.');
        }

        $cacheKey = 'portal:availability:dates:v1:'.sha1(json_encode([
            'credential_id' => $credential->id,
            'category_id' => $categoryId,
            'start_from' => $startFrom,
        ]));

        return $this->remember($cacheKey, function () use ($credential, $categoryId, $startFrom): array {
            $data = $this->provider->searchDates(
                (string) $credential->session_cookie,
                (string) $credential->portal_account_id,
                $categoryId,
                $startFrom,
            );
            $credential->forceFill([
                'last_used_at' => now(),
                'last_checked_at' => now(),
                'last_error' => null,
            ])->saveQuietly();

            return [
                'credential' => $this->credentialSummary($credential),
                'data' => $this->normalizeDates($data),
                'fetched_at' => now()->toIso8601String(),
            ];
        }, $credential);
    }

    /** @return array{credential: array<string, mixed>, data: array<string, mixed>, fetched_at: string} */
    public function centers(
        int $credentialId,
        int|string $categoryId,
        string $city,
        string $date,
        int|string $occupationId,
        string $languageCode,
    ): array {
        $credential = $this->credential($credentialId);
        $categoryId = trim((string) $categoryId);
        $city = trim($city);
        $date = trim($date);
        $occupationId = trim((string) $occupationId);
        $languageCode = trim($languageCode);
        if ($categoryId === '' || $city === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $occupationId === '' || $languageCode === '') {
            throw new RuntimeException('Category, city, date, occupation, and language are required.');
        }

        $cacheKey = 'portal:availability:centers:v1:'.sha1(json_encode([
            'credential_id' => $credential->id,
            'category_id' => $categoryId,
            'city' => Str::lower($city),
            'date' => $date,
            'occupation_id' => $occupationId,
            'language_code' => $languageCode,
        ]));

        return $this->remember($cacheKey, function () use ($credential, $categoryId, $city, $date, $occupationId, $languageCode): array {
            $data = $this->provider->centers(
                (string) $credential->session_cookie,
                (string) $credential->portal_account_id,
                $categoryId,
                $city,
                $date,
                $occupationId,
                $languageCode,
            );
            $credential->forceFill([
                'last_used_at' => now(),
                'last_checked_at' => now(),
                'last_error' => null,
            ])->saveQuietly();

            return [
                'credential' => $this->credentialSummary($credential),
                'data' => $this->normalizeCenters($data),
                'fetched_at' => now()->toIso8601String(),
            ];
        }, $credential);
    }

    private function remember(string $key, callable $callback, PortalAvailabilityCredential $credential): array
    {
        try {
            return Cache::remember(
                $key,
                now()->addSeconds(max(1, (int) config('portal.cache_ttl', 30))),
                $callback,
            );
        } catch (\Throwable $exception) {
            $credential->forceFill([
                'last_checked_at' => now(),
                'last_error' => Str::limit($exception->getMessage(), 500),
            ])->saveQuietly();
            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeOccupations(array $payload): array
    {
        return collect($payload)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'name' => trim((string) ($item['name'] ?? '')),
                'occupation_id' => $item['occupation_id'] ?? null,
                'category_id' => $item['category_id'] ?? null,
                'languages' => collect($item['languages'] ?? [])
                    ->filter(fn ($language): bool => is_array($language))
                    ->map(fn (array $language): array => [
                        'code' => trim((string) ($language['code'] ?? '')),
                        'name' => trim((string) ($language['name'] ?? '')),
                    ])
                    ->filter(fn (array $language): bool => $language['code'] !== '')
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $item): bool => $item['name'] !== '' && $item['occupation_id'] !== null && $item['category_id'] !== null)
            ->values()
            ->all();
    }

    /** @return array{dates: array<int, array<string, mixed>>, district_counts: array<string, int>} */
    private function normalizeDates(array $payload): array
    {
        $dates = collect($payload['dates'] ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'city' => trim((string) ($item['city'] ?? '')),
                'date' => trim((string) ($item['date'] ?? '')),
            ])
            ->filter(fn (array $item): bool => $item['city'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['date']) === 1)
            ->values();

        return [
            'dates' => $dates->all(),
            'district_counts' => $dates->groupBy('city')->map->count()->sortKeys()->all(),
        ];
    }

    /** @return array{centers: array<int, array<string, mixed>>, center_count: int} */
    private function normalizeCenters(array $payload): array
    {
        $centers = collect($payload['centers'] ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'test_center_name' => trim((string) ($item['test_center_name'] ?? '')),
                'test_center_id' => $item['test_center_id'] ?? null,
                'test_time' => $item['test_time'] ?? null,
                'available_seats' => is_numeric($item['available_seats'] ?? null) ? (int) $item['available_seats'] : 0,
            ])
            ->filter(fn (array $item): bool => $item['test_center_name'] !== '' && $item['test_center_id'] !== null)
            ->values();

        return [
            'centers' => $centers->all(),
            'center_count' => $centers->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function credentialSummary(PortalAvailabilityCredential $credential): array
    {
        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'portal_account_id' => $credential->portal_account_id,
        ];
    }
}
