<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Candidate;
use App\Services\BookingService;
use App\Services\SvpReservationCreditService;
use App\Services\SvpTemporaryHoldService;
use App\Services\WalletService;
use App\Services\PortalAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Agency-facing booking module UI.
 *
 * Every request that talks to the external SVP API must carry the
 * bearer token that the agency obtained through the SVP login flow
 * (stored in the session as `svp_token`). If it is missing we send
 * the user through the SVP login again instead of failing 500.
 */
class BookingController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private SvpReservationCreditService $credits,
        private SvpTemporaryHoldService $holds,
        private WalletService $wallet,
        private PortalAvailabilityService $portalAvailability
    ) {
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
     * GET /agency/bookings — listing page (calls SVP reservation details).
     */
    public function index(Request $request)
    {
        $agencyId = (int) Auth::user()->agency_id;

        // Local bookings created through our system (fast DB query).
        $localBookings = Booking::with('attempts')
            ->where('agency_id', $agencyId)
            ->latest()
            ->paginate(10);

        // Live reservations from SVP (requires bearer token).
        $svpReservations = null;
        $svpError        = null;
        $token = $this->ensureSvpToken($request);

        if ($token) {
            try {
                $svpReservations = $this->booking->reservations($token);
            } catch (\Throwable $e) {
                Log::warning('SVP reservations fetch failed', ['error' => $e->getMessage()]);
                $svpError = 'Could not load live reservations from SVP.';
            }
        } else {
            $svpError = 'Sign in with your SVP account to see live reservations.';
        }

        return view('agency.bookings', [
            'localBookings'   => $localBookings,
            'svpReservations' => $svpReservations,
            'svpError'        => $svpError,
            'hasSvpToken'     => (bool) $token,
        ]);
    }

    /**
     * GET /agency/bookings/create — booking wizard.
     */
    public function create(Request $request)
    {
        $token = $this->ensureSvpToken($request);
        $agencyId = (int) Auth::user()->agency_id;

        // Profile / wallet / lookup data for the wizard.
        $wallet = $this->wallet->getWallet($agencyId);

        // Candidates synced from SVP profile after login.
        $candidates = Candidate::where('agency_id', $agencyId)->latest()->get();

        $occupations = [];
        $cities      = [];
        $categories  = [];
        $sessions    = [];
        $constraints = [];
        $profile     = null;
        $svpError    = null;

        if (! $token) {
            $svpError = 'Connect your candidate SVP account before creating a hold or booking.';
        }

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

        return view('agency.booking-create', [
            'wallet'      => $wallet,
            'candidates'  => $candidates,
            'occupations' => $occupations,
            'cities'      => $cities,
            'categories'  => $categories,
            'sessions'    => $sessions,
            'constraints' => $constraints,
            'profile'     => $profile,
            'svpError'    => $svpError,
            'svpToken'    => $token,
        ]);
    }

    /**
     * GET /agency/bookings/credit-status?candidate_id=&occupation_id=&methodology=
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

        $candidate = Candidate::where('agency_id', (int) Auth::user()->agency_id)
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
     * GET /agency/bookings/available-dates?session_id=…
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
            $response = $this->booking->availableDates(
                $token,
                $request->query('session_id'),
                $request->only(['category_id', 'city'])
            );
            return $this->expiredSvpResponse($request, $response);
        } catch (\Throwable $e) {
            Log::error('SVP availableDates failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch dates.'], 503);
        }
    }

    /**
     * GET /agency/bookings/lookup/occupations?search=&page=1
     * AJAX: search occupations with pagination from SVP API.
     */
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

    /**
     * GET /agency/bookings/lookup/cities?category_id=…
     * AJAX: return cities available for the selected SVP category.
     */
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
     * GET /agency/bookings/lookup/languages?occupation_id=…
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

    /**
     * GET /agency/bookings/lookup/categories?occupation_id=…
     * AJAX: return categories for the given occupation.
     */
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

    /**
     * GET /agency/bookings/lookup/dates?city=&category_id=
     * AJAX: return Portal Availability dates for the selected category and city.
     */
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

    /**
     * GET /agency/bookings/lookup/test-centers?city=&category_id=&date=
     * AJAX: return date-specific Portal Availability center slots.
     */
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
            return response()->json([
                'success' => true,
                'data' => ['test_centers' => filled($data['date'] ?? null)
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
                    )['test_centers']],
            ]);
        } catch (\Throwable $e) {
            Log::error('Portal lookup test-centers failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'Unable to fetch live test centers.'], 503);
        }
    }

    /**
     * GET /agency/bookings/lookup/sessions?city=&category_id=&test_center_id=
     * AJAX: return exact-center exam sessions for the selected filters.
     */
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
     * POST /agency/bookings — run the full BookingService workflow.
     */
    public function store(Request $request)
    {
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

        $agencyId = (int) Auth::user()->agency_id;
        $candidate = Candidate::where('agency_id', $agencyId)

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
                ->route('agency.bookings.payment', $result['booking']->id)
                ->with('success', 'SVP has created a card checkout for this reservation. Complete payment only on the official SVP page.');
        }

        return redirect()
            ->route('agency.bookings.show', $result['booking']->id)
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
        $this->authorizeOwnership($booking);
        $attempt = $booking->attempts()->latest()->first();
        $providerResponse = (array) ($attempt?->provider_response ?? []);
        $checkoutUrl = $this->booking->checkoutUrlFromProviderResponse($providerResponse);
        $widgetCheckout = $this->booking->widgetCheckoutFromProviderResponse($providerResponse);

        if ((! is_string($checkoutUrl) || $checkoutUrl === '') && $widgetCheckout === null) {
            return redirect()
                ->route('agency.bookings.show', $booking->id)
                ->with('error', 'No active SVP card checkout was found for this booking.');
        }

        return view('bookings.svp-payment', [
            'booking' => $booking,
            'checkoutUrl' => $checkoutUrl,
            'widgetCheckoutId' => $widgetCheckout['checkout_id'] ?? null,
            'widgetIntegrity' => $widgetCheckout['integrity'] ?? null,
            'widgetScriptUrl' => config('svp.hyperpay_widget_url'),
            'shopperResultUrl' => route('agency.bookings.payment-return', $booking->id),
            'backRoute' => route('agency.bookings.show', $booking->id),
            'verifyRoute' => route('agency.bookings.verify-reservation', $booking->id),
            'layout' => 'layouts.panel',
        ]);
    }

    /**
     * Receive HyperPay's COPYandPAY shopper result and verify it server-side.
     */
    public function paymentReturn(Request $request, Booking $booking)
    {
        $this->authorizeOwnership($booking);
        $resourcePath = trim((string) $request->query('resourcePath', ''));
        $showRoute = route('agency.bookings.show', $booking->id);

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

                return redirect($showRoute)->with('error', 'SVP payment was not confirmed. Please review the payment result and try again if needed.');
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
     * Read the current state of the exact selected SVP reservation. This does
     * not submit payment, create a reservation, or alter SVP state.
     */
    public function verifyReservation(Request $request, Booking $booking)
    {
        $this->authorizeOwnership($booking);
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

    /**
     * GET /agency/bookings/{booking} — single booking detail + SVP validation.
     */
    public function show(Request $request, Booking $booking)
    {
        $this->authorizeOwnership($booking);

        $svpValidation = null;
        $svpError      = null;
        $token = $this->ensureSvpToken($request);

        if ($token) {
            try {
                $svpValidation = $this->booking->validateReservation($token);
            } catch (\Throwable $e) {
                Log::warning('SVP validateReservation failed', ['error' => $e->getMessage()]);
                $svpError = 'Could not validate reservation against SVP.';
            }
        }

        return view('agency.booking-show', [
            'booking'       => $booking->load('attempts', 'logs'),
            'svpValidation' => $svpValidation,
            'svpError'      => $svpError,
        ]);
    }

    /**
     * POST /agency/bookings/{booking}/cancel — cancel + refund.
     */
    public function cancel(Request $request, Booking $booking)
    {
        $this->authorizeOwnership($booking);

        if (! in_array($booking->booking_status, ['booked', 'processing'], true)) {
            return back()->with('error', 'Only booked reservations can be cancelled.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->booking->cancelBooking($booking, (float) $data['amount'], $data['reason']);

        return redirect()
            ->route('agency.bookings.show', $booking->id)
            ->with('success', 'Booking cancelled. Refund initiated.');
    }

    private function authorizeOwnership(Booking $booking): void
    {
        if ((int) $booking->agency_id !== (int) Auth::user()->agency_id) {
            abort(403, 'You do not have access to this booking.');
        }
    }
}
