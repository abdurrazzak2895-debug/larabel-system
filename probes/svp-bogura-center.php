<?php

/**
 * Read-only diagnostic for the "unknown center" pre-hold blocker.
 *
 * It compares the live T2Hub session metadata (what the booking wizard shows
 * for a centre such as Bogura Technical Training Centre) with the authoritative
 * SVP exam-session detail that the pre-hold verifier reads, and prints the
 * verifier decision for that exact pair.
 *
 * No hold, reservation, payment or cancellation is created.
 *
 * Usage: php artisan tinker probes/svp-bogura-center.php
 */

use App\Services\BookingService;
use App\Services\SvpApiService;
use App\Services\SvpSessionVerifier;
use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$mask = static fn (string $id): string => $id === '' ? '' : substr($id, 0, 6).'…'.substr($id, -4);
$needle = strtolower((string) (getenv('PROBE_CENTER') ?: 'bogur'));

$provider = app(T2HubProvider::class);
$booking = app(BookingService::class);
$verifier = app(SvpSessionVerifier::class);

echo $say('search centre name containing "'.$needle.'"');

// ---------------------------------------------------------------- T2Hub side
$centres = [];
foreach ((array) $provider->allTestCenters() as $centre) {
    if (! is_array($centre)) {
        continue;
    }
    $name = (string) ($centre['name'] ?? $centre['test_center_name'] ?? $centre['site_name'] ?? '');
    if ($name !== '' && str_contains(strtolower($name), $needle)) {
        $centres[] = [
            'id' => (string) ($centre['id'] ?? $centre['test_center_id'] ?? $centre['site_id'] ?? ''),
            'name' => $name,
            'city' => (string) ($centre['city'] ?? $centre['city_name'] ?? ''),
        ];
    }
}

echo $say('T2Hub centres matching: '.count($centres));
foreach (array_slice($centres, 0, 6) as $centre) {
    echo $say('  - id='.$centre['id'].' name="'.$centre['name'].'" city="'.$centre['city'].'"');
}

if ($centres === []) {
    echo $say('no matching centre in T2Hub; aborting');

    return;
}

$target = $centres[0];
$city = $target['city'] !== '' ? $target['city'] : $target['name'];

// Find a live dated session for that centre across the configured categories.
$occupations = (array) $provider->occupations();
$categories = [];
foreach ($occupations as $occupation) {
    if (! is_array($occupation)) {
        continue;
    }
    $categoryId = (string) ($occupation['id'] ?? '');
    if ($categoryId !== '' && ! isset($categories[$categoryId])) {
        $categories[$categoryId] = (string) ($occupation['name'] ?? '');
    }
}
echo $say('T2Hub categories: '.count($categories));

$found = null;
foreach (array_keys($categories) as $categoryId) {
    try {
        $dates = (array) $provider->availableDates($categoryId, $city);
    } catch (Throwable $e) {
        continue;
    }

    $days = [];
    foreach ($dates as $row) {
        $day = is_array($row) ? (string) ($row['date'] ?? '') : (string) $row;
        if ($day !== '') {
            $days[] = $day;
        }
    }
    sort($days);

    foreach (array_slice($days, 0, 4) as $day) {
        try {
            $payload = (array) $provider->sessions($categoryId, $city, $day);
        } catch (Throwable $e) {
            continue;
        }

        foreach ((array) ($payload['sessions'] ?? []) as $session) {
            if (! is_array($session)) {
                continue;
            }
            $sessionCentre = strtolower((string) ($session['center_name'] ?? $session['site_name'] ?? $session['test_center']['name'] ?? ''));
            if ($sessionCentre === '' || ! str_contains($sessionCentre, $needle)) {
                continue;
            }
            $found = [
                'category_id' => $categoryId,
                'category_name' => $categories[$categoryId],
                'city' => $city,
                'date' => (string) ($session['exam_date'] ?? $day),
                'time' => (string) ($session['exam_time'] ?? ''),
                'exam_session_id' => (string) ($session['exam_session_id'] ?? $session['session_id'] ?? $session['id'] ?? ''),
                'center_id' => (string) ($session['site_id'] ?? $session['test_center_id'] ?? $session['test_center']['id'] ?? ''),
                'center_name' => (string) ($session['center_name'] ?? $session['site_name'] ?? $session['test_center']['name'] ?? ''),
                'raw' => $session,
            ];
            break 3;
        }
    }
}

if ($found === null) {
    echo $say('no live dated T2Hub session for that centre; aborting');

    return;
}

echo $say('');
echo $say('=== T2Hub session (what the wizard shows) ===');
echo $say('category   = '.$found['category_id'].' ('.$found['category_name'].')');
echo $say('city/date  = '.$found['city'].' / '.$found['date'].' '.$found['time']);
echo $say('centre     = id='.$found['center_id'].' name="'.$found['center_name'].'"');
echo $say('session    = '.$mask($found['exam_session_id']));
echo $say('raw keys   = '.implode(', ', array_slice(array_keys($found['raw']), 0, 30)));

// ------------------------------------------------------------------ SVP side
$email = trim((string) config('svp.auto_login.email'));
$password = (string) config('svp.auto_login.password');
$otp = app(App\Services\SvpOtp\OtpAutoVerifier::class);
echo $say('');
echo $say('=== SVP login ===');
echo $say('auto OTP enabled = '.($otp->enabled() ? 'yes' : 'no').' | credentials configured = '.($email !== '' && $password !== '' ? 'yes' : 'no'));

if ($email === '' || $password === '') {
    echo $say('SVP_EMAIL/SVP_PASSWORD not configured; cannot inspect the SVP detail');

    return;
}

$svpApi = app(SvpApiService::class);
$login = $otp->login($email, $password, 'email');
$token = trim((string) data_get($login, 'body.access_token', ''));
echo $say('login status='.(int) ($login['status'] ?? 0).' auto_verified='.(($login['auto_verified'] ?? false) ? 'yes' : 'no').' otp_source='.($login['otp_source'] ?? '-'));

if ($token === '') {
    echo $say('no SVP access token returned (message: '.substr((string) data_get($login, 'body.message', ''), 0, 120).')');

    return;
}

$detail = $booking->examSession($token, $found['exam_session_id']);
$detailBody = $detail->getData(true);
echo $say('');
echo $say('=== SVP exam-session detail ===');
echo $say('http status = '.$detail->getStatusCode());
$record = (array) (data_get($detailBody, 'data') ?? $detailBody);
echo $say('top keys    = '.implode(', ', array_slice(array_keys((array) $detailBody), 0, 20)));
echo $say('data keys   = '.implode(', ', array_slice(array_keys($record), 0, 30)));
foreach (['test_center_id', 'test_center_name', 'site_id', 'site_name', 'center_id', 'center_name', 'city', 'city_name', 'exam_date', 'exam_time', 'test_date', 'test_time', 'methodology'] as $key) {
    if (array_key_exists($key, $record)) {
        $value = $record[$key];
        echo $say('  '.$key.' = '.(is_scalar($value) ? (string) $value : gettype($value)));
    }
}
foreach (['test_center', 'site', 'center', 'testCentre', 'exam_center'] as $nested) {
    if (isset($record[$nested]) && is_array($record[$nested])) {
        echo $say('  '.$nested.' = {'.implode(', ', array_keys($record[$nested])).'}');
    }
}

echo $say('');
echo $say('=== verifier decision for the T2Hub pair ===');
$decision = $verifier->verifyForHold(
    $token,
    $found['exam_session_id'],
    $found['center_id'],
    $found['city'],
    $found['date'],
    $found['center_name'],
    $found['time'],
);
foreach (['verified', 'upstream_status'] as $key) {
    echo $say($key.' = '.var_export($decision[$key] ?? null, true));
}
foreach (['actual', 'checks'] as $key) {
    echo $say($key.' = '.json_encode($decision[$key] ?? null));
}
echo $say('expected = '.json_encode($decision['expected'] ?? null));