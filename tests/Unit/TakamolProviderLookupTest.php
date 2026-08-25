<?php

namespace Tests\Unit;

use App\Services\Providers\TakamolProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TakamolProviderLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('svp.base_url', 'https://svp.test');
        config()->set('svp.country_id', 78);
        config()->set('svp.session_date_probe_backfill_days', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exam_session_by_id_uses_authoritative_read_only_endpoint_and_locale(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'exam_session' => [
                        'id' => 'opaque-session-17',
                        'exam_date' => '2026-08-30',
                        'test_center' => [
                            'id' => 17,
                            'name' => 'Bangladesh Korea TTC Dhaka',
                            'city' => 'Dhaka',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())
            ->withToken('test-token')
            ->examSession('opaque-session-17');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('opaque-session-17', data_get($response->getData(true), 'data.exam_session.id'));

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/individual_labor_space/exam_sessions/opaque-session-17')
                && ($query['locale'] ?? null) === 'en'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request->hasHeader('X-Tenant-Name', 'svp-international');
        });
    }

    public function test_payment_status_normalizes_hyperpay_v1_resource_path_without_double_prefix(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'result' => ['code' => '000.100.110', 'description' => 'Request successfully processed'],
            ], 200),
        ]);

        $response = (new TakamolProvider())
            ->withToken('test-token')
            ->getPaymentStatus('/v1/checkouts/TEST-NDC/payment');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('000.100.110', data_get($response->getData(true), 'result.code'));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/api/v1/checkouts/TEST-NDC/payment'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
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

    public function test_sessions_preserve_all_four_exact_ids_from_a_center_rows_payload(): void
    {
        $sessionIds = [
            'session-first-shift',
            'session-second-shift',
            'session-third-shift',
            'session-fourth-shift',
        ];

        Http::fake([
            'https://svp.test/*' => Http::response([
                'centers' => array_map(
                    static fn (string $id, int $index): array => [
                        'available_seats' => 1,
                        'category_id' => 159,
                        'exam_session_id' => $id,
                        'test_center_id' => 181,
                        'test_center_name' => 'Narail Technical Training Centre',
                        'test_time' => sprintf('%02d:30 PM', $index + 1),
                    ],
                    $sessionIds,
                    array_keys($sessionIds),
                ),
            ], 200),
        ]);

        $payload = (new TakamolProvider())->withToken('test-token')->examSessions([
            'city' => 'Khulna',
            'category_id' => '159',
            'test_center_id' => '181',
            'exam_date' => '2026-08-30',
        ])->getData(true);

        $this->assertSame($sessionIds, array_column($payload['data']['sessions'], 'id'));
        $this->assertSame($sessionIds, array_column($payload['data']['sessions'], 'exam_session_id'));
        $this->assertSame(['181', '181', '181', '181'], array_column($payload['data']['sessions'], 'test_center_id'));
        $this->assertSame(['2026-08-30', '2026-08-30', '2026-08-30', '2026-08-30'], array_column($payload['data']['sessions'], 'exam_date'));
        $this->assertCount(4, $payload['data']['exam_sessions']);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['per_page'] ?? null) === '1000'
                && ($query['test_center_id'] ?? null) === '181'
                && ($query['exam_date'] ?? null) === '2026-08-30'
                && ($query['available_seats'] ?? null) === 'greater_than::0';
        });
    }

    public function test_sessions_preserve_timezone_start_time_and_named_shift_priority(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'exam_sessions' => [
                        [
                            'id' => 'second-shift-session',
                            'name' => 'Second Shift',
                            'start_at_in_tc_time_zone' => '2026-08-25T13:30:00+03:00',
                        ],
                        [
                            'id' => 'first-shift-session',
                            'name' => 'First Shift',
                            'start_at_in_browser_time_zone' => '2026-08-25T09:30:00+03:00',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $sessions = (new TakamolProvider())->withToken('test-token')->examSessions([
            'city' => 'Khulna',
            'category_id' => '159',
            'test_center_id' => '171',
            'exam_date' => '2026-08-25',
        ])->getData(true)['data']['sessions'];

        $this->assertSame('2026-08-25T13:30:00+03:00', $sessions[0]['test_time']);
        $this->assertSame(2, $sessions[0]['session_priority']);
        $this->assertSame('2026-08-25T09:30:00+03:00', $sessions[1]['test_time']);
        $this->assertSame(1, $sessions[1]['session_priority']);
    }

    public function test_date_specific_sessions_only_return_the_requested_date(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'success' => true,
                'data' => [
                    'exam_sessions' => [
                        [
                            'id' => 'session-selected-date',
                            'exam_date' => '2026-08-24',
                            'test_center' => ['id' => 'center-45', 'name' => 'Bangladesh German TTC', 'city' => 'Khulna'],
                        ],
                        [
                            'id' => 'session-other-date',
                            'exam_date' => '2026-08-25',
                            'test_center' => ['id' => 'center-45', 'name' => 'Bangladesh German TTC', 'city' => 'Khulna'],
                        ],
                        [
                            'id' => 'opaque-session-selected-date',
                            'test_center' => ['id' => 'center-45', 'name' => 'Bangladesh German TTC', 'city' => 'Khulna'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $payload = (new TakamolProvider())->withToken('test-token')->examSessions([
            'city' => 'Khulna',
            'category_id' => '159',
            'test_center_id' => 'center-45',
            'exam_date' => '2026-08-24',
        ])->getData(true);

        $sessions = $payload['data']['sessions'];
        $this->assertSame([
            'session-selected-date',
            'opaque-session-selected-date',
        ], array_column($sessions, 'id'));
        $this->assertSame('2026-08-24', $sessions[1]['exam_date']);
        $this->assertStringNotContainsString('session-other-date', json_encode($sessions));

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['exam_date'] ?? null) === '2026-08-24'
                && ($query['test_center_id'] ?? null) === 'center-45'
                && ($query['available_seats'] ?? null) === 'greater_than::0';
        });
    }

    public function test_sessions_extract_nested_json_api_items_and_attributes_metadata(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'items' => [[
                        'type' => 'exam_session',
                        'attributes' => [
                            'id' => 'json-api-session-45',
                            'start_date_in_browser_time_zone' => '2026-08-24T09:00:00+06:00',
                            'test_center' => [
                                'data' => [
                                    'attributes' => [
                                        'id' => 45,
                                        'name' => 'Bangladesh German TTC',
                                        'city' => 'Khulna',
                                    ],
                                ],
                            ],
                        ],
                    ]],
                ],
            ], 200),
        ]);

        $payload = (new TakamolProvider())->withToken('test-token')->examSessions([
            'city' => 'Khulna',
            'category_id' => '159',
            'test_center_id' => '45',
            'exam_date' => '2026-08-24',
        ])->getData(true);

        $session = $payload['data']['sessions'][0];
        $this->assertSame('json-api-session-45', $session['id']);
        $this->assertSame('45', $session['test_center_id']);
        $this->assertSame('Bangladesh German TTC', $session['test_center_name']);
        $this->assertSame('Khulna', $session['test_center_city']);
        $this->assertSame('2026-08-24', $session['exam_date']);
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

    public function test_centers_normalize_nested_json_api_resources(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'test_centers' => [
                        'data' => [[
                            'type' => 'test_center',
                            'attributes' => [
                                'id' => 223,
                                'name' => 'Khulna Technical Training Centre',
                                'city' => 'Khulna',
                            ],
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $payload = (new TakamolProvider())->withToken('test-token')->testCentersForFilters('Khulna', '159')->getData(true);

        $this->assertSame([
            [
                'id' => '223',
                'name' => 'Khulna Technical Training Centre',
                'city' => 'Khulna',
                'address' => null,
                'status' => null,
                'country_code' => null,
            ],
        ], $payload['data']);
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

    public function test_center_session_lookup_loads_every_available_date_for_the_selected_center(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/exam_sessions/available_dates')) {
                return Http::response([
                    'available_dates' => [
                        [
                            'exam_date' => '2026-08-20',
                            'test_center' => ['id' => 223, 'name' => 'Manikganj Technical Training Center', 'city' => 'Dhaka'],
                        ],
                        [
                            'exam_date' => '2026-08-24',
                            'test_center' => ['id' => 17, 'name' => 'Bangladesh Korea TTC Dhaka', 'city' => 'Dhaka'],
                        ],
                        [
                            'exam_date' => '2026-08-25',
                            'test_center' => ['id' => 223, 'name' => 'Manikganj Technical Training Center', 'city' => 'Dhaka'],
                        ],
                    ],
                ], 200);
            }

            $date = $query['exam_date'] ?? null;
            return Http::response([
                'data' => [
                    'exam_sessions' => [[
                        'id' => 'session-'.$date,
                        'start_date_in_browser_time_zone' => $date,
                    ]],
                ],
            ], 200);
        });

        $payload = (new TakamolProvider())->withToken('test-token')->examSessionsForCenter([
            'city' => 'Dhaka',
            'category_id' => 'category-4',
            'test_center_id' => '223',
        ])->getData(true);

        $sessions = $payload['data']['sessions'];
        $this->assertCount(2, $sessions);
        $this->assertSame(['session-2026-08-20', 'session-2026-08-25'], array_column($sessions, 'id'));
        $this->assertSame(['2026-08-20', '2026-08-25'], array_column($sessions, 'exam_date'));
        $this->assertCount(2, $payload['data']['available_dates']);
        $this->assertSame('223', $payload['data']['available_dates'][0]['test_center_id']);

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with($path, '/exam_sessions')
                && ! str_ends_with($path, '/available_dates')
                && in_array($query['exam_date'] ?? null, ['2026-08-20', '2026-08-25'], true)
                && ($query['test_center_id'] ?? null) === '223'
                && ($query['available_seats'] ?? null) === 'greater_than::0';
        });
    }

    public function test_center_session_lookup_probes_bare_and_centerless_available_dates(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/exam_sessions/available_dates')) {
                return Http::response([
                    'data' => [
                        'available_dates' => [
                            '2026-08-22',
                            ['date' => '2026-08-23'],
                        ],
                    ],
                ], 200);
            }

            $date = $query['exam_date'] ?? null;
            return Http::response([
                'data' => [
                    'exam_sessions' => $date === '2026-08-23' ? [[
                        'id' => 'session-2026-08-23',
                    ]] : [],
                ],
            ], 200);
        });

        $payload = (new TakamolProvider())->withToken('test-token')->examSessionsForCenter([
            'city' => 'Dhaka',
            'category_id' => 'category-4',
            'test_center_id' => '17',
        ])->getData(true);

        $this->assertSame(['session-2026-08-23'], array_column($payload['data']['sessions'], 'id'));
        $this->assertSame(['2026-08-23'], array_column($payload['data']['sessions'], 'exam_date'));
        $this->assertSame(['17'], array_column($payload['data']['sessions'], 'test_center_id'));
        $this->assertSame(['2026-08-23'], array_column($payload['data']['available_dates'], 'exam_date'));

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with($path, '/exam_sessions')
                && ! str_ends_with($path, '/available_dates')
                && in_array($query['exam_date'] ?? null, ['2026-08-22', '2026-08-23'], true)
                && ($query['test_center_id'] ?? null) === '17';
        });
    }

    public function test_explicit_date_lookup_bypasses_aggregate_metadata(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/exam_sessions/available_dates')) {
                return Http::response(['available_dates' => []], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'data' => [
                    'exam_sessions' => [
                        [
                            'id' => 'session-explicit-2026-08-19-first',
                            'test_center_id' => '17',
                        ],
                        [
                            'id' => 'session-explicit-2026-08-19-second',
                            'test_center_id' => '17',
                        ],
                    ],
                ],
            ], 200);
        });

        $payload = (new TakamolProvider())->withToken('test-token')->examSessionsForCenter([
            'city' => 'Dhaka',
            'category_id' => 'category-4',
            'test_center_id' => '17',
            'exam_date' => '2026-08-19',
        ])->getData(true);

        $this->assertSame(
            ['session-explicit-2026-08-19-first', 'session-explicit-2026-08-19-second'],
            array_column($payload['data']['sessions'], 'id'),
        );
        $this->assertSame(
            ['2026-08-19', '2026-08-19'],
            array_column($payload['data']['sessions'], 'exam_date'),
        );
        $this->assertSame(
            ['17', '17'],
            array_column($payload['data']['sessions'], 'test_center_id'),
        );
        Http::assertNotSent(function ($request): bool {
            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/exam_sessions/available_dates');
        });
    }

    public function test_center_session_lookup_backfills_dates_missing_from_available_metadata(): void
    {
        Carbon::setTestNow('2026-08-22 12:00:00');
        config()->set('svp.session_date_probe_backfill_days', 14);
        $validDates = ['2026-08-23', '2026-08-24', '2026-08-25'];

        Http::fake(function ($request) use ($validDates) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/exam_sessions/available_dates')) {
                return Http::response([
                    'available_dates' => [[
                        'exam_date' => '2026-08-25',
                        'test_center' => ['id' => 17, 'name' => 'Bangladesh Korea TTC Dhaka', 'city' => 'Dhaka'],
                    ]],
                ], 200);
            }

            $date = $query['exam_date'] ?? null;
            return Http::response([
                'data' => [
                    'exam_sessions' => in_array($date, $validDates, true) ? [[
                        'id' => 'session-'.$date,
                        'start_date_in_browser_time_zone' => $date,
                    ]] : [],
                ],
            ], 200);
        });

        $payload = (new TakamolProvider())->withToken('test-token')->examSessionsForCenter([
            'city' => 'Dhaka',
            'category_id' => 'category-4',
            'test_center_id' => '17',
        ])->getData(true);

        $this->assertSame(
            $validDates,
            array_column($payload['data']['sessions'], 'exam_date'),
        );
        $this->assertSame(
            $validDates,
            array_column($payload['data']['available_dates'], 'exam_date'),
        );
        $this->assertSame(
            array_fill(0, count($validDates), '17'),
            array_column($payload['data']['sessions'], 'test_center_id'),
            'Every backfilled session must remain bound to the selected Center 17.',
        );

        Http::assertSent(function ($request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with($path, '/exam_sessions')
                && ! str_ends_with($path, '/available_dates')
                && in_array($query['exam_date'] ?? null, ['2026-08-23', '2026-08-24'], true)
                && ($query['test_center_id'] ?? null) === '17'
                && ($query['available_seats'] ?? null) === 'greater_than::0';
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
                && ($query['category_id'] ?? null) === 'category-4'
                && ($query['city'] ?? null) === 'Dhaka';
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

    public function test_categories_normalizes_nested_live_catalog_envelope(): void
    {
        Http::fake([
            'https://svp.test/*' => Http::response([
                'data' => [
                    'items' => [
                        [
                            'id' => 2061,
                            'attributes' => [
                                'name' => 'Load and Unload Worker',
                                'categories' => [
                                    'data' => [[
                                        'id' => 159,
                                        'attributes' => [
                                            'english_name' => 'Load and unload workers',
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = (new TakamolProvider())->withToken('test-token')->categories();

        $this->assertSame([
            [
                'id' => '159',
                'attributes' => ['english_name' => 'Load and unload workers'],
                'name' => 'Load and unload workers',
            ],
        ], $response->getData(true)['data']);
    }

}
