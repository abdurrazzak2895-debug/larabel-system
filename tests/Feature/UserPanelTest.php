<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Candidate;
use App\Models\Setting;
use App\Models\SvpAvailabilityAccount;
use App\Models\User;
use App\Services\WalletService;
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
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/temporary_seats*' => Http::response([
                'temporary_seat' => ['id' => 'hold-reschedule-1', 'expires_at' => '2026-09-01T10:00:00Z'],
            ], 201),
            'svp-international-api.pacc.sa/api/v1/users/SVP-RESCHEDULE-USER/balance*' => Http::response([
                'reservation_credits' => 1,
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/reservation_credits/use*' => Http::response([
                'success' => true,
                'reservation_id' => 5370112,
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_reservations/5370112/reschedule*' => Http::response([
                'success' => true,
                'exam_reservation' => ['id' => 5370112, 'exam_date' => '2026-09-01', 'test_center_id' => 17],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_sessions/reschedule-session-1*' => Http::response([
                'exam_session' => [
                    'id' => 'reschedule-session-1',
                    'exam_date' => '2026-09-01',
                    'test_center_id' => 17,
                    'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                    'test_center_city' => 'Dhaka',
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_sessions/available_dates*' => Http::response([
                'available_dates' => [
                    ['exam_date' => '2026-09-01', 'test_center_id' => 17],
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_sessions*' => Http::response([
                'exam_sessions' => [
                    ['id' => 'reschedule-session-1', 'exam_date' => '2026-09-01', 'test_center_id' => 17, 'name' => 'First Shift'],
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_reservations/5370112*' => Http::response([
                'exam_reservation' => [
                    'id' => 5370112,
                    'full_name' => 'Rifat Ahmed',
                    'exam_result' => 'passed',
                    'can_be_canceled' => true,
                    'can_be_rescheduled' => true,
                    'category_id' => 12,
                    'city' => 'Dhaka',
                    'test_center_id' => 17,
                    'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                    'exam_date' => '2026-08-25',
                    'methodology' => 'in_person',
                    'occupation' => [
                        'id' => 2062,
                        'english_name' => 'Kitchen Worker',
                        'name' => 'Kitchen Worker',
                    ],
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_reservations/5370113*' => Http::response([
                'exam_reservation' => [
                    'id' => 5370113,
                    'exam_result' => 'failed',
                    'can_be_canceled' => false,
                    'can_be_rescheduled' => false,
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_reservations*' => Http::response([
                'data' => [
                    [
                        'id' => 5370112,
                        'status' => 'completed',
                        'exam_result' => 'passed',
                        'exam_date' => '2026-08-25',
                        'can_be_canceled' => true,
                        'can_be_rescheduled' => true,
                        'category' => ['english_name' => 'Kitchen Workers'],
                    ],
                    [
                        'id' => 5370113,
                        'status' => 'completed',
                        'exam_result' => 'failed',
                        'exam_date' => '2026-08-26',
                        'can_be_canceled' => false,
                        'can_be_rescheduled' => false,
                        'category' => ['english_name' => 'Electrician'],
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
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/tickets/5370113/show_pdf*' => Http::response(
                "%PDF-1.7\\nSVP ticket 5370113\\n",
                200,
                ['Content-Type' => 'application/pdf'],
            ),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_reservations/5370112' => Http::response([
                'exam_reservation' => [
                    'id' => 5370112,
                    'can_be_canceled' => true,
                    'can_be_rescheduled' => true,
                ],
            ], 200),
        ]);

        $user = $this->loginAgencyUser();
        Setting::create(['key' => 'booking_price', 'value' => '100.00', 'agency_id' => null]);
        $walletBefore = app(WalletService::class)->getWallet($user->agency_id);
        $availableBefore = (float) $walletBefore->available_balance;
        $reservedBefore = (float) $walletBefore->reserved_balance;
        app(WalletService::class)->deposit($user->agency_id, 250.00, 'RESCHEDULE-TEST-DEPOSIT');

        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'full_name' => 'Rifat Ahmed',
            'email' => $user->email,
            'svp_user_id' => 'SVP-RESCHEDULE-USER',
        ]);
        $this->withSession(['svp_token' => 'test-svp-token']);

        $page = $this->get(route('user.bookings.index'));

        $page->assertOk()
            ->assertSee('SVP My Bookings')
            ->assertSee('5370112')
            ->assertSee('Result: Passed')
            ->assertSee('Result: Failed')
            ->assertSee('Cancel Reservation')
            ->assertSee('Reschedule')
            ->assertSee('Download Certificate')
            ->assertSee('Download Ticket');

        $ticket = $this->get(route('user.bookings.svp-ticket', ['reservation' => 5370112]));

        $ticket->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="Rifat_Ahmed_Kitchen_Worker_Certificate.pdf"')
            ->assertSee('%PDF-1.7');

        $failedTicket = $this->get(route('user.bookings.svp-ticket', ['reservation' => 5370113]));

        $failedTicket->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="SVP_Reservation_5370113_Ticket.pdf"');

        $csrfToken = 'svp-cancel-csrf-token';
        $cancel = $this->withSession(['_token' => $csrfToken])
            ->post(route('user.bookings.svp-cancel', ['reservation' => 5370112]), ['_token' => $csrfToken]);
        $cancel->assertRedirect(route('user.bookings.index'))
            ->assertSessionHas('success', 'The SVP reservation was canceled successfully.');

        $reschedule = $this->get(route('user.bookings.svp-reschedule', ['reservation' => 5370112]));
        $reschedule->assertOk()
            ->assertSee('Reschedule SVP Reservation')
            ->assertSee('Choose a new city, test center, date, and session')
            ->assertSee('Occupation and category stay fixed')
            ->assertSee('Bengali', false)
            ->assertSee('value="LOABB"', false)
            ->assertDontSee('Locked center')
            ->assertDontSee('svp-international.pacc.sa/home')
            ->assertSee('function canCreateHold()')
            ->assertSee('return Boolean(occupation.value && category.value && city.value && center.value && centerName.value && session.value && date.value);', false)
            ->assertDontSee("holdButton.disabled = !(candidate.value && session.value && date.value)");

        $lookup = $this->get(route('user.bookings.lookup.sessions', [
            'city' => 'Dhaka',
            'category_id' => '12',
            'test_center_id' => '17',
        ]));
        $lookup->assertOk()->assertJsonPath('data.sessions.0.id', 'reschedule-session-1');

        $hold = $this->withSession(['_token' => $csrfToken, 'svp_token' => 'test-svp-token'])
            ->postJson(route('user.bookings.temporary-hold'), [
                'occupation_id' => '2062',
                'category_id' => '12',
                'city' => 'Dhaka',
                'test_center_id' => '17',
                'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                'exam_session_id' => 'reschedule-session-1',
                'exam_date' => '2026-09-01',
            ], ['X-CSRF-TOKEN' => $csrfToken]);
        $hold->assertStatus(201)->assertJsonPath('data.id', 'hold-reschedule-1');

        $rescheduleSubmit = $this->withSession(['_token' => $csrfToken, 'svp_token' => 'test-svp-token'])
            ->post(route('user.bookings.svp-reschedule.submit', ['reservation' => 5370112]), [
                '_token' => $csrfToken,
                'candidate_id' => $candidate->id,
                'occupation_id' => '2062',
                'category_id' => '12',
                'city' => 'Dhaka',
                'test_center_id' => '17',
                'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                'exam_session_id' => 'reschedule-session-1',
                'exam_session_name' => 'First Shift',
                'exam_date' => '2026-09-01',
                'temporary_hold_id' => 'hold-reschedule-1',
                'language_code' => 'LOABB',
                'methodology' => 'in_person',
            ]);
        $rescheduleSubmit->assertRedirect();
        $this->assertDatabaseHas('bookings', [
            'reservation_id' => '5370112',
            'occupation_id' => '2062',
            'category_id' => '12',
            'test_center_id' => '17',
            'exam_session_id' => 'reschedule-session-1',
            'booking_status' => 'booked',
        ]);
        $booking = Booking::where('reservation_id', '5370112')->latest('id')->firstOrFail();
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'booking_hold',
            'amount' => 100.00,
            'reference' => 'portal-booking-fee-'.$booking->id,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'booking_debit',
            'amount' => 100.00,
            'reference' => 'portal-booking-fee-'.$booking->id,
        ]);
        $this->assertDatabaseHas('agency_wallets', [
            'agency_id' => $user->agency_id,
            'available_balance' => $availableBefore + 150.00,
            'reserved_balance' => $reservedBefore,
        ]);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/tickets/5370112/show_pdf?locale=en')
                && $request->hasHeader('Authorization', 'Bearer test-svp-token');
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_ends_with($request->url(), '/exam_reservations/5370112')
                && $request->hasHeader('Authorization', 'Bearer test-svp-token');
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/exam_reservations/5370112/reschedule')
                && $request['exam_session_id'] === 'reschedule-session-1'
                && $request['exam_date'] === '2026-09-01'
                && $request->hasHeader('Authorization', 'Bearer test-svp-token');
        });
    }

    public function test_booking_wizard_renders_real_occupation_search_and_category_autofill(): void
    {
        Http::fake([
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/occupations*' => Http::response([
                'data' => [
                    [
                        'id' => 2062,
                        'english_name' => 'Kitchen Worker',
                        'arabic_name' => 'عامل مطبخ',
                    ],
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/categories*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Offices and Facilities Cleaning Workers'],
                ],
            ], 200),
        ]);

        $this->loginAgencyUser();
        $this->withSession(['svp_token' => 'test-svp-token']);

        $page = $this->get(route('user.bookings.create'));

        $page->assertOk()
            ->assertSee('id="occupation-search"', false)
            ->assertSee('Kitchen Worker')
            ->assertSee('id="category_id"', false)
            ->assertSee('if (categories.length === 1)', false)
            ->assertSee('Bengali', false)
            ->assertSee('value="LOABB"', false)
            ->assertSee('id="available_session_date"', false)
            ->assertSee('Every date returned by SVP for the selected center is shown automatically.', false)
            ->assertSee('loadSessionsForDate', false)
            ->assertDontSee('>load<', false)
            ->assertDontSee('>loading<', false);
    }

    public function test_availability_dashboard_returns_ajax_filters_and_available_center_rows(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/individual_labor_space/occupations')) {
                return Http::response(['data' => [['id' => '159', 'name' => 'Load and Unload Worker']]], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/test_centers/cities')) {
                return Http::response(['data' => [['id' => 'Dhaka', 'name' => 'Dhaka']]], 200);
            }

            if (str_ends_with($path, '/visitor_space/test_centers')) {
                return Http::response(['data' => [
                    ['id' => '45', 'name' => 'Bangladesh German TTC', 'city' => 'Dhaka'],
                ]], 200);
            }

            if (str_ends_with($path, '/exam_sessions/session-german-2026-08-30')) {
                return Http::response(['data' => ['exam_session' => [
                    'id' => 'session-german-2026-08-30',
                    'exam_date' => '2026-08-30',
                    'test_center_id' => 45,
                    'test_center_name' => 'Bangladesh German TTC',
                    'test_center_city' => 'Dhaka',
                ]]], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/exam_sessions')) {
                if ((string) ($query['test_center_id'] ?? '') !== '45') {
                    return Http::response(['data' => ['exam_sessions' => []]], 200);
                }

                return Http::response(['data' => ['exam_sessions' => [[
                    'id' => 'session-german-2026-08-30',
                    'exam_date' => '2026-08-30',
                    'name' => 'First Shift',
                    'available_seats' => 2,
                ]]]], 200);
            }

            return Http::response([], 404);
        });

        SvpAvailabilityAccount::create([
            'name' => 'Backend availability account',
            'email' => 'backend-availability@example.com',
            'access_token' => 'backend-svp-token',
            'active' => true,
        ]);

        $this->loginAgencyUser();
        $response = $this->withSession(['svp_token' => 'portal-user-token'])
            ->getJson(route('svp.availability', [
                'category_id' => '159',
                'city' => 'Dhaka',
                'date' => '2026-08-30',
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rows.0.center_id', '45')
            ->assertJsonPath('data.rows.0.center_name', 'Bangladesh German TTC')
            ->assertJsonPath('data.rows.0.session_count', 1)
            ->assertJsonPath('data.rows.0.verified', true)
            ->assertJsonPath('data.verified_only', true);

        Http::assertSent(fn ($request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/individual_labor_space/test_centers/cities')
            && $request->header('Authorization')[0] === 'Bearer backend-svp-token');
    }

    public function test_session_per_center_bot_returns_verified_rows_from_backend_accounts_only(): void
    {
        SvpAvailabilityAccount::create([
            'name' => 'Per-center bot account',
            'email' => 'per-center-bot@example.com',
            'access_token' => 'backend-bot-token',
            'active' => true,
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/visitor_space/test_centers')) {
                return Http::response(['data' => [
                    ['id' => '45', 'name' => 'Bot Exact Centre', 'city' => 'Bot City'],
                ]], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/exam_sessions')) {
                return Http::response(['data' => ['exam_sessions' => [[
                    'id' => 'bot-session-2030-09-02',
                    'exam_date' => '2030-09-02',
                    'name' => 'First Shift',
                    'available_seats' => 3,
                ]]]], 200);
            }

            if (str_ends_with($path, '/exam_sessions/bot-session-2030-09-02')) {
                return Http::response(['data' => ['exam_session' => [
                    'id' => 'bot-session-2030-09-02',
                    'exam_date' => '2030-09-02',
                    'test_center_id' => 45,
                    'test_center_name' => 'Bot Exact Centre',
                    'test_center_city' => 'Bot City',
                ]]], 200);
            }

            return Http::response([], 404);
        });

        $this->loginAgencyUser();
        $response = $this->withSession(['svp_token' => 'portal-user-token'])
            ->getJson(route('svp.session-per-center-bot', [
                'category_id' => '991',
                'city' => 'Bot City',
                'date' => '2030-09-02',
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.category_id', '991')
            ->assertJsonPath('data.city', 'Bot City')
            ->assertJsonPath('data.verified_only', true)
            ->assertJsonPath('data.centers.0.center_id', '45')
            ->assertJsonPath('data.centers.0.center_name', 'Bot Exact Centre')
            ->assertJsonPath('data.centers.0.date', '2030-09-02')
            ->assertJsonPath('data.centers.0.sessions.0.id', 'bot-session-2030-09-02')
            ->assertJsonPath('data.centers.0.sessions.0.verified', true);

        Http::assertSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer backend-bot-token');
        Http::assertNotSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer portal-user-token');
    }

    public function test_session_per_center_bot_requires_category_and_city(): void
    {
        $this->loginAgencyUser();

        $this->getJson(route('svp.session-per-center-bot'))
            ->assertStatus(422)
            ->assertJsonPath('errors.category_id.0', 'The category id field is required.')
            ->assertJsonPath('errors.city.0', 'The city field is required.');
    }

    public function test_cached_city_endpoint_uses_backend_token_and_avoids_duplicate_upstream_calls(): void
    {
        SvpAvailabilityAccount::create([
            'name' => 'City cache account',
            'email' => 'city-cache@example.com',
            'access_token' => 'backend-city-token',
            'active' => true,
        ]);

        Http::fake([
            '*individual_labor_space/test_centers/cities*' => Http::response([
                'data' => [['id' => 'Dhaka', 'name' => 'Dhaka'], ['id' => 'Rajshahi', 'name' => 'Rajshahi']],
            ], 200),
        ]);

        $this->loginAgencyUser();
        $first = $this->withSession(['svp_token' => 'portal-user-token'])
            ->getJson(route('svp.availability.cities', ['category_id' => '159']));
        $second = $this->withSession(['svp_token' => 'another-portal-token'])
            ->getJson(route('svp.availability.cities', ['category_id' => '159']));

        $first->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.0.name', 'Dhaka');
        $second->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.1.name', 'Rajshahi');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->header('Authorization')[0] === 'Bearer backend-city-token');
    }

    public function test_cached_city_endpoint_caps_backend_account_failover_attempts(): void
    {
        config(['svp.availability_account_attempts' => 2]);

        foreach (['city-timeout-primary@example.com', 'city-timeout-secondary@example.com', 'city-timeout-third@example.com'] as $index => $email) {
            SvpAvailabilityAccount::create([
                'name' => 'City timeout account '.$index,
                'email' => $email,
                'access_token' => 'city-timeout-token-'.$index,
                'active' => true,
            ]);
        }

        Http::fake(function ($request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/individual_labor_space/test_centers/cities')) {
                return Http::response(['message' => 'upstream unavailable'], 503);
            }

            return Http::response([], 404);
        });

        $this->loginAgencyUser();
        $response = $this->withSession(['svp_token' => 'portal-user-token'])
            ->getJson(route('svp.availability.cities', ['category_id' => '159']));

        $response->assertStatus(504)
            ->assertJsonPath('success', false);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer portal-user-token');
    }

    public function test_verified_availability_rotates_backend_accounts_and_filters_unverified_sessions(): void
    {
        $primary = SvpAvailabilityAccount::create([
            'name' => 'Primary verification account',
            'email' => 'primary-verification@example.com',
            'access_token' => 'backend-primary-token',
            'active' => true,
            'last_used_at' => now()->subMinute(),
        ]);
        SvpAvailabilityAccount::create([
            'name' => 'Secondary verification account',
            'email' => 'secondary-verification@example.com',
            'access_token' => 'backend-secondary-token',
            'active' => true,
            'last_used_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $authorization = $request->header('Authorization')[0] ?? '';

            if ($authorization === 'Bearer backend-primary-token') {
                return Http::response(['message' => 'expired'], 401);
            }

            if (str_ends_with($path, '/individual_labor_space/test_centers/cities')) {
                return Http::response(['data' => [['id' => 'Dhaka', 'name' => 'Dhaka']]], 200);
            }

            if (str_ends_with($path, '/visitor_space/test_centers')) {
                return Http::response(['data' => [['id' => '45', 'name' => 'Bangladesh German TTC', 'city' => 'Dhaka']]], 200);
            }

            if (str_ends_with($path, '/exam_sessions/verified-session-45')) {
                return Http::response(['data' => ['exam_session' => [
                    'id' => 'verified-session-45',
                    'exam_date' => '2026-08-30',
                    'test_center_id' => 45,
                    'test_center_name' => 'Bangladesh German TTC',
                    'test_center_city' => 'Dhaka',
                ]]], 200);
            }

            if (str_ends_with($path, '/individual_labor_space/exam_sessions')) {
                if ((string) ($query['test_center_id'] ?? '') !== '45') {
                    return Http::response(['data' => ['exam_sessions' => []]], 200);
                }

                return Http::response(['data' => ['exam_sessions' => [[
                    'id' => 'verified-session-45',
                    'exam_date' => '2026-08-30',
                    'name' => 'First Shift',
                    'available_seats' => 2,
                ]]]], 200);
            }

            return Http::response([], 404);
        });

        $this->loginAgencyUser();
        $response = $this->withSession(['svp_token' => 'portal-user-token'])
            ->getJson(route('svp.availability', [
                'category_id' => '159',
                'city' => 'Dhaka',
                'date' => '2026-08-30',
            ]));

        $response->assertOk()
            ->assertJsonPath('data.verified_only', true)
            ->assertJsonPath('data.rows.0.center_id', '45')
            ->assertJsonPath('data.rows.0.sessions.0.verified', true);

        Http::assertSent(fn ($request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/exam_sessions/verified-session-45')
            && ($request->header('Authorization')[0] ?? '') === 'Bearer backend-secondary-token');
        Http::assertNotSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer portal-user-token');
    }

    public function test_availability_page_renders_category_options_from_backend_account(): void
    {
        SvpAvailabilityAccount::create([
            'name' => 'Category catalog account',
            'email' => 'category-catalog@example.com',
            'access_token' => 'backend-category-token',
            'active' => true,
        ]);

        Http::fake([
            '*individual_labor_space/occupations*' => Http::response([
                'data' => [
                    'items' => [[
                        'id' => 2061,
                        'attributes' => [
                            'categories' => [
                                'data' => [[
                                    'id' => 159,
                                    'attributes' => ['english_name' => 'Load and unload workers'],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ], 200),
        ]);

        $this->loginAgencyUser();
        $response = $this->withSession(['svp_token' => 'portal-user-token'])
            ->get(route('svp.availability'));

        $response->assertOk()
            ->assertSee('Load and unload workers')
            ->assertSee('value="159"', false);
        Http::assertSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer backend-category-token');
        Http::assertNotSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer portal-user-token');
    }

    public function test_availability_page_degrades_gracefully_when_backend_category_accounts_fail(): void
    {
        SvpAvailabilityAccount::create([
            'name' => 'Expired category account',
            'email' => 'expired-category@example.com',
            'access_token' => 'expired-category-token',
            'active' => true,
        ]);

        Http::fake([
            '*individual_labor_space/occupations*' => Http::response(['message' => 'Signature has expired'], 401),
        ]);

        $this->loginAgencyUser();
        $response = $this->get(route('svp.availability'));

        $response->assertOk()
            ->assertSee('Select category')
            ->assertDontSee('Server Error');
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

    public function test_agency_booking_create_clears_expired_svp_jwt_and_redirects_to_login(): void
    {
        $this->loginAgencyUser();

        $encode = static function (array $payload): string {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

            return $encoded;
        };
        $expiredToken = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'
            .$encode(['exp' => time() - 60, 'user_id' => 1195959]).'.signature';

        $response = $this->withSession(['svp_token' => $expiredToken])
            ->get(route('agency.bookings.create'));

        $response->assertRedirect(route('svp.login.form'));
        $this->assertNull(session('svp_token'));
    }
}
