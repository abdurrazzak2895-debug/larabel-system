<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Models\TestCenter;
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

    public function userBalance(string $userId): JsonResponse
    {
        return $this->dispatch('GET', '/users/'.$userId.'/balance');
    }

    // -----------------------------------------------------------------
    // Exam
    // -----------------------------------------------------------------

    public function examSessions(array $params = []): JsonResponse
    {
        $response = $this->dispatch('GET', '/individual_labor_space/exam_sessions', $params);

        // SVP session nodes do not carry a `name` (only an opaque id, a
        // start date and category), yet the wizard renders each option with
        // `item['name']`. Attach a readable label while keeping the exact
        // response envelope and every original field intact.
        $payload = json_decode($response->getContent(), true);
        if (! is_array($payload) || ! isset($payload['data'])) {
            return $response;
        }

        $data = $payload['data'];
        $mapper = static fn ($node): array => self::formatSessionName($node);

        if (isset($data['exam_sessions']) && is_array($data['exam_sessions'])) {
            $data['exam_sessions'] = array_map($mapper, array_values($data['exam_sessions']));
        } elseif (is_array($data)) {
            $data = array_map($mapper, array_values($data));
        }

        return response()->json(array_merge($payload, ['data' => $data]), $response->getStatusCode());
    }

    /**
     * Return a session node plus a human-readable `name` label, preserving all
     * other fields (id, start dates, category, test_center, …).
     */
    protected static function formatSessionName(array $node): array
    {
        if (isset($node['name']) && is_string($node['name']) && $node['name'] !== '') {
            return $node;
        }

        $date     = $node['start_date_in_browser_time_zone'] ?? null;
        $city     = is_array($node['test_center'] ?? null)
            ? ($node['test_center']['city'] ?? null)
            : null;
        $category = is_array($node['category'] ?? null)
            ? ($node['category']['english_name'] ?? $node['category']['arabic_name'] ?? null)
            : null;

        $label = trim(implode(' • ', array_filter(
            [$date, $city, $category],
            static fn ($v) => is_string($v) && $v !== ''
        ))) ?: 'Exam session';

        $node['name'] = $label;

        return $node;
    }

    public function examSession(string $id): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_sessions/'.$id);
    }

    public function availableDates(?string $sessionId = null): JsonResponse
    {
        $params = $sessionId ? ['session_id' => $sessionId] : [];

        return $this->dispatch('GET', '/individual_labor_space/exam_sessions/available_dates', $params);
    }

    public function temporarySeat(array $payload): JsonResponse
    {
        return $this->dispatch('POST', '/individual_labor_space/temporary_seats', $payload);
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

    public function cities(?string $occupationId = null): JsonResponse
    {
        $params = $occupationId ? ['occupation_id' => $occupationId] : [];

        $cities = collect($this->fetchExamSessions($params))
            ->pluck('test_center')
            ->filter()
            ->map(static fn ($tc) => [
                'name'         => $tc['city'] ?? null,
                'country_code' => $tc['country_code'] ?? null,
                'country_id'   => $tc['country_id'] ?? null,
            ])
            ->filter(fn (array $city) => (bool) $city['name'])
            ->unique('name')
            ->values();

        return response()->json(['data' => $cities]);
    }

    public function testCentersForFilters(?string $city = null, ?string $occupationId = null): JsonResponse
    {
        // The SVP exam_sessions endpoint does not reliably support a `city`
        // filter, so we always fetch for the occupation and narrow client-side.
        $params = array_filter(['occupation_id' => $occupationId]);

        // The session payload only exposes test_center as {city, country_code, …}
        // with no real id/name, so we derive the cities that actually have
        // sessions and then map each city to the genuine SVP test centers
        // maintained in the local test_centers table (real svp_id + name,
        // seeded from the official dataset via TestCenterSeeder).
        $sessions = $this->fetchExamSessions($params);

        $sessionCities = collect($sessions)
            ->pluck('test_center')
            ->filter()
            ->pluck('city')
            ->filter()
            ->map('strval')
            ->unique()
            ->values();

        if (! empty($city)) {
            // Respect the requested city so the dropdown is never empty.
            $targetCities = collect([$city]);
        } elseif ($sessionCities->isNotEmpty()) {
            $targetCities = $sessionCities;
        } else {
            $targetCities = collect([]);
        }

        $testCenters = collect();

        foreach ($targetCities as $targetCity) {
            $centers = TestCenter::where('city', $targetCity)
                ->orderBy('svp_id', 'asc')
                ->get(['svp_id', 'name', 'city', 'country_code']);

            if ($centers->isEmpty()) {
                // No seeded center for this city — represent the city itself
                // so the wizard stays usable (id = city, name = city).
                $centers = collect([
                    ['svp_id' => $targetCity, 'name' => $targetCity, 'city' => $targetCity, 'country_code' => null],
                ]);
            }

            $testCenters = $testCenters->concat(
                $centers->map(fn ($c) => [
                    'id'           => (string) ($c['svp_id'] ?? $targetCity),
                    'name'         => (string) ($c['name'] ?? $c['svp_id'] ?? $targetCity),
                    'city'         => (string) ($c['city'] ?? $targetCity),
                    'country_code' => $c['country_code'] ?? null,
                ])
            );
        }

        return response()->json(['data' => $testCenters->values()]);
    }

    public function categories(): JsonResponse
    {
        $occupationsResponse = $this->dispatch('GET', '/individual_labor_space/occupations');
        $data = json_decode($occupationsResponse->getContent(), true);
        $occupations = $data['data'] ?? $data ?? [];

        $categories = collect($occupations)
            ->flatMap(fn ($occupation) => $occupation['categories'] ?? [])
            ->unique('id')
            ->values();

        return response()->json(['data' => $categories]);
    }

    public function categoriesForOccupation(?string $occupationId = null): JsonResponse
    {
        if (! $occupationId) {
            return $this->categories();
        }

        $occupationsResponse = $this->dispatch('GET', '/individual_labor_space/occupations');
        $data = json_decode($occupationsResponse->getContent(), true);
        $occupations = $data['data'] ?? $data ?? [];

        $occupation = collect($occupations)->firstWhere('id', $occupationId);
        $categories = $occupation['categories'] ?? [];

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
        return $this->dispatch('POST', '/individual_labor_space/payments', $payload);
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
