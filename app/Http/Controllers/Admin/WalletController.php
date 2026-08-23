<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyWallet;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function index()
    {
        return view('admin.wallets.index', [
            'wallets' => AgencyWallet::with('agency')->latest()->paginate(20),
        ]);
    }

    public function credit(Request $request, Agency $agency)
    {
        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string'],
        ]);

        $this->wallet->manualAdjust(
            $agency->id,
            (float) $data['amount'],
            $data['reference'] ?? 'manual_credit',
            ['type' => 'credit', 'admin_id' => Auth::guard('admin')->id() ?? auth()->id()]
        );

        return back()->with('success', "Credited {$agency->name} wallet with {$data['amount']}.");
    }

    public function debit(Request $request, Agency $agency)
    {
        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string'],
        ]);

        $this->wallet->manualAdjust(
            $agency->id,
            -(float) $data['amount'],
            $data['reference'] ?? 'manual_debit',
            ['type' => 'debit', 'admin_id' => Auth::guard('admin')->id() ?? auth()->id()]
        );

        return back()->with('success', "Debited {$agency->name} wallet by {$data['amount']}.");
    }

    public function freeze(Agency $agency)
    {
        $wallet = AgencyWallet::where('agency_id', $agency->id)->first();
        $wallet->update(['credit_limit' => 0]);

        return back()->with('success', "Wallet for '{$agency->name}' frozen.");
    }

    public function show(Agency $agency)
    {
        return view('admin.wallets.show', [
            'wallet'      => AgencyWallet::where('agency_id', $agency->id)->first(),
            'transactions' => WalletTransaction::where('wallet_id', function ($q) use ($agency) {
                $q->select('id')->from('agency_wallets')->where('agency_id', $agency->id);
            })->latest()->paginate(20),
        ]);
    }
}