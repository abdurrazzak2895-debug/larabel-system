<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\RefundRequest;
use App\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    public function __construct(private RefundService $refundService)
    {
        $this->middleware('auth.multi');
    }

    public function index()
    {
        $userId = Auth::id();
        $ownedBooking = fn ($query) => $query->where('user_id', $userId);

        $refunds = RefundRequest::with('booking')
            ->whereHas('booking', $ownedBooking)
            ->latest()
            ->paginate(10);

        return view('user.refunds.index', [
            'refunds'          => $refunds,
            'eligibleBookings' => $this->eligibleBookings((int) $userId)->get(),
            'totalRefunded'    => RefundRequest::whereHas('booking', $ownedBooking)
                ->whereIn('status', ['approved', 'processed'])->sum('amount'),
            'pendingCount'     => RefundRequest::whereHas('booking', $ownedBooking)->where('status', 'pending')->count(),
            'approvedCount'    => RefundRequest::whereHas('booking', $ownedBooking)->where('status', 'approved')->count(),
            'rejectedCount'    => RefundRequest::whereHas('booking', $ownedBooking)->where('status', 'rejected')->count(),
        ]);
    }

    public function create(Request $request)
    {
        return view('user.refunds.create', [
            'eligibleBookings' => $this->eligibleBookings((int) Auth::id())->get(),
            'selectedBookingId' => (int) $request->query('booking', 0),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $booking = Booking::with('refundRequests')
            ->where('user_id', Auth::id())
            ->findOrFail((int) $data['booking_id']);

        if (! in_array($booking->booking_status, ['failed', 'cancelled'], true)) {
            return back()->withInput()->with('error', 'A manual refund request is only available for failed or cancelled bookings.');
        }

        $amount = (float) ($booking->portal_booking_fee ?? 0);
        if ($amount <= 0) {
            return back()->withInput()->with('error', 'This booking has no refundable portal fee.');
        }

        if ($booking->refundRequests->contains(fn (RefundRequest $refund): bool => in_array($refund->status, ['pending', 'approved', 'processed'], true))) {
            return back()->withInput()->with('error', 'A refund request already exists for this booking.');
        }

        try {
            $refund = $this->refundService->request([
                'booking_id' => $booking->id,
                'agency_id' => $booking->agency_id,
                'amount' => $amount,
                'reason' => $data['reason'],
            ]);
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('user.refunds.index')
            ->with('success', "Refund request #{$refund->id} submitted for administrator review.");
    }

    private function eligibleBookings(int $userId)
    {
        return Booking::query()
            ->with(['credential', 'refundRequests'])
            ->where('user_id', $userId)
            ->whereIn('booking_status', ['failed', 'cancelled'])
            ->where('portal_booking_fee', '>', 0)
            ->whereDoesntHave('refundRequests', fn ($query) => $query->whereIn('status', ['pending', 'approved', 'processed']))
            ->latest();
    }
}
