<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
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

    public function test_only_admin_can_open_user_deposit_creation(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $admin = Admin::factory()->create();
        $admin->assignRole('Super Admin');

        Auth::guard('admin')->login($admin);
        $this->get(route('admin.deposits.create'))
            ->assertOk()
            ->assertSee('Create User Deposit')
            ->assertSee('bKash')
            ->assertSee('Nagad');

        Auth::guard('admin')->logout();
        Auth::guard('web')->login($user);
        $this->get('/user/deposits/create')->assertNotFound();
        $this->get('/agency/deposits')->assertNotFound();
    }

    public function test_agency_deposit_approval_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('agency.deposits.index'));
        $this->assertFalse(Route::has('agency.deposits.approve'));
        $this->assertTrue(Route::has('admin.deposits.approve'));
        $this->assertTrue(Route::has('admin.deposits.reject'));
    }
}
