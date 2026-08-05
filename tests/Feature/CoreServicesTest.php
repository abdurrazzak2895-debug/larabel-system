<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
