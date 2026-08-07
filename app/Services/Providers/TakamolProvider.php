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

    ...
