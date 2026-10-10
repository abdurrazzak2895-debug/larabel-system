<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
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
        $userId = Auth::id();
        $ownedBooking = fn ($query) => $query->where('user_id', $userId);

        $refunds = RefundRequest::with('booking')
            ->whereHas('booking', $ownedBooking)
            ->latest()
            ->paginate(10);

        return view('user.refunds.index', [
            'refunds'       => $refunds,
            'totalRefunded' => RefundRequest::whereHas('booking', $ownedBooking)
                ->whereIn('status', ['approved', 'processed'])->sum('amount'),
            'pendingCount'  => RefundRequest::whereHas('booking', $ownedBooking)->where('status', 'pending')->count(),
            'approvedCount' => RefundRequest::whereHas('booking', $ownedBooking)->where('status', 'approved')->count(),
            'rejectedCount' => RefundRequest::whereHas('booking', $ownedBooking)->where('status', 'rejected')->count(),
        ]);
    }

    public function create(Request $request)
    {
        abort(403, 'Portal-fee refunds are disabled.');
    }

    public function store(Request $request)
    {
        abort(403, 'Portal-fee refunds are disabled.');
    }
}
