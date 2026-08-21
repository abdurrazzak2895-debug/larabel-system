<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SvpSessionVerifier;
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

    public function test_verifier_accepts_all_configured_dhaka_centers_across_response_shapes(): void
    {
        $agency = Agency::create([
            'name' => 'All Centers Verification Agency',
            'code' => 'SVERIFY5',
            'status' => true,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id]);
        Auth::guard('web')->login($user);

        $centers = config('svp.dhaka_test_centers');
        $service = $this->mock(BookingService::class);
        $service->shouldReceive('examSession')
            ->times(count($centers))
            ->andReturnUsing(function (string $token, string $sessionId) use ($centers) {
                $center = collect($centers)->firstWhere('id', substr($sessionId, strlen('center-session-')));
                $index = array_search($center, $centers, true);
                $date = '2026-08-'.str_pad((string) (23 + $index), 2, '0', STR_PAD_LEFT);
                $metadata = [
                    'id' => (int) $center['id'],
                    'name' => $center['name'],
                    'city' => $center['city'],
                ];

                return match ($index % 3) {
                    0 => response()->json([
                        'data' => ['exam_session' => [
                            'id' => $sessionId,
                            'test_center' => $metadata,
                            'exam_date' => $date,
                        ]],
                    ], 200),
                    1 => response()->json([
                        'data' => ['exam_sessions' => [[
                            'id' => $sessionId,
                            'site' => ['data' => $metadata],
                            'start_date_in_browser_time_zone' => $date.'T08:00:00Z',
                        ]]],
                    ], 200),
                    default => response()->json([
                        'session' => [
                            'id' => $sessionId,
                            'testCenterId' => $metadata['id'],
                            'testCenterName' => $metadata['name'],
                            'testCenterCity' => $metadata['city'],
                            'date' => $date,
                        ],
                    ], 200),
                };
            });

        $verifier = app(SvpSessionVerifier::class);

        foreach ($centers as $center) {
            $sessionId = 'center-session-'.$center['id'];
            $date = '2026-08-'.str_pad((string) (23 + array_search($center, $centers, true)), 2, '0', STR_PAD_LEFT);
            $result = $verifier->verify('test-token', $sessionId, $center['id'], $center['city'], $date);

            $this->assertTrue($result['verified'], 'Center '.$center['id'].' should verify.');
            $this->assertSame((string) $center['id'], data_get($result, 'actual.test_center_id'));
            $this->assertSame($center['name'], data_get($result, 'actual.test_center_name'));
        }
    }

    public function test_verifier_accepts_direct_data_list_session_with_center_metadata(): void
    {
        $agency = Agency::create([
            'name' => 'Direct List Agency',
            'code' => 'SVERIFY6',
            'status' => true,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id]);
        Auth::guard('web')->login($user);

        $service = $this->mock(BookingService::class);
        $service->shouldReceive('examSession')
            ->once()
            ->with('test-token', 'opaque-session-rajshahi')
            ->andReturn(response()->json([
                'data' => [[
                    'id' => 'opaque-session-rajshahi',
                    'testCenterId' => 45,
                    'testCenterName' => 'Rajshahi Technical Training Centre',
                    'testCenterCity' => 'Rajshahi',
                    'date' => '2026-08-30',
                ]],
            ], 200));

        $response = $this->withSession(['svp_token' => 'test-token'])
            ->getJson(route('agency.bookings.lookup.verify-session-center', [
                'exam_session_id' => 'opaque-session-rajshahi',
                'expected_test_center_id' => '45',
                'expected_city' => 'Rajshahi',
                'expected_exam_date' => '2026-08-30',
            ]));

        $response->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('checks.center_match', true)
            ->assertJsonPath('actual.test_center_id', '45')
            ->assertJsonPath('actual.test_center_name', 'Rajshahi Technical Training Centre')
            ->assertJsonPath('actual.city', 'Rajshahi');
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

