<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Services\ResponseService;

class TakamolProvider implements BookingProviderInterface
{
    protected PendingRequest $client;

    protected string $baseUrl;

    protected string $apiPrefix = '/api/v1';

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('svp.base_url'), '/');

        $this->client = Http::baseUrl($this->baseUrl)
            ->timeout((int) config('svp.timeout', 30))
            ->acceptJson()
            // NOTE: do not explicitly set Host — let the HTTP client derive it
            // from the base URL. Setting Host manually can break routing when
            // requests pass through proxies.
            // ->withHeader('Host', (string) config('svp.base_url_host', 'svp-international-api.pacc.sa'))
            ->withHeader('Accept', 'application/json, text/plain, */*')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('sec-ch-ua', '"Not;A=Brand";v="8", "Chromium";v="150", "Google Chrome";v="150"')
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Tenant-Name', (string) config('svp.tenant_name', ''))
            ->withHeader('Sec-Fetch-Dest', 'empty')
            ->withHeader('Sec-Fetch-Mode', 'cors')
            ->withHeader('Sec-Fetch-Site', 'same-site')
            ->withHeader('sec-ch-ua-mobile', '?0')
            ->withHeader('sec-ch-ua-platform', '"Windows"')
            ->retry(
                (int) config('svp.retry_times', 3),
                (int) config('svp.retry_delay', 1000),
                static fn ($exception) => $exception instanceof ConnectionException
            );

        if ($csrf = Session::get('svp_csrf')) {
            $this->client = $this->client->withHeader('X-CSRF-Token', $csrf);
        }
    }

    /**
     * Switch the Bearer token used by this instance.
     *
     * Returns a new instance to keep the base client immutable across calls.
     */
    public function withToken(string $token): static
    {
        $clone = clone $this;
        $clone->client = $clone->client->withToken($token);

        return $clone;
    }

    /**
     * Attach a Rails-style CSRF token (returned as `csrf` in the OTP response)
     * so authenticated requests pass the SVP API's CSRF protection.
     */
    public function withCsrfToken(string $csrf): static
    {
        $clone = clone $this;
        $clone->client = $clone->client->withHeader('X-CSRF-Token', $csrf);

        return $clone;
    }

    /**
     * Override the multi-tenant header used to route requests to the right tenant.
     */
    public function withTenant(string $tenant): static
    {
        $clone = clone $this;
        $clone->client = $clone->client->withHeader('X-Tenant-Name', $tenant);

        return $clone;
    }

    // -----------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------

    public function login(array $payload): JsonResponse
    {
        return $this->dispatch('POST', '/sessions/login', $payload);
    }

    public function verifyOtp(array $payload): JsonResponse
    {
        return $this->dispatch('POST', '/sessions/otp', $payload);
    }

    // -----------------------------------------------------------------
    // Profile
    // -----------------------------------------------------------------

    public function profile(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/profile');
    }

    public function permissions(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/permissions');
    }

    public function certificatePrice(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/certificate_price');
    }

    public function featureFlags(): JsonResponse
    {
        return $this->dispatch('GET', '/flipper/feature_flags');
    }

    public function userBalance(string $userId, array $params = []): JsonResponse
    {
        return $this->dispatch('GET', '/users/'.$userId.'/balance', $params);
    }

    // -----------------------------------------------------------------
    // Exam
    // -----------------------------------------------------------------

    public function examSessions(array $params = []): JsonResponse
    {
        $params = $this->normalizeQueryParams($params);

        if (empty($params['category_id'])) {
            return response()->json([
                'success' => false,
                'error'   => 'category_id is required',
            ], 422);
        }

        // The working SVP wizard always asks for sessions with category_id and
        // available_seats, then narrows by city and the exact test_center_id.
        // Omitting the center mixes sessions from every center in a city.
        $params['available_seats'] ??= 'greater_than::0';
        $response = $this->dispatch('GET', '/individual_labor_space/exam_sessions', $params);

        // SVP session nodes do not always carry a `name`; the frontend needs a
        // stable label but must retain the original id and all upstream fields.
        $payload = json_decode($response->getContent(), true);
        if (! is_array($payload)) {
            return $response;
        }

        $sessions = $this->extractList($payload, ['exam_sessions', 'sessions', 'available_sessions', 'items', 'results', 'records']);
        if ($sessions === null) {
            return $response;
        }

        $requestedCenterId = isset($params['test_center_id']) ? (string) $params['test_center_id'] : null;
        $requestedDate = isset($params['exam_date']) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string) $params['exam_date']) === 1
            ? (string) $params['exam_date']
            : null;
        $sessions = array_map(
            static function ($node) use ($requestedDate): array {
                $session = is_array($node)
                    ? self::formatSessionName($node)
                    : ['id' => (string) $node, 'name' => (string) $node];

                // Exact-date SVP responses may return opaque session rows with
                // no date field. The requested date is authoritative in that
                // response shape, so annotate the row for the date-first UI and
                // for temporary-hold matching without overwriting a real date.
                if ($requestedDate !== null && (string) ($session['exam_date'] ?? '') === '') {
                    $session['exam_date'] = $requestedDate;
                }

                return $session;
            },
            $sessions
        );

        // The session lookup is already scoped to the requested center. SVP
        // sometimes returns only an opaque session id and date, without any
        // embedded center metadata. In that response shape, the requested
        // center is authoritative; never let the session id become a false
        // center id.
        if ($requestedCenterId !== null && $requestedCenterId !== '') {
            $dhakaCanonical = collect(config('svp.dhaka_test_centers', []))->keyBy('id');
            $sessions = array_map(static function (array $session) use ($requestedCenterId, $dhakaCanonical): array {
                if ((string) ($session['test_center_id'] ?? '') === '') {
                    $session['test_center_id'] = $requestedCenterId;

                    if ((string) ($session['test_center_name'] ?? '') === '') {
                        $canonical = $dhakaCanonical->get($requestedCenterId);
                        if (is_array($canonical)) {
                            $session['test_center_name'] = $canonical['name'] ?? null;
                            $session['test_center_city'] = $canonical['city'] ?? 'Dhaka';
                        }
                    }
                }

                return $session;
            }, $sessions);
        }

        // The upstream query is center-scoped, but keep a defensive check here:
        // a stale/proxy response from another center must never reach the form.
        if ($requestedCenterId !== null && $requestedCenterId !== '') {
            $sessions = array_values(array_filter($sessions, static function (array $session) use ($requestedCenterId): bool {
                $sessionCenterId = $session['test_center_id'] ?? null;
                return $sessionCenterId === null || (string) $sessionCenterId === $requestedCenterId;
            }));
        }

        return response()->json([
            'success' => $payload['success'] ?? true,
            'data'    => [
                'sessions'      => $sessions,
                'exam_sessions' => $sessions,
            ],
        ] + array_diff_key($payload, array_flip(['success', 'data', 'exam_sessions', 'sessions', 'available_sessions'])), $response->getStatusCode());
    }

    /**
     * Resolve every available date for the selected center before loading its
     * opaque session IDs. The SVP available_dates endpoint is category/city
     * based and carries the center in each date record; the exam_sessions
     * endpoint must then be called once per date with the exact center ID.
     */
    public function examSessionsForCenter(array $params = []): JsonResponse
    {
        $params = $this->normalizeQueryParams($params);
        $categoryId = (string) ($params['category_id'] ?? '');
        $city = (string) ($params['city'] ?? '');
        $centerId = (string) ($params['test_center_id'] ?? '');
        $requestedDate = (string) ($params['exam_date'] ?? '');

        if ($categoryId === '' || $city === '' || $centerId === '') {
            return $this->examSessions($params);
        }

        // A date-specific request is authoritative. Do not replace it with
        // the aggregate available_dates response, which may omit valid dates.
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $requestedDate) === 1) {
            return $this->examSessions($params);
        }

        $availableResponse = $this->availableDates(null, [
            'category_id' => $categoryId,
            'city' => $city,
            'country_id' => $params['country_id'] ?? null,
            'per_page' => $params['per_page'] ?? 10000,
        ]);

        if ($availableResponse->getStatusCode() >= 400) {
            // Preserve the existing lookup behavior if the auxiliary
            // available_dates endpoint is temporarily unavailable.
            return $this->examSessions($params);
        }

        $availablePayload = json_decode($availableResponse->getContent(), true);
        $dateRecords = is_array($availablePayload)
            ? $this->extractAvailableDateRecords($availablePayload)
            : [];
        $centerDates = [];

        foreach ($dateRecords as $record) {
            if (is_array($record)) {
                $normalized = self::formatSessionName($record);
                $recordCenter = (string) ($normalized['test_center_id'] ?? '');
                $date = self::normalizeExamDate($normalized['exam_date'] ?? null);
            } elseif (is_scalar($record)) {
                $date = self::normalizeExamDate($record);
                $normalized = $date !== null
                    ? $this->syntheticCenterDate($date, $centerId, $city)
                    : [];
                $recordCenter = $centerId;
            } else {
                continue;
            }

            if ($date === null) {
                continue;
            }

            // Some SVP responses return category/city-wide date strings or
            // date objects without center metadata. Those dates are still safe
            // candidates because every date is queried again with the selected
            // center_id and only sessions returned for that center are kept.
            if ($recordCenter === '' || $recordCenter === $centerId) {
                $centerDates[$date] = $recordCenter === ''
                    ? $this->syntheticCenterDate($date, $centerId, $city) + $normalized
                    : $normalized;
            }
        }

        // SVP can omit earlier dates from available_dates even though the
        // explicit exam_sessions?exam_date=YYYY-MM-DD endpoint still returns
        // valid seats. Probe the bounded gap before the first discovered date;
        // every candidate is still accepted only when its response contains a
        // session for the selected center.
        $probeDates = $this->explicitDateProbeDates($centerDates);
        foreach ($probeDates as $date) {
            $centerDates[$date] ??= $this->syntheticCenterDate($date, $centerId, $city);
        }
        ksort($centerDates);

        if ($centerDates === []) {
            // Some SVP deployments omit center metadata from available_dates.
            // Keep the established exact-center session query as a safe
            // compatibility path rather than rendering an empty wizard.
            return $this->examSessions($params);
        }

        $sessions = [];
        $availableDates = [];

        foreach (array_keys($centerDates) as $date) {
            $dateResponse = $this->examSessions($params + ['exam_date' => $date]);
            if ($dateResponse->getStatusCode() >= 400) {
                continue;
            }

            $datePayload = json_decode($dateResponse->getContent(), true);
            if (! is_array($datePayload)) {
                continue;
            }

                $dateSessions = array_values(array_filter(
                $this->extractList($datePayload, ['exam_sessions', 'sessions', 'available_sessions', 'items', 'results', 'records']) ?? [],
                static fn ($session): bool => is_array($session),
            ));
            if ($dateSessions === []) {
                continue;
            }

            $availableDates[$date] = $centerDates[$date];
            foreach ($dateSessions as $session) {
                $sessions[] = $session;
            }
        }

        $unique = [];
        foreach ($sessions as $session) {
            $key = (string) ($session['id'] ?? $session['exam_session_id'] ?? '');
            $key = $key !== '' ? $key : sha1(json_encode($session));
            $unique[$key] = $session;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => array_values($unique),
                'exam_sessions' => array_values($unique),
                'available_dates' => array_values($availableDates),
            ],
        ]);
    }

    /**
     * Return the dates before the first SVP metadata date that should be
     * checked explicitly for seats.
     *
     * @param array<string, array<string, mixed>> $centerDates
     * @return array<int, string>
     */
    protected function explicitDateProbeDates(array $centerDates): array
    {
        $backfillDays = max(0, min(31, (int) config('svp.session_date_probe_backfill_days', 14)));
        if ($backfillDays === 0) {
            return [];
        }

        $dates = array_keys($centerDates);
        if ($dates === []) {
            $start = now()->startOfDay();
            return collect(range(0, $backfillDays))
                ->map(static fn (int $offset): string => $start->copy()->addDays($offset)->toDateString())
                ->all();
        }

        $firstDate = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', min($dates));
        $today = now()->startOfDay();
        if ($firstDate->lessThanOrEqualTo($today)) {
            return [];
        }

        $probeStart = $firstDate->subDays($backfillDays);
        if ($probeStart->lessThan($today)) {
            $probeStart = \Carbon\CarbonImmutable::instance($today);
        }

        $daysToProbe = (int) $probeStart->diffInDays($firstDate);
        return collect(range($daysToProbe, 1))
            ->map(static fn (int $offset): string => $firstDate->subDays($offset)->toDateString())
            ->all();
    }

    /**
     * Build a center-scoped date record for a date discovered by an explicit
     * session probe, without inventing an exam-session ID.
     *
     * @return array<string, mixed>
     */
    protected function syntheticCenterDate(string $date, string $centerId, string $city): array
    {
        $canonical = collect(config('svp.dhaka_test_centers', []))->first(
            static fn (array $center): bool => (string) ($center['id'] ?? '') === $centerId,
        );
        $centerName = is_array($canonical) ? (string) ($canonical['name'] ?? '') : '';
        $centerCity = is_array($canonical) ? (string) ($canonical['city'] ?? $city) : $city;

        return [
            'exam_date' => $date,
            'test_center_id' => $centerId,
            'test_center_name' => $centerName !== '' ? $centerName : null,
            'test_center_city' => $centerCity,
            'name' => trim(implode(' • ', array_filter([$date, $centerName, $centerCity]))),
        ];
    }

    /**
     * Extract available-date records from all SVP response envelopes.
     *
     * @return array<int, mixed>
     */
    protected function extractAvailableDateRecords(array $payload): array
    {
        $records = $this->extractList($payload, [
            'available_dates',
            'dates',
            'items',
            'results',
            'records',
        ]);

        if (is_array($records)) {
            return array_values($records);
        }

        // A few SVP responses use a bare JSON:API `data` list for dates.
        $data = $payload['data'] ?? null;
        return is_array($data) && array_is_list($data) ? array_values($data) : [];
    }

    /**
     * Keep only non-empty query values and coerce the SVP filter names to the
     * contract used by the working Playwright client.
     */
    protected function normalizeQueryParams(array $params): array
    {
        $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

        if (isset($params['category']) && ! isset($params['category_id'])) {
            $params['category_id'] = $params['category'];
            unset($params['category']);
        }

        if (isset($params['testCenterId']) && ! isset($params['test_center_id'])) {
            $params['test_center_id'] = $params['testCenterId'];
            unset($params['testCenterId']);
        }

        if (isset($params['reservationId']) && ! isset($params['reservation_id'])) {
            $params['reservation_id'] = $params['reservationId'];
            unset($params['reservationId']);
        }

        return $params;
    }

    /**
     * Extract a list from the response envelopes seen in the SVP API.
     */
    protected function extractList(array $payload, array $keys): ?array
    {
        $visit = function (mixed $node, int $depth = 0) use (&$visit, $keys): ?array {
            if (! is_array($node) || $depth > 6) {
                return null;
            }

            if (array_is_list($node)) {
                return array_values($node);
            }

            foreach ($keys as $key) {
                if (! array_key_exists($key, $node)) {
                    continue;
                }

                $candidate = $node[$key];
                if (is_array($candidate) && array_is_list($candidate)) {
                    return array_values($candidate);
                }

                $nested = $visit($candidate, $depth + 1);
                if ($nested !== null) {
                    return $nested;
                }
            }

            // Traverse common response envelopes so `data.items`,
            // `data.attributes.items`, and `response.data.results` work even
            // when the upstream resource adds another wrapper layer.
            foreach (['data', 'attributes', 'result', 'response', 'payload'] as $wrapper) {
                if (isset($node[$wrapper]) && is_array($node[$wrapper])) {
                    $nested = $visit($node[$wrapper], $depth + 1);
                    if ($nested !== null) {
                        return $nested;
                    }
                }
            }

            return null;
        };

        return $visit($payload);
    }

    /**
     * Return a session node plus a human-readable `name` label, preserving all
     * other fields (id, start dates, category, test_center, …).
     */
    protected static function formatSessionName(array $node): array
    {
        // JSON:API session resources may keep the opaque identifier under
        // attributes while the surrounding node contains only `type`/`id`.
        // Prefer the resource/session identifiers and never borrow a nested
        // center id as the session id.
        if ((string) ($node['id'] ?? '') === '') {
            $nestedId = data_get($node, 'attributes.id')
                ?? data_get($node, 'data.attributes.id')
                ?? data_get($node, 'data.id')
                ?? $node['exam_session_id']
                ?? $node['session_id']
                ?? null;
            if (is_scalar($nestedId) && (string) $nestedId !== '') {
                $node['id'] = (string) $nestedId;
            }
        }

        $center = self::extractCenterMetadata($node);
        $centerId = $center['id'] ?? null;
        $centerName = $center['name'] ?? null;
        $city = $center['city'] ?? null;
        $date = self::firstScalarDeep($node, [
            'exam_date',
            'test_date',
            'date',
            'start_date',
            'start_at',
            'start_date_in_browser_time_zone',
            'start_date_in_tc_time_zone',
        ], 3);
        $category = is_array($node['category'] ?? null)
            ? ($node['category']['english_name'] ?? $node['category']['arabic_name'] ?? null)
            : null;

        if ($centerId !== null && $centerId !== '') {
            $node['test_center_id'] = (string) $centerId;
        }
        if (is_string($centerName) && trim($centerName) !== '') {
            $node['test_center_name'] = trim($centerName);
        }
        if (is_string($city) && trim($city) !== '') {
            $node['test_center_city'] = trim($city);
        }

        // The session's own start date is the only booking date that may be
        // submitted. The available_dates endpoint is category/city-wide, so it
        // cannot safely replace this center-scoped session date in the UI.
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1) {
            $node['exam_date'] = substr($date, 0, 10);
        }

        if (! isset($node['name']) || ! is_string($node['name']) || trim($node['name']) === '') {
            $label = trim(implode(' • ', array_filter(
                [$date, $centerName, $city, $category],
                static fn ($v) => is_string($v) && trim($v) !== ''
            ))) ?: 'Exam session';
            $node['name'] = $label;
        }

        return $node;
    }

    /**
     * Normalize center metadata from the aliases used by different SVP
     * session envelopes. Some responses use test_center, some use site or
     * center, and some wrap those objects under data/attributes.
     *
     * @return array{id: ?string, name: ?string, city: ?string}
     */
    protected static function extractCenterMetadata(array $node, int $depth = 0): array
    {
        $id = self::firstScalar($node, [
            'test_center_id', 'testCenterId', 'center_id', 'centerId',
            'site_id', 'siteId', 'test_center_code', 'center_code',
        ]);
        $name = self::firstText($node, [
            'test_center_name', 'testCenterName', 'center_name', 'centerName',
            'site_name', 'siteName',
        ]);
        $city = self::firstText($node, [
            'test_center_city', 'testCenterCity', 'center_city', 'centerCity',
            'site_city', 'siteCity',
        ]);

        $objects = [];
        foreach ([
            'test_center', 'testCenter', 'center', 'site', 'exam_center',
            'test_center_data', 'test_center_details', 'center_data',
            'location', 'data', 'attributes',
        ] as $key) {
            if (is_array($node[$key] ?? null)) {
                $objects[] = $node[$key];
            }
        }

        foreach ($objects as $object) {
            if ($id === null || $name === null || $city === null) {
                $nested = self::extractCenterMetadata($object, $depth + 1);
                $id ??= $nested['id'];
                $name ??= $nested['name'];
                $city ??= $nested['city'];
            }
        }

        // A center object commonly exposes its own generic id/name fields.
        if ($id === null && $depth > 0) {
            $id = self::firstScalar($node, ['id', 'value']);
        }
        if ($name === null && $depth > 0) {
            $name = self::firstText($node, ['name', 'english_name', 'title', 'label']);
        }
        if ($city === null && $depth > 0) {
            $city = self::firstText($node, ['city', 'english_city', 'location_name']);
        }

        return [
            'id' => $id !== null ? (string) $id : null,
            'name' => $name !== null ? trim((string) $name) : null,
            'city' => $city !== null ? trim((string) $city) : null,
        ];
    }

    protected static function normalizeExamDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/(\\d{4}-\\d{2}-\\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected static function firstScalar(array $node, array $keys): string|int|float|null
    {
        foreach ($keys as $key) {
            $value = $node[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected static function firstScalarDeep(array $node, array $keys, int $depth = 3): string|int|float|null
    {
        $value = self::firstScalar($node, $keys);
        if ($value !== null || $depth <= 0) {
            return $value;
        }

        foreach ($node as $nested) {
            if (is_array($nested)) {
                $value = self::firstScalarDeep($nested, $keys, $depth - 1);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    protected static function firstText(array $node, array $keys): ?string
    {
        $value = self::firstScalar($node, $keys);

        return $value !== null ? trim((string) $value) : null;
    }

    public function examSession(string $id): JsonResponse
    {
        return $this->dispatch(
            'GET',
            '/individual_labor_space/exam_sessions/'.rawurlencode(trim($id)),
            ['locale' => 'en']
        );
    }

    public function availableDates(?string $sessionId = null, array $params = []): JsonResponse
    {
        // The working SVP wizard uses category_id + optional city for the
        // available-date query. Keep the old session_id-only call compatible,
        // but do not mix session_id into the full-filter contract.
        if (empty($params) && $sessionId !== null && $sessionId !== '') {
            $params['session_id'] = $sessionId;
        }

        $params['country_id'] ??= (int) config('svp.country_id', 78);
        $params['per_page'] ??= 10000;

        return $this->dispatch(
            'GET',
            '/individual_labor_space/exam_sessions/available_dates',
            $this->normalizeQueryParams($params)
        );
    }

    public function temporarySeat(array $payload): JsonResponse
    {
        // The official PACC contract requires locale=en, a one-or-more-item
        // exam_session_id array, and methodology. The Laravel wizard performs
        // center validation before this call; test_center_id is intentionally
        // not sent because it is not part of the upstream hold contract.
        $rawSessionIds = $payload['exam_session_id'] ?? [];
        $sessionIds = is_array($rawSessionIds) ? $rawSessionIds : [$rawSessionIds];
        $sessionIds = array_values(array_filter(array_map(
            static fn ($id): mixed => is_numeric($id) ? (int) $id : trim((string) $id),
            $sessionIds
        ), static fn ($id): bool => $id !== '' && $id !== null));

        return $this->dispatch('POST', '/individual_labor_space/temporary_seats?locale=en', [
            'exam_session_id' => $sessionIds,
            'methodology' => $payload['methodology'] ?? config('svp.default_methodology', 'in_person'),
        ]);
    }

    public function validateReservation(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_reservations/validate');
    }

    public function reservationDetails(?string $id = null): JsonResponse
    {
        $uri = $id
            ? '/individual_labor_space/exam_reservations/'.$id
            : '/individual_labor_space/exam_reservations';

        return $this->dispatch('GET', $uri);
    }

    /**
     * Download the official SVP ticket PDF without exposing the bearer token
     * or the upstream API directly to the browser.
     */
    public function ticketPdf(string $reservationId, ?string $filename = null): \Symfony\Component\HttpFoundation\Response
    {
        $reservationId = trim($reservationId);

        if (! preg_match('/^[0-9]+$/', $reservationId)) {
            return response('Invalid reservation ID.', 422, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $uri = $this->apiPrefix.'/individual_labor_space/tickets/'.rawurlencode($reservationId).'/show_pdf?locale=en';

        try {
            $upstream = $this->client->get($uri);

            if ($upstream->failed()) {
                Log::warning('SVP ticket PDF request failed', [
                    'url' => $this->baseUrl.$uri,
                    'status' => $upstream->status(),
                    'body' => $upstream->body(),
                ]);
            }

            $headers = [
                'Content-Type' => $upstream->header('Content-Type') ?: 'application/pdf',
                'Cache-Control' => 'private, no-store',
            ];

            if ($filename !== null && trim($filename) !== '') {
                $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($filename)) ?: 'svp-ticket-'.$reservationId.'.pdf';
                $safeFilename = trim($safeFilename, '._');
                if (! str_ends_with(strtolower($safeFilename), '.pdf')) {
                    $safeFilename .= '.pdf';
                }
                $headers['Content-Disposition'] = 'attachment; filename="'.$safeFilename.'"';
            } elseif ($contentDisposition = $upstream->header('Content-Disposition')) {
                $headers['Content-Disposition'] = $contentDisposition;
            } else {
                $headers['Content-Disposition'] = 'attachment; filename="svp-ticket-'.$reservationId.'.pdf"';
            }

            return response($upstream->body(), $upstream->status(), $headers);
        } catch (ConnectionException $e) {
            Log::error('SVP ticket PDF connection error', [
                'url' => $this->baseUrl.$uri,
                'error' => $e->getMessage(),
            ]);

            return response('The SVP service is unreachable — please try again.', 503, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }
    }

    public function createReservation(array $payload): JsonResponse
    {
        return $this->dispatch('POST', '/individual_labor_space/exam_reservations', $payload);
    }

    public function cancelReservation(string $id): JsonResponse
    {
        return $this->dispatch('DELETE', '/individual_labor_space/exam_reservations/'.$id);
    }

    public function rescheduleReservation(string $id, array $payload): JsonResponse
    {
        return $this->dispatch('POST', '/individual_labor_space/exam_reservations/'.$id.'/reschedule', $payload);
    }

    public function useReservationCredit(array $payload): JsonResponse
    {
        return $this->dispatch('POST', '/individual_labor_space/reservation_credits/use', $payload);
    }

    public function occupations(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/occupations');
    }

    public function occupationsSearch(?string $search = null, int $page = 1, int $perPage = 1000): JsonResponse
    {
        $params = [];
        if ($search) {
            $params['search'] = $search;
        }
        $params['page'] = $page;
        $params['per_page'] = $perPage;

        return $this->dispatch('GET', '/individual_labor_space/occupations', $params);
    }

    public function cities(?string $categoryId = null): JsonResponse
    {
        $params = [
            'country_id' => (int) config('svp.country_id', 78),
            'per_page'   => 10000,
        ];

        if ($categoryId) {
            $params['category_id'] = $categoryId;
        }

        $response = $this->dispatch('GET', '/individual_labor_space/test_centers/cities', $params);
        $payload = json_decode($response->getContent(), true);
        $rawCities = is_array($payload)
            ? ($payload['cities'] ?? $payload['data']['cities'] ?? $payload['data'] ?? [])
            : [];

        $cities = collect(is_array($rawCities) ? $rawCities : [])
            ->map(static function ($city): ?array {
                if (is_string($city) || is_numeric($city)) {
                    $name = trim((string) $city);
                    return $name === '' ? null : ['id' => $name, 'name' => $name];
                }

                if (! is_array($city)) {
                    return null;
                }

                $name = trim((string) ($city['name'] ?? $city['city'] ?? $city['locality'] ?? ''));
                if ($name === '') {
                    return null;
                }

                return [
                    'id'           => (string) ($city['id'] ?? $name),
                    'name'         => $name,
                    'country_code' => $city['country_code'] ?? null,
                    'country_id'   => $city['country_id'] ?? config('svp.country_id', 78),
                ];
            })
            ->filter()
            ->unique(fn (array $city): string => mb_strtolower($city['name']))
            ->values();

        return response()->json([
            'success' => $payload['success'] ?? true,
            'data'    => $cities,
        ], $response->getStatusCode());
    }

    public function testCentersForFilters(?string $city = null, ?string $categoryId = null): JsonResponse
    {
        // The official center endpoint carries the real SVP id/name/address.
        // Do not synthesize a center from the city or read a stale local mirror.
        $params = [
            'country_id' => (int) config('svp.country_id', 78),
            'per_page'   => 10000,
        ];

        if ($categoryId) {
            $params['category_id'] = $categoryId;
        }

        if ($city !== null && trim($city) !== '') {
            $params['city'] = trim($city);
        }

        $response = $this->dispatch('GET', '/visitor_space/test_centers', $params);
        $payload = json_decode($response->getContent(), true);
        $rawCenters = is_array($payload)
            ? ($this->extractList($payload, ['test_centers', 'centers', 'items', 'results', 'records']) ?? [])
            : [];

        $centers = collect(is_array($rawCenters) ? $rawCenters : [])
            ->filter(fn ($center): bool => is_array($center))
            ->map(static function (array $center): ?array {
                $metadata = self::extractCenterMetadata($center);
                $address = is_array($center['address'] ?? null) ? $center['address'] : [];
                $id = trim((string) ($metadata['id'] ?? $center['id'] ?? $center['svp_id'] ?? ''));
                $name = trim((string) ($metadata['name'] ?? $center['name'] ?? $center['test_center_name'] ?? $id));
                $centerCity = trim((string) (
                    $metadata['city']
                    ?? $center['city']
                    ?? $center['locality']
                    ?? $address['city']
                    ?? $address['locality']
                    ?? ''
                ));

                if ($id === '' || $name === '') {
                    return null;
                }

                return [
                    'id'           => $id,
                    'name'         => $name,
                    'city'         => $centerCity,
                    'address'      => $center['address'] ?? null,
                    'status'       => $center['status'] ?? null,
                    'country_code' => $center['country_code'] ?? null,
                ];
            })
            ->filter()
            ->when($city, static fn ($collection) => $collection->filter(
                static fn (array $center): bool => mb_strtolower($center['city']) === mb_strtolower(trim($city))
            ))
            ->unique('id')
            ->values();

        // The live category-filtered endpoint can omit Dhaka centers 45 and 17
        // even though they are valid supplied SVP centers. For real numeric SVP
        // responses, expose the canonical seven-center set while preserving all
        // upstream fields for centers that were returned. Session lookup remains
        // scoped to the selected center, so this does not create cross-center
        // fallback behavior.
        if (mb_strtolower(trim((string) $city)) === 'dhaka') {
            $canonicalCenters = collect(config('svp.dhaka_test_centers', []))
                ->map(static fn (array $center): array => [
                    'id'           => (string) ($center['id'] ?? ''),
                    'name'         => (string) ($center['name'] ?? $center['id'] ?? ''),
                    'city'         => (string) ($center['city'] ?? 'Dhaka'),
                    'address'      => $center['address'] ?? null,
                    'status'       => $center['status'] ?? null,
                    'country_code' => $center['country_code'] ?? 'BD',
                ])
                ->filter(static fn (array $center): bool => $center['id'] !== '')
                ->values();

            $hasRealNumericCenter = $centers->contains(
                static fn (array $center): bool => ctype_digit((string) $center['id'])
            );

            // Keep synthetic test fixtures/non-SVP IDs untouched. Production
            // responses use numeric SVP IDs, including the supplied seven.
            if ($centers->isEmpty() || $hasRealNumericCenter) {
                $liveById = $centers->keyBy('id');
                $centers = $canonicalCenters->map(static function (array $center) use ($liveById): array {
                    $live = $liveById->get($center['id']);

                    return is_array($live) ? array_replace($center, $live) : $center;
                })->values();
            }
        }

        return response()->json([
            'success' => $payload['success'] ?? true,
            'data'    => $centers,
        ], $response->getStatusCode());
    }

    /**
     * Normalize the SVP occupations envelope, which may be a direct list or
     * nested under data.occupations depending on the upstream response.
     */
    private function extractOccupationRecords(array $payload): array
    {
        $records = [];
        $visit = function ($node, int $depth = 0) use (&$visit, &$records): void {
            if ($depth > 6 || ! is_array($node)) {
                return;
            }

            if (array_is_list($node)) {
                foreach ($node as $item) {
                    $visit($item, $depth + 1);
                }

                return;
            }

            // JSON:API-style occupations keep the record ID beside an
            // attributes object. Flatten only this explicit occupation node;
            // do not mistake a nested category object for an occupation.
            if (is_array($node['attributes'] ?? null)
                && (array_key_exists('categories', $node['attributes']) || array_key_exists('category', $node['attributes']))) {
                $records[] = array_merge($node['attributes'], [
                    'id' => $node['id'] ?? $node['occupation_id'] ?? null,
                    'occupation_id' => $node['occupation_id'] ?? $node['id'] ?? null,
                ]);
                return;
            }

            // An occupation is identified by its category collection. This
            // prevents a wrapper or a category record from being misclassified.
            if (array_key_exists('categories', $node) || array_key_exists('category', $node)) {
                $records[] = $node;
                return;
            }

            foreach (['data', 'occupations', 'occupation', 'items', 'results', 'result', 'attributes'] as $key) {
                if (array_key_exists($key, $node)) {
                    $visit($node[$key], $depth + 1);
                }
            }
        };

        $visit($payload);

        return collect($records)
            ->filter(static fn ($occupation): bool => is_array($occupation))
            ->map(static function (array $occupation): array {
                $id = trim((string) ($occupation['id'] ?? $occupation['occupation_id'] ?? ''));
                $name = trim((string) (
                    $occupation['name']
                    ?? $occupation['english_name']
                    ?? $occupation['arabic_name']
                    ?? $occupation['title']
                    ?? $id
                ));

                return $occupation + [
                    'id' => $id,
                    'name' => $name,
                ];
            })
            ->filter(static fn (array $occupation): bool => $occupation['id'] !== '')
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * Normalize category records returned by SVP. The live occupations endpoint
     * currently returns one singular `category` object, while older responses
     * used a plural `categories` list.
     */
    private function extractOccupationCategories(array $occupation): array
    {
        $rawCategories = $occupation['categories'] ?? null;
        if (! is_array($rawCategories) && is_array($occupation['attributes'] ?? null)) {
            $rawCategories = $occupation['attributes']['categories'] ?? $occupation['attributes']['category'] ?? null;
        }

        if (is_array($rawCategories) && ! array_is_list($rawCategories)) {
            $rawCategories = $rawCategories['data'] ?? $rawCategories['items'] ?? [$rawCategories];
        }

        if (! is_array($rawCategories)) {
            $rawCategories = is_array($occupation['category'] ?? null)
                ? [$occupation['category']]
                : [];
        }

        return collect($rawCategories)
            ->filter(static fn ($category): bool => is_array($category))
            ->map(static function (array $category): array {
                $attributes = is_array($category['attributes'] ?? null) ? $category['attributes'] : [];
                $rawId = $category['id'] ?? $category['category_id'] ?? $attributes['id'] ?? $attributes['category_id'] ?? '';
                $id = $attributes !== [] ? trim((string) $rawId) : $rawId;
                $name = trim((string) (
                    $category['name']
                    ?? $category['english_name']
                    ?? $category['arabic_name']
                    ?? $attributes['name']
                    ?? $attributes['english_name']
                    ?? $attributes['arabic_name']
                    ?? $id
                ));

                return array_replace($category, [
                    'id'   => $id,
                    'name' => $name,
                ]);
            })
            ->filter(static fn (array $category): bool => $category['id'] !== '' && $category['name'] !== '')
            ->unique('id')
            ->values()
            ->all();
    }

    public function categories(): JsonResponse
    {
        $occupationsResponse = $this->dispatch('GET', '/individual_labor_space/occupations', [
            'page' => 1,
            'per_page' => 10000,
        ]);
        if ($occupationsResponse->getStatusCode() < 200 || $occupationsResponse->getStatusCode() >= 300) {
            return $occupationsResponse;
        }
        $data = json_decode($occupationsResponse->getContent(), true);
        $occupations = $this->extractOccupationRecords(is_array($data) ? $data : []);

        $categories = collect($occupations)
            ->filter(static fn ($occupation): bool => is_array($occupation))
            ->flatMap(fn (array $occupation): array => $this->extractOccupationCategories($occupation))
            ->unique('id')
            ->values();

        return response()->json(['data' => $categories]);
    }

    public function categoriesForOccupation(?string $occupationId = null): JsonResponse
    {
        if (! $occupationId) {
            return $this->categories();
        }

        $occupationsResponse = $this->dispatch('GET', '/individual_labor_space/occupations', [
            'page' => 1,
            'per_page' => 10000,
        ]);
        if ($occupationsResponse->getStatusCode() < 200 || $occupationsResponse->getStatusCode() >= 300) {
            return $occupationsResponse;
        }
        $data = json_decode($occupationsResponse->getContent(), true);
        $occupations = $this->extractOccupationRecords(is_array($data) ? $data : []);

        $occupation = collect($occupations)->first(static fn ($item): bool => is_array($item) && (string) ($item['id'] ?? '') === (string) $occupationId);
        $categories = is_array($occupation) ? $this->extractOccupationCategories($occupation) : [];

        return response()->json(['data' => $categories]);
    }

    public function countries(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/countries');
    }

    public function examConstraints(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_constraints');
    }

    public function examEngines(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_engines');
    }

    // Payment / Notification / Verification
    public function validatePendingPayment(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/payments/validate_pending');
    }

    public function payments(?string $id = null): JsonResponse
    {
        $uri = $id
            ? '/individual_labor_space/payments/'.$id
            : '/individual_labor_space/payments';

        return $this->dispatch('GET', $uri);
    }

    public function createPayment(array $payload): JsonResponse
    {
        // The official SVP/HyperPay contract documented in the supplied
        // Postman collection requires locale=en and wraps the payment fields
        // in a top-level `payment` object.
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : $payload;

        return $this->dispatch('POST', '/individual_labor_space/payments?locale=en', [
            'payment' => [
                'payment_method' => $payment['payment_method'] ?? 'card',
                'payable_type' => $payment['payable_type'] ?? 'Reservation',
                'payable_id' => $payment['payable_id'] ?? null,
            ],
        ]);
    }

    public function getPaymentStatus(string $resourcePath): JsonResponse
    {
        $resourcePath = trim($resourcePath);

        // HyperPay returns a relative resourcePath. Never allow a callback to
        // turn this proxy into an arbitrary URL fetcher.
        if ($resourcePath === '' || ! str_starts_with($resourcePath, '/') || str_starts_with($resourcePath, '//') || filter_var($resourcePath, FILTER_VALIDATE_URL)) {
            return response()->json(['message' => 'Invalid payment resource path.'], 422);
        }

        return $this->dispatch('GET', $resourcePath);
    }

    public function updatePayment(string $id, array $payload): JsonResponse
    {
        return $this->dispatch('PUT', '/individual_labor_space/payments/'.$id, $payload);
    }

    public function notifications(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/notifications');
    }

/**
     * Fetch and flatten the raw exam_sessions list from the SVP API.
     *
     * Handles both response shapes the API has returned:
     *   { data: { exam_sessions: [...] } }
     *   { data: [...] }
     */
    protected function fetchExamSessions(array $params = []): array
    {
        $response = $this->dispatch('GET', '/individual_labor_space/exam_sessions', $params);
        $data = json_decode($response->getContent(), true);

        $sessions = $data['data']['exam_sessions']
            ?? $data['exam_sessions']
            ?? $data['data']
            ?? [];

        return is_array($sessions) ? array_values($sessions) : [];
    }
    public function verificationRequests(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/verification_requests');
    }

    // -----------------------------------------------------------------
    // HTTP dispatch
    // -----------------------------------------------------------------

    /**
     * Send the request to the SVP API and normalize the response into a
     * JsonResponse, logging failures for diagnostics.
     */
    protected function dispatch(string $method, string $uri, array $params = []): JsonResponse
    {
        $url = $this->apiPrefix.$uri;

        try {
            $response = match (strtoupper($method)) {
                'GET' => $this->client->get($url, $params),
                'POST' => $this->client->post($url, $params),
                'PUT' => $this->client->put($url, $params),
                'PATCH' => $this->client->patch($url, $params),
                'DELETE' => $this->client->delete($url, $params),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            if ($response->failed()) {
                Log::warning('SVP API request failed', [
                    'method' => $method,
                    'path' => $url,
                    'status' => $response->status(),
                    'response_keys' => is_array($response->json()) ? array_keys($response->json()) : [],
                ]);
            }

            return response()->json($response->json(), $response->status());
        } catch (ConnectionException $e) {
            Log::error('SVP API connection error', [
                'method' => $method,
                'url' => $this->baseUrl.$url,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The SVP service is unreachable — please try again.',
            ], 503);
        }
    }
}
