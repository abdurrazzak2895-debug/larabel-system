<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserWallet;
use App\Models\UserWalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Owns the balance used by an individual user. Agency wallets remain available
 * for legacy records, but new user bookings and deposits use this service.
 */
class UserWalletService
{
    public function getWallet(int $userId): UserWallet
    {
        if (! User::whereKey($userId)->exists()) {
            throw new \InvalidArgumentException("Cannot access wallet for user [{$userId}].");
        }

        return UserWallet::firstOrCreate(
            ['user_id' => $userId],
            ['available_balance' => 0, 'reserved_balance' => 0, 'credit_limit' => 0]
        );
    }

    public function deposit(int $userId, float $amount, ?string $reference = null, array $meta = []): UserWalletTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($userId, $amount, $reference, $meta): UserWalletTransaction {
            $wallet = $this->lockedWallet($userId);
            $wallet->increment('available_balance', $amount);

            return $wallet->transactions()->create([
                'type' => 'deposit',
                'amount' => $amount,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    public function hold(int $userId, float $amount, ?string $reference = null, array $meta = []): UserWalletTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($userId, $amount, $reference, $meta): UserWalletTransaction {
            $wallet = $this->lockedWallet($userId);
            if ((float) $wallet->available_balance < $amount) {
                throw new \RuntimeException('Insufficient user wallet balance for booking fee.');
            }

            $wallet->decrement('available_balance', $amount);
            $wallet->increment('reserved_balance', $amount);

            return $wallet->transactions()->create([
                'type' => 'booking_hold',
                'amount' => $amount,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    public function debit(int $userId, float $amount, ?string $reference = null, array $meta = []): UserWalletTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($userId, $amount, $reference, $meta): UserWalletTransaction {
            $wallet = $this->lockedWallet($userId);
            if ((float) $wallet->reserved_balance < $amount) {
                throw new \RuntimeException('Insufficient reserved user wallet balance to complete booking debit.');
            }

            $wallet->decrement('reserved_balance', $amount);

            return $wallet->transactions()->create([
                'type' => 'booking_debit',
                'amount' => $amount,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    public function releaseHold(int $userId, float $amount, ?string $reference = null, array $meta = []): UserWalletTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($userId, $amount, $reference, $meta): UserWalletTransaction {
            $wallet = $this->lockedWallet($userId);
            if ((float) $wallet->reserved_balance < $amount) {
                throw new \RuntimeException('Insufficient reserved user wallet balance to release hold.');
            }

            $wallet->decrement('reserved_balance', $amount);
            $wallet->increment('available_balance', $amount);

            return $wallet->transactions()->create([
                'type' => 'refund',
                'amount' => $amount,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    public function refund(int $userId, float $amount, ?string $reference = null, array $meta = []): UserWalletTransaction
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($userId, $amount, $reference, $meta): UserWalletTransaction {
            $wallet = $this->lockedWallet($userId);
            $wallet->increment('available_balance', $amount);

            return $wallet->transactions()->create([
                'type' => 'refund',
                'amount' => $amount,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    public function manualAdjust(int $userId, float $amount, ?string $reference = null, array $meta = []): UserWalletTransaction
    {
        if ($amount == 0.0) {
            throw new \InvalidArgumentException('Wallet adjustment cannot be zero.');
        }

        return DB::transaction(function () use ($userId, $amount, $reference, $meta): UserWalletTransaction {
            $wallet = $this->lockedWallet($userId);
            if ($amount > 0) {
                $wallet->increment('available_balance', $amount);
            } else {
                if ((float) $wallet->available_balance < abs($amount)) {
                    throw new \RuntimeException('Insufficient user wallet balance for adjustment.');
                }
                $wallet->decrement('available_balance', abs($amount));
            }

            return $wallet->transactions()->create([
                'type' => 'manual_adjustment',
                'amount' => $amount,
                'reference' => $reference,
                'meta' => $meta,
            ]);
        });
    }

    private function lockedWallet(int $userId): UserWallet
    {
        $wallet = $this->getWallet($userId);

        return UserWallet::query()->lockForUpdate()->findOrFail($wallet->id);
    }

    private function assertPositiveAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Wallet amount must be greater than zero.');
        }
    }
}
