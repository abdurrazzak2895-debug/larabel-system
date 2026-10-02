<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use App\Services\T2Hub\T2HubProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class T2HubBookingLookupTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $agency = Agency::create(['name' => 'T2Hub Lookup Agency', 'code' => 'LOOKUPTEST', 'status' => true]);
        Auth::guard('web')->login(User::factory()->create(['agency_id' => $agency->id]));
        config()->set('t2hub.data_source', 't2hub');
    }

    public function test_centre_lookup_bundles_only_its_real_sessions_without_raw_upstream_data(): void
    {
        $this->signIn();
        $provider = Mockery::mock(T2HubProvider::class);
        $provider->shouldReceive('sessions')->times(4)->with('50', 'Rajshahi', '2026-10-06')->andReturn([
            'sessions' => [
                ['exam_session_id' => 'session-54', 'site_id' => '54', 'center_name' => 'Rajshahi TTC', 'exam_date' => '2026-10-06', 'exam_time' => '10:00 AM', 'available_seats' => 3, 'raw_secret' => 'never-expose'],
                ['exam_session_id' => 'session-61', 'site_id' => '61', 'center_name' => 'Other TTC', 'exam_date' => '2026-10-06', 'exam_time' => '11:00 AM', 'available_seats' => 2],
            ],
        ]);
        app()->instance(T2HubProvider::class, $provider);

        $response = $this->getJson(route('user.bookings.lookup.test-centers', [
            'city' => 'Rajshahi', 'category_id' => '50', 'date' => '2026-10-06',
            'occupation_id' => '50', 'language_code' => 'BRBBB',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.sessions_bundled', true)
            ->assertJsonPath('data.sessions_by_center.54.0.exam_session_id', 'session-54')
            ->assertJsonPath('data.sessions_by_center.61.0.exam_session_id', 'session-61');
        $this->assertCount(2, $response->json('data.test_centers'));
        $this->assertArrayNotHasKey('raw', $response->json('data.sessions_by_center.54.0'));
        $this->assertArrayNotHasKey('raw_secret', $response->json('data.sessions_by_center.54.0'));

        $agencyResponse = $this->getJson(route('agency.bookings.lookup.test-centers', [
            'city' => 'Rajshahi', 'category_id' => '50', 'date' => '2026-10-06',
            'occupation_id' => '50', 'language_code' => 'BRBBB',
        ]));
        $agencyResponse->assertOk()
            ->assertJsonPath('data.sessions_bundled', true)
            ->assertJsonPath('data.sessions_by_center.54.0.exam_session_id', 'session-54');
    }

    public function test_empty_t2hub_date_returns_quickly_without_svp_fallback(): void
    {
        $this->signIn();
        $provider = Mockery::mock(T2HubProvider::class);
        $provider->shouldReceive('sessions')->once()->with('50', 'Rajshahi', '2026-10-07')->andReturn(['sessions' => []]);
        app()->instance(T2HubProvider::class, $provider);

        $response = $this->getJson(route('user.bookings.lookup.test-centers', [
            'city' => 'Rajshahi', 'category_id' => '50', 'date' => '2026-10-07',
            'occupation_id' => '50', 'language_code' => 'BRBBB',
        ]));

        $response->assertOk()->assertJsonPath('data.sessions_bundled', true)
            ->assertJsonPath('data.test_centers', []);
    }
}
