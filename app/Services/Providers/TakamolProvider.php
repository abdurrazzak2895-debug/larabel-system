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
            ->withHeader('Host', (string) config('svp.base_url_host', 'svp-international-api.pacc.sa'))
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
        return $this->dispatch('GET', '/individual_labor_space/exam_sessions', $params);
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

        $sessionsResponse = $this->dispatch('GET', '/individual_labor_space/exam_sessions', $params);

        // Propagate real upstream failures (expired token, SVP down, connection
        // error, etc.) instead of silently returning success with an empty
        // list — that used to mask genuine outages as "no cities available".
        if ($sessionsResponse->getStatusCode() >= 300 || ($sessionsResponse->getData(true)['success'] ?? false) === false) {
            return $sessionsResponse;
        }

        $sessionsData = $sessionsResponse->getData(true);

        $cities = [];
        if (isset($sessionsData['data']['exam_sessions']) && is_array($sessionsData['data']['exam_sessions'])) {
            $citySet = [];
            foreach ($sessionsData['data']['exam_sessions'] as $session) {
                if (isset($session['test_center']['city']) && $session['test_center']['city']) {
                    $citySet[$session['test_center']['city']] = [
                        'name' => $session['test_center']['city'],
                        'country_code' => $session['test_center']['country_code'] ?? '',
                        'country_id' => $session['test_center']['country_id'] ?? null,
                    ];
                }
            }
            $cities = array_values($citySet);
        }

        return ResponseService::success(['cities' => $cities]);
    }

    public function testCentersForFilters(?string $city = null, ?string $occupationId = null): JsonResponse
    {
        $params = [];
        if ($city) {
            $params['city'] = $city;
        }
        if ($occupationId) {
            $params['occupation_id'] = $occupationId;
        }

        $sessionsResponse = $this->dispatch('GET', '/individual_labor_space/exam_sessions', $params);

        // Same rule as cities(): don't mask real upstream failures as "no
        // test centers available".
        if ($sessionsResponse->getStatusCode() >= 300 || ($sessionsResponse->getData(true)['success'] ?? false) === false) {
            return $sessionsResponse;
        }

        $sessionsData = $sessionsResponse->getData(true);

        $testCenters = [];
        if (isset($sessionsData['data']['exam_sessions']) && is_array($sessionsData['data']['exam_sessions'])) {
            $centerSet = [];
            foreach ($sessionsData['data']['exam_sessions'] as $session) {
                $center = $session['test_center'] ?? null;
                if ($center && isset($center['id']) && isset($center['name'])) {
                    $centerSet[$center['id']] = [
                        'id' => $center['id'],
                        'name' => $center['name'],
                        'city' => $center['city'] ?? '',
                        'country_code' => $center['country_code'] ?? '',
                    ];
                }
            }
            $testCenters = array_values($centerSet);
        }

        return ResponseService::success(['test_centers' => $testCenters]);
    }

    public function reservations(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_reservations');
    }

    public function categories(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/categories');
    }

    public function categoriesForOccupation(?string $occupationId = null): JsonResponse
    {
        $params = $occupationId ? ['occupation_id' => $occupationId] : [];

        return $this->dispatch('GET', '/individual_labor_space/categories', $params);
    }

    public function examConstraints(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_constraints');
    }

    public function examEngines(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/exam_engines');
    }

    public function countries(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/countries');
    }

    // -----------------------------------------------------------------
    // Payment / Notification / Verification
    // -----------------------------------------------------------------

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

    public function verificationRequests(): JsonResponse
    {
        return $this->dispatch('GET', '/individual_labor_space/verification_requests');
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    /**
     * @param  'GET'|'POST'|'PUT'|'PATCH'|'DELETE'  $method
     * @param  array<string, mixed>  $payload
     */
    protected function dispatch(string $method, string $uri, array $payload = []): JsonResponse
    {
        try {
            // The SVP endpoints are versioned under /api/v1 (e.g. /api/v1/sessions/login).
            $uri = str_starts_with($uri, $this->apiPrefix) ? $uri : $this->apiPrefix.$uri;

            // The frontend always sends ?locale=en — the API expects it.
            $separator = str_contains($uri, '?') ? '&' : '?';
            $uri .= $separator.'locale=en';

            $response = match (strtoupper($method)) {
                'GET'    => $this->client->get($uri, $payload),
                'POST'   => $this->client->post($uri, $payload),
                'PUT'    => $this->client->put($uri, $payload),
                'PATCH'  => $this->client->patch($uri, $payload),
                'DELETE' => $this->client->delete($uri),
                default  => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
            };

            if ((bool) config('svp.log_requests', true)) {
                Log::channel((string) config('svp.log_channel', 'daily'))->info('SVP API request', [
                    'method'  => $method,
                    'uri'     => $uri,
                    'payload' => $payload,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
            }

            return ResponseService::fromHttpClient($response);
        } catch (\Throwable $e) {
            Log::channel((string) config('svp.log_channel', 'daily'))->error('SVP API request exception', [
                'method' => $method,
                'uri'    => $uri,
                'error'  => $e->getMessage(),
                'class'  => get_class($e),
            ]);

            // Also surface on the default channel (stderr on Railway) so the
            // underlying connection error is visible in the platform logs.
            Log::error('SVP API request exception', [
                'method' => $method,
                'uri'    => $uri,
                'error'  => $e->getMessage(),
                'class'  => get_class($e),
            ]);

            return ResponseService::error(
                message: 'Unable to reach the SVP API. Please try again later.',
                statusCode: 503
            );
        }
    }
}
