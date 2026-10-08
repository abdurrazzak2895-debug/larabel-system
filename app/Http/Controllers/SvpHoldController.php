<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use App\Services\SvpOtp\SvpAutoSession;
use App\Services\SvpSessionVerifier;
use App\Services\T2Hub\T2HubBookingData;
use App\Services\SvpTemporaryHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SvpHoldController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private SvpTemporaryHoldService $holds,
        private SvpSessionVerifier $sessionVerifier,
        private SvpAutoSession $autoSession,
        private T2HubBookingData $t2hub,
    ) {
    }

    /**
     * Create one temporary seat hold through SVP.
     *
     * T2Hub supplies the occupation, city, date, center and opaque session
     * identity. The selected center-scoped T2Hub snapshot is validated before
     * the SVP temporary_seats mutation; SVP receives the scalar session ID and
     * physical center ID, while language/methodology are sent at confirmation.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'occupation_id' => ['required', 'string', 'max:100'],
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'test_center_id' => ['required', 'string', 'max:100'],
            'test_center_name' => ['nullable', 'string', 'max:255'],
            'test_center_time' => ['required', 'string', 'max:80'],
            'exam_session_id' => ['required', 'string', 'max:255'],
            'exam_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $requestId = (string) ($request->header('X-Request-ID') ?: (string) \Illuminate\Support\Str::uuid());
        $upstreamStatus = null;
        $upstreamBody = null;

        // The wizard is driven by live T2Hub data, so a hold can be requested
        // after the SVP bearer token has expired. Renew it automatically rather
        // than refusing a session the user just selected.
        $token = $this->autoSession->ensure($request);
        if (! is_string($token) || $token === '') {
            return response()->json([
                'success' => false,
                'requires_svp_login' => true,
                'login_url' => route('svp.login.form', ['force' => 1]),
                'error' => 'SVP session expired and automatic sign-in is unavailable. Sign in with SVP again, then retry.',
            ], 401);
        }

        try {
            // Validate against the exact center-scoped session list that was
            // returned to this browser. If an upstream session unexpectedly
            // carries another center, resolve the next dated session from the
            // requested center only. Never silently switch centers.
            $context = [
                'category_id' => $data['category_id'],
                'city' => $data['city'],
                'test_center_id' => $data['test_center_id'],
            ];
            $selectedSession = $this->holds->resolveCenterSession(
                $request,
                $context,
                $data['exam_session_id'],
                $data['exam_date']
            );
            // The browser snapshot is the primary source. When it is missing
            // (for example after a cache clear or a fresh tab), re-read the live
            // centre-scoped T2Hub list so a session the user just selected is
            // still resolvable instead of failing with a misleading error.
            if ($selectedSession === null && $this->t2hub->enabled()) {
                try {
                    $selectedSession = $this->t2hub->sessionSnapshot(
                        $data['category_id'],
                        $data['city'],
                        $data['exam_date'],
                        $data['test_center_id'],
                        $data['exam_session_id'],
                    );
                } catch (\Throwable $e) {
                    $selectedSession = null;
                }
            }

            $selectedSessionDate = $this->sessionDate($selectedSession);
            $resolvedSessionId = (string) ($selectedSession['id'] ?? '');

            if ($selectedSession === null || $selectedSessionDate === null || $resolvedSessionId === '') {
                return response()->json([
                    'success' => false,
                    'error' => 'No available SVP session remains at the selected test center on or after the requested date.',
                ], 422);
            }

            $selectedCenterId = $this->sessionCenterId($selectedSession);
            if ($selectedCenterId !== '' && $selectedCenterId !== (string) $data['test_center_id']) {
                return response()->json([
                    'success' => false,
                    'error' => 'SVP returned no session at the selected test center. Choose another date from this same center.',
                ], 422);
            }

            if ($resolvedSessionId === (string) $data['exam_session_id'] && $selectedSessionDate !== $data['exam_date']) {
                return response()->json([
                    'success' => false,
                    'error' => 'The exam date must match the selected live SVP session date.',
                ], 422);
            }

            if ($this->t2hub->enabled()) {
                // T2Hub is the source of occupation, city, date, center and
                // session availability. Do not ask the candidate SVP account
                // to resolve the T2Hub session through a different detail
                // endpoint; that cross-system lookup is not authoritative.
                $verification = [
                    'success' => true,
                    'verified' => true,
                    'upstream_status' => 200,
                    'actual' => [
                        'test_center_id' => (string) $data['test_center_id'],
                        'test_center_name' => $data['test_center_name'] ?? null,
                        'city' => (string) $data['city'],
                        'test_time' => $data['test_center_time'],
                    ],
                    'expected' => [
                        'test_center_id' => (string) $data['test_center_id'],
                        'test_center_name' => $data['test_center_name'] ?? null,
                        'city' => (string) $data['city'],
                        'exam_date' => $selectedSessionDate,
                        'test_time' => $data['test_center_time'],
                    ],
                ];
            } else {
                // Non-T2Hub deployments use the candidate-authenticated SVP
                // detail endpoint as the session authority.
                $verification = $this->sessionVerifier->verifyForHold(
                    $token,
                    $resolvedSessionId,
                    (string) $data['test_center_id'],
                    (string) $data['city'],
                    $selectedSessionDate,
                    (string) ($data['test_center_name'] ?? ''),
                    (string) $data['test_center_time'],
                    // The local browser snapshot is not a T2Hub authority in
                    // this legacy path; only T2Hub mode may bypass SVP detail.
                    snapshotConfirmed: false,
                );
            }

            if (! $verification['success']) {
                if ((int) ($verification['upstream_status'] ?? 0) === 401) {
                    return response()->json([
                        'success' => false,
                        'requires_svp_login' => true,
                        'login_url' => route('svp.login.form', ['force' => 1]),
                        'error' => 'Your SVP session has expired. Sign in with SVP again, then retry this same session.',
                    ], 401);
                }

                return response()->json([
                    'success' => false,
                    'error' => 'SVP could not verify the selected session before creating a hold. Please refresh the available sessions and try again.',
                ], 502);
            }

            if (! $verification['verified']) {
                $actualCenterName = data_get($verification, 'actual.test_center_name') ?: 'unknown center';
                $expectedCenterName = ($data['test_center_name'] ?? '') ?: 'the selected test center';

                return response()->json([
                    'success' => false,
                    'error' => sprintf(
                        'Blocked: SVP session does not match the selected center, date, or %s slot.',
                        $data['test_center_time']
                    ),
                    'verification' => $verification,
                ], 422);
            }

            $response = $this->booking->temporarySeat($token, [
                // The live-verified PACC hold contract accepts one opaque
                // session id plus the selected physical center id. The
                // methodology is sent later with exam_reservations.
                'exam_session_id' => $resolvedSessionId,
                'test_center_id' => (string) $data['test_center_id'],
            ]);

            $upstreamStatus = $response->getStatusCode();
            $upstreamBody = $this->safeResponseBody($response);

            $payload = $response->getData(true);
            $hold = $this->extractHold($payload);

            if ($response->getStatusCode() === 401
                || $response->getStatusCode() < 200
                || $response->getStatusCode() >= 300
                || $hold === null) {
                Log::warning('SVP temporary hold upstream response was unusable', [
                    'request_id' => $requestId,
                    'test_center_id' => $data['test_center_id'],
                    'exam_session_id' => $data['exam_session_id'],
                    'upstream_status' => $upstreamStatus,
                    'upstream_response_body' => $upstreamBody,
                ]);
            }

            if ($response->getStatusCode() === 401) {
                $this->autoSession->forgetCurrent($request);
                return response()->json([
                    'success' => false,
                    'requires_svp_login' => true,
                    'login_url' => route('svp.login.form', ['force' => 1]),
                    'error' => 'Your SVP session has expired. Sign in with SVP again, then retry this same session.',
                ], 401);
            }

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $hold === null) {
                return response()->json([
                    'success' => false,
                    'request_id' => $requestId,
                    'error' => $this->describeUpstreamFailure(
                        is_array($payload) ? $payload : [],
                        $response->getStatusCode()
                    ),
                ], $response->getStatusCode() >= 400 ? $response->getStatusCode() : 502);
            }

            $selection = [
                'occupation_id' => $data['occupation_id'],
                'category_id' => $data['category_id'],
                'city' => $data['city'],
                'test_center_id' => $data['test_center_id'],
                'test_center_name' => data_get($verification, 'actual.test_center_name') ?: ($data['test_center_name'] ?? null),
                'test_center_time' => data_get($verification, 'expected.test_time') ?: $data['test_center_time'],
                'exam_session_id' => $resolvedSessionId,
                'exam_session_name' => $selectedSession['name'] ?? $selectedSession['session_name'] ?? null,
                'exam_date' => $selectedSessionDate,
            ];

            $rememberedHold = $this->holds->remember(
                $request,
                $selection,
                $hold['id'],
                $hold['expired_at'] ?? $hold['expires_at'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $rememberedHold,
                'selection' => $selection,
                'resolved_from_session_id' => $data['exam_session_id'] !== $resolvedSessionId ? $data['exam_session_id'] : null,
            ], $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP temporary hold failed', [
                'request_id' => $requestId,
                'test_center_id' => $data['test_center_id'],
                'exam_session_id' => $data['exam_session_id'],
                'upstream_status' => $upstreamStatus,
                'upstream_response_body' => $upstreamBody,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'request_id' => $requestId,
                'error' => 'Unable to create a temporary SVP hold.',
            ], 503);
        }
    }

    /**
     * Capture the complete upstream response body for diagnostics while
     * redacting fields that could contain credentials, tokens, or payment data.
     */
    private function safeResponseBody(JsonResponse $response): mixed
    {
        try {
            $body = $response->getData(true);
        } catch (\Throwable) {
            $body = $response->getContent();
        }

        return $this->redactSensitive($body);
    }

    private function redactSensitive(mixed $value): mixed
    {
        $sensitiveKeys = [
            'authorization', 'access_token', 'refresh_token', 'token', 'bearer',
            'password', 'passwd', 'secret', 'otp', 'cookie', 'set-cookie',
            'card_number', 'cardNumber', 'cvv', 'cvc', 'iban',
        ];

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $normalizedKey = strtolower(str_replace(['-', '_'], '', (string) $key));
                $isSensitive = in_array($normalizedKey, array_map(
                    static fn (string $s): string => strtolower(str_replace(['-', '_'], '', $s)),
                    $sensitiveKeys
                ), true);
                $redacted[$key] = $isSensitive ? '[REDACTED]' : $this->redactSensitive($item);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return preg_replace(
                '/(Bearer\s+)[^\s,]+/i',
                '$1[REDACTED]',
                $value
            ) ?? $value;
        }

        return $value;
    }

    /**
     * Keep the useful SVP validation reason while avoiding a generic UI error.
     * This is especially important for an already-active temporary hold.
     */
    private function describeUpstreamFailure(array $payload, int $status): string
    {
        $messages = [];
        $errors = $payload['errors'] ?? null;

        if (is_array($errors)) {
            foreach ($errors as $field => $fieldMessages) {
                foreach ((array) $fieldMessages as $message) {
                    if (is_scalar($message)) {
                        $messages[] = is_string($field)
                            ? $field . ': ' . (string) $message
                            : (string) $message;
                    }
                }
            }
        }

        foreach (['message', 'detail', 'error'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                $messages[] = (string) $payload[$key];
            }
        }

        $messages = array_values(array_unique(array_filter(array_map('trim', $messages))));
        return $messages === []
            ? 'SVP did not return a valid temporary hold (HTTP ' . $status . ').'
            : 'SVP rejected the temporary hold (HTTP ' . $status . '): ' . implode(' ', array_slice($messages, 0, 3));
    }

    private function sessionCenterId(?array $session): string
    {
        if ($session === null) {
            return '';
        }

        $center = is_array($session['test_center'] ?? null) ? $session['test_center'] : [];
        return (string) ($session['test_center_id'] ?? $session['site_id'] ?? $center['id'] ?? '');
    }

    /**
     * Return the canonical YYYY-MM-DD date supplied by a normalized SVP session.
     *
     * @param array<string, mixed>|null $session
     */
    private function sessionDate(?array $session): ?string
    {
        if ($session === null) {
            return null;
        }

        foreach (['exam_date', 'test_date', 'date', 'start_date_in_browser_time_zone', 'start_date_in_tc_time_zone'] as $key) {
            $value = $session[$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                return substr($value, 0, 10);
            }
        }

        return null;
    }

    /**
     * Normalize common SVP temporary-seat response envelopes.
     *
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function extractHold(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ([
            $payload,
            $payload['temporary_seat'] ?? null,
            $payload['data'] ?? null,
            data_get($payload, 'data.temporary_seat'),
        ] as $candidate) {
            if (is_array($candidate) && isset($candidate['id']) && is_scalar($candidate['id'])) {
                return $candidate;
            }
        }

        return null;
    }
}
