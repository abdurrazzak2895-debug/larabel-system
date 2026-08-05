<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaccCredential;
use App\Services\BookingService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private BookingService $booking,
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

        return view('user.bookings.index', [
            'bookings' => $bookings,
            'counts'   => $counts,
            'filter'   => $request->query('status', 'all'),
            'search'   => $request->query('q', ''),
        ]);
    }

    /**
     * GET /user/bookings/create — SVP booking wizard scoped to the logged-in user.
     */
    public function create(Request $request)
    {
        $token = $this->ensureSvpToken($request);
        $agencyId = (int) Auth::user()->agency_id;

        if (! $token) {
            return redirect()->route('svp.login.form')
                ->with('status', 'Please sign in with your SVP account to create a booking.');
        }

        // Wallet + candidates saved on file for "who to book for".
        $wallet = $this->wallet->getWallet($agencyId);
        $candidates = PaccCredential::where('agency_id', $agencyId)->latest()->get();

        $occupations = [];
        $cities      = [];
        $categories  = [];
        $sessions    = [];
        $constraints = [];
        $profile     = null;
        $svpError    = null;

        try {
            $occupations = $this->booking->occupations($token);
            $cities      = $this->booking->cities($token);
            $categories  = $this->booking->categories($token);
            $sessions    = $this->booking->sessions($token);
            $constraints = $this->booking->examConstraints($token);
        } catch (\Throwable $e) {
            Log::warning('SVP booking lookup failed', ['error' => $e->getMessage()]);
            $svpError = 'Could not load SVP booking data. Please try again.';
        }

        return view('user.bookings.create', [
            'wallet'      => $wallet,
            'candidates'  => $candidates,
            'occupations' => $occupations,
            'cities'      => $cities,
            'categories'  => $categories,
            'sessions'    => $sessions,
            'constraints' => $constraints,
            'profile'     => $profile,
            'svpError'    => $svpError,
        ]);
    }

    /**
     * GET /user/bookings/available-dates?session_id=…
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
     * POST /user/bookings — run the full BookingService workflow.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'candidate_id'    => ['required', 'integer', 'exists:pacc_credentials,id'],
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
        $candidate = PaccCredential::where('agency_id', $agencyId)
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
            ->route('user.bookings.show', $result['booking']->id)
            ->with('success', 'Booking confirmed.');
    }

    public function show(Booking $booking)
    {
        abort_unless($booking->user_id === Auth::id(), 403);

        $booking->load(['credential', 'logs', 'attempts', 'refundRequests']);

        return view('user.bookings.show', [
            'booking'  => $booking,
            'logs'     => $booking->logs,
            'attempts' => $booking->attempts,
            'refunds'  => $booking->refundRequests,
        ]);
    }
}
