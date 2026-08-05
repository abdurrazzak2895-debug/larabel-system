<?php

namespace App\Services;

use App\Models\AgencyWallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Manages agency wallet balances using immutable ledger transactions.
 *
 * Financial rules:
 * - Never modify wallet balance directly.
 * - Every balance update must create a WalletTransaction record.
 * - Wallet operations run inside a database transaction.
 */
class WalletService
{
    public function getWallet(int $agencyId): AgencyWallet
    {
        return AgencyWallet::firstOrCreate(
            ['agency_id' => $agencyId],
            [
                'available_balance' => 0,
                'reserved_balance'  => 0,
                'credit_limit'      => 0,
            ]
        );
    }

    /**
     * Record a deposit (increases available balance).
     */
    public function deposit(int $agencyId, float $amount, ?string $reference = null, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($agencyId, $amount, $reference, $meta) {
            $wallet = $this->getWallet($agencyId);

            $wallet->increment('available_balance', $amount);

            return $wallet->transactions()->create([
                'type'      => 'deposit',
                'amount'    => $amount,
                'reference' => $reference,
                'meta'      => $meta,
            ]);
        });
    }

    /**
     * Reserve balance for a booking.
     *
     * Moves money from available to reserved.
     */
    public function hold(int $agencyId, float $amount, ?string $reference = null, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($agencyId, $amount, $reference, $meta) {
            $wallet = $this->getWallet($agencyId);

            if ($wallet->available_balance < $amount) {
                throw new \RuntimeException('Insufficient available balance to hold booking amount.');
            }

            $wallet->decrement('available_balance', $amount);
            $wallet->increment('reserved_balance', $amount);

            return $wallet->transactions()->create([
                'type'      => 'booking_hold',
                'amount'    => $amount,
                'reference' => $reference,
                'meta'      => $meta,
            ]);
        });
    }

    /**
     * Complete a booking debit.
     *
     * Moves money from reserved to a final debit (removes it).
     */
    public function debit(int $agencyId, float $amount, ?string $reference = null, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($agencyId, $amount, $reference, $meta) {
            $wallet = $this->getWallet($agencyId);

            if ($wallet->reserved_balance < $amount) {
                throw new \RuntimeException('Insufficient reserved balance to complete booking debit.');
            }

            $wallet->decrement('reserved_balance', $amount);

            return $wallet->transactions()->create([
                'type'      => 'booking_debit',
                'amount'    => $amount,
                'reference' => $reference,
                'meta'      => $meta,
            ]);
        });
    }

    /**
     * Release a hold back to available balance.
     */
    public function releaseHold(int $agencyId, float $amount, ?string $reference = null, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($agencyId, $amount, $reference, $meta) {
            $wallet = $this->getWallet($agencyId);

            if ($wallet->reserved_balance < $amount) {
                throw new \RuntimeException('Insufficient reserved balance to release hold.');
            }

            $wallet->decrement('reserved_balance', $amount);
            $wallet->increment('available_balance', $amount);

            return $wallet->transactions()->create([
                'type'      => 'refund',
                'amount'    => $amount,
                'reference' => $reference,
                'meta'      => $meta,
            ]);
        });
    }

    /**
     * Refund money back to available balance.
     */
    public function refund(int $agencyId, float $amount, ?string $reference = null, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($agencyId, $amount, $reference, $meta) {
            $wallet = $this->getWallet($agencyId);

            $wallet->increment('available_balance', $amount);

            return $wallet->transactions()->create([
                'type'      => 'refund',
                'amount'    => $amount,
                'reference' => $reference,
                'meta'      => $meta,
            ]);
        });
    }

    /**
     * Manual adjustment by an admin.
     */
    public function manualAdjust(int $agencyId, float $amount, ?string $reference = null, array $meta = []): WalletTransaction
    {
        return DB::transaction(function () use ($agencyId, $amount, $reference, $meta) {
            $wallet = $this->getWallet($agencyId);

            if ($amount >= 0) {
                $wallet->increment('available_balance', $amount);
            } else {
                $wallet->decrement('available_balance', abs($amount));
            }

            return $wallet->transactions()->create([
                'type'      => 'manual_adjustment',
                'amount'    => $amount,
                'reference' => $reference,
                'meta'      => $meta,
            ]);
        });
    }
}
