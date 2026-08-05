<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class UserPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\DemoSeeder::class);
    }

    protected function loginAgencyUser(): User
    {
        $user = User::whereNotNull('agency_id')->firstOrFail();
        Auth::guard('web')->login($user);

        return $user;
    }

    public function test_agency_user_can_login_and_reach_dashboard(): void
    {
        $user = User::where('username', 'alnoor')->firstOrFail();

        $response = $this->post(route('login.attempt'), [
            'login'    => 'alnoor',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('agency.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_user_can_open_all_user_panel_pages(): void
    {
        $user = $this->loginAgencyUser();

        $booking = Booking::where('user_id', $user->id)->first();

        $pages = [
            route('user.dashboard'),
            route('user.bookings.index'),
            route('user.wallets.index'),
            route('user.deposits.index'),
            route('user.deposits.create'),
            route('user.refunds.index'),
            route('user.refunds.create'),
            route('user.notifications.index'),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        if ($booking) {
            $this->get(route('user.bookings.show', $booking))->assertOk();
        }
    }

    public function test_guest_is_redirected_to_login_from_user_panel(): void
    {
        $this->get(route('user.dashboard'))->assertRedirect(route('login'));
    }

    public function test_standalone_user_is_redirected_to_user_dashboard(): void
    {
        $user = User::create([
            'agency_id' => null,
            'name'      => 'Standalone User',
            'username'  => 'standalone',
            'email'     => 'standalone@example.com',
            'password'  => 'password',
            'status'    => true,
        ]);

        $response = $this->post(route('login.attempt'), [
            'login'    => 'standalone',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('user.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');

        $this->get(route('user.dashboard'))->assertOk();
    }

    public function test_booking_create_redirects_to_svp_login_without_token(): void
    {
        $this->loginAgencyUser();

        $this->get(route('user.bookings.create'))->assertRedirect(route('svp.login.form'));
    }
}
