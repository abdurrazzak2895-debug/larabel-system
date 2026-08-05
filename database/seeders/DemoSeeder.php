<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\AgencyWallet;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\BookingLog;
use App\Models\DepositRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Seeder;

/**
 * Populates the database with realistic demo data so every page
 * (admin dashboard, agencies, bookings, audit-logs, agency dashboard)
 * displays meaningful content out of the box.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------
        // 1. Agencies + wallets
        // ------------------------------------------------------------
        $agencies = [
            ['name' => 'Al-Noor Travel & Exams', 'code' => 'ALNOOR', 'balance' => 15250.00, 'reserved' => 3250.00, 'credit' => 10000.00],
            ['name' => 'Al-Amal Study Center',   'code' => 'ALAMAL', 'balance' =>  9800.00, 'reserved' => 1200.00, 'credit' => 5000.00],
            ['name' => 'Sana Overseas',          'code' => 'SANAOV', 'balance' =>  6400.00, 'reserved' => 2300.00, 'credit' => 8000.00],
            ['name' => 'Yaqut Education Hub',    'code' => 'YAQUET', 'balance' => 20350.00, 'reserved' => 4100.00, 'credit' => 15000.00],
        ];

        $agencyModels = [];
        foreach ($agencies as $i => $data) {
            $agency = Agency::updateOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'status' => true]
            );
            $agencyModels[] = $agency;

            AgencyWallet::updateOrCreate(
                ['agency_id' => $agency->id],
                [
                    'available_balance' => $data['balance'],
                    'reserved_balance'  => $data['reserved'],
                    'credit_limit'      => $data['credit'],
                ]
            );
        }

        // ------------------------------------------------------------
        // 2. Agency users (one per agency, username = agency code lower)
        // ------------------------------------------------------------
        $agencyUsers = [];
        $userNames   = ['Ahmed Hassan', 'Fatima Zahra', 'Mahmoud Ali', 'Noor Islam'];
        foreach ($agencyModels as $i => $agency) {
            $username = strtolower($agency->code);
            $user = User::updateOrCreate(
                ['username' => $username],
                [
                    'agency_id' => $agency->id,
                    'name'      => $userNames[$i],
                    'email'     => "{$username}@agency.com",
                    'password'  => 'password',
                    'status'    => true,
                ]
            );
            $agencyUsers[$agency->id] = $user;
        }

        // ------------------------------------------------------------
        // 3. Wallet transactions
        // ------------------------------------------------------------
        foreach ($agencyModels as $agency) {
            $wallet = $agency->wallet;

            $txnTypes = [
                ['type' => 'deposit',           'amount' => 5000.00, 'reference' => 'DEP-2026-001'],
                ['type' => 'booking_debit',     'amount' => 1450.00, 'reference' => 'BK-MBBS-102'],
                ['type' => 'booking_hold',      'amount' =>  850.00, 'reference' => 'BK-NURS-205'],
                ['type' => 'refund',            'amount' =>  450.00, 'reference' => 'REF-2026-011'],
                ['type' => 'manual_adjustment', 'amount' =>  200.00, 'reference' => null],
            ];

            foreach ($txnTypes as $txn) {
                WalletTransaction::create([
                    'wallet_id'  => $wallet->id,
                    'type'       => $txn['type'],
                    'amount'     => $txn['amount'],
                    'reference'  => $txn['reference'],
                    'meta'       => ['seeded' => true],
                ]);
            }
        }

        // ------------------------------------------------------------
        // 4. Bookings + logs + attempts
        // ------------------------------------------------------------
        $occupations = [
            'MBBS-2026' => 1450.00,
            'MD-2026'   => 1850.00,
            'BDS-2026'  =>  950.00,
            'NURS-2026' =>  850.00,
            'PHARM-2026'=>  900.00,
        ];
        $statuses = ['pending', 'processing', 'booked', 'failed', 'cancelled', 'refunded'];

        $bookedBookings = [];
        foreach ($agencyModels as $agency) {
            $user = $agencyUsers[$agency->id];
            $occ  = array_keys($occupations);
            $i    = 0;

            foreach ($statuses as $status) {
                $occKey = $occ[$i % count($occ)];
                $amount = $occupations[$occKey];

                $booking = Booking::create([
                    'agency_id'        => $agency->id,
                    'user_id'          => $user->id,
                    'credential_id'    => null,
                    'occupation_id'    => $occKey,
                    'exam_session_id'  => 'SESS-' . rand(1000, 9999),
                    'booking_status'   => $status,
                    'booking_reference'=> match ($status) {
                        'booked'   => 'TAK-' . strtoupper($agency->code) . '-' . rand(10000, 99999),
                        'processing' => 'PROC-' . rand(1000, 9999),
                        default    => null,
                    },
                    'notes'            => "Seeded demo booking ({$status})",
                ]);

                // Log
                BookingLog::create([
                    'booking_id'        => $booking->id,
                    'event_type'        => 'status_changed',
                    'payload'           => ['from' => 'created', 'to' => $status],
                    'provider_response' => null,
                ]);

                // Attempt for processing/booked/failed
                if (in_array($status, ['processing', 'booked', 'failed'])) {
                    BookingAttempt::create([
                        'booking_id'        => $booking->id,
                        'status'            => $status === 'booked' ? 'success' : $status,
                        'request_payload'   => ['occupation_id' => $occKey],
                        'provider_response' => $status === 'booked'
                            ? ['reference' => $booking->booking_reference, 'status' => 'CONFIRMED']
                            : null,
                        'error_message'     => $status === 'failed' ? 'Provider timeout after 30s' : null,
                    ]);
                }

                if ($status === 'booked') {
                    $bookedBookings[] = $booking;
                }

                $i++;
            }
        }

        // ------------------------------------------------------------
        // 5. Deposit requests
        // ------------------------------------------------------------
        $depositData = [
            ['amount' => 10000.00, 'method' => 'bank_transfer', 'status' => 'pending'],
            ['amount' =>  5000.00, 'method' => 'card',          'status' => 'approved'],
            ['amount' =>  2500.00, 'method' => 'bank_transfer', 'status' => 'rejected'],
            ['amount' =>  7500.00, 'method' => 'card',          'status' => 'pending'],
        ];

        $depositIdx = 0;
        foreach ($agencyModels as $agency) {
            $data = $depositData[$depositIdx % count($depositData)];
            DepositRequest::create([
                'agency_id'      => $agency->id,
                'amount'         => $data['amount'],
                'payment_method' => $data['method'],
                'receipt_path'   => null,
                'status'         => $data['status'],
                'processed_at'   => $data['status'] !== 'pending' ? now()->subDays(rand(1, 5)) : null,
            ]);
            $depositIdx++;
        }

        // ------------------------------------------------------------
        // 6. Refund requests (tied to booked bookings)
        // ------------------------------------------------------------
        $refundReasons = [
            'Candidate duplicate payment',
            'Exam session cancelled by provider',
            'Candidate withdrew application',
        ];

        foreach (array_slice($bookedBookings, 0, 3) as $i => $booking) {
            RefundRequest::create([
                'booking_id'  => $booking->id,
                'agency_id'   => $booking->agency_id,
                'amount'      => $booking->notes ? 450.00 : 450.00,
                'reason'      => $refundReasons[$i % count($refundReasons)],
                'status'      => $i === 0 ? 'pending' : 'approved',
                'processed_at'=> $i === 0 ? null : now()->subDays(1),
            ]);
        }

        // ------------------------------------------------------------
        // 7. Audit logs
        // ------------------------------------------------------------
        $admin = Admin::first() ?? Admin::create(['name' => 'System Admin', 'email' => 'admin@takamol.com', 'password' => 'password']);

        $auditEvents = [
            ['event' => 'login',         'payload' => ['ip' => '10.0.0.1']],
            ['event' => 'wallet',        'payload' => ['action' => 'deposit_approved', 'amount' => 5000]],
            ['event' => 'booking',       'payload' => ['action' => 'booking_created', 'ref' => 'TAK-ALNOOR-24321']],
            ['event' => 'deposit',       'payload' => ['action' => 'deposit_requested']],
            ['event' => 'refund',        'payload' => ['action' => 'refund_approved']],
            ['event' => 'admin_action',  'payload' => ['action' => 'seed_demo_data']],
        ];

        foreach ($auditEvents as $idx => $event) {
            $actor = $idx % 2 === 0 ? $admin : $agencyUsers[array_keys($agencyUsers)[0]];

            AuditLog::create([
                'actor_id'     => $actor->id,
                'actor_type'   => $idx % 2 === 0 ? Admin::class : User::class,
                'event'        => $event['event'],
                'payload'      => $event['payload'],
                'ip_address'   => '127.0.0.1',
                'user_agent'   => 'Seeder',
            ]);
        }

        $this->command?->info('Demo data seeded: agencies, users, wallets, transactions, bookings, deposits, refunds, audit logs.');
    }
}
