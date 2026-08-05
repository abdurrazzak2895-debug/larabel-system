<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\RefundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth.multi');
    }

    public function index()
    {
        $agencyId = Auth::user()->agency_id;

        $refunds = RefundRequest::with('booking')->where('agency_id', $agencyId)->latest()->paginate(10);

        return view('user.refunds.index', [
            'refunds'       => $refunds,
            'totalRefunded' => RefundRequest::where('agency_id', $agencyId)
                ->whereIn('status', ['approved', 'processed'])->sum('amount'),
            'pendingCount'  => RefundRequest::where('agency_id', $agencyId)->where('status', 'pending')->count(),
            'approvedCount' => RefundRequest::where('agency_id', $agencyId)->where('status', 'approved')->count(),
            'rejectedCount' => RefundRequest::where('agency_id', $agencyId)->where('status', 'rejected')->count(),
        ]);
    }

    public function create(Request $request)
    {
        $bookings = Booking::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get();

        return view('user.refunds.create', [
            'bookings'  => $bookings,
            'selectedBooking' => $request->integer('booking') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'amount'     => ['required', 'numeric', 'min:1'],
            'reason'     => ['required', 'string', 'max:1000'],
        ]);

        $booking = Booking::findOrFail($data['booking_id']);

        abort_unless($booking->user_id === Auth::id(), 403);

        $data['agency_id'] = $booking->agency_id;

        app(\App\Services\RefundService::class)->request($data);

        return redirect()->route('user.refunds.index')->with('success', 'Refund request submitted.');
    }
}