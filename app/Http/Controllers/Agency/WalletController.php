<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\AgencyWallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    public function __construct()
    {
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $agencyId = Auth::user()->agency_id;

        return view('agency.wallets.index', [
            'wallet'      => AgencyWallet::where('agency_id', $agencyId)->first(),
            'transactions' => WalletTransaction::where('wallet_id', function ($q) use ($agencyId) {
                $q->select('id')->from('agency_wallets')->where('agency_id', $agencyId);
            })->latest()->paginate(20),
        ]);
    }

    public function ledger()
    {
        $agencyId = Auth::user()->agency_id;

        return view('agency.wallets.ledger', [
            'transactions' => WalletTransaction::where('wallet_id', function ($q) use ($agencyId) {
                $q->select('id')->from('agency_wallets')->where('agency_id', $agencyId);
            })->latest()->paginate(25),
        ]);
    }
}