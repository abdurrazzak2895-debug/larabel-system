<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth.multi');
    }

    public function index()
    {
        $wallet = Auth::user()->wallet;

        $transactions = $wallet
            ? $wallet->transactions()->latest()->paginate(20)
            : new LengthAwarePaginator([], 0, 20);

        $totals = [
            'deposit'           => $wallet ? $wallet->transactions()->where('type', 'deposit')->sum('amount') : 0,
            'booking_debit'     => $wallet ? $wallet->transactions()->where('type', 'booking_debit')->sum('amount') : 0,
            'refund'            => $wallet ? $wallet->transactions()->where('type', 'refund')->sum('amount') : 0,
        ];

        return view('user.wallets.index', [
            'wallet'       => $wallet,
            'balance'      => (float) ($wallet->available_balance ?? 0),
            'creditLimit'  => (float) ($wallet->credit_limit ?? 0),
            'transactions' => $transactions,
            'totals'       => $totals,
        ]);
    }
}
