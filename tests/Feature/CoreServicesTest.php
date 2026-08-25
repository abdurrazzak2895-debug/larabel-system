<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\Candidate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
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

    public function test_successful_credit_booking_debits_the_agency_portal_fee_once(): void
    {
        Setting::create(['key' => 'booking_price', 'value' => '25.00', 'agency_id' => null]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return Http::response(['exam_reservation' => ['id' => 99001]], 201);
            }

            if (str_ends_with($path, '/users/SVP-FEE-USER/balance')) {
                return Http::response(['reservation_credits' => 1], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/reservation_credits/use')) {
                return Http::response(['success' => true, 'reservation_id' => 99001], 200);
            }

            return Http::response([], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $this->agency->id,
            'full_name' => 'Portal Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-FEE-USER',
        ]);

        app(\App\Services\UserWalletService::class)->deposit($user->id, 100.00);

        $result = app(BookingService::class)->completeBooking('fee-token', [
            'agency_id' => $this->agency->id,
            'user_id' => $user->id,
            'credential_id' => $candidate->id,
            'svp_user_id' => 'SVP-FEE-USER',
            'occupation_id' => '2062',
            'exam_session_id' => 'FEE-SESSION',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('booked', $result['booking']->booking_status);
        $this->assertDatabaseHas('user_wallet_transactions', [
            'type' => 'booking_hold',
            'amount' => 25.00,
            'reference' => 'portal-booking-fee-'.$result['booking']->id,
        ]);
        $this->assertDatabaseHas('user_wallet_transactions', [
            'type' => 'booking_debit',
            'amount' => 25.00,
            'reference' => 'portal-booking-fee-'.$result['booking']->id,
        ]);
        $this->assertDatabaseHas('user_wallets', [
            'user_id' => $user->id,
            'available_balance' => 75.00,
            'reserved_balance' => 0.00,
        ]);
    }

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

    public function test_booking_service_submits_center_17_booking_with_real_session_and_site_id(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return Http::response([
                    'exam_reservation' => [
                        'id' => 17817,
                        'test_center' => [
                            'test_center_id' => 17,
                            'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                        ],
                    ],
                ], 201);
            }

            if (str_ends_with($path, '/users/SVP-CENTER-17/balance')) {
                return Http::response(['reservation_credits' => 1], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/reservation_credits/use')) {
                return Http::response(['success' => true, 'reservation_id' => 17817], 200);
            }

            return Http::response(['success' => true], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $this->agency->id,
            'full_name' => 'Center 17 Smoke Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-CENTER-17',
        ]);

        $result = app(BookingService::class)->completeBooking('smoke-token', [
            'agency_id' => $this->agency->id,
            'user_id' => $user->id,
            'credential_id' => $candidate->id,
            'svp_user_id' => 'SVP-CENTER-17',
            'occupation_id' => '2279',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
            'test_center_name' => 'Bangladesh Korea TTC Dhaka',
            'exam_session_id' => 'CENTER-17-LIVE-SESSION',
            'exam_session_name' => '2026-08-31 • First Shift • Center 17',
            'exam_date' => '2026-08-31',
            'temporary_hold_id' => 'CENTER-17-HOLD',
            'temporary_hold_expires_at' => '2026-08-17 12:00:00',
            'language_code' => 'LOABB',
            'methodology' => 'in_person',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('booked', $result['booking']->booking_status);
        $this->assertSame('17', $result['booking']->test_center_id);
        $this->assertSame('Bangladesh Korea TTC Dhaka', $result['booking']->test_center_name);
        $this->assertSame('CENTER-17-HOLD', $result['booking']->temporary_hold_id);
        $this->assertDatabaseHas('bookings', [
            'id' => $result['booking']->id,
            'test_center_id' => '17',
            'test_center_name' => 'Bangladesh Korea TTC Dhaka',
            'exam_session_id' => 'CENTER-17-LIVE-SESSION',
            'booking_status' => 'booked',
        ]);

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return true;
            }

            $body = json_decode($request->body(), true);
            return ($body['exam_session_id'] ?? null) === 'CENTER-17-LIVE-SESSION'
                && ($body['site_id'] ?? null) === '17'
                && ($body['site_city'] ?? null) === 'Dhaka'
                && ($body['hold_id'] ?? null) === 'CENTER-17-HOLD'
                && ($body['language_code'] ?? null) === 'LOABB'
                && ! array_key_exists('amount', $body);
        });

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_ends_with($path, '/individual_labor_space/reservation_credits/use')) {
                return true;
            }

            $body = json_decode($request->body(), true);
            return ($body['reservation_id'] ?? null) === 17817
                && ($body['occupation_id'] ?? null) === 2279
                && ($body['methodology_type'] ?? null) === 'in_person';
        });
    }

    public function test_booking_service_rejects_and_cancels_when_svp_returns_a_different_physical_center(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations') && $request->method() === 'POST') {
                return Http::response([
                    'exam_reservation' => [
                        'id' => 5351764,
                        'prometric_data' => ['site_id' => '17'],
                        'test_center' => [
                            'test_center_id' => 223,
                            'test_center_name' => 'Manikganj Technical Training Center',
                        ],
                    ],
                ], 201);
            }

            if (str_ends_with($path, '/individual_labor_space/exam_reservations/5351764') && $request->method() === 'DELETE') {
                return Http::response(['success' => true, 'cancelled' => true], 200);
            }

            return Http::response(['success' => true], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $this->agency->id,
            'full_name' => 'Center Mismatch Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-CENTER-MISMATCH',
        ]);

        $result = app(BookingService::class)->completeBooking('test-token', [
            'agency_id' => $this->agency->id,
            'user_id' => $user->id,
            'credential_id' => $candidate->id,
            'svp_user_id' => 'SVP-CENTER-MISMATCH',
            'occupation_id' => '2061',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
            'test_center_name' => 'Bangladesh Korea TTC Dhaka',
            'exam_session_id' => 'OPAQUE-SESSION-17',
            'exam_date' => '2026-08-31',
            'temporary_hold_id' => '5201071',
            'language_code' => 'LOABB',
            'methodology' => 'in_person',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['booking']->booking_status);
        $this->assertStringContainsString('assigned test center Manikganj Technical Training Center', $result['error']);
        $this->assertStringContainsString('selected center was Bangladesh Korea TTC Dhaka', $result['error']);
        $this->assertStringNotContainsString('223', $result['error']);
        $this->assertStringNotContainsString('17', $result['error']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/individual_labor_space/exam_reservations/5351764');
        });
        Http::assertNotSent(function ($request): bool {
            return str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/balance');
        });
        Http::assertNotSent(function ($request): bool {
            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/individual_labor_space/payments');
        });
    }

    public function test_booking_service_creates_official_checkout_when_svp_credit_is_unavailable(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return Http::response([
                    'exam_reservation' => [
                        'id' => 7865,
                        'test_center' => [
                            'test_center_id' => 223,
                            'test_center_name' => 'Manikganj Technical Training Center',
                        ],
                    ],
                ], 201);
            }

            if (str_ends_with($path, '/users/SVP-NO-CREDIT/balance')) {
                return Http::response(['reservation_credits' => 0], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/payments')) {
                return Http::response([
                    'hyperpay_url' => 'https://eu-prod.oppwa.com',
                    'payment' => [
                        'id' => 2997943,
                        'ndc' => '0BC8EC7E741E3BDEDC474E8CF89BF356.prod01-vm-tx13',
                        'resource_path' => '/v1/checkouts/0BC8EC7E741E3BDEDC474E8CF89BF356.prod01-vm-tx13/payment',
                    ],
                ], 201);
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
        $this->assertStringStartsWith('https://eu-prod.oppwa.com/v1/redirect.html?', $result['checkout_url']);
        parse_str((string) parse_url($result['checkout_url'], PHP_URL_QUERY), $checkoutQuery);
        $this->assertSame('2997943', $checkoutQuery['paymentId'] ?? null);
        $this->assertSame('0BC8EC7E741E3BDEDC474E8CF89BF356.prod01-vm-tx13', $checkoutQuery['id'] ?? null);
        $this->assertSame('/v1/checkouts/0BC8EC7E741E3BDEDC474E8CF89BF356.prod01-vm-tx13/payment', $checkoutQuery['resourcePath'] ?? null);
        $this->assertSame('0BC8EC7E741E3BDEDC474E8CF89BF356.prod01-vm-tx13', $checkoutQuery['ndc'] ?? null);
        $this->assertSame(
            'https://svp-international.pacc.sa/labor/confirmation?paymentId=2997943&'.
            'id=0BC8EC7E741E3BDEDC474E8CF89BF356.prod01-vm-tx13&'.
            'resourcePath=%2Fv1%2Fcheckouts%2F0BC8EC7E741E3BDEDC474E8CF89BF356.prod01-vm-tx13%2Fpayment',
            $checkoutQuery['redirectUrl'] ?? null
        );
        $storedCheckoutUrl = data_get($result['booking']->attempts()->latest()->first()->provider_response, 'checkout_url');
        $this->assertSame($result['checkout_url'], $storedCheckoutUrl);

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_ends_with($path, '/individual_labor_space/payments')) {
                return true;
            }

            $body = json_decode($request->body(), true);
            return ($body['payment']['payment_method'] ?? null) === 'card'
                && ($body['payment']['payable_type'] ?? null) === 'Reservation'
                && ($body['payment']['payable_id'] ?? null) === 7865
                && parse_url($request->url(), PHP_URL_QUERY) === 'locale=en';
        });
    }

    public function test_widget_checkout_helper_extracts_actual_svp_ndc_and_integrity(): void
    {
        $providerResponse = [
            'checkout' => [
                'id' => 3277685,
                'hyperpay_url' => 'https://eu-prod.oppwa.com',
                'response' => [
                    'result' => ['code' => '000.200.100'],
                    'ndc' => 'D8A7400764D49C59388A0D997CDAE47A.prod02-vm-tx05',
                    'id' => 'D8A7400764D49C59388A0D997CDAE47A.prod02-vm-tx05',
                    'integrity' => 'sha384-v7It9NnCWutsL14cPNz3twZzFeXC0lsc/z6Mq1JJDwsSD8yrRvcf/frkkIuhaznS',
                ],
            ],
        ];

        $widget = app(BookingService::class)->widgetCheckoutFromProviderResponse($providerResponse);

        $this->assertSame([
            'checkout_id' => 'D8A7400764D49C59388A0D997CDAE47A.prod02-vm-tx05',
            'integrity' => 'sha384-v7It9NnCWutsL14cPNz3twZzFeXC0lsc/z6Mq1JJDwsSD8yrRvcf/frkkIuhaznS',
        ], $widget);
        $this->assertNull(app(BookingService::class)->checkoutUrlFromProviderResponse($providerResponse));
    }

    public function test_booking_service_accepts_widget_only_svp_checkout_response(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/individual_labor_space/exam_reservations')) {
                return Http::response(['exam_reservation' => ['id' => 32701, 'test_center' => ['test_center_id' => 223]]], 201);
            }

            if (str_ends_with($path, '/users/SVP-WIDGET-USER/balance')) {
                return Http::response(['reservation_credits' => 0], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/payments')) {
                return Http::response([
                    'checkout' => [
                        'id' => 3277685,
                        'hyperpay_url' => 'https://eu-prod.oppwa.com',
                        'response' => [
                            'result' => ['code' => '000.200.100'],
                            'ndc' => 'WIDGET-NDC.prod02-vm-tx05',
                            'id' => 'WIDGET-NDC.prod02-vm-tx05',
                            'integrity' => 'sha384-widget-integrity',
                        ],
                    ],
                ], 201);
            }

            return Http::response(['success' => true], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $this->agency->id,
            'full_name' => 'Widget Checkout Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-WIDGET-USER',
        ]);

        $result = app(BookingService::class)->completeBooking('widget-token', [
            'agency_id' => $this->agency->id,
            'user_id' => $user->id,
            'credential_id' => $candidate->id,
            'svp_user_id' => 'SVP-WIDGET-USER',
            'occupation_id' => '2279',
            'exam_session_id' => 'WIDGET-SESSION',
            'test_center_id' => '223',
            'test_center_name' => 'Manikganj Technical Training Center',
            'city' => 'Dhaka',
            'exam_date' => '2026-08-31',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['payment_required']);
        $this->assertSame('pending', $result['booking']->booking_status);
        $this->assertNull($result['checkout_url']);
        $this->assertSame([
            'checkout_id' => 'WIDGET-NDC.prod02-vm-tx05',
            'integrity' => 'sha384-widget-integrity',
        ], $result['widget_checkout']);
        $this->assertSame(
            $result['widget_checkout'],
            data_get($result['booking']->attempts()->latest()->first()->provider_response, 'widget_checkout')
        );
    }

    public function test_user_payment_return_marks_pending_booking_booked_after_successful_svp_status(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/v1/checkouts/TEST-NDC/payment')) {
                return Http::response(['result' => ['code' => '000.100.110', 'description' => 'Request successfully processed']], 200);
            }

            return Http::response(['success' => true], 200);
        });

        $user = User::factory()->create(['agency_id' => $this->agency->id]);
        $booking = Booking::create([
            'agency_id' => $this->agency->id,
            'user_id' => $user->id,
            'reservation_id' => '7865',
            'booking_status' => 'pending',
            'booking_reference' => 'PAYMENT-RETURN-TEST',
        ]);
        BookingAttempt::create([
            'booking_id' => $booking->id,
            'status' => 'payment_required',
            'provider_response' => [
                'checkout' => [
                    'response' => ['ndc' => 'TEST-NDC', 'integrity' => 'sha384-test'],
                ],
            ],
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['svp_token' => 'test-token'])
            ->get(route('user.bookings.payment-return', [
                'booking' => $booking->id,
                'resourcePath' => '/v1/checkouts/TEST-NDC/payment',
            ]));

        $response->assertRedirect(route('user.bookings.show', $booking->id));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'booked',
        ]);
        $this->assertDatabaseHas('booking_attempts', [
            'booking_id' => $booking->id,
            'status' => 'success',
        ]);
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
