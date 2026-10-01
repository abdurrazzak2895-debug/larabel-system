<?php

/**
 * Compare one known live T2Hub session with its authoritative SVP detail and
 * print the pre-hold verifier decision (read-only).
 *
 * Usage: PROBE_CATEGORY=50 PROBE_CITY=Rajshahi PROBE_DATE=2026-10-06 php artisan tinker probes/t2hub-svp-one.php
 */

use App\Services\BookingService;
use App\Services\SvpOtp\OtpAutoVerifier;
use App\Services\SvpSessionVerifier;
use App\Services\T2Hub\T2HubBookingData;
use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$clip = static fn (mixed $value, int $max = 130): string => substr((string) (is_scalar($value) ? $value : json_encode($value)), 0, $max);

$categoryId = (string) (getenv('PROBE_CATEGORY') ?: '50');
$city = (string) (getenv('PROBE_CITY') ?: 'Rajshahi');
$date = (string) (getenv('PROBE_DATE') ?: '2026-10-06');

$provider = app(T2HubProvider::class);
$bridge = app(T2HubBookingData::class);
$booking = app(BookingService::class);
$verifier = app(SvpSessionVerifier::class);

$rows = $bridge->sessionRows($categoryId, $city, $date);
$row = $rows[0] ?? null;
if ($row === null) {
    echo $say('no T2Hub session for '.$city.' '.$date);

    return;
}

$sessionId = (string) $row['exam_session_id'];
$centerId = (string) $row['test_center_id'];
$centerName = (string) $row['center_name'];
$time = (string) $row['exam_time'];
echo $say('T2Hub pair: centre id="'.$centerId.'" name="'.$centerName.'"');
echo $say('            city="'.$city.'" date="'.$date.'" time="'.$time.'"');
echo $say('            session='.substr($sessionId, 0, 10).'… status='.$clip($row['status'] ?? null, 20));

$otp = app(OtpAutoVerifier::class);
$email = trim((string) config('svp.auto_login.email'));
$password = (string) config('svp.auto_login.password');
$login = $otp->login($email, $password, 'email');
$token = (string) (data_get($login, 'body.access_payload.access')
        ?? data_get($login, 'body.access_token')
        ?? data_get($login, 'body.token')
        ?? '');
echo $say('');
echo $say('SVP login status='.(int) ($login['status'] ?? 0).' auto_verified='.((bool) ($login['auto_verified'] ?? false) ? 'yes' : 'no').' otp_source='.($login['otp_source'] ?? '-'));
if ($token === '') {
    $lb = (array) ($login['body'] ?? []);
    echo $say('no token: message='.substr((string) data_get($login, 'body.message', ''), 0, 160));
    echo $say('body keys = '.implode(', ', array_keys($lb)));
    echo $say('body = '.substr((string) json_encode($lb), 0, 300));

    return;
}

$detail = $booking->examSession($token, $sessionId);
$body = (array) $detail->getData(true);
$record = (array) (data_get($body, 'data') ?? $body);
echo $say('');
echo $say('SVP detail http='.$detail->getStatusCode());
echo $say('record keys = '.implode(', ', array_slice(array_keys($record), 0, 40)));
foreach ($record as $key => $value) {
    echo $say('  '.$key.' = '.(is_array($value) ? '{'.implode(', ', array_slice(array_keys($value), 0, 10)).'}' : $clip($value, 90)));
}

echo $say('');
$plain = $verifier->verifyForHold($token, $sessionId, $centerId, $city, $date, $centerName, $time, false);
echo $say('verifyForHold(snapshot=false) verified='.var_export($plain['verified'] ?? null, true).' upstream='.($plain['upstream_status'] ?? '-'));
echo $say('  actual='.json_encode($plain['actual'] ?? null));
echo $say('  checks='.json_encode($plain['checks'] ?? null));
$snap = $verifier->verifyForHold($token, $sessionId, $centerId, $city, $date, $centerName, $time, true);
echo $say('verifyForHold(snapshot=true)  verified='.var_export($snap['verified'] ?? null, true));
echo $say('  checks='.json_encode($snap['checks'] ?? null));
echo $say('snapshot lookup confirms centre = '.var_export($bridge->confirmsSessionCenter($categoryId, $city, $date, $centerId, $sessionId), true));

// Negative controls: a different city or a different date must still block.
$wrongCity = $verifier->verifyForHold($token, $sessionId, $centerId, 'Sylhet', $date, $centerName, $time, false, null);
echo $say('negative city: verified='.var_export($wrongCity['verified'] ?? null, true).' city_match='.var_export(data_get($wrongCity, 'checks.city_match'), true));
$wrongDate = $verifier->verifyForHold($token, $sessionId, $centerId, $city, '2026-10-07', $centerName, $time, true, $city);
echo $say('negative date: verified='.var_export($wrongDate['verified'] ?? null, true).' date_match='.var_export(data_get($wrongDate, 'checks.date_match'), true));
$alias = $verifier->verifyForHold($token, $sessionId, $centerId, 'Bogura', $date, $centerName, $time, true, 'Bogra');
echo $say('alias city (Bogura/Bogra): verified='.var_export($alias['verified'] ?? null, true));
