<?php
/**
 * Quick live probe: sample the occupations payload and try to book a
 * `prometric` category occupation.
 *
 * Run: php artisan tinker probes/quick.php
 */

use App\Services\Providers\TakamolProvider;
use App\Services\SvpOtp\OtpAutoVerifier;

$token = app(OtpAutoVerifier::class)->login('mdforidmmmmm@dakbox.net', 'Hannan@1234')['body']['access_payload']['access'] ?? null;

if (! $token) {
    echo '  no token' . PHP_EOL;

    return;
}

$api = app(TakamolProvider::class)->withToken($token);

$payload = $api->occupations(['page' => 1, 'per_page' => 300])->getData(true);
$list = $payload['data']['occupations'] ?? $payload['data'] ?? [];

echo '  occ=' . count($list) . PHP_EOL;

if ($list) {
    $first = (array) $list[0];
    echo '  keys=' . implode(',', array_keys($first)) . PHP_EOL;
    echo '  sample=' . substr((string) json_encode($first, JSON_UNESCAPED_UNICODE), 0, 380) . PHP_EOL;
}

$prometric = [55, 37, 59, 28, 160];
$chosen = null;

foreach ($list as $occupation) {
    $occupation = (array) $occupation;
    $codes = $occupation['categories'] ?? ($occupation['category_ids'] ?? ($occupation['exam_categories'] ?? []));

    foreach ((array) $codes as $entry) {
        $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;
        if ($id !== null && in_array((int) $id, $prometric, true)) {
            $chosen = [
                'id' => (string) $occupation['id'],
                'cat' => (int) $id,
                'lang' => $occupation['languages'][0]['code'] ?? 'LOABB',
            ];
            break 2;
        }
    }
}

if (! $chosen) {
    echo '  no prometric occupation in this page' . PHP_EOL;

    return;
}

echo '  chosen occ=' . $chosen['id'] . ' cat=' . $chosen['cat'] . ' lang=' . $chosen['lang'] . PHP_EOL;

$sessions = $api->examSessions(['category_id' => $chosen['cat']])->getData(true)['data']['sessions'] ?? [];
echo '  sessions=' . count($sessions) . PHP_EOL;

foreach (array_slice($sessions, 0, 2) as $session) {
    $response = $api->createReservation([
        'exam_session_id' => $session['id'],
        'occupation_id' => (int) $chosen['id'],
        'language_code' => $chosen['lang'],
        'methodology' => 'in_person',
        'site_id' => null,
        'site_city' => null,
        'hold_id' => null,
    ]);

    $status = $response->getStatusCode();
    echo '  ' . $session['test_center_city'] . ' ' . $session['exam_date'] . ' -> ' . $status . ' '
        . substr((string) json_encode($response->getData(true), JSON_UNESCAPED_UNICODE), 0, 200) . PHP_EOL;

    if ($status < 300) {
        $data = $response->getData(true);
        $id = $data['exam_reservation']['id'] ?? ($data['data']['id'] ?? ($data['reservation']['id'] ?? null));
        echo '  BOOKED id=' . json_encode($id) . PHP_EOL;

        if ($id) {
            echo '  cancel -> ' . $api->cancelReservation((string) $id)->getStatusCode() . PHP_EOL;
        }

        break;
    }
}