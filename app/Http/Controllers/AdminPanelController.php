<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\AgencyController as AdminAgency;
use App\Http\Controllers\Admin\UserController as AdminUser;
use App\Http\Controllers\Admin\WalletController as AdminWallet;
use App\Http\Controllers\Admin\PricingController as AdminPricing;
use App\Http\Controllers\Admin\DepositController as AdminDeposit;
use App\Http\Controllers\Admin\RefundController as AdminRefund;
use App\Http\Controllers\Admin\ReportController as AdminReport;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLog;
use App\Http\Controllers\Admin\SettingController as AdminSetting;
use App\Http\Controllers\Admin\NotificationController as AdminNotification;
use Illuminate\Http\Request;

/**
 * Legacy AdminPanelController — delegates to new Phase 6 controllers.
 * Kept for backward compatibility.
 */
class AdminPanelController extends Controller
{
    public function dashboard()
    {
        return app(AdminDashboard::class)->index();
    }

    public function agencies()
    {
        return app(AdminAgency::class)->index();
    }

    public function createAgency()
    {
        return app(AdminAgency::class)->create();
    }

    public function storeAgency(Request $request)
    {
        return app(AdminAgency::class)->store($request);
    }

    public function bookings()
    {
        return redirect()->route('admin.dashboard');
    }

    public function approveDeposit(\App\Models\DepositRequest $deposit)
    {
        return app(AdminDeposit::class)->approve($deposit);
    }

    public function rejectDeposit(\App\Models\DepositRequest $deposit, Request $request)
    {
        return app(AdminDeposit::class)->reject($deposit, $request);
    }

    public function approveRefund(\App\Models\RefundRequest $refund)
    {
        return app(AdminRefund::class)->approve($refund);
    }

    public function rejectRefund(\App\Models\RefundRequest $refund, Request $request)
    {
        return app(AdminRefund::class)->reject($refund, $request);
    }

    public function auditLogs()
    {
        return app(AdminAuditLog::class)->index(request());
    }
}
