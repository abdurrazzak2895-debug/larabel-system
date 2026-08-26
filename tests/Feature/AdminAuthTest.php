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

    public function test_agency_user_can_open_agency_dashboard(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        Auth::guard('web')->login($agencyUser);

        $this->get(route('agency.dashboard'))->assertOk();
    }

    public function test_agency_user_can_open_all_agency_panel_pages(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->first();
        $agencyUser->assignRole('Agency Manager');
        Auth::guard('web')->login($agencyUser);

        $pages = [
            route('agency.bookings.index'),
            route('agency.wallets.index'),
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

    public function test_agency_booking_pages_render_and_show_confirmed_svp_center(): void
    {
        $agencyUser = \App\Models\User::whereNotNull('agency_id')->firstOrFail();
        Auth::guard('web')->login($agencyUser);

        $booking = Booking::where('agency_id', $agencyUser->agency_id)
            ->where('booking_status', 'booked')
            ->firstOrFail();
        $booking->update([
            'test_center_id' => null,
            'test_center_name' => null,
            'exam_session_name' => '2026-08-25',
        ]);
        $booking->attempts()->create([
            'status' => 'success',
            'request_payload' => [
                'test_center_id' => '17',
                'test_center_name' => 'Bangladesh Korea TTC Dhaka',
            ],
        ]);

        $this->assertSame(
            '17',
            data_get($booking->fresh()->attempts()->latest('id')->first()?->request_payload, 'test_center_id')
        );

        $this->get(route('agency.bookings.index'))->assertOk();
        $this->get(route('agency.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Bangladesh Korea TTC Dhaka')
            ->assertDontSee('SVP ID: 17');
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
