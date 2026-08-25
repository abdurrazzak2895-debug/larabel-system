<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DepositRequest;
use App\Models\Setting;
use App\Services\UserWalletService;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
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

        $deposits = DepositRequest::where('user_id', $userId)->latest()->paginate(10);

        return view('user.deposits.index', [
            'deposits'          => $deposits,
            'totalDeposited'    => DepositRequest::where('user_id', $userId)->where('status', 'approved')->sum('amount'),
            'pendingCount'      => DepositRequest::where('user_id', $userId)->where('status', 'pending')->count(),
            'approvedCount'     => DepositRequest::where('user_id', $userId)->where('status', 'approved')->count(),
            'rejectedCount'     => DepositRequest::where('user_id', $userId)->where('status', 'rejected')->count(),
            'walletBalance'     => (float) $this->userWallet->getWallet($userId)->available_balance,
        ]);
    }

    public function create()
    {
        return view('user.deposits.create', [
            'walletBalance' => (float) $this->userWallet->getWallet((int) Auth::id())->available_balance,
            'paymentMethods' => config('payments.portal_deposit_methods', ['bkash', 'nagad']),
            'merchantName' => Setting::where('key', 'portal_merchant_name')->value('value') ?: config('payments.merchant_name', 'Portal Wallet'),
        ]);
    }

    public function store(Request $request)
    {
        $agencyId = Auth::user()->agency_id;

        // deposit_requests.agency_id is a required FK — never let a user
        // without an agency (null / 0) submit a deposit (FK violation 500).
        if (! $agencyId || ! Agency::whereKey((int) $agencyId)->exists()) {
            return back()->with('error', 'Your account is not assigned to an agency yet. Please contact the administrator.');
        }

        $data = $request->validate([
            'amount'             => ['required', 'numeric', 'min:1'],
            'payment_method'     => ['required', Rule::in(config('payments.portal_deposit_methods', ['bkash', 'nagad']))],
            'mfs_sender_phone'   => ['required', 'string', 'max:32', 'regex:/^(?:01[3-9][0-9]{8}|\+?8801[3-9][0-9]{8})$/'],
            'mfs_transaction_id' => [
                'required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('deposit_requests', 'mfs_transaction_id')->where(fn ($query) => $query->where('payment_method', $request->input('payment_method'))),
            ],
            'receipt'            => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $data['agency_id'] = (int) $agencyId;
        $data['user_id'] = (int) Auth::id();
        $data['payment_method'] = strtolower((string) $data['payment_method']);

        app(\App\Services\DepositService::class)->submit($data);

        return redirect()->route('user.deposits.index')->with('success', 'Deposit request submitted.');
    }
}