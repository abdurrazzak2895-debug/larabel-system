<?php

use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$needle = strtolower((string) (getenv('PROBE_CENTER') ?: 'bogur'));
$wantedCity = (string) (getenv('PROBE_CITY') ?: '');
$provider = app(T2HubProvider::class);

$categories = [];
foreach ((array) $provider->occupations() as $occupation) {
    if (is_array($occupation) && ($id = (string) ($occupation['id'] ?? '')) !== '') {
        $categories[$id] = (string) ($occupation['name'] ?? '');
    }
}

$cities = [];
foreach ((array) $provider->cities() as $row) {
    $name = is_array($row) ? (string) ($row['name'] ?? $row['city'] ?? '') : (string) $row;
    if ($name !== '' && ($wantedCity === '' || str_contains(strtolower($name), strtolower($wantedCity)))) {
        $cities[] = $name;
    }
}

$seen = [];
foreach ($cities as $city) {
    foreach (array_keys($categories) as $categoryId) {
        try {
            $dates = (array) $provider->availableDates((string) $categoryId, $city);
        } catch (Throwable $e) {
            continue;
        }

        $days = [];
        foreach ($dates as $row) {
            $value = is_array($row) ? ($row['start_date_in_browser_time_zone'] ?? null) : $row;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                $days[] = substr($value, 0, 10);
            }
        }
        $days = array_values(array_unique($days));
        sort($days);
        if ($days === []) {
            continue;
        }

        try {
            $sessions = (array) ($provider->sessions((string) $categoryId, $city, $days[0])['sessions'] ?? []);
        } catch (Throwable $e) {
            continue;
        }

        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            $name = (string) ($session['center_name'] ?? $session['site_name'] ?? $session['test_center']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $id = (string) ($session['site_id'] ?? $session['test_center_id'] ?? $session['test_center']['id'] ?? '');
            $seen[$id.'|'.$name.'|'.$city.'|'.$categoryId.'|'.$days[0]] = true;

            if (str_contains(strtolower($name), $needle)) {
                echo $say('FOUND centre="'.$name.'" id='.$id);
                echo $say('  city='.$city.' category='.$categoryId.' date='.$days[0].' time='.(string) ($session['exam_time'] ?? '').' status='.(string) ($session['status'] ?? '').' seats='.(string) ($session['available_seats'] ?? ''));

                return;
            }
        }
    }
}

echo $say('sampled centre ids:');
foreach (array_slice(array_keys($seen), 0, 25) as $row) {
    echo $say('  '.$row);
}
echo $say('total sampled = '.count($seen).' | '.$needle.' not present');
