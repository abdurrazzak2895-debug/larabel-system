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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private SvpReservationCreditService $credits,
        private SvpTemporaryHoldService $holds,
        private WalletService $wallet
    ) {
        $this->middleware('auth.multi');
    }

    /**
     * Ensure an SVP bearer token is available, otherwise redirect to SVP login.
     */
    private function ensureSvpToken(Request $request): ?string
    {
        $token = $request->session()->get('svp_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $token;
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
            $reservationData = data_get($payload, 'data.exam_reservation')
                ?? data_get($payload, 'exam_reservation')
                ?? data_get($payload, 'data.reservation')
                ?? data_get($payload, 'reservation')
                ?? $payload;
            $result = $this->normalizeSvpResult((array) $reservationData);

            if (! $result['passed']) {
                return redirect()->route('user.bookings.index')
                    ->with('error', 'The certificate is available only after SVP marks the exam as Passed.');
            }

            return $this->booking->ticketPdf($token, $reservation);
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

        if (! $token) {
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to create a booking.');
        }

        // Wallet + candidates synced from SVP profile after login.
        $wallet = $this->wallet->getWallet($agencyId);
        $candidates = Candidate::where('user_id', Auth::id())->latest()->get();

        $occupations = [];
        $categories  = [];
        $svpError    = null;

        try {
            $occupations = $this->booking->occupationsSearch($token, null, 1, 1000)->getData(true);
            $categories  = $this->booking->categories($token)->getData(true);
        } catch (\Throwable $e) {
            Log::warning('SVP booking lookup failed', ['error' => $e->getMessage()]);
            $svpError = 'Could not load SVP booking data. Please try again.';
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
        $request->validate(['category_id' => 'required|string']);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $response = $this->booking->cities($token, $request->query('category_id'));
            return response()->json($response->getData(true), $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP lookup cities failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch cities.'], 503);
        }
    }

    public function lookupCategories(Request $request)
    {
        $request->validate(['occupation_id' => 'nullable|string']);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $response = $this->booking->categoriesForOccupation($token, $request->query('occupation_id'));
            return response()->json($response->getData(true), $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP lookup categories failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch categories.'], 503);
        }
    }

    public function lookupOccupations(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string',
            'page'   => 'nullable|integer|min:1',
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $response = $this->booking->occupationsSearch(
                $token,
                $request->query('search'),
                (int) ($request->query('page') ?? 1),
                1000
            );
            return response()->json($response->getData(true), $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP lookup occupations failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch occupations.'], 503);
        }
    }

    public function lookupTestCenters(Request $request)
    {
        $request->validate([
            'city' => 'required|string',
            'category_id' => 'required|string',
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $response = $this->booking->testCenters($token, $request->query('city'), $request->query('category_id'));
            return response()->json($response->getData(true), $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP lookup test-centers failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch test centers.'], 503);
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
            $response = $this->booking->sessions($token, $params);
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
            return response()->json($response->getData(true), $response->getStatusCode());
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
            'language_code'    => ['required', 'string', 'max:20'],
            'methodology'      => ['nullable', 'string', 'max:40'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

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
