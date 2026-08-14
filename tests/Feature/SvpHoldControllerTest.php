<?php

namespace Tests\Feature;

use App\Http\Controllers\SvpHoldController;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class SvpHoldControllerTest extends TestCase
{
    public function test_live_style_hold_response_is_normalized_and_bound_to_the_session(): void
    {
        $booking = Mockery::mock(BookingService::class);
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
}
