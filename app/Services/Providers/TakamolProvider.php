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

        $sessions = $this->extractList($payload, ['exam_sessions', 'sessions', 'available_sessions']);
        if ($sessions === null) {
            return $response;
        }

        $requestedCenterId = isset($params['test_center_id']) ? (string) $params['test_center_id'] : null;
        $sessions = array_map(
            static fn ($node): array => is_array($node) ? self::formatSessionName($node) : ['id' => (string) $node, 'name' => (string) $node],
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
        $candidates = [
            data_get($payload, 'data.exam_sessions'),
            data_get($payload, 'data.sessions'),
            data_get($payload, 'data.available_sessions'),
            data_get($payload, 'data'),
        ];

        foreach (array_merge($keys, []) as $key) {
            $candidates[] = $payload[$key] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values($candidate);
            }
        }

        return null;
    }

    /**
     * Return a session node plus a human-readable `name` label, preserving all
     * other fields (id, start dates, category, test_center, …).
     */
    protected static function formatSessionName(array $node): array
    {
        $center = self::extractCenterMetadata($node);
        $centerId = $center['id'] ?? null;
        $centerName = $center['name'] ?? null;
        $city = $center['city'] ?? null;
        $date = $node['test_date']
            ?? $node['date']
            ?? $node['start_date_in_browser_time_zone']
            ?? $node['start_date_in_tc_time_zone']
            ?? null;
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

    protected static function firstText(array $node, array $keys): ?string
    {
        $value = self::firstScalar($node, $keys);

        return $value !== null ? trim((string) $value) : null;
    }

    public function examSession(string $id): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_sessions/'.$id);
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

        $response = $this->dispatch('GET', '/visitor_space/test_centers', $params);
        $payload = json_decode($response->getContent(), true);
        $rawCenters = is_array($payload)
            ? ($payload['test_centers'] ?? $payload['data']['test_centers'] ?? $payload['data'] ?? [])
            : [];

        $centers = collect(is_array($rawCenters) ? $rawCenters : [])
            ->filter(fn ($center): bool => is_array($center))
            ->map(static function (array $center): ?array {
                $address = is_array($center['address'] ?? null) ? $center['address'] : [];
                $id = trim((string) ($center['id'] ?? $center['svp_id'] ?? ''));
                $name = trim((string) ($center['name'] ?? $center['test_center_name'] ?? $id));
                $centerCity = trim((string) (
                    $center['city']
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
        $container = $payload['data'] ?? $payload;

        if (is_array($container) && is_array($container['occupations'] ?? null)) {
            return $container['occupations'];
        }

        if (is_array($payload['occupations'] ?? null)) {
            return $payload['occupations'];
        }

        return is_array($container) && array_is_list($container) ? $container : [];
    }

    /**
     * Normalize category records returned by SVP. The live occupations endpoint
     * currently returns one singular `category` object, while older responses
     * used a plural `categories` list.
     */
    private function extractOccupationCategories(array $occupation): array
    {
        $rawCategories = $occupation['categories'] ?? null;

        if (! is_array($rawCategories)) {
            $rawCategories = is_array($occupation['category'] ?? null)
                ? [$occupation['category']]
                : [];
        }

        return collect($rawCategories)
            ->filter(static fn ($category): bool => is_array($category))
            ->map(static function (array $category): array {
                $id = trim((string) ($category['id'] ?? $category['category_id'] ?? ''));
                $name = trim((string) (
                    $category['name']
                    ?? $category['english_name']
                    ?? $category['arabic_name']
                    ?? $id
                ));

                return $category + [
                    'id'   => $id,
                    'name' => $name,
                ];
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
                    'url' => $this->baseUrl.$url,
                    'status' => $response->status(),
                    'body' => $response->body(),
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
