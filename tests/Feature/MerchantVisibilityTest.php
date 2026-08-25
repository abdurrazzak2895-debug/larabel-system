<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\DepositRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Services\UserWalletService;
use Tests\TestCase;

class MerchantVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_user_deposit_history_hides_private_numbers_and_admin_payment_details(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        config(['payments.merchant_numbers' => [
            'bkash' => '01711111111',
            'nagad' => '01822222222',
        ]]);
        Auth::guard('web')->login($user);

        $this->get(route('user.deposits.index'))
            ->assertOk()
            ->assertSee('Deposits managed by Admin')
            ->assertDontSee('01711111111')
            ->assertDontSee('01822222222')
            ->assertDontSee('Merchant Control');
    }

    public function test_admin_can_update_merchant_name_and_private_numbers(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('Super Admin');
        Auth::guard('admin')->login($admin);

        $csrfToken = 'merchant-settings-csrf-token';
        $this->withSession(['_token' => $csrfToken])
            ->put(route('admin.settings.update'), [
                '_token' => $csrfToken,
                'timezone' => 'Asia/Dhaka',
                'currency' => 'BDT',
                'portal_merchant_name' => 'Updated Merchant',
                'bkash_merchant_number' => '01712345678',
                'nagad_merchant_number' => '01812345678',
            ])->assertRedirect();

        $this->assertDatabaseHas('settings', ['key' => 'portal_merchant_name', 'value' => 'Updated Merchant']);
        $this->assertDatabaseHas('settings', ['key' => 'bkash_merchant_number', 'value' => '01712345678']);
        $this->assertDatabaseHas('settings', ['key' => 'nagad_merchant_number', 'value' => '01812345678']);
    }

    public function test_public_and_admin_created_users_can_open_self_service_deposit_but_agency_users_cannot(): void
    {
        $agency = Agency::factory()->create();
        $publicUser = User::factory()->create([
            'agency_id' => $agency->id,
            'account_source' => 'public_registration',
        ]);
        $adminUser = User::factory()->create([
            'agency_id' => $agency->id,
            'account_source' => 'admin_control',
        ]);
        $agencyUser = User::factory()->create([
            'agency_id' => $agency->id,
            'account_source' => 'agency_control',
        ]);
        config(['payments.merchant_numbers' => [
            'bkash' => '01711111111',
            'nagad' => '01822222222',
        ]]);

        Auth::guard('web')->login($publicUser);
        $this->get(route('user.deposits.create'))
            ->assertOk()
            ->assertSee('01711111111')
            ->assertSee('01822222222');

        Auth::guard('web')->logout();
        Auth::guard('web')->login($adminUser);
        $this->get(route('user.deposits.create'))->assertOk();

        Auth::guard('web')->logout();
        Auth::guard('web')->login($agencyUser);
        $this->get(route('user.deposits.create'))->assertForbidden();
        $agencyCsrf = 'agency-self-service-deposit-csrf-token';
        $this->withSession(['_token' => $agencyCsrf])
            ->post(route('user.deposits.store'), ['_token' => $agencyCsrf])
            ->assertForbidden();
    }

    public function test_self_service_deposit_stays_pending_until_admin_approval_and_credits_selected_user_only(): void
    {
        $agency = Agency::factory()->create();
        $publicUser = User::factory()->create([
            'agency_id' => $agency->id,
            'account_source' => 'public_registration',
        ]);
        $otherUser = User::factory()->create([
            'agency_id' => $agency->id,
            'account_source' => 'admin_control',
        ]);
        $csrfToken = 'self-service-deposit-csrf-token';
        Auth::guard('web')->login($publicUser);

        $this->withSession(['_token' => $csrfToken])
            ->post(route('user.deposits.store'), [
                '_token' => $csrfToken,
                'amount' => '125.50',
                'payment_method' => 'bkash',
                'mfs_sender_phone' => '01712345678',
                'mfs_transaction_id' => 'PUBLIC-SELF-SERVICE-001',
            ])
            ->assertRedirect(route('user.deposits.index'));

        $deposit = DepositRequest::where('mfs_transaction_id', 'PUBLIC-SELF-SERVICE-001')->firstOrFail();
        $this->assertSame('pending', $deposit->status);
        $this->assertSame($publicUser->id, $deposit->user_id);
        $this->assertSame(0.0, (float) app(UserWalletService::class)->getWallet($publicUser->id)->fresh()->available_balance);
        $this->assertSame(0.0, (float) app(UserWalletService::class)->getWallet($otherUser->id)->fresh()->available_balance);

        $admin = Admin::factory()->create();
        $admin->assignRole('Super Admin');
        Auth::guard('web')->logout();
        Auth::guard('admin')->login($admin);
        $adminCsrf = 'admin-approve-self-service-csrf-token';

        $this->withSession(['_token' => $adminCsrf])
            ->post(route('admin.deposits.approve', $deposit), ['_token' => $adminCsrf])
            ->assertRedirect();

        $this->assertDatabaseHas('deposit_requests', [
            'id' => $deposit->id,
            'status' => 'approved',
            'user_id' => $publicUser->id,
        ]);
        $this->assertSame(125.50, (float) app(UserWalletService::class)->getWallet($publicUser->id)->fresh()->available_balance);
        $this->assertSame(0.0, (float) app(UserWalletService::class)->getWallet($otherUser->id)->fresh()->available_balance);
    }

    public function test_agency_deposit_approval_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('agency.deposits.index'));
        $this->assertFalse(Route::has('agency.deposits.approve'));
        $this->assertTrue(Route::has('admin.deposits.approve'));
        $this->assertTrue(Route::has('admin.deposits.reject'));
    }
}
