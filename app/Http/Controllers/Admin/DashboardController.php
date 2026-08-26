<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingLog;
use App\Models\DepositRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    public function index()
    {
        return view('admin.dashboard', [
            'totalAgencies'     => Agency::count(),
            'activeUsers'       => User::where('status', true)->count(),
            'totalWalletBalance' => WalletTransaction::sum('amount'),
            'dailyBookings'     => Booking::whereDate('created_at', today())->count(),
            'failedBookings'    => Booking::where('booking_status', 'failed')->whereDate('created_at', today())->count(),
            'queueStatus'       => \Illuminate\Support\Facades\Queue::size(),
            'todaysDeposits'    => DepositRequest::where('status', 'approved')->whereDate('processed_at', today())->count(),
            'todaysRefunds'     => RefundRequest::where('status', 'approved')->whereDate('processed_at', today())->count(),
            'apiHealth'         => ['status' => 'healthy', 'last_check' => now()],
            'revenueOverview'   => $this->reportService->revenueOverview(),
            'bookingSummary'    => [
                'total' => Booking::count(),
                'amount' => (float) Booking::sum('portal_booking_fee'),
                'booked' => Booking::where('booking_status', 'booked')->count(),
                'pending' => Booking::whereIn('booking_status', ['pending', 'processing'])->count(),
                'failed' => Booking::where('booking_status', 'failed')->count(),
            ],
            'agencyBookingSummary' => Agency::query()
                ->withCount([
                    'users as user_count',
                    'bookings as booking_count',
                    'bookings as booked_count' => fn ($query) => $query->where('booking_status', 'booked'),
                    'bookings as failed_count' => fn ($query) => $query->where('booking_status', 'failed'),
                ])
                ->withSum('bookings', 'portal_booking_fee')
                ->orderBy('name')
                ->get(),
            'liveBookingLogs'   => BookingLog::with(['booking.agency', 'booking.user'])
                ->latest()
                ->limit(25)
                ->get(),
        ]);
    }
}