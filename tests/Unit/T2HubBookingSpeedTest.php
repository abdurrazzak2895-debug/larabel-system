<?php

namespace Tests\Unit;

use App\Services\T2Hub\T2HubBookingData;
use App\Services\T2Hub\T2HubClient;
use App\Services\T2Hub\T2HubProvider;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class T2HubBookingSpeedTest extends TestCase
{
    public function test_available_dates_keep_both_cities_when_they_share_a_date(): void
    {
        $client = Mockery::mock(T2HubClient::class);
        $client->shouldReceive('get')->once()->with('/exam-available-dates', [
            'category_id' => '50', 'city' => '',
        ])->andReturn(['available_dates' => [
            ['start_date_in_browser_time_zone' => '2026-10-06', 'test_center' => ['city' => 'Rajshahi']],
            ['start_date_in_browser_time_zone' => '2026-10-06', 'test_center' => ['city' => 'Dhaka']],
        ]]);

        $dates = (new T2HubProvider($client))->availableDates('50', '');

        $this->assertCount(2, $dates['dates']);
        $this->assertEqualsCanonicalizing(['Dhaka', 'Rajshahi'], array_column($dates['dates'], 'city'));
    }

    public function test_prewarm_understands_real_booking_date_rows_and_bounds_upstream_work(): void
    {
        config()->set('t2hub.data_source', 't2hub');
        Cache::flush();
        $provider = Mockery::mock(T2HubProvider::class);
        $provider->shouldReceive('testCenters')->once()->with('Rajshahi')->andReturn([]);
        $provider->shouldReceive('sessions')->once()->with('50', 'Rajshahi', '2026-10-06')->andReturn(['sessions' => []]);
        $provider->shouldReceive('sessions')->once()->with('50', 'Rajshahi', '2026-10-07')->andReturn(['sessions' => []]);

        $bridge = new T2HubBookingData($provider);
        $rows = [
            ['city' => 'Rajshahi', 'date' => '2026-10-06'],
            ['city' => 'Rajshahi', 'date' => 'not-a-date'],
            ['city' => 'Rajshahi', 'date' => '2026-10-06'],
            ['city' => 'Rajshahi', 'date' => '2026-10-07'],
            ['city' => 'Rajshahi', 'date' => '2026-10-08'],
        ];
        $this->assertSame(2, $bridge->warmCityDates('50', 'Rajshahi', $rows));
        $this->assertSame(0, $bridge->warmCityDates('50', 'Rajshahi', $rows));
    }

    public function test_live_sessions_remain_scoped_to_the_selected_centre(): void
    {
        $provider = Mockery::mock(T2HubProvider::class);
        $provider->shouldReceive('sessions')->twice()->with('50', 'Rajshahi', '2026-10-06')->andReturn([
            'sessions' => [
                ['exam_session_id' => 'session-54', 'site_id' => '54', 'center_name' => 'Rajshahi TTC', 'exam_date' => '2026-10-06', 'exam_time' => '10:00 AM', 'available_seats' => 4],
                ['exam_session_id' => 'session-61', 'site_id' => '61', 'center_name' => 'Another TTC', 'exam_date' => '2026-10-06', 'exam_time' => '11:00 AM', 'available_seats' => 2],
            ],
        ]);

        $bridge = new T2HubBookingData($provider);
        $this->assertSame(['session-54'], array_column($bridge->sessionRows('50', 'Rajshahi', '2026-10-06', '54'), 'exam_session_id'));
        $this->assertSame(['session-61'], array_column($bridge->sessionRows('50', 'Rajshahi', '2026-10-06', '61'), 'exam_session_id'));
    }
}
