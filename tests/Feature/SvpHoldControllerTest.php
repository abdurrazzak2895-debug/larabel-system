<?php

namespace Tests\Feature;

use App\Http\Controllers\SvpHoldController;
use App\Services\BookingService;
use App\Services\SvpTemporaryHoldService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class SvpHoldControllerTest extends TestCase
{
    public function test_live_style_hold_response_is_normalized_and_bound_to_the_session(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldNotReceive('sessions');
        $booking->shouldReceive('examSession')
            ->once()
            ->with('svp-token', '2')
            ->andReturn(response()->json([
                'data' => [
                    'exam_session' => [
                        'id' => '2',
                        'exam_date' => '2026-08-18',
                        'test_center' => [
                            'id' => '403',
                            'name' => 'Arkan Al-Taameer',
                            'city' => 'Dhaka',
                        ],
                    ],
                ],
            ], 200));
        $booking->shouldReceive('temporarySeat')
            ->once()
            ->with('svp-token', [
                'exam_session_id' => ['2'],
                'methodology' => 'in_person',
            ])
            ->andReturn(response()->json([
                'id' => 5143290,
                'exam_session_id' => 2,
                'expired_at' => '14/08/2026 05:30',
            ], 201));

        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/agency/bookings/temporary-hold', 'POST', [
            'occupation_id' => '2279',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '403',
            'test_center_name' => 'Arkan Al-Taameer',
            'exam_session_id' => '2',
            'exam_date' => '2026-08-18',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'svp-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '403',
        ], [
            'data' => [
                'sessions' => [[
                    'id' => '2',
                    'test_center_id' => '403',
                    'exam_date' => '2026-08-18',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('5143290', $payload['data']['id']);
        $this->assertSame('14/08/2026 05:30', $payload['data']['expires_at']);
        $this->assertSame(
            '403',
            $request->session()->get('svp_temporary_holds.5143290.test_center_id')
        );
    }

    public function test_hold_is_rejected_when_submitted_date_does_not_match_the_live_session(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldNotReceive('sessions');
        $booking->shouldNotReceive('temporarySeat');
        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/agency/bookings/temporary-hold', 'POST', [
            'occupation_id' => '2061',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
            'exam_session_id' => 'live-session',
            'exam_date' => '2026-08-18',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'svp-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
        ], [
            'data' => [
                'sessions' => [[
                    'id' => 'live-session',
                    'test_center_id' => '223',
                    'exam_date' => '2026-08-31',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'The exam date must match the selected live SVP session date.',
            $response->getData(true)['error']
        );
    }

    public function test_rotated_session_id_resolves_to_the_next_date_at_the_same_center(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldNotReceive('sessions');
        $booking->shouldReceive('examSession')
            ->once()
            ->with('svp-token', 'different-session-id')
            ->andReturn(response()->json([
                'data' => [
                    'session' => [
                        'id' => 'different-session-id',
                        'exam_date' => '2026-08-31',
                        'test_center_id' => '223',
                        'test_center_name' => 'Manikganj Technical Training Center',
                        'test_center_city' => 'Dhaka',
                    ],
                ],
            ], 200));
        $booking->shouldReceive('temporarySeat')
            ->once()
            ->with('svp-token', [
                'exam_session_id' => ['different-session-id'],
                'methodology' => 'in_person',
            ])
            ->andReturn(response()->json([
                'id' => 5144550,
                'exam_session_id' => 'different-session-id',
                'expired_at' => '14/08/2026 06:00',
            ], 201));
        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/agency/bookings/temporary-hold', 'POST', [
            'occupation_id' => '2061',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
            'exam_session_id' => 'rotated-session-id',
            'exam_date' => '2026-08-30',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'svp-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
        ], [
            'data' => [
                'exam_sessions' => [[
                    'id' => 'different-session-id',
                    'test_center_id' => '223',
                    'exam_date' => '2026-08-31',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('different-session-id', $payload['selection']['exam_session_id']);
        $this->assertSame('2026-08-31', $payload['selection']['exam_date']);
        $this->assertSame('223', $payload['selection']['test_center_id']);
        $this->assertSame('rotated-session-id', $payload['resolved_from_session_id']);
    }

    public function test_expired_svp_session_returns_reauthentication_instruction_without_a_hold(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldReceive('examSession')
            ->once()
            ->with('expired-token', '223-session')
            ->andReturn(response()->json([
                'message' => 'Signature has expired',
            ], 401));
        $booking->shouldNotReceive('temporarySeat');
        $booking->shouldReceive('temporarySeat')
            ->never()
            ->with('expired-token', [
                'exam_session_id' => ['223-session'],
                'methodology' => 'in_person',
            ])
            ->andReturn(response()->json([
                'message' => 'Signature has expired',
            ], 401));
        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/agency/bookings/temporary-hold', 'POST', [
            'occupation_id' => '2061',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
            'exam_session_id' => '223-session',
            'exam_date' => '2026-08-31',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'expired-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
        ], [
            'data' => [
                'sessions' => [[
                    'id' => '223-session',
                    'test_center_id' => '223',
                    'exam_date' => '2026-08-31',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertTrue($payload['requires_svp_login']);
        $this->assertSame('Your SVP session has expired. Sign in with SVP again, then retry this same session.', $payload['error']);
        $this->assertStringContainsString('force=1', $payload['login_url']);
        $this->assertSame([], $request->session()->get('svp_temporary_holds', []));
    }

    public function test_rotated_session_id_is_rejected_when_no_same_center_date_remains(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldNotReceive('sessions');
        $booking->shouldNotReceive('temporarySeat');
        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/agency/bookings/temporary-hold', 'POST', [
            'occupation_id' => '2061',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
            'exam_session_id' => 'rotated-session-id',
            'exam_date' => '2026-08-31',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'svp-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '223',
        ], [
            'data' => [
                'exam_sessions' => [[
                    'id' => 'earlier-session-id',
                    'test_center_id' => '223',
                    'exam_date' => '2026-08-30',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'No available SVP session remains at the selected test center on or after the requested date.',
            $response->getData(true)['error']
        );
    }

    public function test_hold_is_blocked_when_authoritative_session_center_differs(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldReceive('examSession')
            ->once()
            ->with('svp-token', 'opaque-session')
            ->andReturn(response()->json([
                'data' => [
                    'exam_session' => [
                        'id' => 'opaque-session',
                        'exam_date' => '2026-08-30',
                        'test_center' => [
                            'id' => '45',
                            'name' => 'Bangladesh German TTC',
                            'city' => 'Dhaka',
                        ],
                    ],
                ],
            ], 200));
        $booking->shouldNotReceive('temporarySeat');
        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/user/bookings/temporary-hold', 'POST', [
            'occupation_id' => '159',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
            'test_center_name' => 'Bangladesh Korea TTC Dhaka',
            'exam_session_id' => 'opaque-session',
            'exam_date' => '2026-08-30',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'svp-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
        ], [
            'data' => [
                'sessions' => [[
                    'id' => 'opaque-session',
                    'test_center_id' => '17',
                    'exam_date' => '2026-08-30',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertFalse($payload['verification']['verified']);
        $this->assertFalse($payload['verification']['checks']['center_match']);
        $this->assertSame([], $request->session()->get('svp_temporary_holds', []));
    }

    public function test_hold_is_blocked_when_authoritative_session_has_no_center_metadata(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldReceive('examSession')
            ->once()
            ->with('svp-token', 'centerless-session')
            ->andReturn(response()->json([
                'data' => [
                    'exam_session' => [
                        'id' => 'centerless-session',
                        'exam_date' => '2026-08-30',
                    ],
                ],
            ], 200));
        $booking->shouldNotReceive('temporarySeat');
        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/agency/bookings/temporary-hold', 'POST', [
            'occupation_id' => '159',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
            'exam_session_id' => 'centerless-session',
            'exam_date' => '2026-08-30',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'svp-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
        ], [
            'data' => [
                'sessions' => [[
                    'id' => 'centerless-session',
                    'exam_date' => '2026-08-30',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertFalse($payload['verification']['verified']);
        $this->assertFalse($payload['verification']['checks']['session_center_present']);
    }

    public function test_hold_is_allowed_when_authoritative_session_confirms_center_and_date(): void
    {
        $booking = Mockery::mock(BookingService::class);
        $booking->shouldReceive('examSession')
            ->once()
            ->with('svp-token', 'centerless-list-session')
            ->andReturn(response()->json([
                'data' => [
                    'exam_session' => [
                        'id' => 'centerless-list-session',
                        'exam_date' => '2026-08-30',
                        'test_center' => [
                            'id' => '17',
                            'name' => 'Bangladesh Korea TTC Dhaka',
                            'city' => 'Dhaka',
                        ],
                    ],
                ],
            ], 200));
        $booking->shouldReceive('temporarySeat')
            ->once()
            ->with('svp-token', [
                'exam_session_id' => ['centerless-list-session'],
                'methodology' => 'in_person',
            ])
            ->andReturn(response()->json([
                'id' => 5270001,
                'expired_at' => '30/08/2026 06:00',
            ], 201));
        $this->app->instance(BookingService::class, $booking);

        $request = Request::create('/user/bookings/temporary-hold', 'POST', [
            'occupation_id' => '159',
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
            'test_center_name' => 'Bangladesh Korea TTC Dhaka',
            'exam_session_id' => 'centerless-list-session',
            'exam_date' => '2026-08-30',
        ]);
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();
        $request->session()->put('svp_token', 'svp-token');

        app(SvpTemporaryHoldService::class)->rememberSessionLookup($request, [
            'category_id' => '159',
            'city' => 'Dhaka',
            'test_center_id' => '17',
        ], [
            'data' => [
                'sessions' => [[
                    'id' => 'centerless-list-session',
                    'exam_date' => '2026-08-30',
                ]],
            ],
        ]);

        $response = app(SvpHoldController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['verification']['verified'] ?? true);
        $this->assertSame('Bangladesh Korea TTC Dhaka', $payload['selection']['test_center_name']);
    }
}
