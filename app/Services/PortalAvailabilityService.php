<?php

namespace App\Services;

use App\Models\PortalAvailabilityCredential;
use App\Contracts\PortalAvailabilityProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class PortalAvailabilityService
{
    public function __construct(
        private readonly PortalAvailabilityProviderInterface $provider,
    ) {
    }

    /** @return array<string, mixed> */
    public function refreshCredential(PortalAvailabilityCredential $credential): array
    {
        if (! $credential->active) {
            throw new RuntimeException('Portal availability credential is inactive.');
        }
        if (! filled($credential->session_cookie)) {
            throw new RuntimeException('Portal availability session is not configured.');
        }

        try {
            $result = $this->provider->refreshAccount(
                (string) $credential->session_cookie,
                trim((string) $credential->portal_account_id),
            );
            $updates = [
                'last_checked_at' => now(),
                'last_error' => null,
            ];
            if (filled($result['session_cookie'] ?? null)) {
                $updates['session_cookie'] = trim((string) $result['session_cookie']);
            }
            if (filled($result['expires_at'] ?? null)) {
                $updates['expires_at'] = $result['expires_at'];
            }

            $credential->forceFill($updates)->saveQuietly();

            return [
                'credential' => $this->credentialSummary($credential),
                'rotated' => (bool) ($result['rotated'] ?? false),
                'expires_at' => $credential->fresh()->expires_at?->toIso8601String(),
                'checked_at' => $credential->fresh()->last_checked_at?->toIso8601String(),
            ];
        } catch (\Throwable $exception) {
            $credential->forceFill([
                'last_checked_at' => now(),
                'last_error' => Str::limit($exception->getMessage(), 500),
            ])->saveQuietly();
            throw $exception;
        }
    }

    /** @return array{refreshed: int, failed: int, failures: array<int, array{id: int, message: string}>} */
    public function refreshCredentials(?int $credentialId = null): array
    {
        $query = PortalAvailabilityCredential::query()
            ->where('active', true)
            ->whereNotNull('session_cookie');
        if ($credentialId !== null) {
            $query->whereKey($credentialId);
        }

        $summary = ['refreshed' => 0, 'failed' => 0, 'failures' => []];
        foreach ($query->orderBy('id')->get() as $index => $credential) {
            if ($index > 0) {
                usleep(max(0, (int) config('portal.rate_limit_delay_ms', 250)) * 1000);
            }

            try {
                $this->refreshCredential($credential);
                $summary['refreshed']++;
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['failures'][] = [
                    'id' => (int) $credential->id,
                    'message' => Str::limit($exception->getMessage(), 200),
                ];
            }
        }

        return $summary;
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
        ?int $credentialId,
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
        ?int $credentialId,
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

    /**
     * Run a read-only availability lookup against every usable saved account.
     * A failed account is recorded and skipped so another account can still
     * supply live booking data.
     *
     * @return array<int, mixed>
     */
    private function forEachUsableCredential(callable $callback): array
    {
        $results = [];
        $credentials = PortalAvailabilityCredential::query()
            ->usable()
            ->orderBy('last_used_at')
            ->orderBy('id')
            ->get();

        foreach ($credentials as $credential) {
            try {
                $results[] = $callback($credential);
            } catch (\Throwable $exception) {
                $credential->forceFill([
                    'last_checked_at' => now(),
                    'last_error' => Str::limit($exception->getMessage(), 500),
                ])->saveQuietly();
                Log::warning('Portal availability account lookup failed', [
                    'credential_id' => $credential->id,
                    'portal_account_id' => $credential->portal_account_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    public function bookingOccupations(?string $search = null): array
    {
        $items = [];
        foreach ($this->forEachUsableCredential(fn (PortalAvailabilityCredential $credential): array => $this->occupations((int) $credential->id)['data'] ?? []) as $credentialItems) {
            foreach ($credentialItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $key = (string) ($item['occupation_id'] ?? $item['name'] ?? '');
                if ($key === '') {
                    continue;
                }

                if (! isset($items[$key])) {
                    $items[$key] = $item;
                    continue;
                }

                $items[$key]['languages'] = collect($items[$key]['languages'] ?? [])
                    ->concat($item['languages'] ?? [])
                    ->filter(fn ($language): bool => is_array($language))
                    ->unique(fn (array $language): string => strtoupper((string) ($language['code'] ?? $language['name'] ?? '')))
                    ->values()
                    ->all();
                $items[$key]['category_name'] = ($items[$key]['category_name'] ?? '') ?: ($item['category_name'] ?? '');
            }
        }

        $term = Str::lower(trim((string) $search));
        if ($term !== '') {
            $items = array_filter($items, static fn (array $item): bool => Str::contains(Str::lower((string) ($item['name'] ?? '')), $term));
        }

        return array_values($items);
    }

    /** @return array<int, array{code: string, name: string, codes: array<int, string>}> */
    public function bookingLanguages(int|string $occupationId): array
    {
        $occupationId = trim((string) $occupationId);
        if ($occupationId === '') {
            return [];
        }

        $occupation = collect($this->bookingOccupations())
            ->first(static fn (array $item): bool => (string) ($item['occupation_id'] ?? '') === $occupationId);

        if (! is_array($occupation)) {
            return [];
        }

        return collect($occupation['languages'] ?? [])
            ->filter(static fn ($language): bool => is_array($language))
            ->map(static fn (array $language): array => [
                'code' => strtoupper(trim((string) ($language['code'] ?? ''))),
                'name' => trim((string) ($language['name'] ?? $language['english_name'] ?? $language['title'] ?? $language['code'] ?? '')),
            ])
            ->filter(static fn (array $language): bool => $language['code'] !== '' && $language['name'] !== '')
            ->unique('code')
            ->groupBy(static fn (array $language): string => Str::lower(preg_replace('/\\s+/', ' ', trim($language['name'])) ?? $language['name']))
            ->map(static function ($languages): array {
                $first = $languages->first();
                return [
                    'code' => $first['code'],
                    'name' => $first['name'],
                    'codes' => $languages->pluck('code')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array{id: string, name: string}> */
    public function bookingCategories(int|string $occupationId): array
    {
        $occupationId = trim((string) $occupationId);
        if ($occupationId === '') {
            return [];
        }

        $occupation = collect($this->bookingOccupations())
            ->first(static fn (array $item): bool => (string) ($item['occupation_id'] ?? '') === $occupationId);
        $categoryId = is_array($occupation) ? trim((string) ($occupation['category_id'] ?? '')) : '';

        return $categoryId === '' ? [] : [[
            'id' => $categoryId,
            'name' => trim((string) ($occupation['category_name'] ?? '')) ?: 'Category '.$categoryId,
        ]];
    }

    /** @return array<int, array{city: string, date: string}> */
    private function bookingDateRows(int|string $categoryId): array
    {
        $rows = [];
        foreach ($this->forEachUsableCredential(fn (PortalAvailabilityCredential $credential): array => $this->searchDates(
            (int) $credential->id,
            $categoryId,
            now()->toDateString(),
        )['data']['dates'] ?? []) as $credentialRows) {
            foreach ($credentialRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $city = trim((string) ($row['city'] ?? ''));
                $date = trim((string) ($row['date'] ?? ''));
                if ($city === '' || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) !== 1) {
                    continue;
                }

                $key = Str::lower($city).'|'.$date;
                $rows[$key] = ['city' => $city, 'date' => $date];
            }
        }

        return array_values($rows);
    }

    /** @return array<int, array{name: string}> */
    public function bookingCities(int|string $categoryId): array
    {
        return collect($this->bookingDateRows($categoryId))
            ->pluck('city')
            ->filter(static fn ($city): bool => is_string($city) && trim($city) !== '')
            ->map(static fn (string $city): array => ['name' => trim($city)])
            ->unique('name')
            ->values()
            ->all();
    }

    /** @return array<int, array{city: string, date: string}> */
    public function bookingDates(int|string $categoryId, string $city): array
    {
        $city = trim($city);
        if ($city === '') {
            return [];
        }

        return collect($this->bookingDateRows($categoryId))
            ->filter(static fn (array $item): bool => strcasecmp(trim((string) ($item['city'] ?? '')), $city) === 0)
            ->map(static fn (array $item): array => ['city' => $city, 'date' => trim((string) $item['date'])])
            ->unique('date')
            ->sortBy('date')
            ->values()
            ->all();
    }

    private function centerSlotKey(array $row, string $fallbackDate = ''): string
    {
        $center = trim((string) ($row['test_center_id'] ?? $row['test_center_name'] ?? $row['center_id'] ?? $row['center_name'] ?? ''));
        $date = trim((string) ($row['date'] ?? $row['exam_date'] ?? $row['test_date'] ?? $fallbackDate));
        $time = trim((string) ($row['test_time'] ?? $row['start_time'] ?? $row['time'] ?? $row['start_at'] ?? ''));
        $session = trim((string) ($row['exam_session_id'] ?? $row['session_id'] ?? ''));
        $slot = $time !== '' ? $time : $session;

        return Str::lower((string) preg_replace('/\\s+/', ' ', $center.'|'.$date.'|'.$slot));
    }

    /** @return array<int, string> */
    private function normalizedLanguageCodes(string $primaryCode, array $aliasCodes = []): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            array_merge([$primaryCode], $aliasCodes),
        ))));
    }

    /** @return array<int, array<string, mixed>> */
    public function bookingCentersForDate(
        int|string $categoryId,
        string $city,
        string $date,
        int|string $occupationId,
        string $languageCode,
        array $languageCodes = [],
    ): array {
        $languageCodes = $this->normalizedLanguageCodes($languageCode, $languageCodes);
        $merged = [];
        foreach ($this->forEachUsableCredential(function (PortalAvailabilityCredential $credential) use ($categoryId, $city, $date, $occupationId, $languageCodes): array {
            $rows = [];
            foreach ($languageCodes as $code) {
                try {
                    $rows = array_merge($rows, $this->centers(
                        (int) $credential->id,
                        $categoryId,
                        trim($city),
                        trim($date),
                        $occupationId,
                        $code,
                    )['data']['centers'] ?? []);
                } catch (\Throwable $exception) {
                    Log::warning('Portal availability language alias lookup failed', [
                        'credential_id' => $credential->id,
                        'language_code' => $code,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            return $rows;
        }) as $rows) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $centerKey = trim((string) ($row['test_center_id'] ?? $row['test_center_name'] ?? ''));
                $key = $this->centerSlotKey($row, $date);
                if ($centerKey === '') {
                    continue;
                }

                $sessionId = trim((string) ($row['exam_session_id'] ?? $row['session_id'] ?? ''));
                $normalized = array_merge($row, [
                    'id' => $row['test_center_id'] ?? null,
                    'name' => $row['test_center_name'] ?? null,
                    'date' => trim($date),
                    'session_ids' => $sessionId !== '' ? [$sessionId] : [],
                    'session_count' => $sessionId !== '' ? 1 : 0,
                ]);
                if (! isset($merged[$key])) {
                    $merged[$key] = $normalized;
                    continue;
                }

                $merged[$key]['available_seats'] = max(
                    (int) ($merged[$key]['available_seats'] ?? 0),
                    (int) ($normalized['available_seats'] ?? 0),
                );
                $merged[$key]['session_ids'] = array_values(array_unique(array_merge(
                    (array) ($merged[$key]['session_ids'] ?? []),
                    (array) ($normalized['session_ids'] ?? []),
                )));
                $merged[$key]['session_count'] = count($merged[$key]['session_ids']);
            }
        }

        return array_values($merged);
    }

    /** @return array{test_centers: array<int, array<string, mixed>>} */
    public function bookingCenters(
        int|string $categoryId,
        string $city,
        int|string $occupationId,
        string $languageCode,
        array $languageCodes = [],
    ): array {
        $languageCodes = $this->normalizedLanguageCodes($languageCode, $languageCodes);
        $city = trim($city);
        $dates = $this->bookingDateRows($categoryId);
        $dates = collect($dates)
            ->filter(static fn (array $item): bool => strcasecmp(trim((string) ($item['city'] ?? '')), $city) === 0)
            ->pluck('date')
            ->filter(static fn ($date): bool => is_string($date) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) === 1)
            ->unique()
            ->take(max(1, (int) config('portal.booking_center_date_limit', 14)));

        $centers = [];
        foreach ($dates as $date) {
            $rows = $this->bookingCentersForDate($categoryId, $city, $date, $occupationId, $languageCode, $languageCodes);
            foreach ($rows as $row) {
                $key = (string) ($row['test_center_id'] ?? $row['test_center_name'] ?? '');
                if ($key !== '') {
                    $centers[$key] ??= array_merge($row, [
                        'id' => $row['test_center_id'] ?? null,
                        'name' => $row['test_center_name'] ?? null,
                    ]);
                }
            }
        }

        return ['test_centers' => array_values($centers)];
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
                'category_name' => trim((string) ($item['category_name'] ?? $item['category_name_en'] ?? data_get($item, 'category.name', ''))),
                'languages' => collect($item['languages'] ?? [])
                    ->filter(fn ($language): bool => is_array($language))
                    ->map(fn (array $language): array => [
                        'code' => trim((string) ($language['code'] ?? '')),
                        'name' => trim((string) ($language['english_name'] ?? $language['name'] ?? $language['title'] ?? $language['code'] ?? '')),
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
        $rows = $payload['centers']
            ?? $payload['test_centers']
            ?? data_get($payload, 'data.centers')
            ?? data_get($payload, 'data.test_centers')
            ?? data_get($payload, 'data.data.centers')
            ?? [];

        $centers = collect(is_array($rows) ? $rows : [])
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'test_center_name' => trim((string) ($item['test_center_name'] ?? '')),
                'test_center_id' => $item['test_center_id'] ?? null,
                'test_time' => $item['test_time'] ?? null,
                'available_seats' => is_numeric($item['available_seats'] ?? null) ? (int) $item['available_seats'] : 0,
                // Preserve the upstream opaque session identity and category
                // for display/counting. Payable and user identifiers are not
                // needed by the read-only adapter and must not cross its API
                // boundary; booking still uses candidate-authenticated
                // exact-session verification before any hold or reservation.
                'exam_session_id' => $item['exam_session_id'] ?? null,
                'category_id' => $item['category_id'] ?? null,
            ])
            ->filter(fn (array $item): bool => $item['test_center_name'] !== '' && $item['test_center_id'] !== null)
            ->values();

        // Group by center so callers can see how many live session slots each
        // center actually has (matching the sessionCount SVP reports),
        // without losing any individual session's exam_session_id.
        $sessionCounts = $centers
            ->groupBy(fn (array $item): string => (string) $item['test_center_id'])
            ->map(fn ($group) => $group->count());

        $centers = $centers->map(function (array $item) use ($sessionCounts): array {
            $item['session_count'] = $sessionCounts->get((string) $item['test_center_id'], 1);

            return $item;
        });

        return [
            'centers' => $centers->values()->all(),
            'center_count' => $centers->pluck('test_center_id')->unique()->count(),
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
