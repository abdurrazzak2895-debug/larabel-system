<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

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
        ?string $expectedCenterName = null,
    ): array {
        return $this->evaluate(
            $this->booking->examSession($token, $examSessionId),
            $examSessionId,
            $expectedCenterId,
            $expectedCity,
            $expectedDate,
            $expectedCenterName,
            false,
        );
    }

    /**
     * Same authoritative check as verify(), but with the bounded/no-retry
     * availability client. This is intentionally read-only and is used only
     * before exposing sessions on the availability dashboard.
     */
    public function verifyAvailability(
        string $token,
        string $examSessionId,
        string $expectedCenterId,
        ?string $expectedCity = null,
        ?string $expectedDate = null,
        ?string $expectedCenterName = null,
    ): array {
        return $this->evaluate(
            $this->booking->availabilityExamSession($token, $examSessionId),
            $examSessionId,
            $expectedCenterId,
            $expectedCity,
            $expectedDate,
            $expectedCenterName,
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(
        \Illuminate\Http\JsonResponse $response,
        string $examSessionId,
        string $expectedCenterId,
        ?string $expectedCity,
        ?string $expectedDate,
        ?string $expectedCenterName,
        bool $allowScopedCenterFallback,
    ): array {
        $payload = $response->getData(true);
        $status = $response->getStatusCode();
        $session = $this->extractSession(is_array($payload) ? $payload : []);
        $center = $this->extractCenter($session);
        $actualSessionId = $this->firstValue($session, [
            'id', 'exam_session_id', 'session_id',
        ]);
        $actualDate = $this->sessionDate($session);
        $normalizedExpectedDate = $this->normalizeDate($expectedDate);
        $centerIdMatch = $center['id'] !== null && $center['id'] === (string) $expectedCenterId;
        $centerNameMatch = $center['id'] === null
            && filled($expectedCenterName)
            && $this->normalizeLabel($center['name']) === $this->normalizeLabel($expectedCenterName);
        $cityMatch = $expectedCity === null || trim($expectedCity) === ''
            ? null
            : ($center['city'] !== null && mb_strtolower($center['city']) === mb_strtolower(trim($expectedCity)));
        $scopedCenterFallback = $allowScopedCenterFallback
            && $center['id'] === null
            && $center['name'] === null
            && $cityMatch === true;
        $centerMatch = $centerIdMatch || $centerNameMatch || $scopedCenterFallback;
        $dateMatch = $normalizedExpectedDate === null
            ? null
            : ($actualDate !== null && $actualDate === $normalizedExpectedDate);
        $upstreamSuccess = $status >= 200 && $status < 300;

        if ($upstreamSuccess && ($center['id'] === null || $center['name'] === null)) {
            Log::info('SVP availability verifier center envelope', [
                'session_hash' => substr(sha1($examSessionId), 0, 12),
                'payload_keys' => array_keys($payload),
                'session_keys' => array_keys($session),
                'center_keys' => array_values(array_filter(array_keys($session), static fn (string $key): bool => preg_match('/center|centre|site|location|city|date/i', $key) === 1)),
                'center_scalars' => $this->centerScalarFields($session),
            ]);
        }

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
                'test_center_name' => $expectedCenterName,
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
                'center_scope_fallback' => $scopedCenterFallback,
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
            data_get($payload, 'data.data.0'),
            data_get($payload, 'data.items.0'),
            data_get($payload, 'data.results.0'),
            data_get($payload, 'result.exam_session'),
            data_get($payload, 'result.session'),
            data_get($payload, 'response.exam_session'),
            data_get($payload, 'response.session'),
            data_get($payload, 'data.0'),
            data_get($payload, 'exam_sessions.0'),
            data_get($payload, 'sessions.0'),
            data_get($payload, 'exam_session.0'),
            data_get($payload, 'session.0'),
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
            'test_center', 'testCenter', 'testCentre', 'center', 'site', 'exam_center',
            'test_center_data', 'test_center_details', 'test_center_info', 'center_data',
            'exam_site', 'test_center_location', 'location', 'data', 'attributes',
        ] as $key) {
            if (is_array($session[$key] ?? null)) {
                $centerRecord = $session[$key];
                $centerRecord['__svp_explicit_center'] = in_array($key, [
                    'test_center', 'testCenter', 'testCentre', 'center', 'site', 'exam_center',
                    'test_center_data', 'test_center_details', 'test_center_info', 'center_data',
                    'exam_site', 'test_center_location',
                ], true);
                $nested[] = $centerRecord;
            }
        }

        $id = $this->firstValue($session, [
            'test_center_id', 'testCenterId', 'center_id', 'centerId',
            'site_id', 'siteId', 'test_center_code', 'center_code',
            'testCenterCode', 'centerCode', 'test_centre_id', 'testCentreId',
        ]);
        $name = $this->firstValue($session, [
            'test_center_name', 'testCenterName', 'center_name', 'centerName',
            'site_name', 'siteName', 'test_centre_name', 'testCentreName',
        ]);
        $city = $this->firstValue($session, [
            'test_center_city', 'testCenterCity', 'center_city', 'centerCity',
            'site_city', 'siteCity', 'test_centre_city', 'testCentreCity',
        ]);

        foreach ($nested as $center) {
            $candidate = $center;
            $hasExplicitCenterNode = (bool) ($center['__svp_explicit_center'] ?? false);
            $explicitCenterId = $hasExplicitCenterNode
                ? $this->firstValueDeep($center, [
                    'id', 'value', 'test_center_id', 'testCenterId', 'test_centre_id', 'testCentreId',
                    'site_id', 'siteId', 'center_id', 'centerId',
                ], 4)
                : null;
            // SVP has returned center metadata through several nested resource
            // envelopes: site.data.attributes, center.details, and data.value.
            // Merge a few bounded layers while keeping this traversal scoped to
            // an explicit center/site object.
            for ($depth = 0; $depth < 3; $depth++) {
                $nestedValues = [];
                foreach (['data', 'attributes', 'details', 'value', 'resource', 'site', 'test_center', 'testCenter', 'center', 'exam_center'] as $nestedKey) {
                    if (is_array($candidate[$nestedKey] ?? null)) {
                        $nestedValues[] = $candidate[$nestedKey];
                        $isExplicitCenterKey = in_array($nestedKey, ['site', 'test_center', 'testCenter', 'center', 'exam_center'], true);
                        $hasExplicitCenterNode = $hasExplicitCenterNode || $isExplicitCenterKey;
                        if ($isExplicitCenterKey && $explicitCenterId === null) {
                            $explicitCenterId = $this->firstValueDeep($candidate[$nestedKey], [
                                'id', 'value', 'test_center_id', 'testCenterId', 'test_centre_id', 'testCentreId',
                                'site_id', 'siteId', 'center_id', 'centerId',
                            ], 4);
                        }
                    }
                }
                if ($nestedValues === []) {
                    break;
                }
                foreach ($nestedValues as $nestedValue) {
                    $candidate = array_merge($candidate, $nestedValue);
                }
            }

            $idKeys = [
                'test_center_id', 'testCenterId', 'test_centre_id', 'testCentreId',
                'site_id', 'siteId', 'test_center_code', 'center_code',
                'testCenterCode', 'centerCode', 'center_id', 'centerId',
            ];
            if ($explicitCenterId !== null) {
                $id ??= $explicitCenterId;
            } elseif ($hasExplicitCenterNode) {
                $id ??= $this->firstValue($candidate, $idKeys);
            }
            $name ??= $this->firstValue($candidate, [
                'name', 'english_name', 'title', 'label',
                'test_center_name', 'testCenterName', 'test_centre_name', 'testCentreName',
                'site_name', 'siteName',
            ]);
            $city ??= $this->firstValue($candidate, [
                'city', 'english_city', 'location_name', 'locality',
                'test_center_city', 'testCenterCity', 'test_centre_city', 'testCentreCity',
                'site_city', 'siteCity',
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

        foreach (['exam_session', 'schedule', 'attributes', 'data', 'details', 'resource'] as $key) {
            if (is_array($session[$key] ?? null)) {
                $date = $this->sessionDate($session[$key]);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function firstValueDeep(array $record, array $keys, int $depth): mixed
    {
        $value = $this->firstValue($record, $keys);
        if ($value !== null || $depth <= 0) {
            return $value;
        }

        foreach ($record as $nested) {
            if (is_array($nested)) {
                $value = $this->firstValueDeep($nested, $keys, $depth - 1);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function normalizeLabel(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\\s+/', ' ', (string) $value)));
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, scalar|null>
     */
    private function centerScalarFields(array $session): array
    {
        $fields = [];
        $visit = function (mixed $node, string $path = '', int $depth = 0) use (&$visit, &$fields): void {
            if (! is_array($node) || $depth > 4 || count($fields) >= 40) {
                return;
            }

            foreach ($node as $key => $value) {
                $key = (string) $key;
                $nextPath = $path === '' ? $key : $path.'.'.$key;
                if (is_scalar($value) && preg_match('/center|centre|site|location|city|date|id|code|name/i', $key) === 1) {
                    $fields[$nextPath] = is_string($value) ? trim(substr($value, 0, 120)) : $value;
                } elseif (is_array($value)) {
                    $visit($value, $nextPath, $depth + 1);
                }
            }
        };

        $visit($session);
        return $fields;
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
