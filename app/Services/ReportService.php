<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\DepositRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\UserWallet;
use App\Models\UserWalletTransaction;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function agencySummary(): array
    {
        return [
            'total_agencies'    => \App\Models\Agency::count(),
            'active_agencies'   => \App\Models\Agency::where('status', true)->count(),
            'total_users'       => User::count(),
            'total_wallet_balance' => WalletTransaction::sum('amount') + UserWalletTransaction::sum('amount'),
        ];
    }

    public function revenueOverview(int $days = 30): array
    {
        $agency = WalletTransaction::where('type', 'booking_debit')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $users = UserWalletTransaction::where('type', 'booking_debit')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        return $agency->keys()->merge($users->keys())->unique()->sort()->values()
            ->map(fn ($date) => ['date' => $date, 'total' => (float) ($agency->get($date)?->total ?? 0) + (float) ($users->get($date)?->total ?? 0)])
            ->all();
    }

    public function bookingStatistics(int $days = 30): array
    {
        return [
            'total'   => Booking::where('created_at', '>=', now()->subDays($days))->count(),
            'failed'  => Booking::where('booking_status', 'failed')->where('created_at', '>=', now()->subDays($days))->count(),
            'daily'   => Booking::where('created_at', '>=', now()->subDays($days))
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
        ];
    }

    public function depositStatistics(int $days = 30): array
    {
        return [
            'total'    => DepositRequest::where('created_at', '>=', now()->subDays($days))->count(),
            'approved' => DepositRequest::where('status', 'approved')->where('created_at', '>=', now()->subDays($days))->count(),
            'rejected' => DepositRequest::where('status', 'rejected')->where('created_at', '>=', now()->subDays($days))->count(),
            'pending'  => DepositRequest::where('status', 'pending')->where('created_at', '>=', now()->subDays($days))->count(),
        ];
    }

    public function refundStatistics(int $days = 30): array
    {
        return [
            'total'    => RefundRequest::where('created_at', '>=', now()->subDays($days))->count(),
            'approved' => RefundRequest::where('status', 'approved')->where('created_at', '>=', now()->subDays($days))->count(),
            'rejected' => RefundRequest::where('status', 'rejected')->where('created_at', '>=', now()->subDays($days))->count(),
            'pending'  => RefundRequest::where('status', 'pending')->where('created_at', '>=', now()->subDays($days))->count(),
        ];
    }

    public function walletSummary(): array
    {
        return [
            'total_available'    => DB::table('agency_wallets')->sum('available_balance') + DB::table('user_wallets')->sum('available_balance'),
            'total_credit_limit' => DB::table('agency_wallets')->sum('credit_limit') + DB::table('user_wallets')->sum('credit_limit'),
        ];
    }

    public function apiActivity(int $days = 30): array
    {
        $rows = WalletTransaction::where('created_at', '>=', now()->subDays($days))
            ->selectRaw('type, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('type')->get();
        $userRows = UserWalletTransaction::where('created_at', '>=', now()->subDays($days))
            ->selectRaw('type, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('type')->get();

        return $rows->concat($userRows)->groupBy('type')
            ->map(fn ($items, $type) => ['type' => $type, 'count' => $items->sum('count'), 'total' => (float) $items->sum('total')])
            ->values()->all();
    }

    public function agencyDailyBookings(int $agencyId, int $days = 30): array
    {
        return Booking::where('agency_id', $agencyId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(CASE WHEN booking_status = "failed" THEN 1 ELSE 0 END) as failed')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->all();
    }

    public function agencyWalletStatement(int $agencyId): array
    {
        $agencyEntries = WalletTransaction::where('wallet_id', function ($q) use ($agencyId) {
            $q->select('id')->from('agency_wallets')->where('agency_id', $agencyId);
        })->get();
        $userEntries = UserWalletTransaction::whereHas('wallet.user', fn ($q) => $q->where('agency_id', $agencyId))->get();

        return $agencyEntries->concat($userEntries)->sortBy('created_at')->values()->all();
    }

    public function agencyUserActivity(int $agencyId, int $days = 30): array
    {
        return User::where('agency_id', $agencyId)
            ->withCount(['bookings as booking_count' => fn ($q) => $q->where('created_at', '>=', now()->subDays($days))])
            ->get()
            ->all();
    }

    public function agencyFailedBookings(int $agencyId, int $days = 30): array
    {
        return Booking::where('agency_id', $agencyId)
            ->where('booking_status', 'failed')
            ->where('created_at', '>=', now()->subDays($days))
            ->get()
            ->all();
    }

    public function agencyDepositHistory(int $agencyId): array
    {
        return DepositRequest::where('agency_id', $agencyId)->get()->all();
    }
}