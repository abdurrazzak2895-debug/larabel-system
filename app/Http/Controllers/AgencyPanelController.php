<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Agency\DashboardController as AgencyDashboard;
use App\Http\Controllers\Agency\DepositController as AgencyDeposit;
use App\Http\Controllers\Agency\RefundController as AgencyRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Legacy AgencyPanelController — delegates to new Phase 6 controllers.
 * Kept for backward compatibility.
 */
class AgencyPanelController extends Controller
{
    public function dashboard()
    {
        return app(AgencyDashboard::class)->index();
    }

    public function submitDeposit(Request $request)
    {
        return app(AgencyDeposit::class)->store($request);
    }

    public function submitRefund(Request $request)
    {
        return app(AgencyRefund::class)->store($request);
    }
}
