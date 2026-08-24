<?php

namespace Tests\Unit;

use App\Services\Providers\PortalAvailabilityProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalAvailabilityProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.base_url' => 'https://svp-international.xyz',
            'portal.timeout' => 15,
            'portal.connect_timeout' => 5,
        ]);
    }

    public function test_it_refreshes_an_account_and_merges_the_rotated_set_cookie(): void
    {
        Http::fake([
            'https://svp-international.xyz/api/accounts/ab9b8cf489a3/refresh' => Http::response(
                ['success' => true, 'expires_at' => '2030-09-01T12:00:00Z'],
                200,
                ['Set-Cookie' => 'session=rotated-session; Path=/; HttpOnly']
            ),
        ]);

        $result = app(PortalAvailabilityProvider::class)->refreshAccount(
            'session=authorized; csrf=keep',
            'ab9b8cf489a3',
        );

        $this->assertSame('session=rotated-session; csrf=keep', $result['session_cookie']);
        $this->assertTrue($result['rotated']);
        $this->assertSame('2030-09-01 12:00:00', $result['expires_at']);
        $this->assertArrayNotHasKey('success', $result);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://svp-international.xyz/api/accounts/ab9b8cf489a3/refresh'
                && $request->data() === []
                && $request->hasHeader('Cookie', 'session=authorized; csrf=keep');
        });
    }

    public function test_it_fetches_occupations_from_the_allowlisted_get_endpoint(): void
    {
        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                ['name' => 'Load and Unload Worker', 'occupation_id' => 2061, 'category_id' => 159, 'languages' => [['code' => 'LOABB', 'name' => 'Bengali']]],
            ], 200),
        ]);

        $result = app(PortalAvailabilityProvider::class)->occupations('session=authorized');

        $this->assertSame(2061, $result[0]['occupation_id']);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://svp-international.xyz/api/occupations'
                && $request->hasHeader('Cookie', 'session=authorized');
        });
    }

    public function test_it_posts_the_verified_search_dates_payload(): void
    {
        Http::fake([
            'https://svp-international.xyz/api/search_dates' => Http::response([
                'dates' => [['city' => 'Khulna', 'date' => '2026-08-25']],
            ], 200),
        ]);

        $result = app(PortalAvailabilityProvider::class)->searchDates(
            'session=authorized',
            'portal-account-1',
            159,
            '2026-08-22',
        );

        $this->assertSame('Khulna', $result['dates'][0]['city']);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://svp-international.xyz/api/search_dates'
                && $request->data() === [
                    'account_id' => 'portal-account-1',
                    'category_id' => 159,
                    'start_from' => '2026-08-22',
                ]
                && $request->hasHeader('Cookie', 'session=authorized');
        });
    }

    public function test_it_posts_the_verified_centers_payload(): void
    {
        Http::fake([
            'https://svp-international.xyz/api/centers' => Http::response([
                'centers' => [['test_center_name' => 'Jashore Technical Training Centre', 'test_center_id' => 171, 'test_time' => '14:00', 'available_seats' => 12]],
            ], 200),
        ]);

        $result = app(PortalAvailabilityProvider::class)->centers(
            'session=authorized',
            'portal-account-1',
            159,
            'Khulna',
            '2026-08-25',
            2061,
            'LOABB',
        );

        $this->assertSame(171, $result['centers'][0]['test_center_id']);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://svp-international.xyz/api/centers'
                && $request->data() === [
                    'account_id' => 'portal-account-1',
                    'category_id' => 159,
                    'city' => 'Khulna',
                    'date' => '2026-08-25',
                    'occupation_id' => 2061,
                    'language_code' => 'LOABB',
                ]
                && $request->hasHeader('Cookie', 'session=authorized');
        });
    }

    public function test_it_rejects_an_unauthorized_session_response(): void
    {
        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response(['error' => 'not_authenticated'], 401),
        ]);

        $this->expectExceptionMessage('Portal session expired or is not authorized.');
        app(PortalAvailabilityProvider::class)->occupations('session=expired');
    }
}
