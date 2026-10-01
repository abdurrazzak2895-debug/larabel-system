<?php

/**
 * Pair one live T2Hub session with the authoritative SVP exam-session detail and
 * print both raw shapes plus the pre-hold verifier decision.
 *
 * Read-only: no hold, reservation, payment, or cancellation is created.
 *
 * Usage: PROBE_CITY=Barishal php artisan tinker probes/t2hub-svp-pair.php
 */

use App\Services\BookingService;
use App\Services\SvpOtp\OtpAutoVerifier;
use App\Services\SvpApiService;
use App\Services\SvpSessionVerifier;
use App\Services\T2Hub\T2HubProvider;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;
$mask = static fn (string $id): string => $id === '' ? '' : substr($id, 0, 6).'…'.substr($id, -4);
$clip = static fn (mixed $value, int $max = 120): string => substr((string) (is_scalar($value) ? $value : json_encode($value)), 0, $max);

$provider = app(T2HubProvider::class);
$booking = app(BookingService::class);
$verifier = app(SvpSessionVerifier::class);

$categories = [];
foreach ((array) $provider->occupations() as $occupation) {
    if (is_array($occupation) && ($id = (string) ($occupation['id'] ?? '')) !== '') {
        $categories[$id] = (string) ($occupation['name'] ?? '');
    }
}

$cities = [];
foreach ((array) $provider->cities() as $row) {
    $name = is_array($row) ? (string) ($row['name'] ?? $row['city'] ?? '') : (string) $row;
    if ($name !== '') {
        $cities[] = $name;
    }
}
echo $say('cities = '.implode(', ', $cities));
echo $say('categories = '.count($categories));

$found = null;
foreach ($cities as $city) {
    foreach (array_slice(array_keys($categories), 0, 8) as $categoryId) {
        try {
            $dates = (array) $provider->availableDates($categoryId, $city);
        } catch (Throwable $e) {
            continue;
        }

        $raw = json_encode($dates);
        $days = [];
        foreach ($dates as $row) {
            if (is_array($row)) {
                foreach (['date', 'exam_date', 'available_date', 'value'] as $key) {
                    if (is_string($row[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $row[$key]) === 1) {
                        $days[] = substr($row[$key], 0, 10);
                    }
                }
            } elseif (is_string($row) && preg_match('/^\d{4}-\d{2}-\d{2}/', $row) === 1) {
                $days[] = substr($row, 0, 10);
            }
        }
        $days = array_values(array_unique($days));
        sort($days);

        if ($found === null) {
            echo $say('dates raw for '.$city.' / '.$categoryId.' = '.substr((string) $raw, 0, 200));
            echo $say('dates parsed = '.implode(',', array_slice($days, 0, 6)));
        }

        foreach (array_slice($days, 0, 3) as $day) {
            try {
                $payload = (array) $provider->sessions((string) $categoryId, $city, $day);
            } catch (Throwable $e) {
                continue;
            }

            foreach ((array) ($payload['sessions'] ?? []) as $session) {
                if (! is_array($session)) {
                    continue;
                }
                $sessionId = (string) ($session['exam_session_id'] ?? $session['session_id'] ?? $session['id'] ?? '');
                if ($sessionId === '') {
                    continue;
                }
                $found = [
                    'category_id' => (string) $categoryId,
                    'city' => $city,
                    'date' => (string) ($session['exam_date'] ?? $day),
                    'time' => (string) ($session['exam_time'] ?? ''),
                    'session_id' => $sessionId,
                    'center_id' => (string) ($session['site_id'] ?? $session['test_center_id'] ?? $session['test_center']['id'] ?? ''),
                    'center_name' => (string) ($session['center_name'] ?? $session['site_name'] ?? $session['test_center']['name'] ?? ''),
                    'raw' => $session,
                ];
                break 4;
            }
        }
    }
}

if ($found === null) {
    echo $say('no live session discovered; aborting');

    return;
}

echo $say('');
echo $say('=== T2Hub session ===');
echo $say('category/city/date = '.$found['category_id'].' / '.$found['city'].' / '.$found['date'].' '.$found['time']);
echo $say('centre = id="'.$found['center_id'].'" name="'.$found['center_name'].'"');
echo $say('session = '.$mask($found['session_id']));
echo $say('raw keys = '.implode(', ', array_keys($found['raw'])));
foreach ($found['raw'] as $key => $value) {
    if (in_array($key, ['exam_session_id', 'session_id', 'id'], true)) {
        continue;
    }
    echo $say('  '.$key.' = '.$clip($value, 90));
}

$otp = app(OtpAutoVerifier::class);
$email = trim((string) config('svp.auto_login.email'));
$password = (string) config('svp.auto_login.password');
echo $say('');
echo $say('=== SVP login (auto OTP) ===');
echo $say('mailbox enabled = '.($otp->enabled() ? 'yes' : 'no').' | credentials = '.($email !== '' && $password !== '' ? 'configured' : 'missing'));

$login = $otp->login($email, $password, 'email');
$token = trim((string) data_get($login, 'body.access_token', ''));
echo $say('status='.(int) ($login['status'] ?? 0).' auto_verified='.((bool) ($login['auto_verified'] ?? false) ? 'yes' : 'no').' otp_source='.($login['otp_source'] ?? '-'));

if ($token === '') {
    echo $say('no access token; message='.substr((string) data_get($login, 'body.message', ''), 0, 140));

    return;
}

$detail = $booking->examSession($token, $found['session_id']);
$body = (array) $detail->getData(true);
$record = (array) (data_get($body, 'data') ?? $body);
echo $say('');
echo $say('=== SVP exam-session detail ===');
echo $say('http status = '.$detail->getStatusCode());
echo $say('top keys = '.implode(', ', array_slice(array_keys($body), 0, 20)));
echo $say('record keys = '.implode(', ', array_slice(array_keys($record), 0, 40)));
foreach ($record as $key => $value) {
    if (is_array($value)) {
        echo $say('  '.$key.' = {'.implode(', ', array_slice(array_keys($value), 0, 12)).'}');
    } else {
        echo $say('  '.$key.' = '.$clip($value, 90));
    }
}

echo $say('');
echo $say('=== verifier decision with the T2Hub expectations ===');
$decision = $verifier->verifyForHold(
    $token,
    $found['session_id'],
    $found['center_id'],
    $found['city'],
    $found['date'],
    $found['center_name'],
    $found['time'],
);
echo $say('verified='.var_export($decision['verified'] ?? null, true).' success='.var_export($decision['success'] ?? null, true).' upstream='.($decision['upstream_status'] ?? '-'));
echo $say('session = '.json_encode($decision['session'] ?? null));
echo $say('actual  = '.json_encode($decision['actual'] ?? null));
echo $say('checks  = '.json_encode($decision['checks'] ?? null));
echo $say('expected = '.json_encode($decision['expected'] ?? null));