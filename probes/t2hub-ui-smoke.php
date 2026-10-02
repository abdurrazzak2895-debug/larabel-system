<?php

/**
 * Read-only production smoke test for the T2Hub booking wizard.
 * Run: php artisan tinker probes/t2hub-ui-smoke.php
 * No bearer tokens, user details, OTPs, holds or reservations are printed.
 */
use App\Services\PortalAvailabilityService;
use App\Services\T2Hub\T2HubBookingData;

$availability = app(PortalAvailabilityService::class);
$bridge = app(T2HubBookingData::class);
$category = '50';
$city = 'Rajshahi';
$t0 = microtime(true);
$dates = $availability->bookingDates($category, $city);
$datesMs = round((microtime(true) - $t0) * 1000);
$date = (string) ($dates[0]['date'] ?? '');
echo "dates count=".count($dates)." ms={$datesMs} first={$date}".PHP_EOL;
if ($date === '') {
    throw new RuntimeException('No live date for read-only smoke.');
}

$t0 = microtime(true);
$warmed = $bridge->warmCityDates($category, $city, $dates);
$warmMs = round((microtime(true) - $t0) * 1000);
echo "warm dates={$warmed} ms={$warmMs}".PHP_EOL;

$t0 = microtime(true);
$centers = $availability->bookingCentersForDate($category, $city, $date, $category, 'BRBBB');
$centerMs = round((microtime(true) - $t0) * 1000);
$rows = $bridge->sessionRows($category, $city, $date);
echo 'centers count='.count($centers)." ms={$centerMs} sessions=".count($rows).PHP_EOL;

foreach ($centers as $center) {
    $id = (string) ($center['test_center_id'] ?? '');
    $expectedIds = array_values((array) ($center['session_ids'] ?? []));
    $actual = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['test_center_id'] ?? '') === $id));
    $actualIds = array_column($actual, 'exam_session_id');
    if ($id === '' || $expectedIds === [] || array_diff($expectedIds, $actualIds) !== []) {
        throw new RuntimeException('A centre/session snapshot did not match the live data.');
    }
    echo 'center id='.$id.' session_count='.count($actual).' matches_snapshot=yes'.PHP_EOL;
}
