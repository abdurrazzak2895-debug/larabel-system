<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    public function index()
    {
        return view('admin.reports.index', [
            'agencySummary'   => $this->reportService->agencySummary(),
            'revenueOverview' => $this->reportService->revenueOverview(),
            'bookingStats'    => $this->reportService->bookingStatistics(),
            'depositStats'    => $this->reportService->depositStatistics(),
            'refundStats'     => $this->reportService->refundStatistics(),
            'walletSummary'   => $this->reportService->walletSummary(),
            'apiActivity'     => $this->reportService->apiActivity(),
        ]);
    }

    public function agencyReport(Request $request, string $type)
    {
        $agencyId = $request->query('agency_id');
        $days = $request->query('days', 30);

        return match ($type) {
            'daily_bookings'   => view('admin.reports.agency-daily-bookings', [
                'data' => $this->reportService->agencyDailyBookings((int) $agencyId, (int) $days),
            ]),
            'wallet_statement' => view('admin.reports.agency-wallet-statement', [
                'data' => $this->reportService->agencyWalletStatement((int) $agencyId),
            ]),
            'user_activity'    => view('admin.reports.agency-user-activity', [
                'data' => $this->reportService->agencyUserActivity((int) $agencyId, (int) $days),
            ]),
            'failed_bookings'  => view('admin.reports.agency-failed-bookings', [
                'data' => $this->reportService->agencyFailedBookings((int) $agencyId, (int) $days),
            ]),
            'deposit_history'  => view('admin.reports.agency-deposit-history', [
                'data' => $this->reportService->agencyDepositHistory((int) $agencyId),
            ]),
            default => abort(404),
        };
    }
}