<?php

/**
 * Time every upstream hop the booking wizard touches so the slow stage is
 * measurable instead of guessed.
 *
 * Usage: PROBE_CITY=Rajshahi PROBE_CATEGORY=50 PROBE_DATE=2026-10-06 php artisan tinker probes/lookup-latency.php
 */
use App\Services\SvpOtp\SvpAutoSession;
use App\Services\T2Hub\T2HubBookingData;
use App\Services\T2Hub\T2HubProvider;
use Illuminate\Support\Facades\Cache;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$ms = static fn (float $t0): string => number_format((microtime(true) - $t0) * 1000, 0).'ms';

$city = (string) (getenv('PROBE_CITY') ?: 'Rajshahi');
$categoryId = (string) (getenv('PROBE_CATEGORY') ?: '50');
$date = (string) (getenv('PROBE_DATE') ?: '2026-10-06');

echo $say('data_source = '.config('t2hub.data_source').'  cache_enabled = '.var_export(config('t2hub.cache_enabled'), true));
echo $say('default ttl = '.config('t2hub.cache_ttl').'s  overrides = '.json_encode(config('t2hub.cache_ttl_overrides')));

$provider = app(T2HubProvider::class);

$t0 = microtime(true);
$cities = $provider->cities($categoryId);
echo $say('T2Hub cities        : '.$ms($t0).' ('.count($cities).')');

$t0 = microtime(true);
$centers = $provider->testCenters($city);
echo $say('T2Hub centres cold  : '.$ms($t0).' ('.count($centers).')');

$t0 = microtime(true);
$centers2 = $provider->testCenters($city);
echo $say('T2Hub centres warm  : '.$ms($t0).' ('.count($centers2).')');

$centerId = (string) ($centers[0]['id'] ?? '');
$t0 = microtime(true);
$sessions = $provider->sessions($categoryId, $city, $date, $centerId);
echo $say('T2Hub sessions cold : '.$ms($t0).' ('.count($sessions).')');

$t0 = microtime(true);
$sessions2 = $provider->sessions($categoryId, $city, $date, $centerId);
echo $say('T2Hub sessions warm : '.$ms($t0).' ('.count($sessions2).')');

$t0 = microtime(true);
$dates = $provider->availableDates($categoryId, $city);
echo $say('T2Hub dates cold    : '.$ms($t0).' ('.count($dates).')');

$t0 = microtime(true);
$dates2 = $provider->availableDates($categoryId, $city);
echo $say('T2Hub dates warm    : '.$ms($t0).' ('.count($dates2).')');

// Prewarm: the dates lookup warms centres + upcoming dates after the response.
$bridge = app(T2HubBookingData::class);
Cache::forget('t2hub:get:'.sha1('/pacc-exam-sessions|'.json_encode([
    'category_id' => $categoryId, 'city' => $city, 'exam_date' => $date, 'auto_fix_search' => 1,
])));

$t0 = microtime(true);
$warmed = $bridge->warmCityDates($categoryId, $city, [$date, '2026-10-07', '2026-10-08']);
echo $say('prewarm city        : '.$ms($t0).' ('.$warmed.' dates)');

$t0 = microtime(true);
$provider->sessions($categoryId, $city, $date, $centerId);
echo $say('sessions after warm : '.$ms($t0).' (cache hit expected)');

echo $say('');