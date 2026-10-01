<?php

use App\Services\T2Hub\T2HubBookingData;
use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$provider = app(T2HubProvider::class);
$bridge = app(T2HubBookingData::class);

$categoryId = (string) (getenv('PROBE_CATEGORY') ?: '50');
$city = (string) (getenv('PROBE_CITY') ?: 'Rajshahi');
$date = (string) (getenv('PROBE_DATE') ?: '2026-10-06');

$dates = (array) $provider->availableDates($categoryId, $city);
$row = $dates[array_key_first($dates)] ?? null;
echo $say('available_dates row keys = '.(is_array($row) ? implode(', ', array_keys($row)) : 'none'));
echo $say('available_dates row = '.substr((string) json_encode($row), 0, 900));
echo $say('');

$payload = (array) $provider->sessions($categoryId, $city, $date);
echo $say('sessions payload keys = '.implode(', ', array_keys($payload)));
$sessions = (array) ($payload['sessions'] ?? []);
echo $say('sessions count = '.count($sessions));
$first = $sessions[array_key_first($sessions)] ?? null;
if (is_array($first)) {
    echo $say('session keys = '.implode(', ', array_keys($first)));
    echo $say('session raw = '.substr((string) json_encode($first), 0, 900));
    foreach ($first as $key => $value) {
        if (is_array($value)) {
            echo $say('  '.$key.' = {'.implode(', ', array_keys($value)).'}');
        }
    }
}
echo $say('');
echo $say('bridge centersForDate = '.substr((string) json_encode($bridge->centersForDate($categoryId, $city, $date)), 0, 700));
echo $say('bridge sessionRows count = '.count($bridge->sessionRows($categoryId, $city, $date)));
$rows = $bridge->sessionRows($categoryId, $city, $date);
if ($rows !== []) {
    echo $say('first sessionRow = '.substr((string) json_encode($rows[0]), 0, 700));
}
