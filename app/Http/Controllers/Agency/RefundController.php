<?php

namespace App\Http\Controllers\Agency;

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
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $agencyId = (int) Auth::user()->agency_id;

        return view('agency.refunds.index', [
            'refunds' => RefundRequest::with(['booking.user', 'booking.credential'])
                ->where('agency_id', $agencyId)
                ->latest()
                ->paginate(10),
        ]);
    }

    public function create()
    {
        return view('agency.refunds.create', [
            'bookings' => $this->eligibleBookings((int) Auth::user()->agency_id)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $agencyId = (int) Auth::user()->agency_id;

        $booking = Booking::with('refundRequests')
            ->where('agency_id', $agencyId)
            ->findOrFail((int) $data['booking_id']);

        if (! in_array($booking->booking_status, ['failed', 'cancelled'], true)) {
            return back()->withInput()->with('error', 'A refund request is only available for failed or cancelled bookings.');
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
                'agency_id' => $agencyId,
                'amount' => $amount,
                'reason' => $data['reason'],
            ]);
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('agency.refunds.index')
            ->with('success', "Refund request #{$refund->id} submitted.");
    }

    public function update(Request $request, RefundRequest $refund)
    {
        abort_unless((int) $refund->agency_id === (int) Auth::user()->agency_id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($data['status'] === 'approved') {
                $this->refundService->approve($refund);
                $message = "Refund #{$refund->id} approved and credited.";
            } else {
                $this->refundService->reject($refund, (string) ($data['reason'] ?: 'Rejected by agency manager'));
                $message = "Refund #{$refund->id} rejected.";
            }
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }

    private function eligibleBookings(int $agencyId)
    {
        return Booking::query()
            ->with(['credential', 'user', 'refundRequests'])
            ->where('agency_id', $agencyId)
            ->whereIn('booking_status', ['failed', 'cancelled'])
            ->where('portal_booking_fee', '>', 0)
            ->whereDoesntHave('refundRequests', fn ($query) => $query->whereIn('status', ['pending', 'approved', 'processed']))
            ->latest();
    }
}
