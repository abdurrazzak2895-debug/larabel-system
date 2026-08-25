<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Setting;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DepositService;
use App\Services\UserWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyUserWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_wallets_are_isolated_and_do_not_write_to_agency_wallet_ledger(): void
    {
        $agency = Agency::factory()->create();
        $alice = User::factory()->create(['agency_id' => $agency->id]);
        $bob = User::factory()->create(['agency_id' => $agency->id]);
        $wallets = app(UserWalletService::class);

        $wallets->deposit($alice->id, 100.00, 'alice-deposit');
        $wallets->hold($alice->id, 30.00, 'alice-booking');
        $wallets->debit($alice->id, 30.00, 'alice-booking');

        $this->assertSame(70.00, (float) $wallets->getWallet($alice->id)->fresh()->available_balance);
        $this->assertSame(0.00, (float) $wallets->getWallet($alice->id)->fresh()->reserved_balance);
        $this->assertSame(0.00, (float) $wallets->getWallet($bob->id)->fresh()->available_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseHas('user_wallet_transactions', [
            'type' => 'booking_debit',
            'amount' => 30.00,
            'reference' => 'alice-booking',
        ]);
    }

    public function test_approved_agency_deposit_credits_the_selected_user_wallet(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $service = app(DepositService::class);

        $deposit = $service->submit([
            'agency_id' => $agency->id,
            'user_id' => $user->id,
            'amount' => 250.00,
            'payment_method' => 'cash',
        ]);
        $service->approve($deposit);

        $this->assertDatabaseHas('deposit_requests', [
            'id' => $deposit->id,
            'agency_id' => $agency->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);
        $this->assertSame(250.00, (float) app(UserWalletService::class)->getWallet($user->id)->fresh()->available_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_user_specific_portal_fee_overrides_agency_and_global_defaults(): void
    {
        $agency = Agency::factory()->create();
        $pricedUser = User::factory()->create([
            'agency_id' => $agency->id,
            'portal_booking_fee' => 135.50,
        ]);
        Setting::create(['key' => 'booking_price', 'value' => '90.00', 'agency_id' => null]);

        $fee = app(BookingService::class)->portalBookingFee($pricedUser->id, $agency->id);

        $this->assertSame(135.50, $fee);
    }
}
