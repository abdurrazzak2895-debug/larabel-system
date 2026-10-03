<?php

namespace App\Http\Controllers;

use App\Services\SvpOtp\SvpAutoSession;
use App\Services\T2Hub\T2HubBookingData;
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
        private T2HubBookingData $t2hub,
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
            'category_id' => ['nullable', 'string', 'max:100'],
        ]);

        // Establish the provenance of the submitted session ID server-side: it
        // must be present in the live, centre-scoped session list for the
        // requested city and date. SVP sometimes returns no test centre at all
        // on the exam-session detail, and without this check such a session
        // could not be confirmed at all.
        $snapshotConfirmed = false;
        $centerCity = null;
        if (! empty($data['category_id']) && $this->t2hub->enabled()) {
            try {
                $snapshotRow = $this->t2hub->sessionSnapshot(
                    (string) $data['category_id'],
                    (string) ($data['expected_city'] ?? ''),
                    (string) ($data['expected_exam_date'] ?? ''),
                    $data['expected_test_center_id'],
                    $data['exam_session_id'],
                );
                $snapshotConfirmed = $snapshotRow !== null;
                $centerCity = trim((string) (
                    data_get($snapshotRow, 'raw.center_city')
                    ?? data_get($snapshotRow, 'raw.site_city')
                    ?? data_get($snapshotRow, 'raw.test_center.city')
                    ?? ''
                )) ?: null;
            } catch (\Throwable $e) {
                $snapshotConfirmed = false;
                $centerCity = null;
            }
        }

        // T2Hub is the catalogue authority for this deployment. Its opaque
        // session IDs are intentionally not re-read through the candidate's
        // SVP account: the two systems can expose different session-detail
        // endpoints while the same T2Hub session ID remains valid for SVP hold.
        if ($this->t2hub->enabled()) {
            if (! $snapshotConfirmed) {
                return response()->json([
                    'success' => false,
                    'verified' => false,
                    'read_only' => true,
                    'error' => 'The selected T2Hub session is no longer available at the selected center and date. Refresh availability and try again.',
                ], 422);
            }

            $snapshotTime = trim((string) (
                data_get($snapshotRow, 'test_time')
                ?? data_get($snapshotRow, 'exam_time')
                ?? data_get($snapshotRow, 'time')
                ?? ''
            ));

            return response()->json([
                'success' => true,
                'verified' => true,
                'read_only' => true,
                'upstream_status' => 200,
                'session' => [
                    'id' => $data['exam_session_id'],
                    'exam_date' => $data['expected_exam_date'] ?? null,
                    'test_time' => $snapshotTime !== '' ? $snapshotTime : null,
                    'methodology' => null,
                ],
                'expected' => [
                    'test_center_id' => $data['expected_test_center_id'],
                    'test_center_name' => $data['expected_test_center_name'] ?? null,
                    'city' => $data['expected_city'] ?? null,
                    'exam_date' => $data['expected_exam_date'] ?? null,
                    'test_time' => $data['expected_test_time'] ?? null,
                ],
                'actual' => [
                    'test_center_id' => $data['expected_test_center_id'],
                    'test_center_name' => $data['expected_test_center_name'] ?? null,
                    'city' => $data['expected_city'] ?? null,
                    'test_time' => $snapshotTime !== '' ? $snapshotTime : null,
                ],
                'checks' => [
                    't2hub_snapshot_confirmed' => true,
                    'source' => 't2hub',
                ],
            ]);
        }

        // Non-T2Hub deployments use the candidate-authenticated SVP detail
        // endpoint as their session authority.
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
                $snapshotConfirmed,
                $centerCity,
            );

            if (($result['verified'] ?? false) !== true) {
                Log::info('SVP pre-hold session check not verified', [
                    'exam_session_id' => $data['exam_session_id'],
                    'expected_test_center_id' => $data['expected_test_center_id'],
                    'expected_test_center_name' => $data['expected_test_center_name'] ?? null,
                    'expected_city' => $data['expected_city'] ?? null,
                    'expected_exam_date' => $data['expected_exam_date'] ?? null,
                    'expected_test_time' => $data['expected_test_time'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'session_center_snapshot_confirmed' => $snapshotConfirmed,
                    'session_center_city' => $centerCity,
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
