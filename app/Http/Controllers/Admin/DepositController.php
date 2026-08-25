<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DepositRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepositController extends Controller
{
    public function index()
    {
        return view('admin.deposits.index', [
            'deposits' => DepositRequest::with(['agency', 'user'])->latest()->paginate(20),
            'merchantName' => $this->merchantName(),
        ]);
    }

    public function create()
    {
        return view('admin.deposits.create', [
            'agencies' => Agency::where('status', true)->orderBy('name')->get(),
            'users' => User::with('agency')
                ->where('status', true)
                ->whereNotNull('agency_id')
                ->whereIn('account_source', User::SELF_SERVICE_DEPOSIT_SOURCES)
                ->orderBy('name')
                ->get(),
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
        $data = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where(fn ($query) => $query->where('status', true))],
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('agency_id', $request->input('agency_id'))
                    ->where('status', true)
                    ->whereIn('account_source', User::SELF_SERVICE_DEPOSIT_SOURCES)),
            ],
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', Rule::in(config('payments.portal_deposit_methods', ['bkash', 'nagad']))],
            'mfs_sender_phone' => ['required', 'string', 'max:32', 'regex:/^(?:01[3-9][0-9]{8}|\+?8801[3-9][0-9]{8})$/'],
            'mfs_transaction_id' => [
                'required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('deposit_requests', 'mfs_transaction_id')->where(fn ($query) => $query->where('payment_method', $request->input('payment_method'))),
            ],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $deposits->submit($data);

        return redirect()->route('admin.deposits.index')->with('success', 'Manual MFS deposit created and queued for approval.');
    }

    public function approve(DepositRequest $deposit, DepositService $deposits)
    {
        try {
            $deposits->approve($deposit);
            return back()->with('success', "Deposit {$deposit->id} approved and user wallet credited.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(DepositRequest $deposit, Request $request, DepositService $deposits)
    {
        try {
            $deposits->reject($deposit, (string) $request->input('reason', 'Rejected by admin'));
            return back()->with('success', "Deposit {$deposit->id} rejected.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function merchantName(): string
    {
        return (string) (Setting::where('key', 'portal_merchant_name')->value('value') ?: config('payments.merchant_name', 'Portal Wallet'));
    }
}
