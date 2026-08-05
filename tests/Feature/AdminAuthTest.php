<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\DemoSeeder::class);
    }

    protected function adminCredentials(): array
    {
        return [
            'login'    => env('ADMIN_EMAIL', 'admin@takamol.example.com'),
            'password' => env('ADMIN_PASSWORD', 'ChangeMe123!'),
        ];
    }

    public function test_admin_can_login_with_email_and_reach_dashboard(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasPermission('manage_agencies'));

        $response = $this->post(route('login.attempt'), $this->adminCredentials());

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_can_login_with_display_name(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->first();

        $response = $this->post(route('login.attempt'), [
            'login'    => $admin->name,
            'password' => env('ADMIN_PASSWORD', 'ChangeMe123!'),
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_can_access_all_admin_panel_pages(): void
    {
        Auth::guard('admin')->loginUsingId(
            Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->first()->id
        );

        $pages = [
            route('admin.dashboard'),
            route('admin.agencies.index'),
            route('admin.users.index'),
            route('admin.wallets.index'),
            route('admin.deposits.index'),
            route('admin.refunds.index'),
            route('admin.notifications.index'),
            route('admin.pricing.index'),
            route('admin.settings.index'),
            route('admin.audit-logs.index'),
            route('admin.reports.index'),
            route('admin.reports.agency', ['type' => 'daily_bookings', 'agency_id' => \App\Models\Agency::first()->id]),
            route('admin.reports.agency', ['type' => 'wallet_statement', 'agency_id' => \App\Models\Agency::first()->id]),
            route('admin.reports.agency', ['type' => 'user_activity', 'agency_id' => \App\Models\Agency::first()->id]),
            route('admin.reports.agency', ['type' => 'failed_bookings', 'agency_id' => \App\Models\Agency::first()->id]),
            route('admin.reports.agency', ['type' => 'deposit_history', 'agency_id' => \App\Models\Agency::first()->id]),
            route('admin.wallets.show', ['agency' => \App\Models\Agency::first()->id]),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_never_blocked_by_residual_agency_web_session(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->first();
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();

        // Simulate the broken state: both guards authenticated in one session.
        Auth::guard('web')->login($agencyUser);
        Auth::guard('admin')->login($admin);

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.agencies.index'))->assertOk();
    }

    public function test_agency_user_is_redirected_to_login_flow_for_admin_pages(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        Auth::guard('web')->login($agencyUser);

        // Agency users must not pass the manage_agencies permission check.
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_agency_user_can_open_agency_dashboard(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        Auth::guard('web')->login($agencyUser);

        $this->get(route('agency.dashboard'))->assertOk();
    }

    public function test_agency_user_can_open_all_agency_panel_pages(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        Auth::guard('web')->login($agencyUser);

        $pages = [
            route('agency.bookings'),
            route('agency.wallets.index'),
            route('agency.deposits.index'),
            route('agency.refunds.index'),
            route('agency.reports.daily-bookings'),
            route('agency.reports.wallet-statement'),
            route('agency.reports.user-activity'),
            route('agency.reports.failed-bookings'),
            route('agency.reports.deposit-history'),
            route('agency.users.index'),
            route('agency.notifications.index'),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_super_admin_role_owns_manage_agencies_permission(): void
    {
        $role = Role::where('slug', 'super-admin')->firstOrFail();

        $this->assertTrue(
            $role->permissions()->where('slug', 'manage-agencies')->exists()
        );
    }
}
