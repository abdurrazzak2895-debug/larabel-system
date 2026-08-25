<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Handles user wallet deposit requests, with a legacy agency-wallet fallback
 * for historical requests that have no user target.
 *
 * Flow: submit (with receipt) → admin approve → credit wallet → audit + notify
 */
class DepositService
{
    public function __construct(
        private WalletService $wallet,
        private UserWalletService $userWallet,
        private NotificationService $notifications,
        private AuditService $audit
    ) {}

    /**
     * Create a new deposit request.
     *
     * @param  array{agency_id: int, user_id?: int|null, amount: float, payment_method: string, receipt?: UploadedFile|null}  $data
     */
    public function submit(array $data): DepositRequest
    {
        return DB::transaction(function () use ($data) {
            $userId = isset($data['user_id']) && $data['user_id'] !== null ? (int) $data['user_id'] : null;
            $agencyId = (int) $data['agency_id'];

            if ($userId !== null && ! User::query()->whereKey($userId)->where('agency_id', $agencyId)->exists()) {
                throw new \InvalidArgumentException('The deposit user does not belong to the selected agency.');
            }

            $deposit = DepositRequest::create([
                'agency_id'      => $agencyId,
                'user_id'        => $userId,
                'amount'         => $data['amount'],
                'payment_method' => $data['payment_method'],
                'status'         => 'pending',
            ]);

            if (isset($data['receipt']) && $data['receipt'] instanceof UploadedFile) {
                $path = $data['receipt']->store('deposits/receipts', 'public');
                $deposit->update(['receipt_path' => $path]);
            }

            $this->audit->log(
                (int) $deposit->agency_id,
                'deposit',
                ['deposit_id' => $deposit->id, 'action' => 'submitted']
            );

            return $deposit;
        });
    }

    /**
     * Approve a pending deposit and credit the targeted user wallet.
     */
    public function approve(DepositRequest $deposit): DepositRequest
    {
        return DB::transaction(function () use ($deposit) {
            if ($deposit->status !== 'pending') {
                throw new \RuntimeException('Only pending deposits can be approved.');
            }

            $deposit->update([
                'status'         => 'approved',
                'processed_at'   => now(),
            ]);

            // New requests credit the user wallet. Legacy agency-only requests
            // remain supported so historical balances are not stranded.
            if ($deposit->user_id) {
                $this->userWallet->deposit(
                    (int) $deposit->user_id,
                    (float) $deposit->amount,
                    "deposit-request-{$deposit->id}",
                    ['deposit_id' => $deposit->id, 'agency_id' => $deposit->agency_id]
                );
            } else {
                $this->wallet->deposit(
                    (int) $deposit->agency_id,
                    (float) $deposit->amount,
                    "deposit-request-{$deposit->id}",
                    ['deposit_id' => $deposit->id]
                );
            }

            $this->audit->log(
                (int) $deposit->agency_id,
                'wallet',
                ['deposit_id' => $deposit->id, 'action' => 'approved', 'amount' => $deposit->amount]
            );

            return $deposit;
        });
    }

    /**
     * Reject a pending deposit request.
     */
    public function reject(DepositRequest $deposit, string $reason): DepositRequest
    {
        return DB::transaction(function () use ($deposit, $reason) {
            if ($deposit->status !== 'pending') {
                throw new \RuntimeException('Only pending deposits can be rejected.');
            }

            $deposit->update([
                'status'       => 'rejected',
                'processed_at' => now(),
            ]);

            $this->audit->log(
                (int) $deposit->agency_id,
                'deposit',
                ['deposit_id' => $deposit->id, 'action' => 'rejected', 'reason' => $reason]
            );

            return $deposit;
        });
    }

    /**
     * List deposits for an agency.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DepositRequest>
     */
    public function listForAgency(int $agencyId, ?string $status = null)
    {
        return DepositRequest::with('user')
            ->where('agency_id', $agencyId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->get();
    }

    public function listForUser(int $userId, ?string $status = null)
    {
        return DepositRequest::where('user_id', $userId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest();
    }
}
