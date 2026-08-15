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
        $booking->shouldReceive('temporarySeat')
            ->once()
            ->with('svp-token', [
                'exam_session_id' => '2',
                'test_center_id' => '403',
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
        $booking->shouldReceive('temporarySeat')
            ->once()
            ->with('svp-token', [
                'exam_session_id' => 'different-session-id',
                'test_center_id' => '223',
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
}
