<?php

namespace App\Services;

use App\Models\TestCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Uses the authenticated candidate SVP account as a read-only availability
 * fallback when the separate Portal Availability source returns no center
 * rows. Every returned center is backed by at least one exact session from the
 * requested center and date.
 */
final class SvpDirectAvailabilityService
{
    public function __construct(
        private readonly BookingService $booking,
    ) {
    }

    /**
     * @param array{city: string, category_id: string|int, date: string} $params
     * @return array{success: bool, centers: array<int, array<string, mixed>>, availability_source: string, fallback: bool, requires_svp_login?: bool, error?: string}
     */
    public function centersForDate(string $token, array $params): array
    {
        $city = trim((string) ($params['city'] ?? ''));
        $categoryId = trim((string) ($params['category_id'] ?? ''));
        $date = trim((string) ($params['date'] ?? ''));

        if ($city === '' || $categoryId === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [
                'success' => false,
                'centers' => [],
                'availability_source' => 'candidate_authenticated_sessions',
                'fallback' => true,
                'error' => 'A valid city, category, and date are required for the direct SVP lookup.',
            ];
        }

        $catalog = $this->centerCatalog($token, $city, $categoryId);
        $rows = [];

        foreach ($catalog as $center) {
            $centerId = (string) ($center['id'] ?? '');
            if ($centerId === '') {
                continue;
            }

            try {
                $response = $this->booking->sessionsForCenter($token, [
                    'city' => $city,
                    'category_id' => $categoryId,
                    'test_center_id' => $centerId,
                    'exam_date' => $date,
                    'available_seats' => 'greater_than::0',
                    'per_page' => max(1, min(1000, (int) config('svp.session_per_page', 1000))),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Direct SVP center availability probe failed', [
                    'city' => $city,
                    'category_id' => $categoryId,
                    'center_id' => $centerId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($response->getStatusCode() === 401) {
                return [
                    'success' => false,
                    'centers' => [],
                    'availability_source' => 'candidate_authenticated_sessions',
                    'fallback' => true,
                    'requires_svp_login' => true,
                    'error' => 'Your SVP session has expired. Sign in with SVP again, then retry the lookup.',
                ];
            }

            if ($response->getStatusCode() >= 400) {
                continue;
            }

            $sessions = $this->normalizeSessions($response, $center, $date);
            if ($sessions === []) {
                continue;
            }

            $sessionCount = count($sessions);
            foreach ($sessions as $session) {
                $rows[] = [
                    'test_center_name' => (string) ($center['name'] ?? 'Live test center'),
                    'test_center_id' => $centerId,
                    'test_time' => $this->sessionTime($session),
                    'available_seats' => $this->sessionSeats($session),
                    'session_count' => $sessionCount,
                    'exam_session_id' => (string) ($session['id'] ?? ''),
                    'exam_date' => (string) ($session['exam_date'] ?? $date),
                    'availability_source' => 'candidate_authenticated_sessions',
                ];
            }
        }

        return [
            'success' => true,
            'centers' => array_values($rows),
            'availability_source' => 'candidate_authenticated_sessions',
            'fallback' => true,
        ];
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function centerCatalog(string $token, string $city, string $categoryId): array
    {
        $local = TestCenter::query()
            ->whereRaw('LOWER(city) = ?', [mb_strtolower($city)])
            ->orderBy('name')
            ->limit(max(1, min(100, (int) config('svp.direct_center_probe_limit', 100))))
            ->get(['svp_id', 'name'])
            ->map(fn (TestCenter $center): array => [
                'id' => (string) $center->svp_id,
                'name' => trim((string) $center->name) ?: 'Live test center',
            ])
            ->filter(fn (array $center): bool => $center['id'] !== '')
            ->unique('id')
            ->values()
            ->all();

        if ($local !== []) {
            return $local;
        }

        try {
            $response = $this->booking->testCenters($token, $city, $categoryId);
            if ($response->getStatusCode() >= 400) {
                return [];
            }

            $payload = $response->getData(true);
            $raw = data_get($payload, 'data');
            if (! is_array($raw)) {
                $raw = data_get($payload, 'data.test_centers')
                    ?? data_get($payload, 'data.centers')
                    ?? data_get($payload, 'test_centers')
                    ?? data_get($payload, 'centers')
                    ?? [];
            }

            return collect(is_array($raw) ? $raw : [])
                ->filter(fn ($center): bool => is_array($center))
                ->map(function (array $center): array {
                    $id = trim((string) ($center['id'] ?? $center['test_center_id'] ?? $center['svp_id'] ?? ''));
                    $name = trim((string) ($center['name'] ?? $center['test_center_name'] ?? 'Live test center'));

                    return ['id' => $id, 'name' => $name !== '' ? $name : 'Live test center'];
                })
                ->filter(fn (array $center): bool => $center['id'] !== '')
                ->unique('id')
                ->take(max(1, min(100, (int) config('svp.direct_center_probe_limit', 100))))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Direct SVP center catalog lookup failed', [
                'city' => $city,
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param array{id: string, name: string} $center
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSessions(JsonResponse $response, array $center, string $date): array
    {
        $payload = $response->getData(true);
        $sessions = data_get($payload, 'data.sessions');
        if (! is_array($sessions) || $sessions === []) {
            $sessions = data_get($payload, 'data.exam_sessions');
        }
        if (! is_array($sessions) || $sessions === []) {
            $sessions = $payload['sessions'] ?? $payload['exam_sessions'] ?? [];
        }

        if (! is_array($sessions)) {
            return [];
        }

        $centerId = (string) $center['id'];
        $centerName = (string) $center['name'];
        $unique = [];

        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }

            $id = trim((string) ($session['id'] ?? $session['exam_session_id'] ?? $session['session_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $sessionDate = $this->sessionDate($session) ?: $date;
            if ($sessionDate !== $date) {
                continue;
            }

            $sessionCenterId = trim((string) (
                $session['test_center_id']
                ?? $session['site_id']
                ?? data_get($session, 'test_center.id')
                ?? data_get($session, 'site.id')
                ?? data_get($session, 'center.id')
                ?? ''
            ));
            if ($sessionCenterId !== '' && $sessionCenterId !== $centerId) {
                continue;
            }

            $session['id'] = $id;
            $session['exam_date'] = $sessionDate;
            $session['test_center_id'] = $centerId;
            $session['test_center_name'] = $session['test_center_name']
                ?? $session['site_name']
                ?? $session['center_name']
                ?? $centerName;
            $unique[$id] = $session;
        }

        return array_values($unique);
    }

    private function sessionDate(array $session): ?string
    {
        foreach (['exam_date', 'test_date', 'date', 'start_date_in_browser_time_zone', 'start_date_in_tc_time_zone', 'start_at'] as $key) {
            $value = $session[$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                return substr($value, 0, 10);
            }
        }

        return null;
    }

    private function sessionTime(array $session): ?string
    {
        foreach ([
            'test_time',
            'start_time',
            'time',
            'start_at_in_tc_time_zone',
            'start_at_in_browser_time_zone',
            'start_at',
            'start_date_in_tc_time_zone',
            'start_date_in_browser_time_zone',
        ] as $key) {
            $value = $session[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $time = trim((string) $value);
            if ($time === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $time) === 1) {
                continue;
            }

            $time = preg_replace('/^\d{4}-\d{2}-\d{2}[T ]/', '', $time) ?? $time;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($time)) === 1) {
                continue;
            }

            return trim($time);
        }

        return null;
    }

    private function sessionSeats(array $session): ?int
    {
        $value = $session['available_seats']
            ?? $session['availableSeats']
            ?? $session['remaining_seats']
            ?? $session['remainingSeats']
            ?? $session['seats_available']
            ?? $session['available_seat_count']
            ?? $session['seat_count']
            ?? $session['seats']
            ?? null;

        return $value !== null && $value !== '' && is_numeric($value) ? (int) $value : null;
    }
}
