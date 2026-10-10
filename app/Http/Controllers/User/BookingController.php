<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Candidate;
use App\Services\BookingService;
use App\Services\SvpReservationCreditService;
use App\Services\SvpTemporaryHoldService;
use App\Services\UserWalletService;
use App\Services\PortalAvailabilityService;
use App\Services\SvpDirectAvailabilityService;
use App\Services\SvpOtp\SvpAutoSession;
use App\Services\SvpPaymentHistoryService;
use App\Services\SvpPracticalPdfService;
use App\Services\SvpSessionVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private SvpReservationCreditService $credits,
        private SvpTemporaryHoldService $holds,
        private UserWalletService $userWallet,
        private PortalAvailabilityService $portalAvailability,
        private SvpDirectAvailabilityService $directAvailability,
        private SvpPaymentHistoryService $paymentHistory,
        private SvpSessionVerifier $sessionVerifier,
        private SvpAutoSession $autoSession
    ) {
        $this->middleware('auth.multi');
    }

    /**
     * Ensure an SVP bearer token is available and has not already expired.
     *
     * SVP returns a JSON 401 such as "Signature has expired" when the JWT
     * lifetime ends. Detecting the expiry locally avoids rendering a booking
     * wizard with an empty occupation list and lets the user re-authenticate.
     */
    /**
     * Does this upstream error look like an invalidated SVP bearer token?
     */
    private function looksLikeSvpAuthFailure(string $error): bool
    {
        if ($error === '') {
            return false;
        }

        foreach (['401', 'unauthorized', 'unauthenticated', 'session expired', 'jwt'] as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each selected SVP profile has its own bearer token. If the same external
     * account is signed in elsewhere, SVP may invalidate that profile token;
     * read paths therefore retry only through the same selected profile.
     */
    private function withFreshSvpToken(Request $request, callable $call, ?string $token = null): mixed
    {
        $token = $token ?? $this->ensureSvpToken($request);
        if (! is_string($token) || $token === '') {
            return null;
        }

        $result = $call($token);
        if (! $this->isUnauthorized($result)) {
            return $result;
        }

        $renewed = $this->autoSession->ensure($request, true);
        if (! is_string($renewed) || $renewed === '' || $renewed === $token) {
            return $result;
        }

        Log::warning('SVP call retried with a renewed bearer token.');

        return $call($renewed);
    }

    private function isUnauthorized(mixed $result): bool
    {
        if ($result instanceof \Illuminate\Http\JsonResponse || $result instanceof \Illuminate\Http\Client\Response) {
            return (int) $result->getStatusCode() === 401;
        }

        if (is_array($result)) {
            return (int) ($result['status'] ?? 0) === 401;
        }

        return false;
    }

    private function ensureSvpToken(Request $request): ?string
    {
        $token = $this->autoSession->token($request);

        if (is_string($token) && $token !== '' && ! $this->svpTokenExpired($token)) {
            return $token;
        }

        // A missing or expired bearer token must not fail the action the user
        // just started, so renew through the configured automatic SVP sign-in.
        if (is_string($token) && $token !== '') {
            $this->forgetSvpSession($request);
        }

        $renewed = $this->autoSession->ensure($request, true);

        return is_string($renewed) && $renewed !== '' ? $renewed : null;
    }

    private function svpTokenExpired(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return false;
        }

        $payload = json_decode($this->decodeJwtPart($parts[1]), true);
        if (! is_array($payload) || ! is_numeric($payload['exp'] ?? null)) {
            return false;
        }

        return (int) $payload['exp'] <= now()->timestamp;
    }

    private function decodeJwtPart(string $part): string
    {
        $part = strtr($part, '-_', '+/');
        $padding = strlen($part) % 4;
        if ($padding > 0) {
            $part .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($part, true);

        return is_string($decoded) ? $decoded : '';
    }

    private function forgetSvpSession(Request $request): void
    {
        $this->autoSession->forgetCurrent($request);
    }

    private function expiredSvpResponse(Request $request, mixed $response)
    {
        if ($response->getStatusCode() !== 401) {
            return response()->json($response->getData(true), $response->getStatusCode());
        }

        $this->forgetSvpSession($request);

        return response()->json([
            'success' => false,
            'requires_svp_login' => true,
            'login_url' => route('svp.login.form', ['force' => 1]),
            'error' => 'Your SVP session has expired. Sign in with SVP again, then retry the lookup.',
        ], 401);
    }

    /**
     * Normalize the several result/certificate aliases used by SVP payloads.
     * The normalized state is intentionally kept server-side and is used to
     * protect certificate downloads as well as render the user-facing badge.
     *
     * @return array{state: string, label: string, passed: bool}
     */
    private function normalizeSvpResult(array $reservation): array
    {
        $rawResult = data_get($reservation, 'result_status')
            ?? data_get($reservation, 'exam_result')
            ?? data_get($reservation, 'result')
            ?? data_get($reservation, 'outcome')
            ?? data_get($reservation, 'exam_status')
            ?? data_get($reservation, 'reservation_status')
            ?? data_get($reservation, 'status');

        if (is_array($rawResult)) {
            $rawResult = data_get($rawResult, 'status')
                ?? data_get($rawResult, 'result')
                ?? data_get($rawResult, 'label')
                ?? data_get($rawResult, 'name');
        }

        $value = is_scalar($rawResult) ? strtolower(trim((string) $rawResult)) : '';
        $certificate = data_get($reservation, 'certificate');
        $hasCertificate = is_array($certificate)
            ? count(array_filter($certificate, static fn ($item) => $item !== null && $item !== '')) > 0
            : is_string($certificate) && trim($certificate) !== '';

        if ($hasCertificate || preg_match('/(^|[^a-z])(pass|passed|successful|success)([^a-z]|$)/', $value)) {
            return ['state' => 'passed', 'label' => 'Passed', 'passed' => true];
        }

        if (preg_match('/fail|reject|unsuccess|not[ _-]?pass/', $value)) {
            return ['state' => 'failed', 'label' => 'Failed', 'passed' => false];
        }

        return ['state' => 'pending', 'label' => 'Pending', 'passed' => false];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSvpReservations(array $payload): array
    {
        $items = data_get($payload, 'data.exam_reservations')
            ?? data_get($payload, 'exam_reservations')
            ?? data_get($payload, 'data.reservations')
            ?? data_get($payload, 'reservations')
            ?? data_get($payload, 'data')
            ?? [];

        if (is_array($items) && isset($items['items'])) {
            $items = $items['items'];
        }

        if (is_array($items) && ! array_is_list($items) && isset($items['id'])) {
            $items = [$items];
        }

        if (! is_array($items)) {
            return [];
        }

        return array_map(function ($item): array {
            $reservation = (array) $item;
            $reservation = $this->normalizeSvpReservationDisplayFields($reservation);
            $result = $this->normalizeSvpResult($reservation);
            $reservation['_result_state'] = $result['state'];
            $reservation['_result_label'] = $result['label'];
            $reservation['_result_passed'] = $result['passed'];

            return $reservation;
        }, $items);
    }

    /**
     * The list endpoint can return only reservation id, exam and session id.
     * Fetch each reservation's authoritative detail when display metadata is
     * absent, then normalize the merged row for the Blade view.
     *
     * @param array<int, array<string, mixed>> $reservations
     * @return array<int, array<string, mixed>>
     */
    private function enrichSvpReservationDetails(string $token, array $reservations): array
    {
        return array_map(function (array $reservation) use ($token): array {
            $reservation = $this->normalizeSvpReservationDisplayFields($reservation);
            $hasDate = is_string($reservation['exam_date'] ?? null) && trim($reservation['exam_date']) !== '';
            $hasCenter = is_string($reservation['test_center_name'] ?? null) && trim($reservation['test_center_name']) !== '';
            $reservationId = $reservation['id'] ?? null;

            if (($hasDate && $hasCenter) || ! is_scalar($reservationId) || trim((string) $reservationId) === '') {
                return $reservation;
            }

            try {
                $detailResponse = $this->booking->reservation($token, (string) $reservationId);
                if ($detailResponse->getStatusCode() >= 200 && $detailResponse->getStatusCode() < 300) {
                    $detail = $this->svpReservationData($detailResponse->getData(true));
                    if ($detail !== []) {
                        $reservation = array_replace_recursive($detail, $reservation);
                        $reservation = $this->normalizeSvpReservationDisplayFields($reservation);
                    }
                }
            } catch (\Throwable $e) {
                Log::notice('SVP reservation detail enrichment skipped', [
                    'reservation_id' => (string) $reservationId,
                    'error' => $e->getMessage(),
                ]);
            }

            return $reservation;
        }, $reservations);
    }

    /**
     * SVP has returned reservation metadata in several shapes over time:
     * direct fields, nested exam_session/test_center objects, and JSON:API
     * data.attributes wrappers. Flatten the useful display fields once so the
     * Blade view does not have to know about every upstream response variant.
     *
     * @param array<string, mixed> $reservation
     * @return array<string, mixed>
     */
    private function normalizeSvpReservationDisplayFields(array $reservation): array
    {
        $date = $this->firstReservationScalar($reservation, [
            'exam_date',
            'test_date',
            'date',
            'start_date_in_browser_time_zone',
            'start_date_in_tc_time_zone',
            'attributes.exam_date',
            'attributes.test_date',
            'attributes.start_date_in_browser_time_zone',
            'attributes.start_date_in_tc_time_zone',
            'exam_session.exam_date',
            'exam_session.test_date',
            'exam_session.date',
            'exam_session.start_date_in_browser_time_zone',
            'exam_session.start_date_in_tc_time_zone',
            'exam_session.attributes.exam_date',
            'exam_session.attributes.start_date_in_browser_time_zone',
            'exam_session.data.attributes.exam_date',
            'exam_session.data.attributes.start_date_in_browser_time_zone',
            'examSession.exam_date',
            'examSession.date',
            'examSession.start_date_in_browser_time_zone',
            'examSession.data.attributes.exam_date',
            'session.exam_date',
            'session.date',
            'session.start_date_in_browser_time_zone',
        ]);

        $centerName = $this->firstReservationScalar($reservation, [
            'test_center_name',
            'center_name',
            'site_name',
            'test_center.english_name',
            'test_center.name',
            'test_center.attributes.english_name',
            'test_center.attributes.name',
            'test_center.data.attributes.english_name',
            'test_center.data.attributes.name',
            'testCenter.english_name',
            'testCenter.name',
            'testCenter.data.attributes.name',
            'site.english_name',
            'site.name',
            'site.data.attributes.name',
            'exam_session.test_center.english_name',
            'exam_session.test_center.name',
            'exam_session.test_center.data.attributes.name',
            'exam_session.site.name',
            'exam_session.site.data.attributes.name',
            'examSession.test_center.name',
            'examSession.test_center.data.attributes.name',
            'session.test_center.name',
            'session.test_center.data.attributes.name',
        ]);

        if ($date !== null) {
            $reservation['exam_date'] = $this->normalizeReservationDate($date);
        }

        if ($centerName !== null) {
            $reservation['test_center_name'] = $centerName;
        }

        return $reservation;
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<int, string> $paths
     */
    private function firstReservationScalar(array $reservation, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($reservation, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function normalizeReservationDate(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? substr($value, 0, 10)
            : $value;
    }

    private function certificateFilename(array $reservation, string $reservationId, bool $passed = true): string
    {
        $fullName = data_get($reservation, 'full_name')
            ?? data_get($reservation, 'fullName')
            ?? data_get($reservation, 'candidate_name')
            ?? data_get($reservation, 'name')
            ?? data_get($reservation, 'candidate.full_name')
            ?? data_get($reservation, 'user.full_name')
            ?? data_get($reservation, 'candidate.name')
            ?? data_get($reservation, 'user.name');
        $occupation = data_get($reservation, 'occupation.name')
            ?? data_get($reservation, 'occupation.english_name')
            ?? data_get($reservation, 'occupation.name_en')
            ?? data_get($reservation, 'occupation')
            ?? data_get($reservation, 'occupation_name')
            ?? data_get($reservation, 'exam_name')
            ?? data_get($reservation, 'exam.english_name')
            ?? data_get($reservation, 'exam.name')
            ?? data_get($reservation, 'category.english_name')
            ?? data_get($reservation, 'category.name')
            ?? data_get($reservation, 'category_name');

        $parts = array_values(array_filter([
            is_scalar($fullName) ? trim((string) $fullName) : '',
            is_scalar($occupation) ? trim((string) $occupation) : '',
        ]));
        $base = implode(' ', $parts);
        $base = Str::of($base)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->value();

        if ($base === '') {
            $base = 'SVP_Reservation_'.$reservationId;
        }

        return $base.'_'.($passed ? 'Certificate' : 'Ticket').'.pdf';
    }

    private function svpReservationData(array $payload): array
    {
        return (array) (data_get($payload, 'data.exam_reservation')
            ?? data_get($payload, 'exam_reservation')
            ?? data_get($payload, 'data.reservation')
            ?? data_get($payload, 'reservation')
            ?? $payload);
    }

    private function svpReservationFlag(array $reservation, string $snake, string $camel): bool
    {
        return filter_var($reservation[$snake] ?? $reservation[$camel] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Extract only the immutable exam identity for a reschedule. City, center,
     * date, and session are deliberately omitted so the user can choose a new
     * live SVP location exactly as they would for a fresh booking.
     *
     * @return array<string, string|null>
     */
    private function rescheduleContext(array $reservation): array
    {
        return [
            'occupation_id' => $this->reservationValue($reservation, ['occupation_id', 'occupation.id']),
            'category_id' => $this->reservationValue($reservation, ['category_id', 'category.id']),
            'current_exam_date' => $this->reservationValue($reservation, ['exam_date', 'test_date', 'date', 'start_date_in_browser_time_zone', 'start_date_in_tc_time_zone']),
            'methodology' => $this->reservationValue($reservation, ['methodology', 'methodology_type']) ?? config('svp.default_methodology', 'in_person'),
        ];
    }

    private function reservationValue(array $reservation, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($reservation, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function sessionCenterId(?array $session): string
    {
        $center = is_array($session['test_center'] ?? null) ? $session['test_center'] : [];
        return (string) ($session['test_center_id'] ?? $session['site_id'] ?? $session['center_id'] ?? $center['id'] ?? '');
    }

    private function sessionDate(?array $session): ?string
    {
        if ($session === null) {
            return null;
        }

        foreach (['exam_date', 'test_date', 'date', 'start_date_in_browser_time_zone', 'start_date_in_tc_time_zone'] as $key) {
            $value = $session[$key] ?? null;
            if (is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $value) === 1) {
                return substr($value, 0, 10);
            }
        }

        return null;
    }

    private function svpResponseFailed(array $payload): bool
    {
        if (($payload['success'] ?? null) === false) {
            return true;
        }

        foreach (['error', 'errors', 'exception'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the authenticated user's agency id — or null when the account
     * is not (yet) assigned to a real agency. Guards against the FK crash
     * caused by casting a missing agency_id (null) to (int) 0.
     */
    private function currentAgencyId(): ?int
    {
        $user = Auth::user();

        if (! $user || ! $user->agency_id) {
            return null;
        }

        $agencyId = (int) $user->agency_id;

        return Agency::whereKey($agencyId)->exists() ? $agencyId : null;
    }

    public function index(Request $request)
    {
        $userId = Auth::id();

        $svpReservations = null;
        $svpError = null;
        $svpToken = $this->ensureSvpToken($request);
        $svpUserId = Candidate::where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotNull('svp_user_id')
            ->latest()
            ->value('svp_user_id');

        if ($svpToken) {
            try {
                $svpResponse = $this->booking->reservations($svpToken);

                if ($svpResponse->getStatusCode() >= 400) {
                    $svpError = 'Could not load live reservations from SVP.';
                } else {
                    $svpReservations = $this->normalizeSvpReservations($svpResponse->getData(true));
                    $svpReservations = $this->enrichSvpReservationDetails($svpToken, $svpReservations);
                }
            } catch (\Throwable $e) {
                Log::warning('User SVP reservations fetch failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $svpError = 'Could not load live reservations from SVP.';
            }
        } else {
            $svpError = 'Sign in with your SVP account to see live reservations and tickets.';
        }

        $paymentStatus = (string) $request->query('payment_status', 'all');
        $paymentSearch = trim((string) $request->query('payment_search', ''));
        $svpPayments = [];
        $svpPaymentError = null;

        if ($svpToken) {
            try {
                $paymentResponse = $this->booking->payments($svpToken, [
                    'per_page' => 100,
                    'locale' => 'en',
                ]);

                if ($paymentResponse->getStatusCode() >= 400) {
                    $svpPaymentError = 'Could not load payment history from SVP.';
                } else {
                    $svpPayments = $this->paymentHistory->normalize(
                        (array) $paymentResponse->getData(true),
                        $paymentStatus,
                        $paymentSearch
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('User SVP payment history fetch failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $svpPaymentError = 'Could not load payment history from SVP.';
            }
        } else {
            $svpPaymentError = 'Sign in with your SVP account to see payment history.';
        }

        return view('user.bookings.index', [
            'svpReservations' => $svpReservations,
            'svpError'        => $svpError,
            'hasSvpToken'     => (bool) $svpToken,
            'svpUserId'       => $svpUserId,
            'svpPayments'     => $svpPayments,
            'svpPaymentError' => $svpPaymentError,
            'paymentStatus'   => $paymentStatus,
            'paymentSearch'   => $paymentSearch,
        ]);
    }

    /**
     * Download an official SVP ticket for a reservation belonging to the
     * currently authenticated SVP session.
     */
    public function svpTicket(Request $request, string $reservation)
    {
        $token = $this->ensureSvpToken($request);

        if (! $token) {
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to download the ticket.');
        }

        abort_unless(ctype_digit($reservation), 404);

        try {
            $svpResponse = $this->booking->reservation($token, $reservation);

            if ($svpResponse->getStatusCode() >= 400) {
                return redirect()->route('user.bookings.index')
                    ->with('error', 'SVP could not verify this reservation result.');
            }

            $payload = $svpResponse->getData(true);
            $reservationData = $this->svpReservationData((array) $payload);

            // Some live SVP reservation-detail responses omit the candidate
            // name even though the authenticated portal account has a synced
            // local candidate. Use that local identity only as a filename
            // fallback; the official PDF body still comes from SVP.
            $candidate = Candidate::where('user_id', Auth::id())
                ->where('is_active', true)
                ->where('svp_user_id', (string) ($reservationData['svp_user_id'] ?? $reservationData['user_id'] ?? ''))
                ->first();
            $candidate ??= Candidate::where('user_id', Auth::id())
                ->where('is_active', true)
                ->latest()
                ->first();

            if ($candidate && ! data_get($reservationData, 'full_name')) {
                $reservationData['full_name'] = $candidate->full_name;
            }

            $result = $this->normalizeSvpResult($reservationData);
            $filename = $this->certificateFilename($reservationData, $reservation, $result['passed']);

            return $this->booking->ticketPdf($token, $reservation, $filename);
        } catch (\Throwable $e) {
            Log::warning('SVP certificate verification failed', [
                'reservation_id' => $reservation,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('user.bookings.index')
                ->with('error', 'Could not verify the SVP result. Please try again.');
        }
    }

    /**
     * Generate a portal-owned PDF containing the practical-exam metadata
     * returned by SVP, including form, weights and Prometric codes.
     */
    public function svpPracticalPdf(Request $request, string $reservation)
    {
        $token = $this->ensureSvpToken($request);

        if (! $token) {
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to download practical details.');
        }

        abort_unless(ctype_digit($reservation), 404);

        try {
            $svpResponse = $this->booking->reservation($token, $reservation);

            if ($svpResponse->getStatusCode() >= 400) {
                return redirect()->route('user.bookings.index')
                    ->with('error', 'SVP could not verify this reservation.');
            }

            $reservationData = $this->svpReservationData($svpResponse->getData(true));
            $activeCandidateId = $this->autoSession->activeCandidateId($request);
            $candidate = $activeCandidateId
                ? Candidate::where('user_id', Auth::id())
                    ->where('is_active', true)
                    ->find($activeCandidateId)
                : Candidate::where('user_id', Auth::id())
                    ->where('is_active', true)
                    ->latest()
                    ->first();

            if ($candidate && ! data_get($reservationData, 'full_name')) {
                $reservationData['full_name'] = $candidate->full_name;
            }

            return app(SvpPracticalPdfService::class)->download($reservationData, $reservation);
        } catch (\Throwable $e) {
            Log::warning('SVP practical details PDF generation failed', [
                'reservation_id' => $reservation,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('user.bookings.index')
                ->with('error', 'Could not generate the practical details PDF. Please try again.');
        }
    }

    /**
     * Cancel an eligible reservation after verifying its live SVP state.
     */
    public function svpCancel(Request $request, string $reservation)
    {
        $token = $this->ensureSvpToken($request);

        if (! $token) {
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to cancel a reservation.');
        }

        abort_unless(ctype_digit($reservation), 404);

        try {
            $detail = $this->booking->reservation($token, $reservation);
            if ($detail->getStatusCode() >= 400) {
                return redirect()->route('user.bookings.index')->with('error', 'SVP could not verify this reservation.');
            }

            $reservationData = $this->svpReservationData($detail->getData(true));
            if (! $this->svpReservationFlag($reservationData, 'can_be_canceled', 'canBeCanceled')) {
                return redirect()->route('user.bookings.index')->with('error', 'SVP does not allow this reservation to be canceled.');
            }

            $response = $this->booking->cancelReservation($token, $reservation);
            if ($response->getStatusCode() < 200
                || $response->getStatusCode() >= 300
                || $this->svpResponseFailed($response->getData(true))) {
                return redirect()->route('user.bookings.index')->with('error', 'SVP could not cancel the reservation.');
            }

            return redirect()->route('user.bookings.index')->with('success', 'The SVP reservation was canceled successfully.');
        } catch (\Throwable $e) {
            Log::warning('SVP reservation cancellation failed', [
                'reservation_id' => $reservation,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('user.bookings.index')->with('error', 'SVP could not cancel the reservation; cancellation was not confirmed by the upstream service.');
        }
    }

    /**
     * Show a booking-style reschedule wizard. Occupation and category remain
     * fixed from the live reservation; city, center, date, and session are new
     * selections loaded through the same SVP lookup endpoints as fresh booking.
     */
    public function svpReschedule(Request $request, string $reservation)
    {
        $token = $this->ensureSvpToken($request);

        if (! $token) {
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to reschedule a reservation.');
        }

        abort_unless(ctype_digit($reservation), 404);

        try {
            $detail = $this->booking->reservation($token, $reservation);
            if ($detail->getStatusCode() >= 400) {
                return redirect()->route('user.bookings.index')->with('error', 'SVP could not verify this reservation.');
            }

            $reservationData = $this->svpReservationData($detail->getData(true));
            if (! $this->svpReservationFlag($reservationData, 'can_be_rescheduled', 'canBeRescheduled')) {
                return redirect()->route('user.bookings.index')->with('error', 'SVP does not allow this reservation to be rescheduled.');
            }

            $agencyId = $this->currentAgencyId();
            if ($agencyId === null) {
                return redirect()->route('user.dashboard')
                    ->with('error', 'Your account is not assigned to an agency yet. Please contact the administrator.');
            }

            $context = $this->rescheduleContext($reservationData);
            $candidates = Candidate::where('user_id', Auth::id())
                ->where('is_active', true)
                ->latest()
                ->get();
            $reservationName = $this->reservationValue($reservationData, [
                'full_name', 'fullName', 'candidate.full_name', 'user.full_name', 'candidate.name', 'user.name',
            ]);
            $selectedCandidateId = optional($candidates->first(function (Candidate $candidate) use ($reservationName): bool {
                return $reservationName !== null
                    && strcasecmp(trim((string) $candidate->full_name), trim($reservationName)) === 0;
            }))->id;

            return view('user.bookings.reschedule', [
                'reservation' => $reservation,
                'reservationData' => $reservationData,
                'context' => $context,
                'wallet' => $this->userWallet->getWallet((int) Auth::id()),
                'candidates' => $candidates,
                'selectedCandidateId' => $selectedCandidateId,
                'svpToken' => $token,
                'svpError' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SVP reservation reschedule form failed', [
                'reservation_id' => $reservation,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('user.bookings.index')->with('error', 'Could not load the SVP reschedule form. Please try again.');
        }
    }

    /**
     * Submit the fresh-booking-style reschedule. Only the live reservation's
     * occupation and category are immutable; all location/session fields are
     * checked against the new session-bound temporary hold.
     */
    public function svpRescheduleSubmit(Request $request, string $reservation)
    {
        $data = $request->validate([
            'candidate_id' => ['required', 'integer', 'exists:candidates,id'],
            'occupation_id' => ['required', 'string', 'max:100'],
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'test_center_id' => ['required', 'string', 'max:100'],
            'test_center_name' => ['required', 'string', 'max:255'],
            'test_center_time' => ['required', 'string', 'max:80'],
            'exam_session_id' => ['required', 'string', 'max:255'],
            'exam_session_name' => ['nullable', 'string', 'max:255'],
            'exam_date' => ['required', 'date_format:Y-m-d'],
            // An existing SVP reservation must not request a second labor
            // hold; SVP rejects that with temporaryseat.labor_id taken.
            'temporary_hold_id' => ['nullable', 'string', 'max:100'],
            'language_code' => ['nullable', 'string', 'max:20'],
            'methodology' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // The language select can briefly remain disabled while the live
        // catalogue finishes refreshing. SVP requires this field, and this
        // deployment's advertised default language is LOABB, so never send
        // an empty language_code to the upstream reschedule endpoint.
        $data['language_code'] = strtoupper(trim((string) ($data['language_code'] ?? '')));
        if ($data['language_code'] === '') {
            $data['language_code'] = strtoupper((string) config('svp.default_language_code', 'LOABB'));
        }

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to reschedule a reservation.');
        }

        abort_unless(ctype_digit($reservation), 404);

        try {
            $detail = $this->booking->reservation($token, $reservation);
            if ($detail->getStatusCode() >= 400) {
                return redirect()->route('user.bookings.index')->with('error', 'SVP could not verify this reservation.');
            }

            $reservationData = $this->svpReservationData($detail->getData(true));
            if (! $this->svpReservationFlag($reservationData, 'can_be_rescheduled', 'canBeRescheduled')) {
                return redirect()->route('user.bookings.index')->with('error', 'SVP does not allow this reservation to be rescheduled.');
            }

            $context = $this->rescheduleContext($reservationData);
            foreach (['occupation_id', 'category_id'] as $field) {
                if (($context[$field] ?? '') === '' || (string) $context[$field] !== (string) $data[$field]) {
                    return back()->withInput()->with('error', 'The occupation and category of a reservation cannot be changed during rescheduling.');
                }
            }

            $agencyId = $this->currentAgencyId();
            if ($agencyId === null) {
                return back()->withInput()->with('error', 'Your account is not assigned to an agency yet. Please contact the administrator.');
            }

            $candidate = Candidate::where('user_id', Auth::id())
                ->where('agency_id', $agencyId)
                ->where('is_active', true)
                ->findOrFail($data['candidate_id']);

            // T2Hub is the authority for the live date/centre/session list in
            // this deployment. Candidate-SVP session detail can omit the
            // physical centre, so using it here incorrectly rejected valid
            // sessions immediately before reschedule.
            if (config('t2hub.data_source') === 't2hub') {
                $snapshot = app(\App\Services\T2Hub\T2HubBookingData::class)->sessionSnapshot(
                    (string) $context['category_id'],
                    (string) $data['city'],
                    (string) $data['exam_date'],
                    (string) $data['test_center_id'],
                    (string) $data['exam_session_id'],
                );
                $sessionVerification = [
                    'success' => $snapshot !== null,
                    'verified' => $snapshot !== null,
                    'upstream_status' => 200,
                    'session' => ['id' => (string) $data['exam_session_id']],
                ];
            } else {
                // Non-T2Hub deployments use the candidate-authenticated SVP
                // detail endpoint as the authoritative session source.
                $sessionVerification = $this->sessionVerifier->verify(
                    $token,
                    (string) $data['exam_session_id'],
                    (string) $data['test_center_id'],
                    (string) $data['city'],
                    (string) $data['exam_date'],
                    (string) $data['test_center_name'],
                );
            }
            $verifiedSessionId = (string) data_get($sessionVerification, 'session.id', '');
            if ((int) ($sessionVerification['upstream_status'] ?? 0) === 401) {
                return redirect()->route('svp.login.form', ['force' => 1])
                    ->with('status', 'Your SVP session expired. Sign in again before confirming this reschedule.');
            }
            if (! ($sessionVerification['success'] ?? false) || ! ($sessionVerification['verified'] ?? false)) {
                return back()->withInput()->with('error', 'The selected SVP session no longer matches the selected center and date. Refresh the live sessions and try again.');
            }
            if ($verifiedSessionId !== '' && $verifiedSessionId !== (string) $data['exam_session_id']) {
                return back()->withInput()->with('error', 'SVP returned a different session than the one selected. Refresh the live sessions and try again.');
            }

            // The verified reservation ID authorizes the reschedule mutation.
            // Do not create or consume a fresh temporary seat here: the
            // active reservation already occupies the candidate's labor and
            // SVP rejects a second hold as `labor_id has already been taken`.
            $hold = null;

            $result = $this->booking->completeReschedule($token, $reservation, [
                'agency_id' => $agencyId,
                'user_id' => Auth::id(),
                'credential_id' => $candidate->id,
                'svp_user_id' => $candidate->svp_user_id,
                'occupation_id' => $context['occupation_id'],
                'category_id' => $context['category_id'],
                'city' => $data['city'],
                'test_center_id' => $data['test_center_id'],
                'test_center_name' => $data['test_center_name'],
                'test_center_time' => $data['test_center_time'],
                'exam_session_id' => $data['exam_session_id'],
                'exam_session_name' => $data['exam_session_name'] ?? null,
                'exam_date' => $data['exam_date'],
                'temporary_hold_id' => null,
                'temporary_hold_expires_at' => null,
                'language_code' => strtoupper(trim($data['language_code'])),
                'methodology' => $data['methodology'] ?? ($context['methodology'] ?? config('svp.default_methodology', 'in_person')),
                'notes' => $data['notes'] ?? null,
            ]);

            if (! $result['success']) {
                return back()->withInput()->with('error', $result['error'] ?? 'Reschedule failed.');
            }

            if (! empty($result['payment_required'])) {
                return redirect()->route('user.bookings.payment', $result['booking']->id)
                    ->with('success', 'SVP has created a card checkout for this rescheduled reservation. Complete payment only on the official SVP page.');
            }

            return redirect()->route('user.bookings.show', $result['booking']->id)
                ->with('success', 'The SVP reservation was rescheduled successfully with the available SVP credit.');
        } catch (\Throwable $e) {
            Log::warning('SVP reservation reschedule failed', [
                'reservation_id' => $reservation,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Could not reschedule the SVP reservation. Please try again.');
        }
    }

    /**
     * GET /user/bookings/create — SVP booking wizard scoped to the logged-in user.
     */
    public function create(Request $request)
    {
        $agencyId = $this->currentAgencyId();

        if ($agencyId === null) {
            return redirect()->route('user.dashboard')
                ->with('error', 'Your account is not assigned to an agency yet. Please contact the administrator to create bookings.');
        }

        // Wallet + currently active candidates synced from SVP profile after login.
        // Catalogue browsing must not wait for an e-mail OTP. The hold/confirm
        // endpoints retain their existing authenticated SVP behaviour.
        $token = config('t2hub.data_source') === 't2hub'
            ? $this->autoSession->token($request)
            : $this->ensureSvpToken($request);

        $wallet = $this->userWallet->getWallet((int) Auth::id());
        $candidates = Candidate::where('user_id', Auth::id())
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->latest()
            ->get();

        // If a verified profile is still present in the current request context,
        // repair its local active flag before rendering the booking dropdown.
        if ($candidates->isEmpty()) {
            $sessionSvpUserId = trim((string) $this->autoSession->svpUserId($request));
            if ($sessionSvpUserId !== '') {
                $staleCandidate = Candidate::where('user_id', Auth::id())
                    ->where('agency_id', $agencyId)
                    ->where('svp_user_id', $sessionSvpUserId)
                    ->latest()
                    ->first();

                if ($staleCandidate) {
                    Candidate::where('user_id', Auth::id())->update(['is_active' => false]);
                    $staleCandidate->update(['is_active' => true]);
                    Log::info('SVP candidate self-heal reactivated candidate for booking form', [
                        'user_id' => Auth::id(),
                        'candidate_id' => $staleCandidate->id,
                        'svp_user_id' => $sessionSvpUserId,
                    ]);
                    $candidates = Candidate::where('user_id', Auth::id())
                        ->where('agency_id', $agencyId)
                        ->where('is_active', true)
                        ->latest()
                        ->get();
                }
            }
        }

        $occupations = [];
        $categories  = [];
        $svpError    = $token ? null : 'Connect your candidate SVP account before creating a hold or booking.';

        try {
            // Read-only occupation metadata comes from the encrypted portal
            // availability session. Candidate SVP credentials remain reserved
            // for candidate profile, credit, session verification, hold, and
            // reservation operations.
            $occupations = ['data' => $this->portalAvailability->bookingOccupations()];
        } catch (\Throwable $e) {
            Log::warning('Portal booking lookup failed', ['error' => $e->getMessage()]);
            $svpError ??= 'Could not load live occupation data. Please try again.';
        }

        return view('user.bookings.create', [
            'wallet'      => $wallet,
            'candidates'  => $candidates,
            'occupations' => $occupations,
            'categories'  => $categories,
            'svpError'    => $svpError,
            'svpToken'    => $token,
        ]);
    }

    public function lookupCities(Request $request)
    {
        $data = $request->validate(['category_id' => 'required|string']);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->portalAvailability->bookingCities($data['category_id']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup cities failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live cities.'], 503);
        }
    }

    /**
     * GET /user/bookings/lookup/languages?occupation_id=…
     * AJAX: return live exam languages for the selected occupation.
     */
    public function lookupLanguages(Request $request)
    {
        $data = $request->validate(['occupation_id' => 'required|string']);

        try {
            return response()->json([
                'success' => true,
                'data' => ['languages' => $this->portalAvailability->bookingLanguages($data['occupation_id'])],
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup languages failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live exam languages.'], 503);
        }
    }

    public function lookupCategories(Request $request)
    {
        $data = $request->validate(['occupation_id' => 'required|string']);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->portalAvailability->bookingCategories($data['occupation_id']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup categories failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live categories.'], 503);
        }
    }

    public function lookupOccupations(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string',
            'page'   => 'nullable|integer|min:1',
        ]);

        try {
            return response()->json([
                'success' => true,
                'data' => ['occupations' => $this->portalAvailability->bookingOccupations($request->query('search'))],
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup occupations failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live occupations.'], 503);
        }
    }

    public function lookupDates(Request $request)
    {
        $data = $request->validate([
            'city' => 'required|string|max:120',
            'category_id' => 'required|string',
        ]);

        try {
            $dates = $this->portalAvailability->bookingDates($data['category_id'], $data['city']);

            // Warm centres and the next dates after the response is flushed so
            // the operator's click on a date is served from cache instead of a
            // cold T2Hub round trip.
            $categoryId = (string) $data['category_id'];
            $city = (string) $data['city'];
            defer(function () use ($categoryId, $city, $dates): void {
                try {
                    app(\App\Services\T2Hub\T2HubBookingData::class)->warmCityDates($categoryId, $city, array_values((array) $dates));
                } catch (\Throwable) {
                    // Prewarming is best effort only.
                }
            });

            return response()->json([
                'success' => true,
                'data' => ['dates' => $dates],
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup dates failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live available dates.'], 503);
        }
    }

    public function lookupTestCenters(Request $request)
    {
        $data = $request->validate([
            'city' => 'required|string',
            'category_id' => 'required|string',
            'date' => 'nullable|date_format:Y-m-d',
            'occupation_id' => 'required|string',
            'language_code' => 'required|string|max:120',
            'language_codes' => ['nullable', 'array'],
            'language_codes.*' => ['string', 'max:120'],
        ]);
        $languageCodes = array_values(array_unique(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            array_merge([$data['language_code']], $data['language_codes'] ?? []),
        ))));

        try {
            $centers = filled($data['date'] ?? null)
                ? $this->portalAvailability->bookingCentersForDate(
                    $data['category_id'],
                    $data['city'],
                    $data['date'],
                    $data['occupation_id'],
                    $data['language_code'],
                    $languageCodes,
                )
                : $this->portalAvailability->bookingCenters(
                    $data['category_id'],
                    $data['city'],
                    $data['occupation_id'],
                    $data['language_code'],
                    $languageCodes,
                )['test_centers'];
            $availabilitySource = 'portal_availability';
            $fallback = false;

            // An empty T2Hub date is not an SVP authentication failure. Never
            // start an OTP login just to render an empty centre list.
            if (config('t2hub.data_source') !== 't2hub' && filled($data['date'] ?? null) && $centers === []) {
                $token = $this->ensureSvpToken($request);
                if ($token) {
                    $direct = $this->directAvailability->centersForDate($token, [
                        'city' => $data['city'],
                        'category_id' => $data['category_id'],
                        'date' => $data['date'],
                    ]);
                    if (($direct['requires_svp_login'] ?? false) === true) {
                        return response()->json($direct, 401);
                    }
                    $centers = $direct['centers'];
                    $availabilitySource = $direct['availability_source'];
                    $fallback = $direct['fallback'];
                }
            }

            // Remember the live sessions behind each returned centre slot so the
            // hold endpoint can validate the exact session this browser saw. The
            // entries are keyed exactly like the hold request (category + city +
            // centre), which is what makes the hold resolvable without depending
            // on a separate sessions round-trip.
            if (is_array($centers) && $centers !== [] && filled($data['date'] ?? null)) {
                foreach ($centers as $center) {
                    if (! is_array($center)) {
                        continue;
                    }
                    $centerId = (string) ($center['test_center_id'] ?? $center['id'] ?? '');
                    if ($centerId === '') {
                        continue;
                    }
                    $sessionIds = array_values(array_filter(array_map(
                        static fn ($id): string => trim((string) $id),
                        (array) ($center['session_ids'] ?? []),
                    )));
                    $this->holds->rememberSessionLookup($request, [
                        'category_id' => (string) $data['category_id'],
                        'city' => (string) $data['city'],
                        'test_center_id' => $centerId,
                    ], [
                        'sessions' => array_map(static fn (string $id): array => [
                            'id' => $id,
                            'exam_session_id' => $id,
                            'test_center_id' => $centerId,
                            'exam_date' => (string) $data['date'],
                        ], $sessionIds),
                    ]);
                }
            }

            $sessionsByCenter = [];
            if (config('t2hub.data_source') === 't2hub' && filled($data['date'] ?? null) && $centers !== []) {
                // The centre lookup already loaded this category/city/date from
                // T2Hub. Return the same snapshot's sessions alongside its
                // centres rather than making the browser fetch each centre.
                $activeCenterIds = array_fill_keys(array_map(
                    static fn (array $center): string => (string) ($center['test_center_id'] ?? $center['id'] ?? ''),
                    array_filter($centers, 'is_array'),
                ), true);
                foreach (app(\App\Services\T2Hub\T2HubBookingData::class)->sessionRows(
                    $data['category_id'], $data['city'], $data['date'],
                ) as $session) {
                    $centerId = (string) ($session['test_center_id'] ?? '');
                    if ($centerId !== '' && isset($activeCenterIds[$centerId])) {
                        // Do not send the raw upstream payload to the browser.
                        $sessionsByCenter[$centerId][] = array_intersect_key($session, array_flip([
                            'id', 'exam_session_id', 'test_center_id', 'center_name', 'exam_date',
                            'exam_time', 'test_time', 'time', 'session_name', 'label',
                            'available_seats', 'status', 'city',
                        ]));
                    }
                }
            }

            return response()->json([
                'success' => true,
                'availability_source' => $availabilitySource,
                'fallback' => $fallback,
                'data' => [
                    'test_centers' => array_map(static function (array $center): array {
                        unset($center['source']);
                        return $center;
                    }, $centers),
                    'sessions_by_center' => $sessionsByCenter,
                    'sessions_bundled' => config('t2hub.data_source') === 't2hub' && filled($data['date'] ?? null),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup test-centers failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live test centers.'], 503);
        }
    }

    public function lookupSessions(Request $request)
    {
        if (config('t2hub.data_source') === 't2hub') {
            $data = $request->validate([
                'city' => 'required|string',
                'category_id' => 'required|string',
                'test_center_id' => 'required|string',
                'exam_date' => 'required|date_format:Y-m-d',
            ]);

            try {
                $sessions = app(\App\Services\T2Hub\T2HubBookingData::class)->sessionRows(
                    $data['category_id'], $data['city'], $data['exam_date'], $data['test_center_id'],
                );
                $sessions = array_map(static function (array $session): array {
                    unset($session['source']);
                    return $session;
                }, $sessions);
            } catch (\Throwable $e) {
                Log::warning('T2Hub lookup sessions failed', ['error' => $e->getMessage()]);
                return response()->json(['success' => false, 'error' => 'Unable to load live sessions.'], 503);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'sessions' => $sessions,
                    'exam_sessions' => $sessions,
                ],
            ]);
        }

        $request->validate([
            'city' => 'required|string',
            'category_id' => 'required|string',
            'test_center_id' => 'required|string',
            'exam_date' => 'nullable|date_format:Y-m-d',
            'reservation_id' => 'nullable|string',
        ]);

        $params = $request->only([
            'city', 'category_id', 'test_center_id', 'exam_date', 'reservation_id', 'available_seats',
        ]);
        $params = array_filter($params, static fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->withFreshSvpToken(
                $request,
                fn (string $bearer) => $this->booking->sessionsForCenter($bearer, $params)
            );
            if ($response === null) {
                return response()->json(['error' => 'SVP session expired.'], 401);
            }
            if ($response->getStatusCode() === 401) {
                return $this->expiredSvpResponse($request, $response);
            }
            $payload = $response->getData(true);

            $this->holds->rememberSessionLookup($request, [
                'category_id' => $params['category_id'],
                'city' => $params['city'],
                'test_center_id' => $params['test_center_id'],
            ], $payload);

            return response()->json($payload, $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP lookup sessions failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch sessions.'], 503);
        }
    }

    /**
     * GET /user/bookings/credit-status?candidate_id=&occupation_id=&methodology=
     * AJAX: read the selected SVP user's credits for the exact occupation.
     */
    public function creditStatus(Request $request)
    {
        $data = $request->validate([
            'candidate_id' => ['required', 'integer', 'exists:candidates,id'],
            'occupation_id' => ['required', 'string'],
            'methodology' => ['nullable', 'string', 'max:40'],
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['success' => false, 'error' => 'SVP session expired.'], 401);
        }

        $candidate = Candidate::where('user_id', Auth::id())
            ->where('agency_id', $this->currentAgencyId())
            ->where('is_active', true)
            ->find($data['candidate_id']);

        if (! $candidate) {
            return response()->json([
                'success' => false,
                'error' => 'The selected SVP candidate is no longer active. Refresh the booking page and select the current candidate.',
            ], 422);
        }

        $fetchCredits = function (string $bearer) use ($candidate, $data): array {
            return $this->credits->status(
                $bearer,
                $candidate,
                $data['occupation_id'],
                $data['methodology'] ?? config('svp.default_methodology', 'in_person')
            );
        };

        try {
            $status = $fetchCredits($token);

            return response()->json(['success' => true, 'data' => ['credits' => $status['credits']]]);
        } catch (\Throwable $e) {
            // SVP invalidates the previous bearer token whenever another sign-in
            // happens, so renew once and repeat before reporting the failure.
            if ($this->looksLikeSvpAuthFailure($e->getMessage())) {
                $renewed = $this->autoSession->ensure($request, true);
                if (is_string($renewed) && $renewed !== '' && $renewed !== $token) {
                    try {
                        $status = $fetchCredits($renewed);

                        return response()->json(['success' => true, 'data' => ['credits' => $status['credits']]]);
                    } catch (\Throwable $retryError) {
                        $e = $retryError;
                    }
                }
            }

            Log::warning('SVP credit status lookup failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 503);
        }
    }

    /**
     * GET /user/bookings/available-dates?session_id=…
     * AJAX: fetch available dates for a chosen exam session.
     */
    public function availableDates(Request $request)
    {
        $request->validate([
            'session_id'  => 'nullable|string',
            'category_id' => 'required|string',
            'city'        => 'required|string',
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $sessionId = $request->query('session_id');
            $response = $this->booking->availableDates(
                $token,
                $sessionId,
                $request->only(['category_id', 'city'])
            );
            return $this->expiredSvpResponse($request, $response);
        } catch (\Throwable $e) {
            Log::error('SVP availableDates failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch dates.'], 503);
        }
    }

    /**
     * POST /user/bookings — run the full BookingService workflow.
     */
    public function store(Request $request)
    {
        $agencyId = $this->currentAgencyId();

        if ($agencyId === null) {
            return back()->with('error', 'Your account is not assigned to an agency yet. Please contact the administrator.');
        }

        try {
            $data = $request->validate([
                'candidate_id'    => ['required', 'integer', 'exists:candidates,id'],
                'occupation_id'    => ['required', 'string'],
                'category_id'      => ['required', 'string'],
                'city'             => ['required', 'string', 'max:120'],
                'test_center_id'   => ['required', 'string'],
                'test_center_name' => ['required', 'string', 'max:255'],
                'test_center_time' => ['required', 'string', 'max:80'],
                'exam_session_id'  => ['required', 'string'],
                'exam_session_name'=> ['nullable', 'string', 'max:255'],
                'exam_date'        => ['required', 'date'],
                'temporary_hold_id' => ['required', 'string', 'max:100'],
                'language_code'    => ['required', 'string', 'max:120'],
                'methodology'      => ['nullable', 'string', 'max:40'],
                'notes'           => ['nullable', 'string', 'max:500'],
            ]);
        } catch (ValidationException $e) {
            Log::warning('Booking confirm rejected: payload validation', ['errors' => $e->errors()]);
            throw $e;
        }

        $data['language_code'] = $this->validatedLiveLanguageCode(
            (string) $data['occupation_id'],
            $data['language_code']
        );

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            throw ValidationException::withMessages([
                'candidate_id' => 'Your SVP session has expired. Please sign in again.',
            ])->redirectTo(route('svp.login.form'));
        }

        $agencyId = $this->currentAgencyId();

        if ($agencyId === null) {
            return back()->with('error', 'Your account is not assigned to an agency yet. Please contact the administrator.');
        }

        $candidate = Candidate::where('user_id', Auth::id())
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->find($data['candidate_id']);

        if (! $candidate) {
            return back()
                ->withInput()
                ->withErrors(['candidate_id' => 'The selected SVP candidate is no longer active. Refresh the page and select the current candidate before confirming.']);
        }

        $hold = $this->holds->consumeMatching($request, $data);
        if ($hold === null) {
            Log::warning('Booking confirm rejected: no matching temporary hold', [
                'temporary_hold_id' => $data['temporary_hold_id'] ?? null,
                'exam_session_id' => $data['exam_session_id'] ?? null,
                'exam_date' => $data['exam_date'] ?? null,
                'test_center_id' => $data['test_center_id'] ?? null,
                'test_center_time' => $data['test_center_time'] ?? null,
            ]);
            return back()
                ->withInput()
                ->withErrors(['temporary_hold_id' => 'Create a new temporary SVP hold for the selected session before confirming the booking.']);
        }

        $result = $this->booking->completeBooking($token, [
            'agency_id'       => $agencyId,
            'user_id'         => Auth::id(),
            'credential_id'   => $candidate->id,
            'svp_user_id'     => $candidate->svp_user_id,
            'occupation_id'    => $data['occupation_id'],
            'category_id'      => $data['category_id'],
            'city'             => $data['city'],
            'test_center_id'   => $data['test_center_id'],
            'test_center_name' => $data['test_center_name'],
            'test_center_time' => $data['test_center_time'],
            'exam_session_id' => $data['exam_session_id'],
            'exam_session_name'=> $data['exam_session_name'] ?? null,
            'exam_date'        => $data['exam_date'],
            'temporary_hold_id' => $hold['id'],
            'temporary_hold_expires_at' => $hold['expires_at'] ?? null,
            'language_code'    => strtoupper(trim($data['language_code'])),
            'methodology'      => $data['methodology'] ?? config('svp.default_methodology', 'in_person'),
            'notes'            => $data['notes'] ?? null,
            'require_verified_context' => true,
        ]);

        if (! $result['success'] && $this->looksLikeSvpAuthFailure((string) ($result['error'] ?? ''))) {
            // SVP invalidates bearer tokens (expiry, a parallel login elsewhere)
            // independently of our JWT check. Renew once and retry the exact same
            // confirmed selection before reporting a failure.
            $renewed = $this->autoSession->ensure($request, true);
            if (is_string($renewed) && $renewed !== '' && $renewed !== $token) {
                Log::warning('Booking confirm retried after an SVP auth failure', [
                    'exam_session_id' => $data['exam_session_id'] ?? null,
                ]);
                $token = $renewed;
                $result = $this->booking->completeBooking($renewed, [
                    'agency_id'       => $agencyId,
                    'user_id'         => Auth::id(),
                    'credential_id'   => $candidate->id,
                    'svp_user_id'     => $candidate->svp_user_id,
                    'occupation_id'    => $data['occupation_id'],
                    'category_id'      => $data['category_id'],
                    'city'             => $data['city'],
                    'test_center_id'   => $data['test_center_id'],
                    'test_center_name' => $data['test_center_name'],
                    'test_center_time' => $data['test_center_time'],
                    'exam_session_id' => $data['exam_session_id'],
                    'exam_session_name'=> $data['exam_session_name'] ?? null,
                    'exam_date'        => $data['exam_date'],
                    'temporary_hold_id' => $hold['id'],
                    'temporary_hold_expires_at' => $hold['expires_at'] ?? null,
                    'language_code'    => strtoupper(trim($data['language_code'])),
                    'methodology'      => $data['methodology'] ?? config('svp.default_methodology', 'in_person'),
                    'notes'            => $data['notes'] ?? null,
                    'require_verified_context' => true,
                ]);
            }
        }

        if (! $result['success']) {
            Log::warning('Booking confirm rejected: SVP reservation failed', [
                'error' => $result['error'] ?? null,
                'exam_session_id' => $data['exam_session_id'] ?? null,
                'test_center_id' => $data['test_center_id'] ?? null,
            ]);
            return back()
                ->withInput()
                ->with('error', $result['error'] ?? 'Booking failed.');
        }

        if (! empty($result['payment_required'])) {
            return redirect()
                ->route('user.bookings.payment', $result['booking']->id)
                ->with('success', 'SVP has created a card checkout for this reservation. Complete payment only on the official SVP page.');
        }

        return redirect()
            ->route('user.bookings.show', $result['booking']->id)
            ->with('success', 'Booking confirmed with the available SVP reservation credit.');
    }

    /**
     * Accept only a language currently advertised by Portal Availability for the
     * selected occupation. This read-only lookup never uses the candidate token.
     */
    private function validatedLiveLanguageCode(string $occupationId, string $languageCode): string
    {
        $normalized = strtoupper(trim($languageCode));

        try {
            $languages = $this->portalAvailability->bookingLanguages($occupationId);
        } catch (\Throwable $e) {
            Log::warning('Live SVP booking language validation failed', [
                'occupation_id' => $occupationId,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'language_code' => 'Live SVP exam languages are temporarily unavailable. Please refresh and select a live language again.',
            ]);
        }

        $validCodes = array_values(array_filter(array_map(
            static fn (array $language): string => strtoupper(trim((string) ($language['code'] ?? ''))),
            $languages
        )));

        if (! in_array($normalized, $validCodes, true)) {
            Log::warning('Booking confirm rejected: language not advertised live', [
                'occupation_id' => $occupationId,
                'submitted' => $normalized,
                'valid' => $validCodes,
            ]);

            throw ValidationException::withMessages([
                'language_code' => 'Select a live SVP exam language for the selected occupation.',
            ]);
        }

        return $normalized;
    }

    /**
     * Show the official SVP card checkout created for a reservation with no credit.
     */
    public function payment(Booking $booking)
    {
        abort_unless((int) $booking->user_id === (int) Auth::id(), 403);
        $attempt = $booking->attempts()->latest()->first();
        $providerResponse = (array) ($attempt?->provider_response ?? []);
        $checkoutUrl = $this->booking->checkoutUrlFromProviderResponse($providerResponse);
        $widgetCheckout = $this->booking->widgetCheckoutFromProviderResponse($providerResponse);

        if ((! is_string($checkoutUrl) || $checkoutUrl === '') && $widgetCheckout === null) {
            return redirect()
                ->route('user.bookings.show', $booking->id)
                ->with('error', 'No active SVP card checkout was found for this booking.');
        }

        return view('bookings.svp-payment', [
            'booking' => $booking,
            'checkoutUrl' => $checkoutUrl,
            'widgetCheckoutId' => $widgetCheckout['checkout_id'] ?? null,
            'widgetIntegrity' => $widgetCheckout['integrity'] ?? null,
            'widgetScriptUrl' => config('svp.hyperpay_widget_url'),
            'shopperResultUrl' => route('user.bookings.payment-return', $booking->id),
            'backRoute' => route('user.bookings.show', $booking->id),
            'verifyRoute' => route('user.bookings.verify-reservation', $booking->id),
            'layout' => 'layouts.user',
        ]);
    }

    /**
     * Receive HyperPay's COPYandPAY shopper result and verify it server-side.
     */
    public function paymentReturn(Request $request, Booking $booking)
    {
        abort_unless((int) $booking->user_id === (int) Auth::id(), 403);
        $resourcePath = trim((string) $request->query('resourcePath', ''));
        $showRoute = route('user.bookings.show', $booking->id);

        if ($booking->booking_status !== 'pending') {
            Log::warning('Payment callback ignored for non-pending booking', [
                'booking_id' => $booking->id,
                'booking_status' => $booking->booking_status,
            ]);

            return redirect($showRoute)->with('error', 'This booking is no longer awaiting payment and cannot be paid again.');
        }

        if ($resourcePath === '') {
            return redirect($showRoute)->with('error', 'SVP did not return a payment status path.');
        }

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return redirect()->route('svp.login.form')->with('status', 'Please sign in with SVP again to verify this payment.');
        }

        try {
            $response = $this->booking->getPaymentStatus($token, $resourcePath);
            $payload = $response->getData(true);
            $resultCode = data_get($payload, 'result.code')
                ?? data_get($payload, 'response.result.code')
                ?? data_get($payload, 'checkout.response.result.code');

            if (! $this->booking->paymentStatusIsSuccessful($response->getStatusCode(), (array) $payload)) {
                Log::warning('SVP HyperPay payment verification failed', [
                    'booking_id' => $booking->id,
                    'resource_path' => $resourcePath,
                    'result_code' => $resultCode,
                    'status' => $response->getStatusCode(),
                ]);

                $attempt = $booking->attempts()->latest()->first();
                $this->booking->markBookingFailedAndRefund(
                    $booking,
                    $attempt,
                    $payload,
                    'SVP payment was not confirmed.'
                );

                return redirect($showRoute)->with('error', 'SVP payment was not confirmed. The portal fee was charged and is non-refundable.');
            }

            $booking->update(['booking_status' => 'booked']);
            $this->booking->finalizePortalBookingFee($booking);
            $attempt = $booking->attempts()->latest()->first();
            if ($attempt) {
                $providerResponse = (array) ($attempt->provider_response ?? []);
                $providerResponse['payment_status'] = $payload;
                $attempt->update(['status' => 'success', 'provider_response' => $providerResponse]);
            }

            return redirect($showRoute)->with('success', 'SVP card payment was confirmed and the booking is now complete.');
        } catch (\Throwable $e) {
            Log::warning('SVP HyperPay payment verification exception', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);

            return redirect($showRoute)->with('error', 'SVP could not verify the card payment. Please try again.');
        }
    }

    /**
     * Read the state of the exact selected SVP reservation without modifying it.
     */
    public function verifyReservation(Request $request, Booking $booking)
    {
        abort_unless((int) $booking->user_id === (int) Auth::id(), 403);
        $token = $this->ensureSvpToken($request);

        if (! $token || ! $booking->reservation_id) {
            return back()->with('error', 'An SVP session and reservation ID are required to verify this booking.');
        }

        try {
            $response = $this->booking->reservation($token, (string) $booking->reservation_id);
            $payload = $response->getData(true);
            Log::info('SVP reservation checked', ['booking_id' => $booking->id, 'reservation_id' => $booking->reservation_id]);

            return back()->with('svp_reservation_check', json_encode($payload, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            Log::warning('SVP reservation verification failed', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'SVP could not verify this reservation. Please try again.');
        }
    }

    public function show(Booking $booking)
    {
        abort_unless((int) $booking->user_id === (int) Auth::id(), 403);

        $booking->load(['credential', 'logs', 'attempts', 'refundRequests']);

        return view('user.bookings.show', [
            'booking'  => $booking,
            'logs'     => $booking->logs,
            'attempts' => $booking->attempts,
            'refunds'  => $booking->refundRequests,
        ]);
    }
}
