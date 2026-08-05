<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    public function __construct()
    {
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $agencyId = Auth::user()->agency_id;

        return view('agency.refunds.index', [
            'refunds' => RefundRequest::with('booking')
                ->where('agency_id', $agencyId)
                ->latest()
                ->paginate(10),
        ]);
    }

    public function create()
    {
        $agencyId = Auth::user()->agency_id;

        return view('agency.refunds.create', [
            'bookings' => \App\Models\Booking::where('agency_id', $agencyId)->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'amount'     => ['required', 'numeric', 'min:1'],
            'reason'     => ['required', 'string', 'max:1000'],
        ]);

        $data['agency_id'] = Auth::user()->agency_id;

        app(\App\Services\RefundService::class)->request($data);

        return redirect()->route('agency.refunds.index')->with('success', 'Refund request submitted.');
    }
}