<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SvpAvailabilityDashboardService
{
    public function __construct(
        private BookingService $booking,
        private SvpSessionVerifier $verifier,
    ) {}

    /**
     * Aggregate read-only SVP session availability by date and center.
     * This method never creates a hold or reservation.
     *
     * @return array{rows: array<int, array<string, mixed>>, fetched_at: string}
     */
    /**
     * @param array<int, string>|string $tokens Backend-managed SVP tokens only.
     */
    public function lookup(array|string $tokens, string $categoryId, string $city, ?string $date = null): array
    {
        $tokens = is_array($tokens) ? array_values(array_filter($tokens, static fn ($token): bool => is_string($token) && trim($token) !== '')) : [trim($tokens)];
        $categoryId = trim($categoryId);
        $city = trim($city);
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;

        $cacheKey = 'svp:availability-dashboard:v2:'.sha1(json_encode([
            'category_id' => $categoryId,
            'city' => Str::lower($city),
            'date' => $date,
        ]));

        return Cache::remember($cacheKey, now()->addSeconds((int) config('svp.availability_cache_ttl', 20)), function () use ($tokens, $categoryId, $city, $date): array {
            if ($tokens === []) {
                return ['rows' => [], 'fetched_at' => now()->toIso8601String()];
            }

            $centerResponse = $this->firstSuccessfulResponse(
                $tokens,
                fn (string $token) => $this->booking->availabilityTestCenters($token, $city, $categoryId),
            );
            if ($centerResponse === null) {
                Log::warning('SVP availability center lookup failed', [
                    'category_id' => $categoryId,
                    'city' => $city,
                    'date' => $date,
                    'verified_only' => true,
                ]);

                return ['rows' => [], 'fetched_at' => now()->toIso8601String(), 'verified_only' => true];
            }

            $centersPayload = $centerResponse->getData(true);
            $centers = $this->extractList($centersPayload, ['test_centers', 'centers']);

            $rows = [];
            foreach ($centers as $centerIndex => $center) {
                if (! is_array($center)) {
                    continue;
                }

                $centerId = trim((string) ($center['id'] ?? $center['test_center_id'] ?? ''));
                $centerName = trim((string) ($center['name'] ?? $center['test_center_name'] ?? $centerId));
                if ($centerId === '' || $centerName === '') {
                    continue;
                }

                $sessionParameters = array_filter([
                    'category_id' => $categoryId,
                    'city' => $city,
                    'test_center_id' => $centerId,
                    'exam_date' => $date,
                    'available_seats' => 'greater_than::0',
                    'country_id' => config('svp.country_id', 78),
                ], static fn ($value) => $value !== null && $value !== '');
                $sessionResponse = $this->firstSuccessfulResponse(
                    $this->rotateTokens($tokens, $centerIndex),
                    fn (string $token) => $this->booking->availabilitySessionsForCenter($token, $sessionParameters),
                );

                if ($sessionResponse === null) {
                    continue;
                }

                $sessionsPayload = $sessionResponse->getData(true);
                $sessions = $this->extractList($sessionsPayload, ['sessions', 'exam_sessions', 'available_sessions']);
                $grouped = [];

                foreach ($sessions as $session) {
                    if (! is_array($session)) {
                        continue;
                    }

                    $sessionId = trim((string) ($session['id'] ?? $session['exam_session_id'] ?? $session['session_id'] ?? ''));
                    if ($sessionId === '') {
                        continue;
                    }
                    $listedDate = $this->normalizeDate($session['exam_date'] ?? $session['date'] ?? $session['examDate'] ?? $date);
                    if ($listedDate === null || ($date !== null && $listedDate !== $date)) {
                        continue;
                    }
                    $verification = $this->verifySessionAcrossAccounts(
                        $tokens,
                        $sessionId,
                        $centerId,
                        $city,
                        $listedDate,
                        $centerName,
                    );
                    if (! ($verification['verified'] ?? false)) {
                        continue;
                    }

                    $verifiedDate = $this->normalizeDate(data_get($verification, 'session.exam_date'));
                    if ($verifiedDate === null || ($date !== null && $verifiedDate !== $date)) {
                        continue;
                    }

                    $shift = trim((string) ($session['shift_name'] ?? $session['shift'] ?? $session['session_name'] ?? $session['name'] ?? 'Session'));
                    $grouped[$verifiedDate]['sessions'][$sessionId] = [
                        'id' => $sessionId,
                        'shift' => $shift,
                        'verified' => true,
                    ];
                }

                foreach ($grouped as $sessionDate => $data) {
                    $verifiedSessions = array_values($data['sessions']);
                    if ($verifiedSessions === []) {
                        continue;
                    }

                    $rows[] = [
                        'city' => $city,
                        'category_id' => $categoryId,
                        'date' => $sessionDate,
                        'center_id' => $centerId,
                        'center_name' => $centerName,
                        'available' => true,
                        'session_count' => count($verifiedSessions),
                        'sessions' => $verifiedSessions,
                        'verified' => true,
                    ];
                }
            }

            usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['center_name']] <=> [$b['date'], $b['center_name']]);


            return [
                'rows' => $rows,
                'fetched_at' => now()->toIso8601String(),
                'verified_only' => true,
            ];
        });
    }

    /**
     * @param array<int, string> $tokens
     * @return array<string, mixed>
     */
    private function verifySessionAcrossAccounts(
        array $tokens,
        string $sessionId,
        string $centerId,
        string $city,
        string $date,
        string $centerName,
    ): array {
        $attemptLimit = max(1, (int) config('svp.availability_account_attempts', 3));
        $lastVerification = null;
        foreach (array_slice($tokens, 0, $attemptLimit) as $token) {
            try {
                $verification = $this->verifier->verifyAvailability(
                    $token,
                    $sessionId,
                    $centerId,
                    $city,
                    $date,
                    $centerName,
                );
                $lastVerification = $verification;
                if (($verification['verified'] ?? false) === true) {
                    return $verification;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return is_array($lastVerification)
            ? array_replace($lastVerification, ['verified' => false])
            : ['verified' => false];
    }

    /**
     * @param array<int, string> $tokens
     */
    private function firstSuccessfulResponse(array $tokens, callable $request): mixed
    {
        $attemptLimit = max(1, (int) config('svp.availability_account_attempts', 3));
        foreach (array_slice($tokens, 0, $attemptLimit) as $token) {
            try {
                $response = $request($token);
                if (is_object($response) && method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    return $response;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    private function rotateTokens(array $tokens, int $offset): array
    {
        if ($tokens === []) {
            return [];
        }

        $offset %= count($tokens);
        return array_values(array_merge(array_slice($tokens, $offset), array_slice($tokens, 0, $offset)));
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
