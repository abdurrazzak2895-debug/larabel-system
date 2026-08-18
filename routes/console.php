<?php

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\BookingLog;
use App\Models\Candidate;
use App\Models\DepositRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:purge-demo-data {--force : Permanently delete the confirmed demo agencies and dependent records}', function () {
    if (app()->environment('production') && ! $this->option('force')) {
        $this->error('Production cleanup requires the --force option.');

        return 1;
    }

    $demoCodes = ['ALNOOR', 'ALAMAL', 'SANAOV', 'YAQUET'];

    $counts = DB::transaction(function () use ($demoCodes): array {
        $agencyIds = Agency::query()
            ->whereIn('code', $demoCodes)
            ->pluck('id');

        if ($agencyIds->isEmpty()) {
            return [
                'agencies' => 0,
                'users' => 0,
                'candidates' => 0,
                'bookings' => 0,
                'booking_logs' => 0,
                'booking_attempts' => 0,
                'deposits' => 0,
                'refunds' => 0,
                'wallet_transactions' => 0,
                'wallets' => 0,
                'notifications' => 0,
                'sessions' => 0,
                'audit_logs' => 0,
                'agency_admins' => 0,
            ];
        }

        $userIds = User::query()
            ->whereIn('agency_id', $agencyIds)
            ->pluck('id');

        $bookingIds = Booking::withTrashed()
            ->whereIn('agency_id', $agencyIds)
            ->pluck('id');

        $walletIds = DB::table('agency_wallets')
            ->whereIn('agency_id', $agencyIds)
            ->pluck('id');

        $counts = [
            'agencies' => $agencyIds->count(),
            'users' => $userIds->count(),
            'candidates' => Candidate::query()
                ->where(function ($query) use ($agencyIds, $userIds): void {
                    $query->whereIn('agency_id', $agencyIds);
                    if ($userIds->isNotEmpty()) {
                        $query->orWhereIn('user_id', $userIds);
                    }
                })
                ->delete(),
            'bookings' => $bookingIds->count(),
            'booking_logs' => BookingLog::query()->whereIn('booking_id', $bookingIds)->delete(),
            'booking_attempts' => BookingAttempt::query()->whereIn('booking_id', $bookingIds)->delete(),
            'deposits' => DepositRequest::query()->whereIn('agency_id', $agencyIds)->delete(),
            'refunds' => RefundRequest::query()
                ->where(function ($query) use ($agencyIds, $bookingIds): void {
                    $query->whereIn('agency_id', $agencyIds);
                    if ($bookingIds->isNotEmpty()) {
                        $query->orWhereIn('booking_id', $bookingIds);
                    }
                })
                ->delete(),
            'wallet_transactions' => WalletTransaction::query()->whereIn('wallet_id', $walletIds)->delete(),
            'wallets' => DB::table('agency_wallets')->whereIn('id', $walletIds)->delete(),
            'notifications' => $userIds->isEmpty()
                ? 0
                : DB::table('notifications')->whereIn('user_id', $userIds)->delete(),
            'sessions' => $userIds->isEmpty()
                ? 0
                : DB::table('sessions')->whereIn('user_id', $userIds)->delete(),
            'audit_logs' => 0,
            'agency_admins' => DB::table('agency_admins')->whereIn('agency_id', $agencyIds)->delete(),
        ];

        if ($userIds->isNotEmpty()) {
            DB::table('role_user')->whereIn('user_id', $userIds)->delete();
            DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
            $counts['audit_logs'] += AuditLog::query()
                ->where('actor_type', User::class)
                ->whereIn('actor_id', $userIds)
                ->delete();
        }

        // DemoSeeder is the only application path that writes the literal Seeder user agent.
        $counts['audit_logs'] += AuditLog::query()
            ->where('user_agent', 'Seeder')
            ->whereIn('event', ['login', 'wallet', 'booking', 'deposit', 'refund', 'admin_action'])
            ->delete();

        Booking::withTrashed()->whereIn('id', $bookingIds)->forceDelete();
        User::query()->whereIn('id', $userIds)->delete();
        $counts['agencies'] = Agency::query()->whereIn('id', $agencyIds)->delete();

        return $counts;
    });

    $this->table(['Record type', 'Deleted'], collect($counts)
        ->map(fn (int $count, string $type): array => [str_replace('_', ' ', ucfirst($type)), $count])
        ->values()
        ->all());
    $this->info('Confirmed demo-data cleanup completed. Existing admin accounts and non-demo agencies were not modified.');

    return 0;
})->purpose('Remove the confirmed seeded demo agencies and all dependent demo records');
