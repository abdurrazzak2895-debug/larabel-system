<?php

/**
 * Read-only mapping probe: which SVP occupation id belongs to the category of a
 * live session, and which one matches the occupation name the operator picked in
 * the T2Hub-driven wizard?
 *
 * Usage: PROBE_CATEGORY=50 PROBE_CITY=Rajshahi PROBE_DATE=2026-10-06 php artisan tinker probes/svp-occupation-map.php
 */
use App\Services\BookingService;
use App\Services\SvpOtp\OtpAutoVerifier;
use App\Services\T2Hub\T2HubBookingData;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$clip = static fn (mixed $value, int $max = 120): string => substr((string) (is_scalar($value) ? $value : json_encode($value)), 0, $max);

$categoryId = (string) (getenv('PROBE_CATEGORY') ?: '50');
$city = (string) (getenv('PROBE_CITY') ?: 'Rajshahi');
$date = (string) (getenv('PROBE_DATE') ?: '2026-10-06');

$bridge = app(T2HubBookingData::class);
$booking = app(BookingService::class);
$otp = app(OtpAutoVerifier::class);

$rows = $bridge->sessionRows($categoryId, $city, $date);
$row = $rows[0] ?? null;
if ($row === null) {
    echo $say('no T2Hub session for '.$city.' '.$date);

    return;
}

$sessionId = (string) $row['exam_session_id'];
$occupationName = (string) ($row['occupation_name'] ?? $row['name'] ?? '');
echo $say('T2Hub row: session='.substr($sessionId, 0, 12).'… centre="'.($row['center_name'] ?? '').'" occupation="'.$occupationName.'" t2hub_occupation_id='.($row['occupation_id'] ?? '-'));

$email = trim((string) config('svp.auto_login.email'));
$password = (string) config('svp.auto_login.password');
$login = $otp->login($email, $password, 'email');
$token = (string) (data_get($login, 'body.access_payload.access')
    ?? data_get($login, 'body.access_token')
    ?? data_get($login, 'body.token')
    ?? '');
echo $say('SVP login status='.(int) ($login['status'] ?? 0).' token='.($token === '' ? 'MISSING' : 'ok'));
if ($token === '') {
    echo $say('body keys = '.implode(', ', array_keys((array) ($login['body'] ?? []))));

    return;
}

// 1) Authoritative session detail: which category/engine does it carry?
$detail = $booking->examSession($token, $sessionId);
$body = (array) $detail->getData(true);
$record = (array) (data_get($body, 'data') ?? $body);
echo $say('');
echo $say('SVP session detail http='.$detail->getStatusCode());
foreach ($record as $key => $value) {
    if (is_array($value)) {
        continue;
    }
    echo $say('  '.$key.' = '.$clip($value, 80));
}
foreach (['category', 'occupation', 'exam_engine', 'exam_session_category'] as $key) {
    if (isset($record[$key]) && is_array($record[$key])) {
        echo $say('  '.$key.' = '.$clip($record[$key], 220));
    }
}

$sessionCategoryId = (string) (
    data_get($record, 'category_id')
    ?? data_get($record, 'category.id')
    ?? data_get($record, 'exam_category_id')
    ?? data_get($record, 'occupation.category_id')
    ?? data_get($record, 'exam_engine.category_id')
    ?? ''
);
echo $say('session category id = '.($sessionCategoryId === '' ? 'NOT EXPOSED' : $sessionCategoryId));

// 2) SVP occupation catalogue: which ids/categories exist for this occupation name?
$provider = app(\App\Services\Providers\TakamolProvider::class)->withToken($token);
$occupations = $provider->occupationsSearch(null, 1, 1000);
$list = (array) $occupations->getData(true);
$records = data_get($list, 'data.data')
    ?? data_get($list, 'data')
    ?? data_get($list, 'occupations')
    ?? $list;
if (! is_array($records)) {
    $records = [];
}
$records = array_values(array_filter($records, static fn ($record): bool => is_array($record)));
echo $say('');
echo $say('SVP occupations http='.$occupations->getStatusCode().' records='.count($records));

$needle = strtolower(trim($occupationName)) ?: 'barber';
$matches = [];
$inCategory = [];
foreach ($records as $record) {
    if (! is_array($record)) {
        continue;
    }
    $name = strtolower(trim((string) ($record['english_name'] ?? $record['name'] ?? '')));
    $categoryIdOfRecord = (string) (data_get($record, 'category.id') ?? data_get($record, 'category_id') ?? '');
    $recordSummary = [
        'id' => (string) ($record['occupation_id'] ?? $record['id'] ?? ''),
        'name' => (string) ($record['english_name'] ?? $record['name'] ?? ''),
        'category' => $categoryIdOfRecord,
        'engine' => (string) data_get($record, 'category.exam_engine', ''),
    ];
    if ($categoryIdOfRecord !== '' && $categoryIdOfRecord === $sessionCategoryId) {
        $inCategory[] = $recordSummary;
    }
    $categories = [];
    foreach ((array) ($record['categories'] ?? $record['occupation_categories'] ?? []) as $category) {
        if (is_array($category)) {
            $categories[] = (string) ($category['id'] ?? $category['category_id'] ?? '');
        } else {
            $categories[] = (string) $category;
        }
    }
    if ($needle !== '' && str_contains($name, $needle)) {
        $matches[] = [
            'id' => (string) ($record['id'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'categories' => $categories,
            'keys' => implode(',', array_slice(array_keys($record), 0, 12)),
        ];
    }
}

echo $say('SVP occupations in the session category ('.$sessionCategoryId.'): '.count($inCategory));
foreach (array_slice($inCategory, 0, 12) as $summary) {
    echo $say('  id='.$summary['id'].' name="'.$summary['name'].'" engine='.$summary['engine']);
}

echo $say('occupation name matches for "'.$occupationName.'": '.count($matches));
foreach (array_slice($matches, 0, 6) as $match) {
    echo $say('  id='.$match['id'].' name="'.$match['name'].'" categories=['.implode(',', $match['categories']).'] keys='.$match['keys']);
}

if ($matches === [] && $records !== []) {
    $first = reset($records);
    if (is_array($first)) {
        echo $say('  sample record = '.$clip($first, 320));
    }
}