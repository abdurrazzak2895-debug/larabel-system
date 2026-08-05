<?php

namespace App\Services;

use App\Models\DepositRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Handles agency wallet deposit requests.
 *
 * Flow: submit (with receipt) → admin approve → credit wallet → audit + notify
 */
class DepositService
{
    public function __construct(
        private WalletService $wallet,
        private NotificationService $notifications,
        private AuditService $audit
    ) {}

    /**
     * Create a new deposit request.
     *
     * @param  array{agency_id: int, amount: float, payment_method: string, receipt?: UploadedFile|null}  $data
     */
    public function submit(array $data): DepositRequest
    {
        return DB::transaction(function () use ($data) {
            $deposit = DepositRequest::create([
                'agency_id'      => $data['agency_id'],
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
     * Approve a pending deposit and credit the agency wallet.
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

            // Credit wallet via immutable ledger
            $this->wallet->deposit(
                (int) $deposit->agency_id,
                (float) $deposit->amount,
                "deposit-request-{$deposit->id}",
                ['deposit_id' => $deposit->id]
            );

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
        return DepositRequest::where('agency_id', $agencyId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->get();
    }
}
