<?php

namespace App\Http\Controllers;

use App\Services\SvpOtp\SvpAutoSession;
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
    public function __construct(
        private SvpSessionVerifier $verifier,
        private SvpAutoSession $autoSession,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->verify($request);
    }

    /**
     * Warm the SVP bearer token while the wizard is opening, so the first hold
     * click does not have to wait for a full candidate login plus e-mail OTP.
     */
    public function status(Request $request): JsonResponse
    {
        $token = $this->autoSession->ensure($request);

        return response()->json([
            'connected' => is_string($token) && trim($token) !== '',
            'auto_login_enabled' => $this->autoSession->enabled($request),
            'login_url' => route('svp.login.form', ['force' => 1]),
        ]);
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

        // The wizard reads live T2Hub data without an SVP login, so the first
        // hold click can arrive with no (or an expired) SVP bearer token. Log
        // the candidate in automatically instead of failing the request with a
        // bare validation error, which the UI used to render as "unknown
        // center" even though the centre and date were correct.
        $token = $this->autoSession->ensure($request);
        if (! is_string($token) || trim($token) === '') {
            return response()->json([
                'success' => false,
                'verified' => false,
                'read_only' => true,
                'requires_svp_login' => true,
                'login_url' => route('svp.login.form', ['force' => 1]),
                'error' => 'SVP session expired and automatic sign-in is unavailable. Sign in with SVP again, then retry the hold.',
            ], 401);
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

            if (($result['verified'] ?? false) !== true) {
                Log::info('SVP pre-hold session check not verified', [
                    'exam_session_id' => $data['exam_session_id'],
                    'expected_test_center_id' => $data['expected_test_center_id'],
                    'expected_test_center_name' => $data['expected_test_center_name'] ?? null,
                    'expected_city' => $data['expected_city'] ?? null,
                    'expected_exam_date' => $data['expected_exam_date'] ?? null,
                    'expected_test_time' => $data['expected_test_time'] ?? null,
                    'upstream_status' => $result['upstream_status'] ?? null,
                    'actual' => $result['actual'] ?? null,
                    'checks' => $result['checks'] ?? null,
                ]);
            }

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
