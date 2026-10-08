<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AgencyWallet;
use App\Models\Booking;
use App\Models\Candidate;
use App\Models\DepositRequest;
use App\Models\Notification;
use App\Models\WalletTransaction;
use App\Services\SvpOtp\SvpAutoSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(private SvpAutoSession $autoSession)
    {
    }

    public function index(Request $request)
    {
        $userId = Auth::id();
        $user = Auth::user();
        $agencyId = $user->agency_id;

        $wallet = $agencyId
            ? AgencyWallet::firstOrCreate(
                ['agency_id' => $agencyId],
                ['available_balance' => 0, 'reserved_balance' => 0, 'credit_limit' => 0]
            )
            : null;

        $counts = [
            'all'       => Booking::where('user_id', $userId)->count(),
            'booked'    => Booking::where('user_id', $userId)->where('booking_status', 'booked')->count(),
            'inProgress'=> Booking::where('user_id', $userId)
                ->whereIn('booking_status', ['pending', 'processing'])->count(),
            'failed'    => Booking::where('user_id', $userId)->where('booking_status', 'failed')->count(),
        ];

        $activeCandidateId = $this->autoSession->activeCandidateId($request);
        $connectedIds = $this->autoSession->connectedCandidateIds($request);
        if ($activeCandidateId === null && $connectedIds !== []) {
            $activeCandidateId = (int) $connectedIds[0];
            $this->autoSession->setActiveCandidate($request, $activeCandidateId);
        }
        $connectedCandidateIds = array_flip($connectedIds);
        $candidates = Candidate::where('user_id', $userId)->latest()->get();
        $candidates->each(function (Candidate $candidate) use ($activeCandidateId, $connectedCandidateIds): void {
            $candidate->setAttribute('is_connected', isset($connectedCandidateIds[$candidate->id]));
            $candidate->setAttribute('is_selected', (int) $candidate->id === (int) $activeCandidateId);
        });

        return view('user.dashboard', [
            'wallet'             => $wallet,
            'walletBalance'      => $wallet?->available_balance ?? 0,
            'creditLimit'        => $wallet?->credit_limit ?? 0,
            'totalBookings'      => $counts['all'],
            'confirmedBookings'  => $counts['booked'],
            'pendingBookings'    => $counts['inProgress'],
            'failedBookings'     => $counts['failed'],
            'upcomingExam'       => Booking::where('user_id', $userId)
                ->where('booking_status', 'booked')
                ->latest()
                ->first(),
            'notifications'      => Notification::where('user_id', $userId)->latest()->take(5)->get(),
            'unreadNotifications'=> Notification::where('user_id', $userId)->whereNull('read_at')->count(),
            'latestDeposit'      => DepositRequest::where('agency_id', $agencyId)->latest()->first(),
            'pendingDeposits'    => DepositRequest::where('agency_id', $agencyId)
                ->where('status', 'pending')->count(),
            'recentTransactions' => $agencyId
                ? WalletTransaction::whereHas('wallet', fn ($q) => $q->where('agency_id', $agencyId))
                    ->latest()->take(5)->get()
                : collect(),
            'candidates'         => $candidates,
        ]);
    }
}
