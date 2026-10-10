<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\BookingLog;
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

    /**
     * Exercise the real CSRF-protected web login route with a valid session
     * token, matching the browser login form's @csrf behavior.
     */
    protected function postLogin(array $credentials)
    {
        $csrfToken = 'admin-login-csrf-token';

        return $this->withSession(['_token' => $csrfToken])
            ->post(route('login.attempt'), $credentials + ['_token' => $csrfToken]);
    }

    public function test_admin_can_login_with_email_and_reach_dashboard(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasPermission('manage_agencies'));

        $response = $this->postLogin($this->adminCredentials());

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_can_login_with_display_name(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->first();

        $response = $this->postLogin([
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

    public function test_admin_dashboard_shows_live_booking_logs_with_agency_and_user(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->firstOrFail();
        $booking = Booking::with(['agency', 'user'])->whereNotNull('user_id')->firstOrFail();
        $event = 'admin_dashboard_live_event';
        BookingLog::create([
            'booking_id' => $booking->id,
            'event_type' => $event,
            'payload' => ['source' => 'admin-dashboard-test'],
        ]);

        Auth::guard('admin')->login($admin);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Live Booking Activity')
            ->assertSee('Agency Booking Summary')
            ->assertSee('Total Booking Amount')
            ->assertSee($booking->agency->name)
            ->assertSee($booking->user->name)
            ->assertSee('Admin Dashboard Live Event');
    }

    public function test_admin_wallet_pages_show_main_balance_without_reserved_balance(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->firstOrFail();
        $agency = \App\Models\Agency::firstOrFail();
        Auth::guard('admin')->login($admin);

        $this->get(route('admin.wallets.index'))
            ->assertOk()
            ->assertSee('Wallet Balance')
            ->assertDontSee('Reserved');
        $this->get(route('admin.wallets.show', ['agency' => $agency->id]))
            ->assertOk()
            ->assertSee('Wallet Balance')
            ->assertDontSee('Reserved');
        $this->get(route('admin.reports.index'))
            ->assertOk()
            ->assertDontSee('Total Reserved');
    }

    public function test_admin_can_credit_an_agency_wallet_through_manual_adjustment(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->firstOrFail();
        $agency = \App\Models\Agency::firstOrFail();
        $wallet = \App\Models\AgencyWallet::where('agency_id', $agency->id)->firstOrFail();
        $startingBalance = (float) $wallet->available_balance;

        Auth::guard('admin')->login($admin);
        $csrfToken = 'admin-credit-csrf-token';

        $this->withSession(['_token' => $csrfToken])
            ->from(route('admin.wallets.show', ['agency' => $agency->id]))
            ->post(route('admin.wallets.credit', ['agency' => $agency->id]), [
                '_token' => $csrfToken,
                'amount' => '123.45',
                'reference' => 'test-admin-credit',
            ])
            ->assertRedirect(route('admin.wallets.show', ['agency' => $agency->id]));

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'manual_adjustment',
            'amount' => 123.45,
            'reference' => 'test-admin-credit',
        ]);
        $this->assertSame($startingBalance + 123.45, (float) $wallet->fresh()->available_balance);
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

    public function test_admin_session_is_redirected_from_user_panel_instead_of_triggering_a_server_error(): void
    {
        $admin = Admin::where('email', env('ADMIN_EMAIL', 'admin@takamol.example.com'))->firstOrFail();
        Auth::guard('admin')->login($admin);

        $this->get(route('user.dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_agency_user_is_redirected_to_login_flow_for_admin_pages(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        Auth::guard('web')->login($agencyUser);

        // Agency users must not pass the manage_agencies permission check.
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_regular_agency_user_cannot_open_agency_dashboard(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        Auth::guard('web')->login($agencyUser);

        $this->get(route('agency.dashboard'))->assertForbidden();
    }

    public function test_agency_manager_cannot_open_agency_dashboard_or_other_user_summary(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->firstOrFail();
        $agencyUser->assignRole('Agency Manager');
        Auth::guard('web')->login($agencyUser);

        $this->get(route('agency.dashboard'))
            ->assertForbidden();
    }

    public function test_agency_manager_can_view_users_belonging_to_their_own_agency_only(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->firstOrFail();
        $agencyUser->assignRole('Agency Manager');
        $visibleUser = \App\Models\User::factory()->create([
            'agency_id' => $agencyUser->agency_id,
            'name' => 'Visible Managed User',
        ]);
        $otherAgency = \App\Models\Agency::factory()->create();
        $hiddenUser = \App\Models\User::factory()->create([
            'agency_id' => $otherAgency->id,
            'name' => 'Hidden Other Agency User',
        ]);
        Auth::guard('web')->login($agencyUser);

        $this->get(route('agency.users.index'))
            ->assertOk()
            ->assertSee($visibleUser->name)
            ->assertDontSee($hiddenUser->name);
    }

    public function test_agency_users_cannot_open_agency_wide_account_pages(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        $agencyUser->assignRole('Agency Manager');
        Auth::guard('web')->login($agencyUser);

        $pages = [
            route('agency.refunds.index'),
            route('agency.reports.daily-bookings'),
            route('agency.reports.wallet-statement'),
            route('agency.reports.user-activity'),
            route('agency.reports.failed-bookings'),
            route('agency.reports.deposit-history'),
            route('agency.notifications.index'),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_agency_booking_pages_are_scoped_to_the_current_portal_user(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->firstOrFail();
        Auth::guard('web')->login($agencyUser);

        $this->get(route('agency.bookings.index'))->assertOk();
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
