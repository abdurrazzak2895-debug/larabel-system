<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use App\Services\SvpTemporaryHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SvpHoldController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private SvpTemporaryHoldService $holds
    ) {
    }

    /**
     * Create one temporary seat hold through SVP.
     *
     * The upstream temporary_seats endpoint accepts only the selected
     * session and center identifiers. The other fields are validated here
     * so the UI cannot create a hold for an unrelated selection.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'occupation_id' => ['required', 'string', 'max:100'],
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'test_center_id' => ['required', 'string', 'max:100'],
            'test_center_name' => ['nullable', 'string', 'max:255'],
            'exam_session_id' => ['required', 'string', 'max:255'],
            'exam_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $token = $request->session()->get('svp_token');
        if (! is_string($token) || $token === '') {
            return response()->json(['error' => 'SVP session expired.'], 401);
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

            $response = $this->booking->temporarySeat($token, [
                'exam_session_id' => $resolvedSessionId,
                'test_center_id' => $data['test_center_id'],
            ]);

            $payload = $response->getData(true);
            $hold = $this->extractHold($payload);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $hold === null) {
                return response()->json([
                    'success' => false,
                    'error' => 'SVP did not return a valid temporary hold.',
                ], $response->getStatusCode() >= 400 ? $response->getStatusCode() : 502);
            }

            $selection = [
                'occupation_id' => $data['occupation_id'],
                'category_id' => $data['category_id'],
                'city' => $data['city'],
                'test_center_id' => $data['test_center_id'],
                'test_center_name' => $data['test_center_name'] ?? null,
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
                'test_center_id' => $data['test_center_id'],
                'exam_session_id' => $data['exam_session_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Unable to create a temporary SVP hold.',
            ], 503);
        }
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
