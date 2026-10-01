<?php

/**
 * Print the raw T2Hub shapes the booking wizard consumes for one city, so the
 * centre-id/name normalisation in T2HubBookingData can be matched to reality.
 *
 * Read-only: no hold, reservation, or payment is created.
 *
 * Usage: php artisan tinker probes/t2hub-session-shape.php
 */

use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$city = (string) (getenv('PROBE_CITY') ?: 'Bogura');
$provider = app(T2HubProvider::class);

echo $say('city = '.$city);

// City reference rows
foreach ((array) $provider->cities() as $row) {
    $name = is_array($row) ? (string) ($row['name'] ?? $row['city'] ?? '') : (string) $row;
    if ($name !== '' && str_contains(strtolower($name), strtolower($city))) {
        echo $say('cities() row = '.json_encode($row));
        break;
    }
}

// Test centre reference rows
$centres = (array) $provider->testCenters($city);
echo $say('testCenters('.$city.') count = '.count($centres));
if ($centres !== []) {
    echo $say('first testCenters row = '.json_encode($centres[array_key_first($centres)]));
}

$occupations = (array) $provider->occupations();
$categories = [];
foreach ($occupations as $occupation) {
    if (is_array($occupation) && ($id = (string) ($occupation['id'] ?? '')) !== '') {
        $categories[$id] = (string) ($occupation['name'] ?? '');
    }
}
echo $say('categories = '.count($categories));

$dated = [];
foreach (array_keys($categories) as $categoryId) {
    try {
        $dates = (array) $provider->availableDates($categoryId, $city);
    } catch (Throwable $e) {
        continue;
    }
    if ($dates === []) {
        continue;
    }
    $first = $dates[array_key_first($dates)];
    echo $say('dates sample category='.$categoryId.' ('.$categories[$categoryId].') = '.json_encode($first));
    $days = [];
    foreach ($dates as $row) {
        $day = is_array($row) ? (string) ($row['date'] ?? '') : (string) $row;
        if ($day !== '') {
            $days[] = $day;
        }
    }
    sort($days);
    $dated[$categoryId] = $days;
    if (count($dated) >= 3) {
        break;
    }
}

echo $say('categories with dates = '.implode(', ', array_keys($dated)));

foreach ($dated as $categoryId => $days) {
    foreach (array_slice($days, 0, 2) as $day) {
        try {
            $payload = (array) $provider->sessions($categoryId, $city, $day);
        } catch (Throwable $e) {
            echo $say('category '.$categoryId.' '.$day.' threw: '.substr($e->getMessage(), 0, 80));
            continue;
        }

        $sessions = (array) ($payload['sessions'] ?? []);
        echo $say('');
        echo $say('category='.$categoryId.' date='.$day.' sessions='.count($sessions));
        echo $say('payload keys = '.implode(', ', array_keys($payload)));

        $sample = $sessions[array_key_first($sessions)] ?? null;
        if (is_array($sample)) {
            echo $say('session keys = '.implode(', ', array_keys($sample)));
            foreach ($sample as $key => $value) {
                if (is_array($value)) {
                    echo $say('  '.$key.' = {'.implode(', ', array_keys($value)).'} => '.substr(json_encode($value), 0, 200));
                } else {
                    echo $say('  '.$key.' = '.substr((string) $value, 0, 90));
                }
            }
        }

        // Are Bogura sessions present at all in this payload?
        $bogura = 0;
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            if (str_contains(strtolower(json_encode($session) ?: ''), 'bogur')) {
                $bogura++;
            }
        }
        echo $say('sessions mentioning bogura = '.$bogura);

        return;
    }
}

echo $say('no sessions returned for '.$city.' on any sampled category');