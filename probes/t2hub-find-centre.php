<?php

use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$needle = strtolower((string) (getenv('PROBE_CENTER') ?: 'bogur'));
$provider = app(T2HubProvider::class);

$categories = [];
foreach ((array) $provider->occupations() as $occupation) {
    if (is_array($occupation) && ($id = (string) ($occupation['id'] ?? '')) !== '') {
        $categories[$id] = (string) ($occupation['name'] ?? '');
    }
}
$categoryIds = array_slice(array_keys($categories), 0, 6);

$cities = [];
foreach ((array) $provider->cities() as $row) {
    $name = is_array($row) ? (string) ($row['name'] ?? $row['city'] ?? '') : (string) $row;
    if ($name !== '') {
        $cities[] = $name;
    }
}

foreach ($cities as $city) {
    foreach ($categoryIds as $categoryId) {
        try {
            $dates = (array) $provider->availableDates((string) $categoryId, $city);
        } catch (Throwable $e) {
            continue;
        }

        $days = [];
        foreach ($dates as $row) {
            $value = is_array($row) ? ($row['start_date_in_browser_time_zone'] ?? $row['date'] ?? null) : $row;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                $days[] = substr($value, 0, 10);
            }
        }
        $days = array_values(array_unique($days));
        sort($days);

        if ($days === []) {
            continue;
        }

        foreach (array_slice($days, 0, 2) as $day) {
            try {
                $sessions = (array) ($provider->sessions((string) $categoryId, $city, $day)['sessions'] ?? []);
            } catch (Throwable $e) {
                continue;
            }

            $names = [];
            foreach ($sessions as $session) {
                if (! is_array($session)) {
                    continue;
                }
                $name = (string) ($session['center_name'] ?? $session['site_name'] ?? $session['test_center']['name'] ?? '');
                $id = (string) ($session['site_id'] ?? $session['test_center_id'] ?? $session['test_center']['id'] ?? '');
                if ($name !== '') {
                    $names[] = $id.'|'.$name.'|'.(string) ($session['exam_time'] ?? '').'|'.(string) ($session['status'] ?? '');
                }
                if ($name !== '' && str_contains(strtolower($name), $needle)) {
                    echo $say('FOUND city='.$city.' category='.$categoryId.' date='.$day);
                    echo $say('  centre id='.$id.' name="'.$name.'" time='.(string) ($session['exam_time'] ?? '').' status='.(string) ($session['status'] ?? ''));
                    echo $say('  session='.substr((string) ($session['exam_session_id'] ?? ''), 0, 12).'…');

                    return;
                }
            }
            echo $say($city.' / '.$categoryId.' / '.$day.' centres='.implode(' ; ', array_slice(array_unique($names), 0, 5)));
        }
    }
}

echo $say('centre not found in sampled city/category/date combinations');
