<?php

/**
 * Find which T2Hub city/date surfaces the Bogura Technical Training Centre and
 * print the raw centre fields of its sessions (read-only).
 *
 * Usage: php artisan tinker probes/t2hub-bogura-city.php
 */

use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$provider = app(T2HubProvider::class);

$categories = [];
foreach ((array) $provider->occupations() as $occupation) {
    if (is_array($occupation) && ($id = (string) ($occupation['id'] ?? '')) !== '') {
        $categories[$id] = (string) ($occupation['name'] ?? '');
    }
}
$categoryIds = array_slice(array_keys($categories), 0, 3);
echo $say('probe categories = '.implode(', ', array_map(static fn ($id) => $id.'('.$categories[$id].')', $categoryIds)));

$cities = [];
foreach ((array) $provider->cities() as $row) {
    $name = is_array($row) ? (string) ($row['name'] ?? $row['city'] ?? '') : (string) $row;
    if ($name !== '') {
        $cities[] = $name;
    }
}
echo $say('cities = '.implode(', ', $cities));

foreach ($cities as $city) {
    foreach ($categoryIds as $categoryId) {
        try {
            $dates = (array) $provider->availableDates($categoryId, $city);
        } catch (Throwable $e) {
            echo $say($city.' / '.$categoryId.' dates threw '.substr($e->getMessage(), 0, 60));
            continue;
        }

        $days = [];
        foreach ($dates as $row) {
            $candidate = is_array($row) ? ($row['date'] ?? $row['exam_date'] ?? null) : $row;
            if (is_string($candidate) && preg_match('/^\d{4}-\d{2}-\d{2}/', $candidate) === 1) {
                $days[] = substr($candidate, 0, 10);
            }
        }
        $days = array_values(array_unique($days));
        sort($days);

        echo $say($city.' / '.$categoryId.' dates='.count($days).' first='.implode(',', array_slice($days, 0, 3)).' raw='.substr((string) json_encode($dates), 0, 120));

        if ($days === []) {
            continue;
        }

        try {
            $sessions = (array) ($provider->sessions((string) $categoryId, $city, $days[0])['sessions'] ?? []);
        } catch (Throwable $e) {
            echo $say('  sessions threw '.substr($e->getMessage(), 0, 70));
            continue;
        }

        $names = [];
        $bogura = null;
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            $name = (string) ($session['center_name'] ?? $session['site_name'] ?? $session['test_center']['name'] ?? '');
            $id = (string) ($session['site_id'] ?? $session['test_center_id'] ?? $session['test_center']['id'] ?? '');
            if ($name !== '') {
                $names[$id.'|'.$name] = true;
            }
            if ($name !== '' && str_contains(strtolower($name), 'bogur') && $bogura === null) {
                $bogura = ['id' => $id, 'name' => $name, 'keys' => array_keys($session), 'session' => $session];
            }
        }
        echo $say('  sessions='.count($sessions).' centres='.implode(' ; ', array_slice(array_keys($names), 0, 6)));
        if ($bogura !== null) {
            echo $say('  BOGURA id='.$bogura['id'].' name="'.$bogura['name'].'"');
            echo $say('  raw keys = '.implode(', ', $bogura['keys']));
            echo $say('  raw = '.substr((string) json_encode($bogura['session']), 0, 700));
            return;
        }
    }
}

echo $say('Bogura centre not present in these city/category samples');
