<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SvpAvailabilityDashboardService
{
    public function __construct(private BookingService $booking) {}

    /**
     * Aggregate read-only SVP session availability by date and center.
     * This method never creates a hold or reservation.
     *
     * @return array{rows: array<int, array<string, mixed>>, fetched_at: string}
     */
    public function lookup(string $token, string $categoryId, string $city, ?string $date = null): array
    {
        $categoryId = trim($categoryId);
        $city = trim($city);
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;

        $cacheKey = 'svp:availability-dashboard:'.sha1(json_encode([
            'category_id' => $categoryId,
            'city' => Str::lower($city),
            'date' => $date,
        ]));

        return Cache::remember($cacheKey, now()->addSeconds((int) config('svp.availability_cache_ttl', 20)), function () use ($token, $categoryId, $city, $date): array {
            $centerResponse = $this->booking->availabilityTestCenters($token, $city, $categoryId);
            $centersPayload = $centerResponse->getData(true);
            $centers = $this->extractList($centersPayload, ['test_centers', 'centers']);

            $rows = [];
            foreach ($centers as $center) {
                if (! is_array($center)) {
                    continue;
                }

                $centerId = trim((string) ($center['id'] ?? $center['test_center_id'] ?? ''));
                $centerName = trim((string) ($center['name'] ?? $center['test_center_name'] ?? $centerId));
                if ($centerId === '' || $centerName === '') {
                    continue;
                }

                $sessionResponse = $this->booking->availabilitySessionsForCenter($token, array_filter([
                    'category_id' => $categoryId,
                    'city' => $city,
                    'test_center_id' => $centerId,
                    'exam_date' => $date,
                    'available_seats' => 'greater_than::0',
                    'country_id' => config('svp.country_id', 78),
                ], static fn ($value) => $value !== null && $value !== ''));

                if ($sessionResponse->getStatusCode() >= 400) {
                    continue;
                }

                $sessionsPayload = $sessionResponse->getData(true);
                $sessions = $this->extractList($sessionsPayload, ['sessions', 'exam_sessions', 'available_sessions']);
                $grouped = [];

                foreach ($sessions as $session) {
                    if (! is_array($session)) {
                        continue;
                    }

                    $sessionDate = $this->normalizeDate($session['exam_date'] ?? $session['date'] ?? $session['examDate'] ?? $date);
                    if ($sessionDate === null || ($date !== null && $sessionDate !== $date)) {
                        continue;
                    }

                    $shift = trim((string) ($session['shift_name'] ?? $session['shift'] ?? $session['session_name'] ?? $session['name'] ?? 'Session'));
                    $grouped[$sessionDate]['sessions'][] = [
                        'id' => (string) ($session['id'] ?? $session['exam_session_id'] ?? ''),
                        'shift' => $shift,
                    ];
                }

                foreach ($grouped as $sessionDate => $data) {
                    $rows[] = [
                        'city' => $city,
                        'category_id' => $categoryId,
                        'date' => $sessionDate,
                        'center_id' => $centerId,
                        'center_name' => $centerName,
                        'available' => count($data['sessions']) > 0,
                        'session_count' => count($data['sessions']),
                        'sessions' => $data['sessions'],
                    ];
                }
            }

            usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['center_name']] <=> [$b['date'], $b['center_name']]);

            return [
                'rows' => $rows,
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /** @return array<int, mixed> */
    private function extractList(mixed $payload, array $keys): array
    {
        if (! is_array($payload)) {
            return [];
        }

        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values($payload[$key]);
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $nested = $this->extractList($payload['data'], $keys);
            if ($nested !== []) {
                return $nested;
            }
        }

        return array_is_list($payload) ? array_values($payload) : [];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = substr(trim((string) $value), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
