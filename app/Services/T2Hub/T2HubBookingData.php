<?php

namespace App\Services\T2Hub;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bridges the live T2Hub catalogue into the shapes the booking and reschedule
 * pages already consume.
 *
 * `PortalAvailabilityService` feeds those pages from the read-only Portal
 * Availability mirror; when `BOOKING_DATA_SOURCE=t2hub` the same methods are
 * served from the T2Hub agent portal instead, so occupation → category → city →
 * available date → test centre → exam session (with time and seats) is exactly
 * what the portal reports. Nothing else in the pages has to change.
 *
 * Chain mapping (T2Hub → page):
 *   occupation  id 50            → occupation_id 50, category_id 50
 *   english_name "Barber"        → name / category_name
 *   language_code "BRBBB"        → languages[0]
 *   available_dates[].test_center.city + date → bookingDates rows {city, date}
 *   pacc-exam-sessions sessions  → centre rows {test_center_id, name, date, seats, session_ids}
 */
final class T2HubBookingData
{
    public function __construct(private readonly T2HubProvider $provider) {}

    /**
     * Whether the booking pages should read the live T2Hub catalogue.
     */
    public function enabled(): bool
    {
        return config('t2hub.data_source') === 't2hub';
    }

    /**
     * Occupation rows in the shape the booking pages expect.
     *
     * @return array<int, array<string, mixed>>
     */
    public function occupations(?string $search = null): array
    {
        $rows = [];

        foreach ($this->provider->occupations() as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            // T2Hub returns the category id in `id` and the actual occupation
            // id separately. Keep both: language/occupation lookups use the
            // occupation id, while availability searches use the category id.
            $categoryId = trim((string) ($occupation['id'] ?? ''));
            $occupationId = trim((string) ($occupation['occupation_id'] ?? $categoryId));
            $name = trim((string) ($occupation['english_name'] ?? $occupation['category_name'] ?? ''));

            if ($categoryId === '' || $occupationId === '' || $name === '') {
                continue;
            }

            $languageCode = strtoupper(trim((string) ($occupation['language_code'] ?? '')));
            $occupationKey = trim((string) ($occupation['occupation_key'] ?? ''));
            $nameKey = Str::of($name)->lower()->replaceMatches('/\s+/', ' ')->trim()->toString();
            // T2Hub can return multiple occupation labels for one category id.
            // Keep each label; the numeric id remains the booking value.
            $rowKey = $occupationId.'|'.$categoryId.'|'.($occupationKey !== '' ? $occupationKey : $nameKey);

            $rows[$rowKey] = [
                'id' => $occupationId,
                'occupation_id' => $occupationId,
                'category_id' => $categoryId,
                'name' => $name,
                'english_name' => $name,
                'category_name' => trim((string) ($occupation['category_name'] ?? $name)) ?: $name,
                'occupation_key' => $occupationKey !== '' ? $occupationKey : $nameKey,
                'language_code' => $languageCode,
                'languages' => $languageCode === '' ? [] : [[
                    'code' => $languageCode,
                    'name' => $this->languageName($languageCode),
                ]],
                'source' => 't2hub',
            ];
        }

        ksort($rows);

        $term = Str::lower(trim((string) $search));

        if ($term !== '') {
            $rows = array_filter(
                $rows,
                static fn (array $row): bool => Str::contains(Str::lower((string) $row['name']), $term),
            );
        }

        return array_values($rows);
    }

    /**
     * City + date rows for a category, taken from the portal's available dates.
     *
     * @return array<int, array{city: string, date: string}>
     */
    public function dateRows(int|string $categoryId): array
    {
        $categoryId = trim((string) $categoryId);

        if ($categoryId === '') {
            return [];
        }

        $payload = $this->provider->availableDates($categoryId, '');
        $rows = [];

        foreach ((array) ($payload['dates'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $city = trim((string) ($row['city'] ?? ''));
            $date = trim((string) ($row['date'] ?? ''));

            if ($city === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $rows[Str::lower($city) . '|' . $date] = ['city' => $city, 'date' => $date];
        }

        return array_values($rows);
    }

    /**
     * Test centres that hold a session on the date, in the shape the page
     * renders (id, name, seats, session ids).
     *
     * @return array<int, array<string, mixed>>
     */
    public function centersForDate(int|string $categoryId, string $city, string $date): array
    {
        $payload = $this->provider->sessions((string) $categoryId, $city, $date);
        $merged = [];

        foreach ((array) ($payload['sessions'] ?? []) as $session) {
            if (! is_array($session)) {
                continue;
            }

            $centerId = trim((string) ($session['site_id'] ?? $session['test_center']['id'] ?? ''));
            $centerName = trim((string) ($session['center_name'] ?? $session['test_center']['name'] ?? ''));

            if ($centerId === '' || $centerName === '') {
                continue;
            }

            $sessionId = trim((string) ($session['exam_session_id'] ?? $session['session_id'] ?? ''));
            $time = trim((string) ($session['exam_time'] ?? ''));
            $seats = (int) ($session['available_seats'] ?? 0);
            $key = Str::lower($centerId . '|' . $date);

            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'id' => $centerId,
                    'test_center_id' => $centerId,
                    'name' => $centerName,
                    'test_center_name' => $centerName,
                    'city' => $city,
                    'date' => $date,
                    'available_seats' => $seats,
                    'session_ids' => $sessionId !== '' ? [$sessionId] : [],
                    'session_count' => $sessionId !== '' ? 1 : 0,
                    'times' => $time !== '' ? [$time] : [],
                    'time' => $time,
                    'source' => 't2hub',
                ];

                continue;
            }

            $merged[$key]['available_seats'] = max((int) $merged[$key]['available_seats'], $seats);

            if ($sessionId !== '') {
                $merged[$key]['session_ids'] = array_values(array_unique(array_merge(
                    (array) $merged[$key]['session_ids'],
                    [$sessionId],
                )));
                $merged[$key]['session_count'] = count($merged[$key]['session_ids']);
            }

            if ($time !== '' && ! in_array($time, (array) $merged[$key]['times'], true)) {
                $merged[$key]['times'][] = $time;
            }
        }

        return array_values($merged);
    }

    /**
     * Every test centre in a city (used before a date is chosen).
     *
     * @return array<int, array<string, mixed>>
     */
    public function centers(string $city): array
    {
        $rows = [];

        foreach ($this->provider->testCenters($city) as $site) {
            if (! is_array($site)) {
                continue;
            }

            $id = trim((string) ($site['id'] ?? $site['center'] ?? ''));
            $name = trim((string) ($site['name'] ?? ''));

            if ($id === '' || $name === '') {
                continue;
            }

            $rows[$id] = [
                'id' => $id,
                'test_center_id' => $id,
                'name' => $name,
                'test_center_name' => $name,
                'city' => trim((string) ($site['raw_city'] ?? $site['division'] ?? $city)),
                'address' => trim((string) ($site['address'] ?? '')),
                'source' => 't2hub',
            ];
        }

        return array_values($rows);
    }

    /**
     * Exam sessions for one centre + date, shaped for the session renderer
     * (`id`, centre, date, `time`, seats).
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Confirm that an exam-session ID really belongs to the requested centre by
     * re-reading the live T2Hub session list for that city and date. This is the
     * provenance check behind the pre-hold guard: the ID must be present in the
     * centre-scoped list, so a caller cannot point the hold at another centre.
     */
    /**
     * Return the live centre-scoped session row for this exam-session ID, or null
     * when the ID is not part of the requested centre/city/date list.
     *
     * @return array<string, mixed>|null
     */
    public function sessionSnapshot(
        int|string $categoryId,
        string $city,
        string $date,
        string $testCenterId,
        string $examSessionId,
    ): ?array {
        $wanted = trim($examSessionId);
        if ($wanted === '' || trim($city) === '' || trim($date) === '') {
            return null;
        }

        foreach ($this->sessionRows($categoryId, $city, $date, $testCenterId) as $row) {
            foreach (['exam_session_id', 'id', 'session_id'] as $key) {
                if (trim((string) ($row[$key] ?? '')) === $wanted) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function confirmsSessionCenter(
        int|string $categoryId,
        string $city,
        string $date,
        string $testCenterId,
        string $examSessionId,
    ): bool {
        return $this->sessionSnapshot($categoryId, $city, $date, $testCenterId, $examSessionId) !== null;
    }

    /**
     * Warm the catalogue for a city so the operator's next click is instant.
     *
     * Runs after the response is flushed: centres once per city and a session
     * lookup per upcoming date are exactly the calls the wizard would otherwise
     * pay for on the first click.
     *
     * @param  array<int, string|array{date: string, city?: string}>  $dates
     */
    public function warmCityDates(int|string $categoryId, string $city, array $dates): int
    {
        if ((string) config('t2hub.data_source') !== 't2hub') {
            return 0;
        }

        // One warm per city/category per two minutes: enough to keep the first
        // clicks instant without hammering the upstream portal.
        $lock = 't2hub:warm:'.sha1((string) $categoryId.'|'.$city);
        if (! Cache::add($lock, 1, 120)) {
            return 0;
        }

        $warmed = 0;
        try {
            $this->provider->testCenters($city);
        } catch (\Throwable) {
            // Enrichment only.
        }

        // bookingDates() returns rows, not strings. Never cast an array to
        // "Array" (which silently skipped every real date in the old warmup).
        $uniqueDates = [];
        foreach ($dates as $row) {
            $date = trim((string) (is_array($row) ? ($row['date'] ?? '') : $row));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            $uniqueDates[$date] = true;
        }

        // Only warm the first two dates: empty upstream dates can take seconds
        // and PHP-FPM must not be tied up warming every date in a calendar.
        foreach (array_slice(array_keys($uniqueDates), 0, 2) as $date) {
            try {
                $this->provider->sessions((string) $categoryId, $city, $date);
                $warmed++;
            } catch (\Throwable) {
                // A single failed date must not break the prewarm batch.
            }
        }

        return $warmed;
    }

    public function sessionRows(int|string $categoryId, string $city, ?string $date, ?string $testCenterId = null): array
    {
        $date = trim((string) $date);

        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }

        $wantedCenter = trim((string) $testCenterId);
        $rows = [];

        foreach ((array) ($this->provider->sessions((string) $categoryId, $city, $date)['sessions'] ?? []) as $session) {
            if (! is_array($session)) {
                continue;
            }

            $centerId = trim((string) ($session['site_id'] ?? $session['test_center']['id'] ?? ''));

            if ($wantedCenter !== '' && $centerId !== $wantedCenter) {
                continue;
            }

            $time = trim((string) ($session['exam_time'] ?? ''));
            $sessionId = trim((string) ($session['exam_session_id'] ?? $session['id'] ?? ''));
            $centerName = trim((string) ($session['center_name'] ?? $session['test_center']['name'] ?? ''));

            $rows[] = [
                'id' => $sessionId,
                'exam_session_id' => $sessionId,
                'session_id' => $session['session_id'] ?? null,
                'test_center_id' => $centerId,
                'site_id' => $centerId,
                'center_id' => $centerId,
                'center_name' => $centerName,
                'test_center_name' => $centerName,
                'exam_date' => trim((string) ($session['exam_date'] ?? $date)),
                'exam_time' => $time,
                // The session renderer reads `test_time`/`time`; keep every alias.
                'test_time' => $time,
                'time' => $time,
                'session_name' => $time,
                'label' => $time,
                'available_seats' => (int) ($session['available_seats'] ?? 0),
                'status' => $session['status'] ?? null,
                'city' => $city,
                'source' => 't2hub',
                'raw' => $session,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['time'], (string) $b['time']));

        return $rows;
    }

    /**
     * Readable label for a Prometric/T2Hub language code.
     */
    private function languageName(string $code): string
    {
        $known = [
            'LOABB' => 'Bengali',
            'BRBBB' => 'Bengali',
            'LOAEN' => 'English',
        ];

        return $known[$code] ?? (str_ends_with($code, 'BB') ? 'Bengali (' . $code . ')' : $code);
    }
}
