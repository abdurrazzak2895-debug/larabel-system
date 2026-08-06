<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Candidate;
use App\Services\BookingService;
use App\Services\WalletService;
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
        private WalletService $wallet
    ) {
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
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to create a booking.');
        }

        try {
            $profile     = $this->booking->sessions($token)->getData(true);   // proxy to SVP profile-ish
            $occupations = $this->booking->occupations($token)->getData(true);
            $cities      = $this->booking->cities($token)->getData(true);
            $categories  = $this->booking->categories($token)->getData(true);
            $sessions    = $this->booking->sessions($token)->getData(true);
            $constraints = $this->booking->examConstraints($token)->getData(true);
        } catch (\Throwable $e) {
            Log::warning('SVP booking lookup failed', ['error' => $e->getMessage()]);
            $svpError = 'Could not load SVP booking data. Please try again.';
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
     * GET /agency/bookings/available-dates?session_id=…
     * AJAX: fetch available dates for a chosen exam session.
     */
    public function availableDates(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $response = $this->booking->availableDates($token);
            return response()->json($response->getData(true), $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP availableDates failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch dates.'], 503);
        }
    }

    /**
     * GET /agency/bookings/lookup/cities?occupation_id=…
     * AJAX: return cities that have sessions for the given occupation.
     */
    public function lookupCities(Request $request)
    {
        $request->validate(['occupation_id' => 'nullable|string']);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $response = $this->booking->cities($token, $request->query('occupation_id'));
            return response()->json($response->getData(true), $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP lookup cities failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch cities.'], 503);
        }
    }

    /**
     * GET /agency/bookings/lookup/categories?occupation_id=…
     * AJAX: return categories for the given occupation.
     */
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

    /**
     * GET /agency/bookings/lookup/test-centers?city=&occupation_id=
     * AJAX: return test centers for the given filters.
     */
    public function lookupTestCenters(Request $request)
    {
        $request->validate([
            'city' => 'nullable|string',
            'occupation_id' => 'nullable|string',
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        try {
            $response = $this->booking->testCenters($token, $request->query('city'), $request->query('occupation_id'));
            return response()->json($response->getData(true), $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('SVP lookup test-centers failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to fetch test centers.'], 503);
        }
    }

    /**
     * GET /agency/bookings/lookup/sessions?city=&occupation_id=&test_center_id=
     * AJAX: return exam sessions for the given filters.
     */
    public function lookupSessions(Request $request)
    {
        $request->validate([
            'city' => 'nullable|string',
            'occupation_id' => 'nullable|string',
            'test_center_id' => 'nullable|string',
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            return response()->json(['error' => 'SVP session expired.'], 401);
        }

        $params = $request->only(['city', 'occupation_id', 'test_center_id']);
        $params = array_filter($params);

        try {
            $response = $this->booking->sessions($token, $params);
            return response()->json($response->getData(true), $response->getStatusCode());
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
            'occupation_id'   => ['required', 'string'],
            'exam_session_id' => ['required', 'string'],
            'exam_date'       => ['required', 'date'],
            'amount'          => ['required', 'numeric', 'min:1'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $token = $this->ensureSvpToken($request);
        if (! $token) {
            throw ValidationException::withMessages([
                'candidate_id' => 'Your SVP session has expired. Please sign in again.',
            ])->redirectTo(route('svp.login.form'));
        }

        $agencyId = (int) Auth::user()->agency_id;
        $candidate = Candidate::where('agency_id', $agencyId)
            ->findOrFail($data['candidate_id']);

        $result = $this->booking->completeBooking($token, [
            'agency_id'       => $agencyId,
            'user_id'         => Auth::id(),
            'credential_id'   => $candidate->id,
            'occupation_id'   => $data['occupation_id'],
            'exam_session_id' => $data['exam_session_id'],
            'amount'          => (float) $data['amount'],
        ]);

        if (! $result['success']) {
            return back()
                ->withInput()
                ->with('error', $result['error'] ?? 'Booking failed.');
        }

        return redirect()
            ->route('agency.bookings.show', $result['booking']->id)
            ->with('success', 'Booking confirmed.');
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
