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

    public function test_sessions_extract_nested_site_center_metadata(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'exam_sessions' => [[
                        'id' => 'opaque-session-1',
                        'start_date_in_browser_time_zone' => '2026-08-27',
                        'site' => [
                            'data' => [
                                'id' => 17,
                                'name' => 'Bangladesh Korea TTC Dhaka',
                                'city' => 'Dhaka',
                            ],
                        ],
                    ]],
                ],
            ], 200),
        ]);

        $payload = (new TakamolProvider())->withToken('test-token')->examSessions([
            'city' => 'Dhaka',
            'category_id' => 'category-4',
            'test_center_id' => '17',
        ])->getData(true);

        $session = $payload['data']['sessions'][0];
        $this->assertSame('17', $session['test_center_id']);
        $this->assertSame('Bangladesh Korea TTC Dhaka', $session['test_center_name']);
        $this->assertSame('Dhaka', $session['test_center_city']);
        $this->assertSame('2026-08-27', $session['exam_date']);
    }

    public function test_sessions_inject_requested_center_when_svp_omits_center_metadata(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'exam_sessions' => [[
                        'id' => 'opaque-session-without-center',
                        'start_date_in_browser_time_zone' => '2026-08-28',
                    ]],
                ],
            ], 200),
        ]);

        $payload = (new TakamolProvider())->withToken('test-token')->examSessions([
            'city' => 'Dhaka',
            'category_id' => 'category-4',
            'test_center_id' => '17',
        ])->getData(true);

        $session = $payload['data']['sessions'][0];
        $this->assertSame('opaque-session-without-center', $session['id']);
        $this->assertSame('17', $session['test_center_id']);
        $this->assertSame('Bangladesh Korea TTC Dhaka', $session['test_center_name']);
        $this->assertSame('Dhaka', $session['test_center_city']);
        $this->assertSame('2026-08-28', $session['exam_date']);
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

    public function test_temporary_seat_uses_the_official_array_body_and_locale(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'id' => 5203034,
                'exam_session_id' => [1536060],
                'expired_at' => '2026-08-17T12:00:00Z',
            ], 201),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->temporarySeat([
            'exam_session_id' => '1536060',
            'methodology' => 'in_person',
            // This Laravel-only field must not leak into the upstream body.
            'test_center_id' => '223',
        ]);

        $this->assertSame(201, $response->getStatusCode());

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/individual_labor_space/temporary_seats')
                && parse_url($request->url(), PHP_URL_QUERY) === 'locale=en'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && ($body['exam_session_id'] ?? null) === [1536060]
                && ($body['methodology'] ?? null) === 'in_person'
                && ! array_key_exists('test_center_id', $body);
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

    public function test_dhaka_returns_all_seven_supplied_svp_centers_with_real_ids(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'test_centers' => [
                        ['id' => 403, 'name' => 'Arkan Al-Taameer for professional classification - Dhaka', 'city' => 'Dhaka'],
                        ['id' => 223, 'name' => 'Manikganj Technical Training Center', 'city' => 'Dhaka'],
                        ['id' => 220, 'name' => 'Kishoreganj Technical Training Centre', 'city' => 'Dhaka'],
                        ['id' => 218, 'name' => 'Narsingdi Technical Training Center', 'city' => 'Dhaka'],
                        ['id' => 102, 'name' => 'Tangail Technical Training Center', 'city' => 'Dhaka'],
                    ],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->testCentersForFilters('Dhaka', 'category-4');
        $centers = $response->getData(true)['data'];

        $this->assertCount(7, $centers);
        $this->assertSame(['403', '223', '220', '218', '102', '45', '17'], array_column($centers, 'id'));
        $this->assertSame('Bangladesh German TTC', $centers[5]['name']);
        $this->assertSame('Bangladesh Korea TTC Dhaka', $centers[6]['name']);
    }

    public function test_categories_for_occupation_handles_nested_occupations_envelope(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'occupations' => [
                        [
                            'id' => '2061',
                            'name' => 'Load and Unload Worker',
                            'categories' => [
                                ['id' => '159', 'name' => 'Load and unload workers'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->categoriesForOccupation('2061');
        $categories = $response->getData(true)['data'];

        $this->assertSame([
            ['id' => '159', 'name' => 'Load and unload workers'],
        ], $categories);
    }

    public function test_categories_for_occupation_normalizes_live_singular_category_envelope(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'occupations' => [
                    [
                        'id' => 2061,
                        'occupation_id' => 2061,
                        'active' => true,
                        'arabic_name' => 'عامل تحميل و تنزيل',
                        'category' => [
                            'id' => 159,
                            'arabic_name' => 'عمال التحميل والتنزيل',
                            'english_name' => 'Load and unload workers',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->categoriesForOccupation('2061');

        $this->assertSame([
            [
                'id' => 159,
                'arabic_name' => 'عمال التحميل والتنزيل',
                'english_name' => 'Load and unload workers',
                'name' => 'Load and unload workers',
            ],
        ], $response->getData(true)['data']);
    }
}
