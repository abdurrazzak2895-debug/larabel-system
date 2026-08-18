<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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

    /**
     * Exercise the real CSRF-protected web login route with a valid session
     * token, matching the browser login form's @csrf behavior.
     */
    protected function postLogin(array $credentials)
    {
        $csrfToken = 'user-panel-login-csrf-token';

        return $this->withSession(['_token' => $csrfToken])
            ->post(route('login.attempt'), $credentials + ['_token' => $csrfToken]);
    }

    public function test_agency_user_can_login_and_reach_dashboard(): void
    {
        $user = User::where('username', 'alnoor')->firstOrFail();

        $response = $this->postLogin([
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

    public function test_user_can_see_live_svp_reservations_and_download_a_ticket(): void
    {
        Http::fake([
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_reservations*' => Http::response([
                'data' => [
                    [
                        'id' => 5370112,
                        'status' => 'confirmed',
                        'exam_date' => '2026-08-25',
                        'can_be_canceled' => true,
                        'can_be_rescheduled' => true,
                        'category' => ['english_name' => 'Kitchen Workers'],
                    ],
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/tickets/5370112/show_pdf*' => Http::response(
                "%PDF-1.7\\nSVP ticket\\n",
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="svp-ticket-5370112.pdf"',
                ],
            ),
        ]);

        $this->loginAgencyUser();
        $this->withSession(['svp_token' => 'test-svp-token']);

        $page = $this->get(route('user.bookings.index'));

        $page->assertOk()
            ->assertSee('SVP My Bookings')
            ->assertSee('5370112')
            ->assertSee('Cancel: Available')
            ->assertSee('Reschedule: Available')
            ->assertSee('Download PDF');

        $ticket = $this->get(route('user.bookings.svp-ticket', ['reservation' => 5370112]));

        $ticket->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="svp-ticket-5370112.pdf"')
            ->assertSee('%PDF-1.7');

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/tickets/5370112/show_pdf?locale=en')
                && $request->hasHeader('Authorization', 'Bearer test-svp-token');
        });
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

        $response = $this->postLogin([
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
