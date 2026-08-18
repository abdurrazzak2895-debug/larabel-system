<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\RefundRequest;
use App\Services\RefundService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomaticRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_pending_booking_is_refunded_and_wallet_hold_is_released_once(): void
    {
        $agency = Agency::factory()->create();
        $wallet = app(WalletService::class);
        $wallet->deposit($agency->id, 100.00, 'TEST-DEPOSIT');

        $booking = Booking::create([
            'agency_id' => $agency->id,
            'occupation_id' => '2062',
            'exam_session_id' => 'SESSION-EXPIRED',
            'booking_status' => 'pending',
            'booking_reference' => 'TEST-PENDING-EXPIRED',
        ]);
        $reference = 'portal-booking-fee-'.$booking->id;
        $wallet->hold($agency->id, 25.00, $reference, ['booking_id' => $booking->id]);
        BookingAttempt::create([
            'booking_id' => $booking->id,
            'status' => 'payment_required',
        ]);
        DB::table('bookings')->where('id', $booking->id)->update([
            'updated_at' => now()->subMinutes(11),
        ]);

        $count = app(RefundService::class)->autoRefundExpiredPending();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'refunded',
        ]);
        $this->assertDatabaseHas('agency_wallets', [
            'agency_id' => $agency->id,
            'available_balance' => 100.00,
            'reserved_balance' => 0.00,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'refund',
            'amount' => 25.00,
            'reference' => $reference,
        ]);
        $this->assertDatabaseHas('refund_requests', [
            'booking_id' => $booking->id,
            'agency_id' => $agency->id,
            'amount' => 25.00,
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('booking_attempts', [
            'booking_id' => $booking->id,
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('booking_logs', [
            'booking_id' => $booking->id,
            'event_type' => 'pending_booking_auto_refunded',
        ]);

        $secondCount = app(RefundService::class)->autoRefundExpiredPending();

        $this->assertSame(0, $secondCount);
        $this->assertSame(1, DB::table('wallet_transactions')
            ->where('reference', $reference)
            ->where('type', 'refund')
            ->count());
        $this->assertSame(1, RefundRequest::where('booking_id', $booking->id)->count());
    }

    public function test_recent_pending_booking_and_non_pending_booking_are_not_refunded(): void
    {
        $agency = Agency::factory()->create();
        $wallet = app(WalletService::class);
        $wallet->deposit($agency->id, 100.00, 'TEST-DEPOSIT');

        $recent = Booking::create([
            'agency_id' => $agency->id,
            'occupation_id' => '2062',
            'exam_session_id' => 'SESSION-RECENT',
            'booking_status' => 'pending',
            'booking_reference' => 'TEST-PENDING-RECENT',
        ]);
        $booked = Booking::create([
            'agency_id' => $agency->id,
            'occupation_id' => '2062',
            'exam_session_id' => 'SESSION-BOOKED',
            'booking_status' => 'booked',
            'booking_reference' => 'TEST-BOOKED-OLD',
        ]);
        $wallet->hold($agency->id, 25.00, 'portal-booking-fee-'.$recent->id, ['booking_id' => $recent->id]);
        DB::table('bookings')->where('id', $recent->id)->update([
            'updated_at' => now()->subMinutes(9),
        ]);
        DB::table('bookings')->where('id', $booked->id)->update([
            'updated_at' => now()->subMinutes(30),
        ]);

        $count = app(RefundService::class)->autoRefundExpiredPending();

        $this->assertSame(0, $count);
        $this->assertDatabaseHas('bookings', ['id' => $recent->id, 'booking_status' => 'pending']);
        $this->assertDatabaseHas('bookings', ['id' => $booked->id, 'booking_status' => 'booked']);
        $this->assertDatabaseHas('agency_wallets', [
            'agency_id' => $agency->id,
            'available_balance' => 75.00,
            'reserved_balance' => 25.00,
        ]);
        $this->assertDatabaseCount('refund_requests', 0);
    }

    public function test_refund_command_processes_expired_pending_bookings(): void
    {
        $agency = Agency::factory()->create();
        $booking = Booking::create([
            'agency_id' => $agency->id,
            'occupation_id' => '2062',
            'exam_session_id' => 'SESSION-COMMAND',
            'booking_status' => 'pending',
            'booking_reference' => 'TEST-PENDING-COMMAND',
        ]);
        DB::table('bookings')->where('id', $booking->id)->update([
            'updated_at' => now()->subMinutes(11),
        ]);

        $this->artisan('bookings:refund-expired-pending')
            ->expectsOutput('Automatically refunded 1 expired pending booking(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'refunded',
        ]);
    }
}

