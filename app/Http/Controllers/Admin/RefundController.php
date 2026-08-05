<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function index()
    {
        return view('admin.refunds.index', [
            'refunds' => RefundRequest::with('agency', 'booking')->latest()->paginate(20),
        ]);
    }

    public function approve(RefundRequest $refund)
    {
        try {
            app(\App\Services\RefundService::class)->approve($refund);
            return back()->with('success', "Refund {$refund->id} approved and processed.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(RefundRequest $refund, Request $request)
    {
        try {
            app(\App\Services\RefundService::class)->reject($refund, (string) $request->input('reason', 'Not specified'));
            return back()->with('success', "Refund {$refund->id} rejected.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}