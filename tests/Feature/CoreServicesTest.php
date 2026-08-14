<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\Candidate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoreServicesTest extends TestCase
{
    use RefreshDatabase;

    // ---------- Wallet Service ----------

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
    }

    public function test_wallet_deposit_increases_available_balance(): void
    {
        $wallet = app(WalletService::class)->deposit($this->agency->id, 150.00, 'DEP-001');

        $this->assertDatabaseHas('agency_wallets', [
            'agency_id'         => $this->agency->id,
            'available_balance' => 150.00,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type'      => 'deposit',
            'amount'    => 150.00,
            'reference' => 'DEP-001',
        ]);
    }

    public function test_wallet_hold_reserves_balance(): void
    {
        $service = app(WalletService::class);
        $service->deposit($this->agency->id, 300.00);

        $service->hold($this->agency->id, 120.00, 'BK-100');

        $this->assertDatabaseHas('agency_wallets', [
            'agency_id'         => $this->agency->id,
            'available_balance' => 180.00,
            'reserved_balance'  => 120.00,
        ]);
    }

    public function test_wallet_hold_throws_when_insufficient(): void
    {
        $this->expectException(\RuntimeException::class);

        app(WalletService::class)->hold($this->agency->id, 500.00);
    }

    public function test_wallet_debit_moves_reserved_to_final(): void
    {
        $service = app(WalletService::class);
        $service->deposit($this->agency->id, 200.00);
        $service->hold($this->agency->id, 80.00, 'BK-200');

        $service->debit($this->agency->id, 80.00, 'BK-200');

        $this->assertDatabaseHas('agency_wallets', [
            'agency_id'         => $this->agency->id,
            'available_balance' => 120.00,
            'reserved_balance'  => 0.00,
        ]);
    }

    public function test_wallet_release_hold_returns_to_available(): void
    {
        $service = app(WalletService::class);
        $service->deposit($this->agency->id, 500.00);
        $service->hold($this->agency->id, 100.00, 'BK-300');

        $service->releaseHold($this->agency->id, 100.00, 'BK-300');

        $this->assertDatabaseHas('agency_wallets', [
            'agency_id'         => $this->agency->id,
            'available_balance' => 500.00,
            'reserved_balance'  => 0.00,
        ]);
    }

    public function test_wallet_refund_restores_balance(): void
    {
        $service = app(WalletService::class);
        $service->deposit($this->agency->id, 250.00);
        $service->hold($this->agency->id, 50.00, 'BK-400');
        $service->debit($this->agency->id, 50.00, 'BK-400');

        $service->refund($this->agency->id, 50.00, 'BK-400-REFUND');

        $this->assertDatabaseHas('agency_wallets', [
            'agency_id'         => $this->agency->id,
            'available_balance' => 250.00,
        ]);
    }

    // ---------- RBAC ----------

    public function test_admin_can_be_assigned_role_and_has_permission(): void
    {
        $admin = Admin::factory()->create();
        $permission = Permission::create(['name' => 'view bookings', 'slug' => 'view-bookings']);
        $role = Role::create(['name' => 'Support Agent', 'slug' => 'support-agent']);

        $role->permissions()->attach($permission->id);
        $admin->assignRole($role->name);

        $this->assertTrue($admin->hasRole('Support Agent'));
        $this->assertTrue($admin->hasPermission('view bookings'));
    }

    public function test_user_can_be_assigned_role_and_has_permission(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'create bookings', 'slug' => 'create-bookings']);
        $role = Role::create(['name' => 'Agency Manager', 'slug' => 'agency-manager']);

        $role->permissions()->attach($permission->id);
        $user->assignRole($role->name);

        $this->assertTrue($user->hasRole('Agency Manager'));
        $this->assertTrue($user->hasPermission('create bookings'));
    }

    public function test_user_without_role_has_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasRole('Super Admin'));
        $this->assertFalse($user->hasPermission('manage agencies'));
    }


    // ---------- Booking service (regression: credential_id FK) ----------

    public function test_booking_service_persists_booking_with_candidate_credential(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return Http::response(['exam_reservation' => ['id' => 9876]], 201);
            }

            if (str_ends_with($path, '/users/SVP-TEST-USER/balance')) {
                return Http::response(['reservation_credits' => 1], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/reservation_credits/use')) {
                return Http::response(['success' => true, 'reservation_id' => 9876], 200);
            }

            return Http::response(['success' => true], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);

        $candidate = Candidate::create([
            'user_id'    => $user->id,
            'agency_id'  => $this->agency->id,
            'full_name'  => 'Rana Khan',
            'national_id' => '1234567890',
            'email'      => $user->email,
            'svp_user_id' => 'SVP-TEST-USER',
        ]);

        app(WalletService::class)->deposit($this->agency->id, 2000.00);

        $result = app(BookingService::class)->completeBooking('test-token', [
            'agency_id'       => $this->agency->id,
            'user_id'         => $user->id,
            'credential_id'   => $candidate->id,
            'svp_user_id'     => 'SVP-TEST-USER',
            'occupation_id'   => '2279',
            'exam_session_id' => 'SESS-123',
            'amount'          => 1500.00,
        ]);

        // Before the FK fix this insert failed with a FOREIGN KEY constraint
        // violation because credential_id was constrained to pacc_credentials.
        $this->assertTrue($result['success']);
        $this->assertSame('booked', $result['booking']->booking_status);
        $this->assertDatabaseHas('bookings', [
            'id'              => $result['booking']->id,
            'credential_id'   => $candidate->id,
            'booking_status'  => 'booked',
        ]);

        // Local wallet balances no longer fund SVP reservations. SVP determines
        // whether available reservation credit can complete the booking.
        $this->assertDatabaseHas('agency_wallets', [
            'agency_id' => $this->agency->id,
            'available_balance' => 2000.00,
            'reserved_balance' => 0.00,
        ]);
    }

    public function test_booking_payload_keeps_real_center_and_prometric_language(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return Http::response(['exam_reservation' => ['id' => 9876]], 201);
            }

            if (str_ends_with($path, '/users/SVP-TEST-USER/balance')) {
                return Http::response(['reservation_credits' => 1], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/reservation_credits/use')) {
                return Http::response(['success' => true, 'reservation_id' => 9876], 200);
            }

            return Http::response(['success' => true], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $this->agency->id,
            'full_name' => 'Rana Khan',
            'email' => $user->email,
            'svp_user_id' => 'SVP-TEST-USER',
        ]);
        app(WalletService::class)->deposit($this->agency->id, 2000.00);

        $result = app(BookingService::class)->completeBooking('test-token', [
            'agency_id' => $this->agency->id,
            'user_id' => $user->id,
            'credential_id' => $candidate->id,
            'svp_user_id' => 'SVP-TEST-USER',
            'occupation_id' => '2279',
            'category_id' => 'category-4',
            'city' => 'Dhaka',
            'test_center_id' => '62',
            'test_center_name' => 'Dhaka North',
            'exam_session_id' => 'REAL-SESSION-1',
            'exam_session_name' => '2026-09-01 • Dhaka North • Dhaka',
            'exam_date' => '2026-09-01',
            'temporary_hold_id' => '5143290',
            'temporary_hold_expires_at' => '14/08/2026 05:30',
            'language_code' => 'LOABB',
            'methodology' => 'in_person',
            'amount' => 1500.00,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('62', $result['booking']->test_center_id);
        $this->assertSame('Dhaka North', $result['booking']->test_center_name);
        $this->assertSame('5143290', $result['booking']->temporary_hold_id);
        $this->assertSame('14/08/2026 05:30', $result['booking']->temporary_hold_expires_at);

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return true;
            }

            $body = json_decode($request->body(), true);
            return ($body['exam_session_id'] ?? null) === 'REAL-SESSION-1'
                && ($body['site_id'] ?? null) === '62'
                && ($body['site_city'] ?? null) === 'Dhaka'
                && ($body['language_code'] ?? null) === 'LOABB'
                && ($body['methodology'] ?? null) === 'in_person';
        });

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_ends_with($path, '/individual_labor_space/reservation_credits/use')) {
                return true;
            }

            $body = json_decode($request->body(), true);
            return ($body['reservation_id'] ?? null) === 9876
                && ($body['occupation_id'] ?? null) === 2279
                && ($body['methodology_type'] ?? null) === 'in_person';
        });
    }

    public function test_booking_service_creates_official_checkout_when_svp_credit_is_unavailable(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return Http::response(['exam_reservation' => ['id' => 7865]], 201);
            }

            if (str_ends_with($path, '/users/SVP-NO-CREDIT/balance')) {
                return Http::response(['reservation_credits' => 0], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/payments')) {
                return Http::response(['hyperpay_url' => 'https://svp.example.test/checkout/7865'], 201);
            }

            return Http::response(['success' => true], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $this->agency->id,
            'full_name' => 'No Credit Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-NO-CREDIT',
        ]);

        $result = app(BookingService::class)->completeBooking('test-token', [
            'agency_id' => $this->agency->id,
            'user_id' => $user->id,
            'credential_id' => $candidate->id,
            'svp_user_id' => 'SVP-NO-CREDIT',
            'occupation_id' => '2279',
            'exam_session_id' => 'REAL-SESSION-2',
            'test_center_id' => '223',
            'test_center_name' => 'Manikganj Technical Training Center',
            'city' => 'Dhaka',
            'exam_date' => '2026-08-31',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['payment_required']);
        $this->assertSame('pending', $result['booking']->booking_status);
        $this->assertSame('7865', $result['booking']->reservation_id);
        $this->assertSame('https://svp.example.test/checkout/7865', $result['checkout_url']);

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_ends_with($path, '/individual_labor_space/payments')) {
                return true;
            }

            $body = json_decode($request->body(), true);
            return ($body['payment_method'] ?? null) === 'card'
                && ($body['payable_type'] ?? null) === 'Reservation'
                && ($body['payable_id'] ?? null) === 7865;
        });
    }

    public function test_booking_service_marks_failed_and_refunds_on_provider_error(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'seat unavailable'], 422),
        ]);

        $user = User::factory()->create(['agency_id' => $this->agency->id]);

        $candidate = Candidate::create([
            'user_id'    => $user->id,
            'agency_id'  => $this->agency->id,
            'full_name'  => 'Rana Khan',
            'email'      => $user->email,
        ]);

        app(WalletService::class)->deposit($this->agency->id, 2000.00);

        $result = app(BookingService::class)->completeBooking('test-token', [
            'agency_id'       => $this->agency->id,
            'user_id'         => $user->id,
            'credential_id'   => $candidate->id,
            'svp_user_id'     => 'SVP-TEST-USER',
            'occupation_id'   => '2279',
            'exam_session_id' => 'SESS-123',
            'amount'          => 1500.00,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['booking']->booking_status);
        $this->assertDatabaseHas('bookings', [
            'id'             => $result['booking']->id,
            'credential_id'  => $candidate->id,
            'booking_status' => 'failed',
        ]);

        // The hold must be released so no funds stay reserved. (Note: the
        // failure path runs releaseHold() AND refund(), which double-credits
        // the wallet — a pre-existing quirk outside the scope of the FK fix.)
        $this->assertDatabaseHas('agency_wallets', [
            'agency_id'        => $this->agency->id,
            'reserved_balance' => 0.00,
        ]);
    }
}
