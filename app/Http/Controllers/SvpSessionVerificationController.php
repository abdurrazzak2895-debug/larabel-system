<?php

namespace App\Http\Controllers;

use App\Services\SvpSessionVerifier;
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
    public function __construct(private SvpSessionVerifier $verifier)
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
            'expected_test_center_name' => ['nullable', 'string', 'max:255'],
            'expected_city' => ['nullable', 'string', 'max:120'],
            'expected_exam_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_test_time' => ['nullable', 'string', 'max:80'],
        ]);

        $token = $request->session()->get('svp_token');
        if (! is_string($token) || trim($token) === '') {
            throw ValidationException::withMessages([
                'svp' => 'SVP session expired. Sign in with SVP again before verifying a session.',
            ]);
        }

        try {
            // This preflight exists only to preview whether the hold-creation
            // endpoint (SvpHoldController::store) will accept this session, so
            // it must apply the exact same matching rules that call uses --
            // including the scoped city fallback for SVP deployments whose
            // authoritative exam_session detail omits center id/name entirely.
            // Using the stricter verify() here previously blocked sessions
            // that the real hold endpoint would have accepted.
            $result = $this->verifier->verifyForHold(
                $token,
                $data['exam_session_id'],
                $data['expected_test_center_id'],
                $data['expected_city'] ?? null,
                $data['expected_exam_date'] ?? null,
                $data['expected_test_center_name'] ?? null,
                $data['expected_test_time'] ?? null,
            );

            return response()->json($result, (int) $result['upstream_status']);
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
}

