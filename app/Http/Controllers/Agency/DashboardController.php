<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DepositRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\UserWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $user = Auth::user();

        if (! $user || empty($user->agency_id)) {
            return redirect()->route('login')
                ->with('status', 'Your account is not assigned to an agency yet. Please contact the administrator.');
        }

        $agencyId = (int) $user->agency_id;

        return view('agency.dashboard', [
            'managedUserBalance' => UserWallet::whereHas('user', fn ($query) => $query->where('agency_id', $agencyId))->sum('available_balance'),
            'todayBookings'   => Booking::where('agency_id', $agencyId)
                ->whereDate('created_at', today())->count(),
            'failedBookings'  => Booking::where('agency_id', $agencyId)
                ->where('booking_status', 'failed')->whereDate('created_at', today())->count(),
            'deposits'        => DepositRequest::with('user')->where('agency_id', $agencyId)->whereNotNull('user_id')->latest()->take(5)->get(),
            'refunds'         => RefundRequest::where('agency_id', $agencyId)->latest()->take(5)->get(),
            'activeUsers'     => User::where('agency_id', $agencyId)->where('status', true)->count(),
        ]);
    }

}