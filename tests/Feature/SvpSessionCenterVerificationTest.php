<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SvpSessionCenterVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_read_only_route_maps_opaque_session_to_expected_center(): void
    {
        $agency = Agency::create([
            'name' => 'Session Verify Agency',
            'code' => 'SVERIFY1',
            'status' => true,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id]);
        Auth::guard('web')->login($user);

        $service = $this->mock(BookingService::class);
        $service->shouldReceive('examSession')
            ->once()
            ->with('test-token', 'opaque-session-17')
            ->andReturn(response()->json([
                'data' => [
                    'exam_session' => [
                        'id' => 'opaque-session-17',
                        'test_center' => [
                            'id' => 17,
                            'name' => 'Bangladesh Korea TTC Dhaka',
                            'city' => 'Dhaka',
                        ],
                        'start_date_in_tc_time_zone' => '2026-08-27T08:00:00Z',
                    ],
                ],
            ], 200));

        $response = $this->withSession(['svp_token' => 'test-token'])
            ->getJson(route('agency.bookings.lookup.verify-session-center', [
                'exam_session_id' => 'opaque-session-17',
                'expected_test_center_id' => '17',
                'expected_city' => 'Dhaka',
            ]));

        $response->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('checks.center_match', true)
            ->assertJsonPath('actual.test_center_id', '17')
            ->assertJsonPath('actual.test_center_name', 'Bangladesh Korea TTC Dhaka')
            ->assertJsonPath('session.id', 'opaque-session-17');
    }

    public function test_user_post_route_rejects_session_from_a_different_center(): void
    {
        $agency = Agency::create([
            'name' => 'Session Mismatch Agency',
            'code' => 'SVERIFY2',
            'status' => true,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id]);
        Auth::guard('web')->login($user);

        $service = $this->mock(BookingService::class);
        $service->shouldReceive('examSession')
            ->once()
            ->with('test-token', 'opaque-session-45')
            ->andReturn(response()->json([
                'data' => [
                    'session' => [
                        'id' => 'opaque-session-45',
                        'test_center_id' => 45,
                        'test_center_name' => 'Bangladesh German TTC',
                        'test_center_city' => 'Dhaka',
                    ],
                ],
            ], 200));

        $csrfToken = 'session-verify-csrf-token';
        $response = $this->withSession([
            'svp_token' => 'test-token',
            '_token' => $csrfToken,
        ])->postJson(route('user.bookings.lookup.verify-session-center.post'), [
            '_token' => $csrfToken,
            'exam_session_id' => 'opaque-session-45',
            'expected_test_center_id' => '17',
            'expected_city' => 'Dhaka',
        ]);

        $response->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('checks.center_match', false)
            ->assertJsonPath('actual.test_center_id', '45')
            ->assertJsonPath('expected.test_center_id', '17');
    }

    public function test_verifier_maps_list_wrapped_session_with_nested_site_data(): void
    {
        $agency = Agency::create([
            'name' => 'Nested Session Agency',
            'code' => 'SVERIFY4',
            'status' => true,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id]);
        Auth::guard('web')->login($user);

        $service = $this->mock(BookingService::class);
        $service->shouldReceive('examSession')
            ->once()
            ->with('test-token', 'opaque-session-site-data')
            ->andReturn(response()->json([
                'data' => [
                    'exam_sessions' => [[
                        'id' => 'opaque-session-site-data',
                        'start_date_in_browser_time_zone' => '2026-08-30T08:00:00Z',
                        'site' => [
                            'data' => [
                                'id' => 17,
                                'name' => 'Bangladesh Korea TTC Dhaka',
                                'city' => 'Dhaka',
                            ],
                        ],
                    ]],
                ],
            ], 200));

        $response = $this->withSession(['svp_token' => 'test-token'])
            ->getJson(route('agency.bookings.lookup.verify-session-center', [
                'exam_session_id' => 'opaque-session-site-data',
                'expected_test_center_id' => '17',
                'expected_city' => 'Dhaka',
                'expected_exam_date' => '2026-08-30',
            ]));

        $response->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('checks.center_match', true)
            ->assertJsonPath('checks.date_match', true)
            ->assertJsonPath('actual.test_center_id', '17')
            ->assertJsonPath('actual.test_center_name', 'Bangladesh Korea TTC Dhaka');
    }

    public function test_verification_does_not_claim_success_when_session_has_no_center_metadata(): void
    {
        $agency = Agency::create([
            'name' => 'Session Missing Center Agency',
            'code' => 'SVERIFY3',
            'status' => true,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id]);
        Auth::guard('web')->login($user);

        $service = $this->mock(BookingService::class);
        $service->shouldReceive('examSession')
            ->once()
            ->andReturn(response()->json([
                'data' => ['session' => ['id' => 'opaque-session-no-center']],
            ], 200));

        $response = $this->withSession(['svp_token' => 'test-token'])
            ->getJson(route('agency.bookings.lookup.verify-session-center', [
                'exam_session_id' => 'opaque-session-no-center',
                'expected_test_center_id' => '17',
            ]));

        $response->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('checks.session_center_present', false);
    }
}

