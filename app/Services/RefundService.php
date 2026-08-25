<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\RefundRequest;
use App\Models\WalletTransaction;
use App\Models\UserWalletTransaction;
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
        private UserWalletService $userWallet,
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

            $booking = $refund->booking;
            if ($booking?->user_id) {
                $this->userWallet->refund(
                    (int) $booking->user_id,
                    (float) $refund->amount,
                    "refund-request-{$refund->id}",
                    ['refund_id' => $refund->id, 'booking_id' => $refund->booking_id]
                );
            } else {
                $this->wallet->refund(
                    (int) $refund->agency_id,
                    (float) $refund->amount,
                    "refund-request-{$refund->id}",
                    ['refund_id' => $refund->id, 'booking_id' => $refund->booking_id]
                );
            }

            $refund->update(['status' => 'processed']);

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
     * Automatically refund pending bookings that have exceeded the payment timeout.
     *
     * A pending booking has a portal-fee hold, not a completed debit. Therefore
     * the timeout path releases that hold back to available balance rather than
     * calling refund(), which would over-credit the wallet. Booking rows are
     * locked and both the booking status and wallet reference are checked so a
     * repeated scheduler run cannot refund the same booking twice.
     */
    public function autoRefundExpiredPending(int $minutes = 10, int $limit = 100): int
    {
        $minutes = max(1, $minutes);
        $limit = max(1, min(500, $limit));
        $cutoff = now()->subMinutes($minutes);
        $processed = 0;

        Booking::query()
            ->where('booking_status', 'pending')
            ->whereNotNull('updated_at')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $bookingId) use ($cutoff, $minutes, &$processed): void {
                $refunded = DB::transaction(function () use ($bookingId, $cutoff, $minutes): bool {
                    /** @var Booking|null $booking */
                    $booking = Booking::query()->lockForUpdate()->find($bookingId);
                    if (! $booking
                        || $booking->booking_status !== 'pending'
                        || ! $booking->updated_at
                        || $booking->updated_at->greaterThan($cutoff)) {
                        return false;
                    }

                    $reference = 'portal-booking-fee-'.$booking->id;
                    if ($booking->user_id) {
                        $hold = UserWalletTransaction::query()
                            ->where('type', 'booking_hold')
                            ->where('reference', $reference)
                            ->whereHas('wallet', fn ($query) => $query->where('user_id', $booking->user_id))
                            ->latest('id')
                            ->first();

                        $alreadyReleased = $hold !== null && UserWalletTransaction::query()
                            ->where('user_wallet_id', $hold->user_wallet_id)
                            ->where('type', 'refund')
                            ->where('reference', $reference)
                            ->exists();
                    } else {
                        $hold = WalletTransaction::query()
                            ->where('type', 'booking_hold')
                            ->where('reference', $reference)
                            ->whereHas('wallet', fn ($query) => $query->where('agency_id', $booking->agency_id))
                            ->latest('id')
                            ->first();

                        $alreadyReleased = $hold !== null && WalletTransaction::query()
                            ->where('wallet_id', $hold->wallet_id)
                            ->where('type', 'refund')
                            ->where('reference', $reference)
                            ->exists();
                    }

                    if ($hold && ! $alreadyReleased) {
                        if ($booking->user_id) {
                            $this->userWallet->releaseHold(
                                (int) $booking->user_id,
                                (float) $hold->amount,
                                $reference,
                                [
                                    'booking_id' => $booking->id,
                                    'purpose' => 'automatic_pending_booking_refund',
                                    'timeout_minutes' => $minutes,
                                ]
                            );
                        } else {
                            $this->wallet->releaseHold(
                                (int) $booking->agency_id,
                                (float) $hold->amount,
                                $reference,
                                [
                                    'booking_id' => $booking->id,
                                    'purpose' => 'automatic_pending_booking_refund',
                                    'timeout_minutes' => $minutes,
                                ]
                            );
                        }
                    }

                    $reason = 'Automatic refund: pending booking exceeded the payment timeout.';
                    $refund = RefundRequest::query()
                        ->where('booking_id', $booking->id)
                        ->where('reason', $reason)
                        ->latest('id')
                        ->first();

                    if (! $refund) {
                        RefundRequest::create([
                            'booking_id' => $booking->id,
                            'agency_id' => $booking->agency_id,
                            'amount' => $hold ? (float) $hold->amount : 0.0,
                            'reason' => $reason,
                            'status' => 'processed',
                            'processed_at' => now(),
                        ]);
                    } elseif ($refund->status !== 'processed') {
                        $refund->update([
                            'status' => 'processed',
                            'processed_at' => now(),
                        ]);
                    }

                    $booking->update(['booking_status' => 'refunded']);
                    $attempt = $booking->attempts()
                        ->whereIn('status', ['processing', 'payment_required'])
                        ->latest('id')
                        ->first();
                    if ($attempt) {
                        $attempt->update([
                            'status' => 'expired',
                            'error_message' => 'Pending booking automatically refunded after the payment timeout.',
                        ]);
                    }

                    $booking->logs()->create([
                        'event_type' => 'pending_booking_auto_refunded',
                        'payload' => [
                            'timeout_minutes' => $minutes,
                            'portal_fee_refunded' => $hold ? (float) $hold->amount : 0.0,
                        ],
                    ]);

                    $this->audit->log((int) $booking->agency_id, 'refund', [
                        'booking_id' => $booking->id,
                        'action' => 'automatic_pending_timeout',
                        'amount' => $hold ? (float) $hold->amount : 0.0,
                    ]);

                    if ($booking->user_id) {
                        $this->notifications->send(
                            (int) $booking->user_id,
                            'Booking automatically refunded',
                            'The pending booking exceeded the 10-minute payment window. The portal fee hold was returned to your wallet.'
                        );
                    }

                    return true;
                });

                if ($refunded) {
                    $processed++;
                }
            });

        return $processed;
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
