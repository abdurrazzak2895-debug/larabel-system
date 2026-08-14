<?php

namespace Tests\Unit;

use App\Services\Providers\TakamolProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TakamolProviderLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('svp.base_url', 'https://svp.test');
        config()->set('svp.country_id', 78);
    }

    public function test_sessions_are_filtered_by_category_and_exact_test_center(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'success' => true,
                'data' => [
                    'exam_sessions' => [
                        [
                            'id' => 'session-1',
                            'start_date_in_browser_time_zone' => '2026-09-01',
                            'test_center' => ['id' => 'center-9', 'name' => 'Dhaka North', 'city' => 'Dhaka'],
                        ],
                        [
                            'id' => 'session-other-center',
                            'start_date_in_browser_time_zone' => '2026-09-01',
                            'test_center' => ['id' => 'center-10', 'name' => 'Dhaka South', 'city' => 'Dhaka'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->examSessions([
            'city' => 'Dhaka',
            'category_id' => 'category-4',
            'test_center_id' => 'center-9',
        ]);

        $payload = $response->getData(true);
        $this->assertCount(1, $payload['data']['sessions']);
        $this->assertSame('session-1', $payload['data']['sessions'][0]['id']);
        $this->assertSame('session-1', $payload['data']['exam_sessions'][0]['id']);
        $this->assertSame('center-9', $payload['data']['sessions'][0]['test_center_id']);
        $this->assertSame('Dhaka North', $payload['data']['sessions'][0]['test_center_name']);
        $this->assertSame('2026-09-01 • Dhaka North • Dhaka', $payload['data']['sessions'][0]['name']);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/individual_labor_space/exam_sessions')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && ($query['category_id'] ?? null) === 'category-4'
                && ($query['test_center_id'] ?? null) === 'center-9'
                && ($query['available_seats'] ?? null) === 'greater_than::0'
                && ($query['city'] ?? null) === 'Dhaka';
        });
    }

    public function test_cities_are_normalized_and_scoped_to_the_configured_country(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    ['name' => 'Dhaka'],
                    ['name' => 'Dhaka'],
                    ['id' => 2, 'city' => 'Chattogram'],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->cities('category-4');
        $cities = $response->getData(true)['data'];

        $this->assertCount(2, $cities);
        $this->assertSame(['id' => 'Dhaka', 'name' => 'Dhaka'], array_intersect_key($cities[0], ['id' => true, 'name' => true]));
        $this->assertSame('Chattogram', $cities[1]['name']);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/individual_labor_space/test_centers/cities')
                && (int) ($query['country_id'] ?? 0) === 78
                && ($query['category_id'] ?? null) === 'category-4';
        });
    }

    public function test_available_dates_use_category_and_city_contract(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'available_dates' => [
                    ['date' => '2026-09-02'],
                ],
            ], 200),
        ]);

        (new TakamolProvider())->withToken('test-token')->availableDates(null, [
            'category_id' => 'category-4',
            'city' => 'Dhaka',
        ]);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/individual_labor_space/exam_sessions/available_dates')
                && (int) ($query['country_id'] ?? 0) === 78
                && ($query['category_id'] ?? null) === 'category-4'
                && ($query['city'] ?? null) === 'Dhaka'
                && ! isset($query['session_id']);
        });
    }

    public function test_centers_keep_the_upstream_id_and_filter_by_city(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'test_centers' => [
                        ['id' => 'center-9', 'name' => 'Dhaka North', 'city' => 'Dhaka'],
                        ['id' => 'center-10', 'name' => 'Chattogram Main', 'city' => 'Chattogram'],
                    ],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->testCentersForFilters('Dhaka', 'category-4');
        $centers = $response->getData(true)['data'];

        $this->assertCount(1, $centers);
        $this->assertSame('center-9', $centers[0]['id']);
        $this->assertSame('Dhaka North', $centers[0]['name']);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/visitor_space/test_centers')
                && (int) ($query['country_id'] ?? 0) === 78
                && ($query['category_id'] ?? null) === 'category-4';
        });
    }
}
