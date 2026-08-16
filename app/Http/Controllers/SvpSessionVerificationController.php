<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Read-only diagnostics for mapping opaque SVP exam-session IDs to centers.
 *
 * The external call is GET /individual_labor_space/exam_sessions/{id}; this
 * controller never creates a hold, reservation, payment, or other mutation.
 */
class SvpSessionVerificationController extends Controller
{
    public function __construct(private BookingService $booking)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->verify($request);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_session_id' => ['required', 'string', 'max:255'],
            'expected_test_center_id' => ['required', 'string', 'max:80'],
            'expected_city' => ['nullable', 'string', 'max:120'],
        ]);

        $token = $request->session()->get('svp_token');
        if (! is_string($token) || trim($token) === '') {
            throw ValidationException::withMessages([
                'svp' => 'SVP session expired. Sign in with SVP again before verifying a session.',
            ]);
        }

        try {
            $response = $this->booking->examSession($token, $data['exam_session_id']);
            $payload = $response->getData(true);
            $status = $response->getStatusCode();
            $session = $this->extractSession($payload);
            $center = $this->extractCenter($session);
            $actualSessionId = $this->firstValue($session, [
                'id', 'exam_session_id', 'session_id',
            ]);
            $expectedCenterId = (string) $data['expected_test_center_id'];
            $actualCenterId = $center['id'];
            $centerMatch = $actualCenterId !== null && $actualCenterId === $expectedCenterId;
            $cityMatch = ! isset($data['expected_city']) || trim((string) $data['expected_city']) === ''
                ? null
                : mb_strtolower((string) $center['city']) === mb_strtolower(trim((string) $data['expected_city']));

            return response()->json([
                'success' => $status >= 200 && $status < 300,
                'verified' => $status >= 200 && $status < 300 && $centerMatch && ($cityMatch !== false),
                'read_only' => true,
                'upstream_status' => $status,
                'session' => [
                    'id' => $actualSessionId ?? (string) $data['exam_session_id'],
                    'exam_date' => $this->firstValue($session, [
                        'exam_date', 'test_date', 'date',
                        'start_date_in_browser_time_zone', 'start_date_in_tc_time_zone',
                    ]),
                    'methodology' => $session['methodology'] ?? null,
                ],
                'expected' => [
                    'test_center_id' => $expectedCenterId,
                    'city' => $data['expected_city'] ?? null,
                ],
                'actual' => [
                    'test_center_id' => $actualCenterId,
                    'test_center_name' => $center['name'],
                    'city' => $center['city'],
                ],
                'checks' => [
                    'center_match' => $centerMatch,
                    'city_match' => $cityMatch,
                    'session_center_present' => $actualCenterId !== null,
                ],
            ], $status);
        } catch (\Throwable $e) {
            Log::warning('SVP exam session center verification failed', [
                'exam_session_id' => $data['exam_session_id'],
                'expected_test_center_id' => $data['expected_test_center_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'verified' => false,
                'read_only' => true,
                'error' => 'Unable to verify the SVP exam session center.',
            ], 503);
        }
    }

    /** @param array<string, mixed> $payload */
    private function extractSession(array $payload): array
    {
        $candidates = [
            data_get($payload, 'data.exam_session'),
            data_get($payload, 'data.session'),
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

    /** @param array<string, mixed> $session */
    private function extractCenter(array $session): array
    {
        $nested = [];
        foreach (['test_center', 'center', 'site'] as $key) {
            if (is_array($session[$key] ?? null)) {
                $nested[] = $session[$key];
            }
        }

        $id = $this->firstValue($session, ['test_center_id', 'site_id', 'center_id']);
        $name = $this->firstValue($session, ['test_center_name', 'site_name', 'center_name']);
        $city = $this->firstValue($session, ['test_center_city', 'site_city', 'city']);

        foreach ($nested as $center) {
            $id ??= $this->firstValue($center, ['id', 'test_center_id', 'site_id', 'center_id']);
            $name ??= $this->firstValue($center, ['name', 'test_center_name', 'site_name', 'center_name']);
            $city ??= $this->firstValue($center, ['city', 'locality', 'test_center_city', 'site_city']);
        }

        return [
            'id' => $id !== null && $id !== '' ? (string) $id : null,
            'name' => $name !== null && $name !== '' ? (string) $name : null,
            'city' => $city !== null && $city !== '' ? (string) $city : null,
        ];
    }

    /** @param array<string, mixed> $record */
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

