<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;

/**
 * Handles refund requests for bookings.
 *
 * Flow: request → review → approve → wallet refund → audit + notify
 */
class RefundService
{
    public function __construct(
        private WalletService $wallet,
        private NotificationService $notifications,
        private AuditService $audit
    ) {}

    /**
     * Create a refund request for a booking.
     *
     * @param  array{booking_id: int, agency_id: int, amount: float, reason: string}  $data
     */
    public function request(array $data): RefundRequest
    {
        return DB::transaction(function () use ($data) {
            $refund = RefundRequest::create([
                'booking_id' => $data['booking_id'],
                'agency_id'  => $data['agency_id'],
                'amount'     => $data['amount'],
                'reason'     => $data['reason'],
                'status'     => 'pending',
            ]);

            $this->audit->log(
                (int) $data['agency_id'],
                'refund',
                ['refund_id' => $refund->id, 'action' => 'requested']
            );

            return $refund;
        });
    }

    /**
     * Approve a refund request and credit the agency wallet.
     */
    public function approve(RefundRequest $refund): RefundRequest
    {
        return DB::transaction(function () use ($refund) {
            if ($refund->status !== 'pending') {
                throw new \RuntimeException('Only pending refunds can be approved.');
            }

            $refund->update([
                'status'       => 'approved',
                'processed_at' => now(),
            ]);

            $this->wallet->refund(
                (int) $refund->agency_id,
                (float) $refund->amount,
                "refund-request-{$refund->id}",
                ['refund_id' => $refund->id, 'booking_id' => $refund->booking_id]
            );

            $refund->update(['status' => 'processed']);

            $booking = $refund->booking;
            if ($booking) {
                $booking->update(['booking_status' => 'refunded']);
            }

            $this->audit->log(
                (int) $refund->agency_id,
                'refund',
                ['refund_id' => $refund->id, 'action' => 'approved']
            );

            return $refund;
        });
    }

    /**
     * Reject a refund request.
     */
    public function reject(RefundRequest $refund, string $reason): RefundRequest
    {
        return DB::transaction(function () use ($refund, $reason) {
            if ($refund->status !== 'pending') {
                throw new \RuntimeException('Only pending refunds can be rejected.');
            }

            $refund->update([
                'status'       => 'rejected',
                'processed_at' => now(),
            ]);

            $this->audit->log(
                (int) $refund->agency_id,
                'refund',
                ['refund_id' => $refund->id, 'action' => 'rejected', 'reason' => $reason]
            );

            return $refund;
        });
    }

    /**
     * List refund requests for an agency.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RefundRequest>
     */
    public function listForAgency(int $agencyId, ?string $status = null)
    {
        return RefundRequest::where('agency_id', $agencyId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->get();
    }
}
