<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\Candidate;
use App\Models\Setting;
use App\Models\PortalAvailabilityCredential;
use App\Models\TestCenter;
use App\Models\User;
use App\Contracts\PortalAvailabilityProviderInterface;
use App\Services\UserWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

        $response->assertRedirect(route('user.dashboard'));
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


    public function test_booking_pages_show_only_the_current_accounts_active_candidate(): void
    {
        $user = $this->loginAgencyUser();
        $sibling = User::factory()->create(['agency_id' => $user->agency_id]);

        Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'svp_user_id' => 'SVP-CURRENT-USER',
            'full_name' => 'Current Account Candidate',
            'email' => $user->email,
            'is_active' => true,
        ]);
        Candidate::create([
            'user_id' => $sibling->id,
            'agency_id' => $sibling->agency_id,
            'svp_user_id' => 'SVP-SIBLING-USER',
            'full_name' => 'Sibling Account Candidate',
            'email' => $sibling->email,
            'is_active' => true,
        ]);

        $this->get(route('user.bookings.create'))
            ->assertOk()
            ->assertSee('Current Account Candidate')
            ->assertDontSee('Sibling Account Candidate');

        $this->get(route('agency.bookings.create'))
            ->assertOk()
            ->assertSee('Current Account Candidate')
            ->assertDontSee('Sibling Account Candidate');
    }

    public function test_wallet_pages_show_only_the_main_balance_not_reserved_balance(): void
    {
        $this->loginAgencyUser();

        foreach ([
            route('user.dashboard'),
            route('user.wallets.index'),
            route('user.bookings.create'),
        ] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertSee($url === route('user.wallets.index') ? 'Personal Wallet Balance' : 'Wallet Balance')
                ->assertDontSee('Reserved Balance')
                ->assertDontSee('Reserved:');
        }

        $this->get(route('user.dashboard'))->assertDontSee('>Reserved<');
        $this->get(route('agency.dashboard'))->assertDontSee('>Reserved<');
    }

    public function test_failed_card_payment_refunds_portal_fee_to_available_balance(): void
    {
        $user = $this->loginAgencyUser();
        Setting::create(['key' => 'booking_price', 'value' => '100.00', 'agency_id' => null]);

        $walletBefore = app(UserWalletService::class)->getWallet($user->id)->fresh();
        $availableBefore = (float) $walletBefore->available_balance;
        $reservedBefore = (float) $walletBefore->reserved_balance;
        app(UserWalletService::class)->deposit($user->id, 200.00, 'PAYMENT-FAILURE-DEPOSIT');

        $booking = Booking::create([
            'agency_id' => $user->agency_id,
            'user_id' => $user->id,
            'booking_status' => 'pending',
            'booking_reference' => 'PAYMENT-FAILURE-BOOKING',
            'reservation_id' => '5370114',
        ]);
        BookingAttempt::create([
            'booking_id' => $booking->id,
            'status' => 'payment_required',
            'request_payload' => ['booking_id' => $booking->id],
        ]);
        app(UserWalletService::class)->hold($user->id, 100.00, 'portal-booking-fee-'.$booking->id, [
            'booking_id' => $booking->id,
            'purpose' => 'portal_booking_fee',
        ]);

        $walletAfterHold = app(UserWalletService::class)->getWallet($user->id)->fresh();
        $this->assertSame($availableBefore + 100.00, (float) $walletAfterHold->available_balance);
        $this->assertSame($reservedBefore + 100.00, (float) $walletAfterHold->reserved_balance);

        Http::fake([
            'svp-international-api.pacc.sa/api/v1/checkouts/payment-failed*' => Http::response([
                'result' => ['code' => '800.100.100'],
            ], 200),
        ]);

        $response = $this->withSession(['svp_token' => 'test-svp-token'])
            ->get(route('user.bookings.payment-return', [
                'booking' => $booking->id,
                'resourcePath' => '/checkouts/payment-failed',
            ]));

        $response->assertRedirect(route('user.bookings.show', $booking));
        $response->assertSessionHas('error', 'SVP payment was not confirmed. The portal fee has been refunded to your personal wallet balance.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'failed',
        ]);
        $this->assertDatabaseHas('user_wallet_transactions', [
            'type' => 'refund',
            'amount' => 100.00,
            'reference' => 'portal-booking-fee-'.$booking->id,
        ]);
        $this->assertDatabaseHas('refund_requests', [
            'booking_id' => $booking->id,
            'agency_id' => $user->agency_id,
            'amount' => 100.00,
            'status' => 'processed',
        ]);
        $walletAfterRefund = app(UserWalletService::class)->getWallet($user->id)->fresh();
        $this->assertSame($availableBefore + 200.00, (float) $walletAfterRefund->available_balance);
        $this->assertSame($reservedBefore, (float) $walletAfterRefund->reserved_balance);
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
        $walletBefore = app(UserWalletService::class)->getWallet($user->id);
        $availableBefore = (float) $walletBefore->available_balance;
        $reservedBefore = (float) $walletBefore->reserved_balance;
        app(UserWalletService::class)->deposit($user->id, 250.00, 'RESCHEDULE-TEST-DEPOSIT');

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
            ->assertSee('Available Sessions — date-first PACC reschedule')
            ->assertSee('Only live Portal Availability dates')
            ->assertSee("const sessionSummary = document.getElementById('session-summary');", false)
            ->assertSee('lookup/dates', false)
            ->assertSee('lookup/test-centers', false)
            ->assertSee('lookup/sessions', false)
            ->assertSee('prefetchSessionsForCenters', false)
            ->assertSee('sessionLookupCache', false)
            ->assertSee('body?.data?.centers', false)
            ->assertSee('reschedule-center-response', false)
            ->assertSee('reschedule-session-response', false)
            ->assertSee('id="reschedule-session-response" class="mt-3"', false)
            ->assertSee('PaccAvailabilityComponent', false)
            ->assertDontSee('exactSessionControl.value = selectedSessionId', false)
            ->assertSee('Live verified sessions')
            ->assertSee('Sessions:', false)
            ->assertSee('Session ID:', false)
            ->assertSee('let selectedCardKey', false)
            ->assertSee('card.dataset.paccIndex === selectedCardKey', false)
            ->assertSee('centerSessionId', false)
            ->assertSee('data-pacc-session-id', false)
            ->assertSee('document.getElementById(options.sessionSelectId', false)
            ->assertSee('sessionPriority', false)
            ->assertSee('start_at_in_tc_time_zone', false)
            ->assertSee('available_seat_count', false)
            ->assertDontSee('Click a card to select.', false)
            ->assertSee('type="hidden" name="exam_session_id"', false)
            ->assertDontSee('<select name="exam_session_id"', false)
            ->assertDontSee('Live sessions and seat counts')
            ->assertDontSee('Bengali', false)
            ->assertDontSee('value="LOABB"', false)
            ->assertDontSee('Notes (optional)')
            ->assertDontSee('Anything the SVP booking should know')
            ->assertDontSee('type="date"', false)
            ->assertDontSee('Locked center')
            ->assertDontSee('svp-international.pacc.sa/home')
            ->assertSee('function canCreateHold()')
            ->assertSee('return Boolean(occupation.value && category.value && city.value && center.value && centerName.value && session.value && date.value && language.value);', false)
            ->assertDontSee("holdButton.disabled = !(candidate.value && session.value && date.value)");

        $rescheduleHtml = $reschedule->getContent();
        $datePosition = strpos($rescheduleHtml, '<select id="available_session_date"');
        $centerPosition = strpos($rescheduleHtml, '<div id="test-center-section"');
        $sessionPosition = strpos($rescheduleHtml, '<div id="reschedule-session-response"');
        $this->assertNotFalse($datePosition);
        $this->assertNotFalse($centerPosition);
        $this->assertNotFalse($sessionPosition);
        $this->assertTrue($datePosition < $centerPosition, 'The reschedule date control must be rendered before the test-center selector.');
        $this->assertTrue($centerPosition < $sessionPosition, 'The reschedule test-center selector must be rendered before the session selector.');

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
        $this->assertDatabaseHas('user_wallet_transactions', [
            'type' => 'booking_hold',
            'amount' => 100.00,
            'reference' => 'portal-booking-fee-'.$booking->id,
        ]);
        $this->assertDatabaseHas('user_wallet_transactions', [
            'type' => 'booking_debit',
            'amount' => 100.00,
            'reference' => 'portal-booking-fee-'.$booking->id,
        ]);
        $this->assertDatabaseHas('user_wallets', [
            'user_id' => $user->id,
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
                && (string) ($request['site_id'] ?? '') === '17'
                && ($request['site_city'] ?? null) === 'Dhaka'
                && $request->hasHeader('Authorization', 'Bearer test-svp-token');
        });
    }

    public function test_reschedule_blocks_center_mismatch_before_upstream_mutation(): void
    {
        $user = $this->loginAgencyUser();
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'full_name' => 'Center Guard Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-CENTER-GUARD',
            'is_active' => true,
        ]);
        $csrfToken = 'reschedule-center-guard-csrf';
        $sessionId = 'selected-cumilla-session';
        $holdId = 'hold-center-guard';
        $reservationId = '6001';

        Http::fake([
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_reservations/6001' => Http::response([
                'exam_reservation' => [
                    'id' => 6001,
                    'can_be_rescheduled' => true,
                    'occupation_id' => 2061,
                    'category_id' => 159,
                    'methodology' => 'in_person',
                ],
            ], 200),
            'svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_sessions/selected-cumilla-session*' => Http::response([
                'exam_session' => [
                    'id' => $sessionId,
                    'exam_date' => '2026-09-07',
                    'test_center_id' => '181',
                    'test_center_name' => 'Noakhali Technical Training Centre',
                    'test_center_city' => 'Noakhali',
                ],
            ], 200),
        ]);

        $response = $this->from(route('user.bookings.svp-reschedule', ['reservation' => $reservationId]))
            ->withSession([
                '_token' => $csrfToken,
                'svp_token' => 'test-svp-token',
                'svp_temporary_holds' => [$holdId => [
                    'id' => $holdId,
                    'occupation_id' => '2061',
                    'category_id' => '159',
                    'city' => 'Cumilla',
                    'test_center_id' => '17',
                    'exam_session_id' => $sessionId,
                    'exam_date' => '2026-09-07',
                ]],
            ])
            ->post(route('user.bookings.svp-reschedule.submit', ['reservation' => $reservationId]), [
                '_token' => $csrfToken,
                'candidate_id' => $candidate->id,
                'occupation_id' => '2061',
                'category_id' => '159',
                'city' => 'Cumilla',
                'test_center_id' => '17',
                'test_center_name' => 'Cumilla Technical Training Centre',
                'exam_session_id' => $sessionId,
                'exam_session_name' => 'First Shift',
                'exam_date' => '2026-09-07',
                'temporary_hold_id' => $holdId,
                'language_code' => 'LOABB',
                'methodology' => 'in_person',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('error', 'The selected SVP session no longer matches the selected center and date. Refresh the live sessions and try again.');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/exam_reservations/6001/reschedule'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/exam_reservations/6001'));
        $this->assertDatabaseMissing('bookings', ['reservation_id' => $reservationId]);
    }

    public function test_booking_wizard_renders_real_occupation_search_and_category_autofill(): void
    {
        PortalAvailabilityCredential::create([
            'name' => 'Booking portal session',
            'portal_account_id' => 'booking-portal-account',
            'session_cookie' => 'session=booking-authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                [
                    'name' => 'Kitchen Worker',
                    'occupation_id' => 2062,
                    'category_id' => 1,
                    'category_name' => 'Offices and Facilities Cleaning Workers',
                    'languages' => [
                        ['code' => 'LOABB', 'name' => 'Bengali'],
                        ['code' => 'LOAEN', 'name' => 'English'],
                    ],
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
            ->assertSee('Select a live SVP exam language…', false)
            ->assertSee('/user/bookings/lookup/languages', false)
            ->assertDontSee('value="LOABB"', false)
            ->assertDontSee('Selected exam date', false)
            ->assertSee('id="available_session_date"', false)
            ->assertSee('Pick a test center to load its open exam dates.', false)
            ->assertDontSee('Click one card to select', false)
            ->assertSee('Live verified sessions')
            ->assertSee('Sessions:', false)
            ->assertSee('Session ID:', false)
            ->assertSee('let selectedCardKey', false)
            ->assertSee('card.dataset.paccIndex === selectedCardKey', false)
            ->assertSee('centerSessionId', false)
            ->assertSee('data-pacc-session-id', false)
            ->assertSee('document.getElementById(options.sessionSelectId', false)
            ->assertSee('sessionPriority', false)
            ->assertSee('start_at_in_tc_time_zone', false)
            ->assertSee('available_seat_count', false)
            ->assertDontSee('Click a card to select.', false)
            ->assertSee('type="hidden" name="exam_session_id"', false)
            ->assertDontSee('<select name="exam_session_id"', false)
            ->assertSee('loadSessionsForDate', false)
            ->assertDontSee('>load<', false)
            ->assertDontSee('>loading<', false);

        $agencyPage = $this->get(route('agency.bookings.create'));

        $agencyPage->assertOk()
            ->assertSee('Select a live SVP exam language…', false)
            ->assertSee('/agency/bookings/lookup/languages', false)
            ->assertDontSee('Click one card to select', false)
            ->assertSee('Live verified sessions')
            ->assertSee('Sessions:', false)
            ->assertSee('Session ID:', false)
            ->assertSee('let selectedCardKey', false)
            ->assertSee('card.dataset.paccIndex === selectedCardKey', false)
            ->assertSee('centerSessionId', false)
            ->assertSee('data-pacc-session-id', false)
            ->assertSee('document.getElementById(options.sessionSelectId', false)
            ->assertSee('sessionPriority', false)
            ->assertSee('start_at_in_tc_time_zone', false)
            ->assertSee('available_seat_count', false)
            ->assertDontSee('Click a card to select.', false)
            ->assertSee('type="hidden" name="exam_session_id"', false)
            ->assertDontSee('<select name="exam_session_id"', false)
            ->assertSee('loadSessionsForDate', false)
            ->assertDontSee('Selected exam date', false);
    }

    public function test_user_live_language_lookup_uses_portal_session_and_returns_all_languages(): void
    {
        PortalAvailabilityCredential::create([
            'name' => 'User language portal session',
            'portal_account_id' => 'user-language-portal-account',
            'session_cookie' => 'session=user-language-authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                [
                    'name' => 'Kitchen Worker',
                    'occupation_id' => 2062,
                    'category_id' => 1,
                    'languages' => [
                        ['code' => 'LOABB', 'name' => 'Bengali'],
                        ['code' => 'LOAEN', 'name' => 'English'],
                    ],
                ],
            ], 200),
        ]);

        $this->loginAgencyUser();
        $response = $this->withSession(['svp_token' => 'candidate-svp-token'])
            ->getJson(route('user.bookings.lookup.languages', ['occupation_id' => 2062]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.languages.0.code', 'LOABB')
            ->assertJsonPath('data.languages.1.code', 'LOAEN');

        Http::assertSent(fn ($request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/occupations')
            && ($request->header('Cookie')[0] ?? '') === 'session=user-language-authorized');
        Http::assertNotSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer candidate-svp-token');
    }

    public function test_user_booking_rejects_language_not_advertised_by_live_portal(): void
    {
        PortalAvailabilityCredential::create([
            'name' => 'User booking validation portal session',
            'portal_account_id' => 'user-booking-validation-portal-account',
            'session_cookie' => 'session=user-booking-validation-authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                [
                    'name' => 'Kitchen Worker',
                    'occupation_id' => 2062,
                    'languages' => [
                        ['code' => 'LOAEN', 'name' => 'English'],
                    ],
                ],
            ], 200),
        ]);

        $user = $this->loginAgencyUser();
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'full_name' => 'Validation Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-VALIDATION-USER',
        ]);

        $csrfToken = 'live-language-validation-csrf';
        $response = $this->from(route('user.bookings.create'))
            ->withSession(['_token' => $csrfToken, 'svp_token' => 'candidate-svp-token'])
            ->post(route('user.bookings.store'), [
                '_token' => $csrfToken,
                'candidate_id' => $candidate->id,
                'occupation_id' => '2062',
                'category_id' => '1',
                'city' => 'Dhaka',
                'test_center_id' => '17',
                'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                'exam_session_id' => 'session-1',
                'exam_date' => '2026-09-01',
                'temporary_hold_id' => 'hold-1',
                'language_code' => 'LOABB',
                'methodology' => 'in_person',
            ]);

        $response->assertRedirect(route('user.bookings.create'))
            ->assertSessionHasErrors(['language_code']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/exam_reservations'));
    }

    public function test_user_booking_accepts_a_live_language_before_existing_hold_completion(): void
    {
        PortalAvailabilityCredential::create([
            'name' => 'User booking completion portal session',
            'portal_account_id' => 'user-booking-completion-portal-account',
            'session_cookie' => 'session=user-booking-completion-authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                [
                    'name' => 'Kitchen Worker',
                    'occupation_id' => 2062,
                    'category_id' => 1,
                    'languages' => [
                        ['code' => 'LOAEN', 'name' => 'English'],
                    ],
                ],
            ], 200),
        ]);

        $user = $this->loginAgencyUser();
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'full_name' => 'Live Language Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-LIVE-LANGUAGE-USER',
        ]);
        $csrfToken = 'live-language-completion-csrf';

        $response = $this->from(route('user.bookings.create'))
            ->withSession(['_token' => $csrfToken, 'svp_token' => 'candidate-svp-token'])
            ->post(route('user.bookings.store'), [
                '_token' => $csrfToken,
                'candidate_id' => $candidate->id,
                'occupation_id' => '2062',
                'category_id' => '1',
                'city' => 'Dhaka',
                'test_center_id' => '17',
                'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                'exam_session_id' => 'session-live-language',
                'exam_date' => '2026-09-01',
                'temporary_hold_id' => 'hold-live-language',
                'language_code' => 'loaen',
                'methodology' => 'in_person',
            ]);

        $response->assertRedirect(route('user.bookings.create'))
            ->assertSessionHasErrors(['temporary_hold_id'])
            ->assertSessionDoesntHaveErrors(['language_code']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/exam_reservations'));
    }

    public function test_user_booking_with_stale_inactive_candidate_redirects_instead_of_404(): void
    {
        Cache::flush();

        PortalAvailabilityCredential::create([
            'name' => 'Stale candidate validation portal session',
            'portal_account_id' => 'stale-candidate-portal-account',
            'session_cookie' => 'session=stale-candidate-authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                [
                    'name' => 'Kitchen Worker',
                    'occupation_id' => 99062,
                    'languages' => [['code' => 'STALE-LANG', 'name' => 'English']],
                ],
            ], 200),
        ]);

        $user = $this->loginAgencyUser();
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'full_name' => 'Stale Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-STALE-CANDIDATE',
            'is_active' => false,
        ]);
        $provider = new class implements PortalAvailabilityProviderInterface
        {
            public function refreshAccount(string $sessionCookie, string $accountId): array
            {
                return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
            }

            public function occupations(string $sessionCookie): array
            {
                return [[
                    'name' => 'Kitchen Worker',
                    'occupation_id' => 99062,
                    'category_id' => 1,
                    'languages' => [['code' => 'STALE-LANG', 'name' => 'English']],
                ]];
            }

            public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
            {
                return ['dates' => []];
            }

            public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
            {
                return ['centers' => []];
            }
        };
        $this->app->instance(PortalAvailabilityProviderInterface::class, $provider);
        $bookingCount = Booking::where('user_id', $user->id)->count();
        $csrfToken = 'stale-candidate-csrf';

        $response = $this->from(route('user.bookings.create'))
            ->withSession(['_token' => $csrfToken, 'svp_token' => 'candidate-svp-token'])
            ->post(route('user.bookings.store'), [
                '_token' => $csrfToken,
                'candidate_id' => $candidate->id,
                'occupation_id' => '99062',
                'category_id' => '1',
                'city' => 'Dhaka',
                'test_center_id' => '17',
                'test_center_name' => 'Bangladesh Korea TTC Dhaka',
                'exam_session_id' => 'session-stale-candidate',
                'exam_date' => '2026-09-01',
                'temporary_hold_id' => 'hold-stale-candidate',
                'language_code' => 'STALE-LANG',
                'methodology' => 'in_person',
            ]);

        $response->assertRedirect(route('user.bookings.create'))
            ->assertSessionHasErrors(['candidate_id']);
        $this->assertSame($bookingCount, Booking::where('user_id', $user->id)->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/exam_reservations'));
    }

    public function test_user_credit_status_with_inactive_candidate_returns_json_error(): void
    {
        $user = $this->loginAgencyUser();
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'full_name' => 'Inactive Credit Candidate',
            'email' => $user->email,
            'svp_user_id' => 'SVP-INACTIVE-CREDIT-CANDIDATE',
            'is_active' => false,
        ]);

        $response = $this->withSession(['svp_token' => 'candidate-svp-token'])
            ->getJson(route('user.bookings.credit-status', [
                'candidate_id' => $candidate->id,
                'occupation_id' => '2062',
                'methodology' => 'in_person',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'The selected SVP candidate is no longer active. Refresh the booking page and select the current candidate.');
    }

    public function test_user_occupation_lookup_uses_portal_session_not_candidate_token(): void
    {
        PortalAvailabilityCredential::create([
            'name' => 'User booking portal session',
            'portal_account_id' => 'user-booking-portal-account',
            'session_cookie' => 'session=user-booking-authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                ['name' => 'Kitchen Worker', 'occupation_id' => 2062, 'category_id' => 1],
            ], 200),
        ]);

        $this->loginAgencyUser();
        $response = $this->withSession(['svp_token' => 'candidate-svp-token'])
            ->getJson(route('user.bookings.lookup.occupations'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.occupations.0.occupation_id', 2062);
        Http::assertSent(fn ($request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/occupations')
            && ($request->header('Cookie')[0] ?? '') === 'session=user-booking-authorized');
        Http::assertNotSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer candidate-svp-token');
    }

    public function test_user_and_agency_date_center_lookups_use_portal_availability_without_candidate_token(): void
    {
        PortalAvailabilityCredential::create([
            'name' => 'Date-first portal session',
            'portal_account_id' => 'date-first-portal-account',
            'session_cookie' => 'session=date-first-authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/search_dates' => Http::response([
                'dates' => [
                    ['city' => 'Dhaka', 'date' => '2030-09-01'],
                    ['city' => 'Khulna', 'date' => '2030-09-02'],
                ],
            ], 200),
            'https://svp-international.xyz/api/centers' => Http::response([
                'centers' => [
                    [
                        'test_center_name' => 'Bangladesh German TTC',
                        'test_center_id' => 45,
                        'test_time' => '12:30 PM',
                        'available_seats' => 3,
                    ],
                    [
                        'test_center_name' => 'Bangladesh German TTC',
                        'test_center_id' => 45,
                        'test_time' => '09:30 AM',
                        'available_seats' => 8,
                    ],
                ],
            ], 200),
        ]);

        $this->loginAgencyUser();
        $session = ['svp_token' => 'candidate-svp-token'];

        foreach ([
            ['dates' => 'user.bookings.lookup.dates', 'centers' => 'user.bookings.lookup.test-centers'],
            ['dates' => 'agency.bookings.lookup.dates', 'centers' => 'agency.bookings.lookup.test-centers'],
        ] as $routes) {
            $dates = $this->withSession($session)->getJson(route($routes['dates'], [
                'city' => 'Dhaka',
                'category_id' => '160',
            ]));
            $dates->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.dates.0.city', 'Dhaka')
                ->assertJsonPath('data.dates.0.date', '2030-09-01');

            $centers = $this->withSession($session)->getJson(route($routes['centers'], [
                'city' => 'Dhaka',
                'category_id' => '160',
                'date' => '2030-09-01',
                'occupation_id' => '2063',
                'language_code' => 'OFFUU',
            ]));
            $centers->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.test_centers.0.test_center_id', 45)
                ->assertJsonPath('data.test_centers.0.test_time', '12:30 PM')
                ->assertJsonPath('data.test_centers.1.test_time', '09:30 AM')
                ->assertJsonPath('data.test_centers.1.available_seats', 8);
        }

        Http::assertSent(fn ($request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/search_dates')
            && ($request->header('Cookie')[0] ?? '') === 'session=date-first-authorized'
            && ($request->data()['city'] ?? null) === null);
        Http::assertSent(fn ($request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/centers')
            && ($request->header('Cookie')[0] ?? '') === 'session=date-first-authorized'
            && ($request->data()['city'] ?? null) === 'Dhaka'
            && ($request->data()['date'] ?? null) === '2030-09-01');
        Http::assertNotSent(fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer candidate-svp-token');
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

    public function test_user_center_lookup_falls_back_to_real_candidate_sessions_when_portal_is_empty(): void
    {
        $this->loginAgencyUser();

        PortalAvailabilityCredential::query()->create([
            'name' => 'Empty portal fallback',
            'portal_account_id' => 'fallback-portal-account',
            'session_cookie' => 'session=fallback-authorized',
            'active' => true,
        ]);
        TestCenter::query()->create([
            'svp_id' => '171',
            'name' => 'Jashore Technical Training Centre',
            'city' => 'Khulna',
            'country_code' => 'BD',
        ]);
        TestCenter::query()->create([
            'svp_id' => '181',
            'name' => 'Narail Technical Training Centre',
            'city' => 'Khulna',
            'country_code' => 'BD',
        ]);

        Http::fake([
            'https://svp-international.xyz/api/centers' => Http::response(['centers' => []], 200),
            'https://svp-international-api.pacc.sa/api/v1/individual_labor_space/exam_sessions*' => function ($request) {
                $centerId = str_contains($request->url(), 'test_center_id=171') ? '171' : '181';
                $count = $centerId === '171' ? 4 : 1;
                $sessions = [];

                for ($index = 1; $index <= $count; $index++) {
                    $sessions[] = [
                        'id' => 'fallback-'.$centerId.'-'.$index,
                        'exam_date' => '2026-08-31',
                        'test_center_id' => $centerId,
                        'available_seats' => 1,
                    ];
                }

                return Http::response(['exam_sessions' => $sessions], 200);
            },
        ]);

        $response = $this->withSession(['svp_token' => 'candidate-token'])
            ->getJson(route('user.bookings.lookup.test-centers', [
                'city' => 'Khulna',
                'category_id' => '159',
                'date' => '2026-08-31',
                'occupation_id' => '2061',
                'language_code' => 'LOABB',
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('fallback', true)
            ->assertJsonPath('availability_source', 'candidate_authenticated_sessions');
        $rows = $response->json('data.test_centers');
        $this->assertCount(5, $rows);
        $this->assertSame(['171', '171', '171', '171'], array_column(array_slice($rows, 0, 4), 'test_center_id'));
        $this->assertSame(['fallback-171-1', 'fallback-171-2', 'fallback-171-3', 'fallback-171-4'], array_column(array_slice($rows, 0, 4), 'exam_session_id'));
        $this->assertSame([4, 4, 4, 4], array_column(array_slice($rows, 0, 4), 'session_count'));
        $this->assertSame('181', $rows[4]['test_center_id']);
        $this->assertSame('fallback-181-1', $rows[4]['exam_session_id']);
        $this->assertSame(1, $rows[4]['session_count']);

        $agencyResponse = $this->withSession(['svp_token' => 'candidate-token'])
            ->getJson(route('agency.bookings.lookup.test-centers', [
                'city' => 'Khulna',
                'category_id' => '159',
                'date' => '2026-08-31',
                'occupation_id' => '2061',
                'language_code' => 'LOABB',
            ]));

        $agencyResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('fallback', true)
            ->assertJsonPath('availability_source', 'candidate_authenticated_sessions');
        $agencyRows = $agencyResponse->json('data.test_centers');
        $this->assertCount(5, $agencyRows);
        $this->assertSame(['171', '171', '171', '171'], array_column(array_slice($agencyRows, 0, 4), 'test_center_id'));
        $this->assertSame(['fallback-171-1', 'fallback-171-2', 'fallback-171-3', 'fallback-171-4'], array_column(array_slice($agencyRows, 0, 4), 'exam_session_id'));
        $this->assertSame([4, 4, 4, 4], array_column(array_slice($agencyRows, 0, 4), 'session_count'));
        $this->assertSame('181', $agencyRows[4]['test_center_id']);
        $this->assertSame('fallback-181-1', $agencyRows[4]['exam_session_id']);
        $this->assertSame(1, $agencyRows[4]['session_count']);

        Http::assertSentCount(8);
    }

    public function test_booking_create_renders_read_only_lookups_without_candidate_token(): void
    {
        $this->loginAgencyUser();

        $response = $this->get(route('user.bookings.create'));
        $response->assertOk()
            ->assertSee('Connect your candidate SVP account before creating a hold or booking.')
            ->assertSee('id="occupation-search"', false)
            ->assertSee('booking-availability-calendar', false)
            ->assertSee('Available Sessions — date-first PACC booking')
            ->assertSee('lookup/dates', false)
            ->assertSee('lookup/test-centers', false)
            ->assertSee('prefetchSessionsForCenters', false)
            ->assertSee('sessionLookupCache', false)
            ->assertSee('if (sessions.length > 0) sessionLookupCache.set(key, sessions);', false)
            ->assertSee('sessionLookupCache.delete(key);', false)
            ->assertSee('body?.data?.centers', false)
            ->assertSee('user-center-response', false)
            ->assertSee('sessionLookupCache.clear();', false)
            ->assertSee('user-session-response', false)
            ->assertSee('id="user-session-response" class="mt-3"', false)
            ->assertSee('Live verified sessions')
            ->assertSee('Sessions:', false)
            ->assertSee('Session ID:', false)
            ->assertSee('let selectedCardKey', false)
            ->assertSee('card.dataset.paccIndex === selectedCardKey', false)
            ->assertSee('centerSessionId', false)
            ->assertSee('data-pacc-session-id', false)
            ->assertSee('document.getElementById(options.sessionSelectId', false)
            ->assertSee('sessionPriority', false)
            ->assertSee('start_at_in_tc_time_zone', false)
            ->assertSee('available_seat_count', false)
            ->assertDontSee('Click a card to select.', false)
            ->assertSee('type="hidden" name="exam_session_id"', false)
            ->assertDontSee('<select name="exam_session_id"', false)
            ->assertDontSee('Live sessions and seat counts')
            ->assertSee('Test Center')
            ->assertDontSee('Notes (optional)')
            ->assertDontSee('Anything the SVP booking should know');
        $html = $response->getContent();
        $languagePosition = strpos($html, 'id="language_code"');
        $availabilityPosition = strpos($html, 'booking-availability-calendar');
        $centerPosition = strpos($html, 'id="test-center-section"');
        $sessionPosition = strpos($html, 'id="exam_session_id"');
        $this->assertNotFalse($languagePosition);
        $this->assertNotFalse($availabilityPosition);
        $this->assertNotFalse($centerPosition);
        $this->assertNotFalse($sessionPosition);
        $this->assertTrue($languagePosition < $availabilityPosition);
        $this->assertTrue($availabilityPosition < $centerPosition);
        $this->assertTrue($centerPosition < $sessionPosition);
    }

    public function test_user_booking_detail_exposes_live_svp_cancel_action_for_saved_reservation(): void
    {
        $user = $this->loginAgencyUser();
        $booking = Booking::create([
            'agency_id' => $user->agency_id,
            'user_id' => $user->id,
            'occupation_id' => '2061',
            'category_id' => '159',
            'exam_session_id' => 'session-5582',
            'test_center_id' => '181',
            'test_center_name' => 'Noakhali Technical Training Centre',
            'exam_date' => '2026-09-02',
            'reservation_id' => '5582',
            'booking_status' => 'failed',
            'booking_reference' => 'booking-5582-test',
        ]);

        $response = $this->get(route('user.bookings.show', $booking));

        $response->assertOk()
            ->assertSee('Cancel SVP Reservation')
            ->assertSee(route('user.bookings.svp-cancel', ['reservation' => '5582']), false)
            ->assertSee('Cancel this live SVP reservation?', false)
            ->assertDontSee(route('agency.bookings.cancel', ['booking' => $booking]), false);
    }

    public function test_agency_booking_create_clears_expired_svp_jwt_but_keeps_read_only_page_available(): void
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

        $response->assertOk()
            ->assertSee('Connect your candidate SVP account before creating a hold or booking.')
            ->assertSee('agency-booking-availability-calendar', false)
            ->assertSee('Available Sessions — date-first PACC booking')
            ->assertSee('lookup/dates', false)
            ->assertSee('lookup/test-centers', false)
            ->assertSee('prefetchSessionsForCenters', false)
            ->assertSee('sessionLookupCache', false)
            ->assertSee('if (sessions.length > 0) sessionLookupCache.set(key, sessions);', false)
            ->assertSee('sessionLookupCache.delete(key);', false)
            ->assertSee('body?.data?.centers', false)
            ->assertSee('agency-center-response', false)
            ->assertSee('sessionLookupCache.clear();', false)
            ->assertSee('agency-session-response', false)
            ->assertSee('id="agency-session-response" class="mt-3"', false)
            ->assertSee('Live verified sessions')
            ->assertSee('Sessions:', false)
            ->assertSee('Session ID:', false)
            ->assertSee('let selectedCardKey', false)
            ->assertSee('card.dataset.paccIndex === selectedCardKey', false)
            ->assertSee('centerSessionId', false)
            ->assertSee('data-pacc-session-id', false)
            ->assertSee('document.getElementById(options.sessionSelectId', false)
            ->assertSee('sessionPriority', false)
            ->assertSee('start_at_in_tc_time_zone', false)
            ->assertSee('available_seat_count', false)
            ->assertDontSee('Click a card to select.', false)
            ->assertSee('type="hidden" name="exam_session_id"', false)
            ->assertDontSee('<select name="exam_session_id"', false)
            ->assertDontSee('Live sessions and seat counts')
            ->assertSee('Test Center')
            ->assertDontSee('Notes (optional)')
            ->assertDontSee('Anything the SVP booking should know');
        $html = $response->getContent();
        $languagePosition = strpos($html, 'id="language_code"');
        $availabilityPosition = strpos($html, 'agency-booking-availability-calendar');
        $centerPosition = strpos($html, 'id="test-center-section"');
        $sessionPosition = strpos($html, 'id="exam_session_id"');
        $this->assertNotFalse($languagePosition);
        $this->assertNotFalse($availabilityPosition);
        $this->assertNotFalse($centerPosition);
        $this->assertNotFalse($sessionPosition);
        $this->assertTrue($languagePosition < $availabilityPosition);
        $this->assertTrue($availabilityPosition < $centerPosition);
        $this->assertTrue($centerPosition < $sessionPosition);
        $this->assertNull(session('svp_token'));
    }
}
