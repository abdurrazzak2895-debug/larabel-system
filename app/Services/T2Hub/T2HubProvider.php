<?php

namespace App\Services\T2Hub;

/**
 * Booking catalogue served by the T2Hub agent API.
 *
 * The chain is:
 *   occupations (PACC category ids)
 *     → cities that have availability for the category
 *       → available exam dates
 *         → test centres that actually hold sessions on that date
 *           → exam sessions with their times and seat counts
 *
 * Responses are normalised so a session always carries the centre id
 * (`site_id` / `test_center.id`) the booking flow needs for a hold.
 */
class T2HubProvider
{
    public function __construct(private readonly T2HubClient $client)
    {
    }

    public function client(): T2HubClient
    {
        return $this->client;
    }

    /**
     * PACC occupation catalogue (carries both the T2Hub category id and the
     * SVP occupation id the reservation calls expect).
     *
     * @return list<array<string, mixed>>
     */
    public function occupations(?string $search = null): array
    {
        $payload = $this->client->get('/pacc/occupations', [
            'per_page' => 10000,
            'exclude_ignored' => 1,
            'search' => $search,
        ]);

        $list = $payload['occupations'] ?? $payload;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /**
     * Test centres in a city/division.
     *
     * @return list<array<string, mixed>>
     */
    public function testCenters(string $city): array
    {
        $payload = $this->client->get('/test-centers', ['division' => $city]);

        $sites = $payload['sites'] ?? $payload['test_centers'] ?? $payload;

        return is_array($sites) ? array_values(array_filter($sites, 'is_array')) : [];
    }

    /**
     * Dates that have sessions for the category in the city.
     *
     * Upstream returns `available_dates[]` keyed by the browser time zone; a
     * normalised `dates[]` list is added for the booking page.
     *
     * @return array<string, mixed>
     */
    public function availableDates(string $categoryId, string $city): array
    {
        $payload = $this->client->get('/exam-available-dates', [
            'category_id' => $categoryId,
            'city' => $city,
        ]);

        $dates = [];

        foreach ((array) ($payload['available_dates'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = (string) ($row['start_date_in_browser_time_zone'] ?? $row['start_date_in_tc_time_zone'] ?? '');

            if ($date === '') {
                continue;
            }

            $dates[$date] = [
                'date' => $date,
                'test_center_date' => (string) ($row['start_date_in_tc_time_zone'] ?? $date),
                'city' => (string) ($row['test_center']['city'] ?? $city),
                'country_id' => $row['test_center']['country_id'] ?? null,
            ];
        }

        ksort($dates);
        $payload['dates'] = array_values($dates);
        $payload['count'] = count($dates);

        return $payload;
    }

    /**
     * Cities that can be booked.
     *
     * With a category id, only cities that actually publish dates for it are
     * returned (that is what the booking chain needs); otherwise every city
     * that has a test centre.
     *
     * @return list<string>
     */
    public function cities(?string $categoryId = null): array
    {
        if ($categoryId !== null && trim($categoryId) !== '') {
            $cities = [];

            foreach ((array) ($this->client->get('/exam-available-dates', ['category_id' => $categoryId])['available_dates'] ?? []) as $row) {
                $city = trim((string) ($row['test_center']['city'] ?? ''));

                if ($city !== '') {
                    $cities[$city] = true;
                }
            }

            if ($cities !== []) {
                $list = array_keys($cities);
                sort($list);

                return $list;
            }
        }

        $cities = [];

        foreach ($this->allTestCenters() as $site) {
            foreach (['raw_city', 'city', 'division'] as $key) {
                $city = trim((string) ($site[$key] ?? ''));

                if ($city !== '') {
                    $cities[$city] = true;
                    break;
                }
            }
        }

        $list = array_keys($cities);
        sort($list);

        return $list;
    }

    /**
     * Every test centre the portal knows about.
     *
     * @return list<array<string, mixed>>
     */
    public function allTestCenters(): array
    {
        $payload = $this->client->get('/test-centers');
        $sites = $payload['sites'] ?? $payload;

        return is_array($sites) ? array_values(array_filter($sites, 'is_array')) : [];
    }

    /**
     * Sessions for a category/city/date, with the centre resolved from the
     * city's centre list and the list narrowed to centres that have sessions.
     *
     * @return array{sessions: list<array<string, mixed>>, sites: list<array<string, mixed>>, raw: array<string, mixed>}
     */
    public function sessions(string $categoryId, string $city, string $examDate, ?string $testCenterId = null): array
    {
        $centers = [];
        $centerByName = [];

        try {
            $centers = $this->testCenters($city);

            foreach ($centers as $center) {
                $name = strtolower(trim((string) ($center['name'] ?? '')));

                if ($name !== '') {
                    $centerByName[$name] = $center;
                }
            }
        } catch (\Throwable) {
            // Centre names are an enrichment only; sessions still resolve by id.
        }

        $raw = $this->client->get('/pacc-exam-sessions', [
            'category_id' => $categoryId,
            'city' => $city,
            'exam_date' => $examDate,
            'auto_fix_search' => 1,
        ]);

        $sessions = [];

        foreach ((array) ($raw['sessions'] ?? []) as $item) {
            if (is_array($item)) {
                $sessions[] = $this->normalizeSession($item, $centerByName);
            }
        }

        $activeCenterIds = [];

        foreach ($sessions as $session) {
            $id = trim((string) ($session['site_id'] ?? $session['test_center']['id'] ?? ''));

            if ($id !== '') {
                $activeCenterIds[$id] = true;
            }
        }

        $sites = array_values(array_filter($centers, static function (array $center) use ($activeCenterIds, $testCenterId) {
            $id = trim((string) ($center['id'] ?? $center['test_center_id'] ?? ''));

            if ($id === '') {
                return false;
            }

            return $testCenterId !== null ? $id === $testCenterId : isset($activeCenterIds[$id]);
        }));

        return [
            'sessions' => $sessions,
            'sites' => $sites,
            'raw' => $raw,
        ];
    }

    /**
     * Add the resolved centre identity to one upstream session row.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $centerByName
     * @return array<string, mixed>
     */
    private function normalizeSession(array $item, array $centerByName): array
    {
        $centerName = trim((string) ($item['center_name'] ?? $item['test_center_name'] ?? ''));
        $center = $centerName !== '' ? ($centerByName[strtolower($centerName)] ?? null) : null;

        $siteId = trim((string) ($center['id'] ?? $center['center'] ?? $item['site_id'] ?? $item['test_center_id'] ?? ''));
        $sessionId = trim((string) ($item['encrypted_session_id'] ?? $item['id'] ?? $item['exam_session_id'] ?? $item['session_id'] ?? ''));
        $city = (string) ($item['center_city'] ?? $center['raw_city'] ?? $center['division'] ?? '');

        $testCenter = array_merge((array) ($item['test_center'] ?? []), array_filter([
            'id' => $siteId,
            'site_id' => $siteId,
            'test_center_id' => $siteId,
            'name' => $centerName ?: null,
            'test_center_name' => $centerName ?: null,
        ], static fn ($value) => $value !== null && $value !== ''));

        $testCenter['test_center_city'] = $city;
        $testCenter['city'] = $city;

        return array_merge($item, [
            'id' => $sessionId,
            'exam_session_id' => $sessionId,
            'numeric_session_id' => $item['session_id'] ?? null,
            'site_id' => $siteId ?: null,
            'site_city' => $city,
            'test_center' => $testCenter,
        ]);
    }
}
