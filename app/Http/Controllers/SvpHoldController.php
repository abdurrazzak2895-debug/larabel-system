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
            $response = $this->booking->temporarySeat($token, [
                'exam_session_id' => $data['exam_session_id'],
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
                'exam_session_id' => $data['exam_session_id'],
                'exam_date' => $data['exam_date'],
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
