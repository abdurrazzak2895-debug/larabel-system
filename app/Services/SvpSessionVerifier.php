<?php

namespace App\Services;

/**
 * Performs the authoritative, read-only SVP exam-session lookup.
 *
 * The opaque exam-session ID is never decoded locally. The full resource
 * returned by GET /individual_labor_space/exam_sessions/{id}?locale=en is the
 * source of truth for the assigned center and date.
 */
class SvpSessionVerifier
{
    public function __construct(private BookingService $booking)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(
        string $token,
        string $examSessionId,
        string $expectedCenterId,
        ?string $expectedCity = null,
        ?string $expectedDate = null,
    ): array {
        $response = $this->booking->examSession($token, $examSessionId);
        $payload = $response->getData(true);
        $status = $response->getStatusCode();
        $session = $this->extractSession(is_array($payload) ? $payload : []);
        $center = $this->extractCenter($session);
        $actualSessionId = $this->firstValue($session, [
            'id', 'exam_session_id', 'session_id',
        ]);
        $actualDate = $this->sessionDate($session);
        $normalizedExpectedDate = $this->normalizeDate($expectedDate);
        $centerMatch = $center['id'] !== null && $center['id'] === (string) $expectedCenterId;
        $cityMatch = $expectedCity === null || trim($expectedCity) === ''
            ? null
            : ($center['city'] !== null && mb_strtolower($center['city']) === mb_strtolower(trim($expectedCity)));
        $dateMatch = $normalizedExpectedDate === null
            ? null
            : ($actualDate !== null && $actualDate === $normalizedExpectedDate);
        $upstreamSuccess = $status >= 200 && $status < 300;

        return [
            'success' => $upstreamSuccess,
            'verified' => $upstreamSuccess
                && $centerMatch
                && ($cityMatch !== false)
                && ($dateMatch !== false),
            'read_only' => true,
            'upstream_status' => $status,
            'session' => [
                'id' => $actualSessionId !== null ? (string) $actualSessionId : $examSessionId,
                'exam_date' => $actualDate,
                'methodology' => $session['methodology'] ?? null,
            ],
            'expected' => [
                'test_center_id' => (string) $expectedCenterId,
                'city' => $expectedCity,
                'exam_date' => $normalizedExpectedDate,
            ],
            'actual' => [
                'test_center_id' => $center['id'],
                'test_center_name' => $center['name'],
                'city' => $center['city'],
            ],
            'checks' => [
                'center_match' => $centerMatch,
                'city_match' => $cityMatch,
                'date_match' => $dateMatch,
                'session_center_present' => $center['id'] !== null,
                'session_date_present' => $actualDate !== null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extractSession(array $payload): array
    {
        $candidates = [
            data_get($payload, 'data.exam_session'),
            data_get($payload, 'data.session'),
            data_get($payload, 'data.exam_sessions.0'),
            data_get($payload, 'data.sessions.0'),
            data_get($payload, 'exam_sessions.0'),
            data_get($payload, 'sessions.0'),
            $payload['exam_session'] ?? null,
            $payload['session'] ?? null,
            data_get($payload, 'data'),
            $payload,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ! array_is_list($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $session
     * @return array{id: ?string, name: ?string, city: ?string}
     */
    private function extractCenter(array $session): array
    {
        $nested = [];
        foreach ([
            'test_center', 'testCenter', 'center', 'site', 'exam_center',
            'test_center_data', 'test_center_details', 'center_data',
            'location', 'data', 'attributes',
        ] as $key) {
            if (is_array($session[$key] ?? null)) {
                $nested[] = $session[$key];
            }
        }

        $id = $this->firstValue($session, [
            'test_center_id', 'testCenterId', 'center_id', 'centerId',
            'site_id', 'siteId', 'test_center_code', 'center_code',
        ]);
        $name = $this->firstValue($session, [
            'test_center_name', 'testCenterName', 'center_name', 'centerName',
            'site_name', 'siteName',
        ]);
        $city = $this->firstValue($session, [
            'test_center_city', 'testCenterCity', 'center_city', 'centerCity',
            'site_city', 'siteCity',
        ]);

        foreach ($nested as $center) {
            $candidate = is_array($center['data'] ?? null)
                ? array_merge($center, $center['data'])
                : $center;

            $id ??= $this->firstValue($candidate, [
                'id', 'value', 'test_center_id', 'testCenterId',
                'site_id', 'siteId', 'center_id', 'centerId',
            ]);
            $name ??= $this->firstValue($candidate, [
                'name', 'english_name', 'title', 'label',
                'test_center_name', 'testCenterName', 'site_name', 'siteName',
            ]);
            $city ??= $this->firstValue($candidate, [
                'city', 'english_city', 'location_name', 'locality',
                'test_center_city', 'testCenterCity', 'site_city', 'siteCity',
            ]);

            if (is_array($candidate['address'] ?? null)) {
                $city ??= $this->firstValue($candidate['address'], ['city', 'locality']);
            }
        }

        return [
            'id' => $id !== null && $id !== '' ? (string) $id : null,
            'name' => $name !== null && $name !== '' ? (string) $name : null,
            'city' => $city !== null && $city !== '' ? (string) $city : null,
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function sessionDate(array $session): ?string
    {
        foreach ([
            'exam_date',
            'test_date',
            'date',
            'start_date',
            'start_at',
            'start_date_in_browser_time_zone',
            'start_date_in_tc_time_zone',
        ] as $key) {
            $date = $this->normalizeDate($session[$key] ?? null);
            if ($date !== null) {
                return $date;
            }
        }

        foreach (['exam_session', 'schedule'] as $key) {
            if (is_array($session[$key] ?? null)) {
                $date = $this->sessionDate($session[$key]);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function firstValue(array $record, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $record[$key] ?? null;
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
