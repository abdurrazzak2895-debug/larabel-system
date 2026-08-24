<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Candidate;
use App\Services\BookingService;
use App\Services\SvpReservationCreditService;
use App\Services\SvpTemporaryHoldService;
use App\Services\WalletService;
use App\Services\PortalAvailabilityService;
use App\Services\SvpDirectAvailabilityService;
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
        private WalletService $wallet,
        private PortalAvailabilityService $portalAvailability,
        private SvpDirectAvailabilityService $directAvailability
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
    private function ensureSvpToken(Request $request): ?string
    {
        $token = $request->session()->get('svp_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        if ($this->svpTokenExpired($token)) {
            $this->forgetSvpSession($request);
            return null;
        }

        return $token;
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
        $request->session()->forget(['svp_token', 'svp_csrf', 'svp_login', 'svp_user_id']);
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
            $result = $this->normalizeSvpResult($reservation);
            $reservation['_result_state'] = $result['state'];
            $reservation['_result_label'] = $result['label'];
            $reservation['_result_passed'] = $result['passed'];

            return $reservation;
        }, $items);
    }

    private function certificateFilename(array $reservation, string $reservationId, bool $passed = true): string
    {
        $fullName = data_get($reservation, 'full_name')
            ?? data_get($reservation, 'fullName')
            ?? data_get($reservation, 'candidate.full_name')
            ?? data_get($reservation, 'user.full_name')
            ?? data_get($reservation, 'candidate.name')
            ?? data_get($reservation, 'user.name');
        $occupation = data_get($reservation, 'occupation.name')
            ?? data_get($reservation, 'occupation.english_name')
            ?? data_get($reservation, 'occupation.name_en')
            ?? data_get($reservation, 'occupation');

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

        $query = Booking::with('credential')->where('user_id', $userId);

        if ($request->filled('status')) {
            $query->where('booking_status', $request->query('status'));
        }

        if ($request->filled('q')) {
            $term = trim($request->query('q'));
            $query->where(function ($q) use ($term) {
                $q->where('booking_reference', 'like', "%{$term}%")
                    ->orWhere('exam_session_id', 'like', "%{$term}%")
                    ->orWhere('notes', 'like', "%{$term}%");
            });
        }

        $bookings = $query->latest()->paginate(10)->withQueryString();

        $counts = [
            'all'        => Booking::where('user_id', $userId)->count(),
            'pending'    => Booking::where('user_id', $userId)->where('booking_status', 'pending')->count(),
            'processing' => Booking::where('user_id', $userId)->where('booking_status', 'processing')->count(),
            'booked'     => Booking::where('user_id', $userId)->where('booking_status', 'booked')->count(),
            'failed'     => Booking::where('user_id', $userId)->where('booking_status', 'failed')->count(),
            'cancelled'  => Booking::where('user_id', $userId)->where('booking_status', 'cancelled')->count(),
            'refunded'   => Booking::where('user_id', $userId)->where('booking_status', 'refunded')->count(),
        ];

        $svpReservations = null;
        $svpError = null;
        $svpToken = $this->ensureSvpToken($request);
        $svpUserId = Candidate::where('user_id', $userId)
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

        return view('user.bookings.index', [
            'bookings'        => $bookings,
            'counts'          => $counts,
            'filter'          => $request->query('status', 'all'),
            'search'          => $request->query('q', ''),
            'svpReservations' => $svpReservations,
            'svpError'        => $svpError,
            'hasSvpToken'     => (bool) $svpToken,
            'svpUserId'       => $svpUserId,
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

            return redirect()->route('user.bookings.index')->with('error', 'Could not cancel the SVP reservation. Please try again.');
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
            $candidates = Candidate::where('user_id', Auth::id())->latest()->get();
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
                'wallet' => $this->wallet->getWallet($agencyId),
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
            'exam_session_id' => ['required', 'string', 'max:255'],
            'exam_session_name' => ['nullable', 'string', 'max:255'],
            'exam_date' => ['required', 'date_format:Y-m-d'],
            'temporary_hold_id' => ['required', 'string', 'max:100'],
            'language_code' => ['required', 'string', 'max:20'],
            'methodology' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

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

            $candidate = Candidate::where('user_id', Auth::id())->findOrFail($data['candidate_id']);
            $hold = $this->holds->consumeMatching($request, $data);
            if ($hold === null) {
                return back()->withInput()->withErrors([
                    'temporary_hold_id' => 'Create a new temporary SVP hold for the selected city, center, session, and date before confirming the reschedule.',
                ]);
            }

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
                'exam_session_id' => $data['exam_session_id'],
                'exam_session_name' => $data['exam_session_name'] ?? null,
                'exam_date' => $data['exam_date'],
                'temporary_hold_id' => $hold['id'],
                'temporary_hold_expires_at' => $hold['expires_at'] ?? null,
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

        $token = $this->ensureSvpToken($request);

        // Wallet + candidates synced from SVP profile after login.
        $wallet = $this->wallet->getWallet($agencyId);
        $candidates = Candidate::where('user_id', Auth::id())->latest()->get();

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
            return response()->json([
                'success' => true,
                'data' => ['dates' => $this->portalAvailability->bookingDates($data['category_id'], $data['city'])],
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
        ]);

        try {
            $centers = filled($data['date'] ?? null)
                ? $this->portalAvailability->bookingCentersForDate(
                    $data['category_id'],
                    $data['city'],
                    $data['date'],
                    $data['occupation_id'],
                    $data['language_code'],
                )
                : $this->portalAvailability->bookingCenters(
                    $data['category_id'],
                    $data['city'],
                    $data['occupation_id'],
                    $data['language_code'],
                )['test_centers'];
            $availabilitySource = 'portal_availability';
            $fallback = false;

            if (filled($data['date'] ?? null) && $centers === []) {
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

            return response()->json([
                'success' => true,
                'availability_source' => $availabilitySource,
                'fallback' => $fallback,
                'data' => ['test_centers' => $centers],
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup test-centers failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live test centers.'], 503);
        }
    }

    public function lookupSessions(Request $request)
    {
        $request->validate([
            'city' => 'required|string',
            'category_id' => 'required|string',
            'test_center_id' => 'required|string',
            'exam_date' => 'nullable|date_format:Y-m-d',
            'reservation_id' => 'nullable|string',
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        $params = $request->only([
            'city', 'category_id', 'test_center_id', 'exam_date', 'reservation_id', 'available_seats',
        ]);
        $params = array_filter($params, static fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->booking->sessionsForCenter($token, $params);
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
            ->findOrFail($data['candidate_id']);

        try {
            $status = $this->credits->status(
                $token,
                $candidate,
                $data['occupation_id'],
                $data['methodology'] ?? config('svp.default_methodology', 'in_person')
            );

            return response()->json(['success' => true, 'data' => ['credits' => $status['credits']]]);
        } catch (\Throwable $e) {
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

        $data = $request->validate([
            'candidate_id'    => ['required', 'integer', 'exists:candidates,id'],
            'occupation_id'    => ['required', 'string'],
            'category_id'      => ['required', 'string'],
            'city'             => ['required', 'string', 'max:120'],
            'test_center_id'   => ['required', 'string'],
            'test_center_name' => ['required', 'string', 'max:255'],
            'exam_session_id'  => ['required', 'string'],
            'exam_session_name'=> ['nullable', 'string', 'max:255'],
            'exam_date'        => ['required', 'date'],
            'temporary_hold_id' => ['required', 'string', 'max:100'],
            'language_code'    => ['required', 'string', 'max:120'],
            'methodology'      => ['nullable', 'string', 'max:40'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

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
            ->findOrFail($data['candidate_id']);

        $hold = $this->holds->consumeMatching($request, $data);
        if ($hold === null) {
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
            'exam_session_id'  => $data['exam_session_id'],
            'exam_session_name'=> $data['exam_session_name'] ?? null,
            'exam_date'        => $data['exam_date'],
            'temporary_hold_id' => $hold['id'],
            'temporary_hold_expires_at' => $hold['expires_at'] ?? null,
            'language_code'    => strtoupper(trim($data['language_code'])),
            'methodology'      => $data['methodology'] ?? config('svp.default_methodology', 'in_person'),
            'notes'            => $data['notes'] ?? null,
        ]);

        if (! $result['success']) {
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

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || ! is_string($resultCode) || ! str_starts_with($resultCode, '000.')) {
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

                return redirect($showRoute)->with('error', 'SVP payment was not confirmed. The reserved portal fee has been refunded to the main wallet balance.');
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
