<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DepositController extends Controller
{
    public function __construct()
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
            'walletBalance'     => (float) (Auth::user()->wallet->available_balance ?? 0),
        ]);
    }

    public function create()
    {
        return view('user.deposits.create', [
            'walletBalance' => (float) (Auth::user()->wallet->available_balance ?? 0),
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
            'amount'         => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'string'],
            'receipt'        => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $data['agency_id'] = (int) $agencyId;
        $data['user_id'] = (int) Auth::id();

        app(\App\Services\DepositService::class)->submit($data);

        return redirect()->route('user.deposits.index')->with('success', 'Deposit request submitted.');
    }
}