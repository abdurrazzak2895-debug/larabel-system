<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\DepositService;
use App\Services\UserWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DepositController extends Controller
{
    private const ELIGIBLE_SOURCES = ['public_registration', 'admin_control'];

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

    public function create()
    {
        $user = $this->eligibleUser();

        return view('user.deposits.create', [
            'user' => $user,
            'paymentMethods' => config('payments.portal_deposit_methods', ['bkash', 'nagad']),
            'merchantName' => $this->merchantName(),
            'merchantNumbers' => [
                'bkash' => Setting::where('key', 'bkash_merchant_number')->value('value') ?: config('payments.merchant_numbers.bkash'),
                'nagad' => Setting::where('key', 'nagad_merchant_number')->value('value') ?: config('payments.merchant_numbers.nagad'),
            ],
        ]);
    }

    public function store(Request $request, DepositService $deposits)
    {
        $user = $this->eligibleUser();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', Rule::in(config('payments.portal_deposit_methods', ['bkash', 'nagad']))],
            'mfs_sender_phone' => ['required', 'string', 'max:32', 'regex:/^(?:01[3-9][0-9]{8}|\+?8801[3-9][0-9]{8})$/'],
            'mfs_transaction_id' => [
                'required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('deposit_requests', 'mfs_transaction_id')->where(fn ($query) => $query->where('payment_method', $request->input('payment_method'))),
            ],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $deposits->submit([
            'agency_id' => (int) $user->agency_id,
            'user_id' => (int) $user->id,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'mfs_sender_phone' => $data['mfs_sender_phone'],
            'mfs_transaction_id' => $data['mfs_transaction_id'],
            'receipt' => $data['receipt'] ?? null,
        ]);

        return redirect()->route('user.deposits.index')->with('success', 'Deposit request submitted. An administrator will review it shortly.');
    }

    private function eligibleUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User && in_array($user->account_source, self::ELIGIBLE_SOURCES, true), 403);

        return $user;
    }

    private function merchantName(): string
    {
        return (string) (Setting::where('key', 'portal_merchant_name')->value('value') ?: config('payments.merchant_name', 'Portal Wallet'));
    }
}
