<?php
/**
 * Live probe: complete a real SVP reservation (book + cancel) using an
 * occupation that belongs to a `prometric` category.
 *
 * Run on the server:  php artisan tinker probes/svp-book-prometric.php
 */

use App\Services\Providers\TakamolProvider;
use App\Services\SvpOtp\OtpAutoVerifier;

$verifier = app(OtpAutoVerifier::class);
$login = $verifier->login('mdforidmmmmm@dakbox.net', 'Hannan@1234');
$token = $login['body']['access_payload']['access'] ?? null;

if (! $token) {
    echo '  no token' . PHP_EOL;
    return;
}

$api = app(TakamolProvider::class)->withToken($token);

$occPayload = $api->occupations(['page' => 1, 'per_page' => 10000])->getData(true);
$occupations = $occPayload['data']['occupations'] ?? $occPayload['data'] ?? [];
if (! $occupations && isset($occPayload['data'][0])) {
    $occupations = $occPayload['data'];
}
echo '  occupations=' . count($occupations) . PHP_EOL;

$prometricCategories = [55, 37, 59, 28, 160];
$chosen = null;

foreach ($occupations as $occupation) {
    $categories = $occupation['categories'] ?? ($occupation['exam_categories'] ?? []);
    foreach ((array) $categories as $category) {
        $categoryId = is_array($category) ? ($category['id'] ?? null) : $category;
        if ($categoryId !== null && in_array((int) $categoryId, $prometricCategories, true)) {
            $chosen = [
                'occupation' => (string) $occupation['id'],
                'category' => (int) $categoryId,
                'name' => substr((string) ($occupation['english_name'] ?? $occupation['name'] ?? '?'), 0, 34),
                'language' => $occupation['languages'][0]['code'] ?? 'LOABB',
            ];
            break 2;
        }
    }
}

if (! $chosen) {
    echo '  no prometric occupation found' . PHP_EOL;

    return;
}

echo '  chosen occupation=' . $chosen['occupation']
    . ' category=' . $chosen['category']
    . ' language=' . $chosen['language']
    . ' name=' . $chosen['name'] . PHP_EOL;

$sessions = $api->examSessions(['category_id' => $chosen['category']])->getData(true)['data']['sessions'] ?? [];
echo '  sessions=' . count($sessions) . PHP_EOL;

foreach (array_slice($sessions, 0, 3) as $session) {
    $response = $api->createReservation([
        'exam_session_id' => $session['id'],
        'occupation_id' => (int) $chosen['occupation'],
        'language_code' => $chosen['language'],
        'methodology' => 'in_person',
        'site_id' => null,
        'site_city' => null,
        'hold_id' => null,
    ]);

    $status = $response->getStatusCode();
    $body = json_encode($response->getData(true), JSON_UNESCAPED_UNICODE);

    echo '  ' . $session['test_center_city'] . ' ' . $session['exam_date']
        . ' -> ' . $status . ' ' . substr((string) $body, 0, 220) . PHP_EOL;

    if ($status < 300) {
        $data = $response->getData(true);
        $id = $data['exam_reservation']['id'] ?? ($data['data']['id'] ?? ($data['reservation']['id'] ?? null));
        echo '  BOOKED id=' . json_encode($id) . PHP_EOL;

        if ($id) {
            $cancel = $api->cancelReservation((string) $id);
            echo '  cancel -> ' . $cancel->getStatusCode() . PHP_EOL;
        }

        break;
    }
}