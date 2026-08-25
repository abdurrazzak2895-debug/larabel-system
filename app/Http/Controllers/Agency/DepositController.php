<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DepositController extends Controller
{
    public function __construct()
    {
        $this->middleware('agency.scope');
    }

    public function index()
    {
        $agencyId = Auth::user()->agency_id;

        return view('agency.deposits.index', [
            'deposits' => DepositRequest::with('user')->where('agency_id', $agencyId)->latest()->paginate(10),
        ]);
    }

    public function create()
    {
        return view('agency.deposits.create', [
            'users' => User::where('agency_id', (int) Auth::user()->agency_id)
                ->where('status', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'        => ['required', 'integer'],
            'amount'         => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'string'],
            'receipt'        => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $agencyId = (int) Auth::user()->agency_id;
        $target = User::where('agency_id', $agencyId)->findOrFail($data['user_id']);
        $data['agency_id'] = $agencyId;
        $data['user_id'] = (int) $target->id;

        app(\App\Services\DepositService::class)->submit($data);

        return redirect()->route('agency.deposits.index')->with('success', 'User deposit request submitted.');
    }

    public function approve(DepositRequest $deposit)
    {
        $this->assertAgencyDeposit($deposit);

        try {
            app(\App\Services\DepositService::class)->approve($deposit);
            return back()->with('success', "Deposit {$deposit->id} approved and user wallet credited.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, DepositRequest $deposit)
    {
        $this->assertAgencyDeposit($deposit);

        try {
            app(\App\Services\DepositService::class)->reject($deposit, (string) $request->input('reason', 'Not specified'));
            return back()->with('success', "Deposit {$deposit->id} rejected.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function assertAgencyDeposit(DepositRequest $deposit): void
    {
        abort_unless((int) $deposit->agency_id === (int) Auth::user()->agency_id, 403);
        abort_unless($deposit->user_id !== null, 403, 'Legacy agency-wallet deposits can only be processed by platform admin.');
    }
}