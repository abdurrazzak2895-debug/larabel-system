<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Services\UserWalletService;
use Illuminate\Support\Facades\Auth;

class DepositController extends Controller
{
    public function __construct(private UserWalletService $userWallet)
    {
        $this->middleware('auth.multi');
    }

    public function index()
    {
        $userId = (int) Auth::id();

        return view('user.deposits.index', [
            'deposits' => DepositRequest::where('user_id', $userId)->latest()->paginate(10),
            'totalDeposited' => DepositRequest::where('user_id', $userId)->where('status', 'approved')->sum('amount'),
            'pendingCount' => DepositRequest::where('user_id', $userId)->where('status', 'pending')->count(),
            'approvedCount' => DepositRequest::where('user_id', $userId)->where('status', 'approved')->count(),
            'rejectedCount' => DepositRequest::where('user_id', $userId)->where('status', 'rejected')->count(),
            'walletBalance' => (float) $this->userWallet->getWallet($userId)->available_balance,
        ]);
    }
}
