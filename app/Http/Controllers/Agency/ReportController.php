<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService)
    {
        $this->middleware('agency.scope');
    }

    public function dailyBookings()
    {
        return view('agency.reports.daily-bookings', [
            'data' => $this->reportService->agencyDailyBookings(Auth::user()->agency_id),
        ]);
    }

    public function walletStatement()
    {
        return view('agency.reports.wallet-statement', [
            'data' => $this->reportService->agencyWalletStatement(Auth::user()->agency_id),
        ]);
    }

    public function userActivity()
    {
        return view('agency.reports.user-activity', [
            'data' => $this->reportService->agencyUserActivity(Auth::user()->agency_id),
        ]);
    }

    public function failedBookings()
    {
        return view('agency.reports.failed-bookings', [
            'data' => $this->reportService->agencyFailedBookings(Auth::user()->agency_id),
        ]);
    }

    public function depositHistory()
    {
        return view('agency.reports.deposit-history', [
            'data' => $this->reportService->agencyDepositHistory(Auth::user()->agency_id),
        ]);
    }
}