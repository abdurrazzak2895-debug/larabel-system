<?php

/** How often does the T2Hub client pay a fresh login, and how slow is one date? */
use App\Services\T2Hub\T2HubProvider;
use Illuminate\Support\Facades\Cache;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$ms = static fn (float $t0): string => number_format((microtime(true) - $t0) * 1000, 0).'ms';
$provider = app(T2HubProvider::class);

foreach (['2026-10-07', '2026-10-08', '2026-10-11'] as $date) {
    $key = 't2hub:get:'.sha1('/pacc-exam-sessions|'.json_encode([
        'category_id' => '50', 'city' => 'Rajshahi', 'exam_date' => $date, 'auto_fix_search' => 1,
    ]));
    Cache::forget($key);
    $t0 = microtime(true);
    $rows = (array) ($provider->sessions('50', 'Rajshahi', $date)['sessions'] ?? []);
    echo $say('cold sessions '.$date.' : '.$ms($t0).' ('.count($rows).' sessions)');

    $t0 = microtime(true);
    $provider->sessions('50', 'Rajshahi', $date);
    echo $say('warm sessions '.$date.' : '.$ms($t0));
}
