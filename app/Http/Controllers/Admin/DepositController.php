<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Setting;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    public function index()
    {
        return view('admin.deposits.index', [
            'deposits' => DepositRequest::with(['agency', 'user'])->latest()->paginate(20),
            'merchantName' => Setting::where('key', 'portal_merchant_name')->value('value') ?: config('payments.merchant_name', 'Portal Wallet'),
        ]);
    }

    public function approve(DepositRequest $deposit)
    {
        try {
            app(\App\Services\DepositService::class)->approve($deposit);
            return back()->with('success', "Deposit {$deposit->id} approved and wallet credited.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(DepositRequest $deposit, Request $request)
    {
        try {
            app(\App\Services\DepositService::class)->reject($deposit, (string) $request->input('reason', 'Not specified'));
            return back()->with('success', "Deposit {$deposit->id} rejected.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}