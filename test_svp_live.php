<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$svp = new App\Services\SvpApiService();

$otp = $argv[1] ?? null;

if (!$otp) {
    die("Usage: php test_svp_live.php <otp_code>\n");
}

echo "=== VERIFYING OTP: $otp ===\n";

try {
    $otpResult = $svp->verifyOtp('ranakhansvp2465@yopmail.com', 'aRrazzak90#', $otp, 'email');
    echo "OTP status: " . $otpResult['status'] . "\n";

    if ($otpResult['status'] !== 200) {
        die("ERROR: OTP verification failed\n");
    }

    $token = $otpResult['body']['access_payload']['access'];
    $csrf = $otpResult['body']['access_payload']['csrf'] ?? null;
    echo "Token: " . substr($token, 0, 30) . "...\n";
    echo "CSRF: " . substr($csrf, 0, 20) . "...\n";

    $provider = (new \App\Services\Providers\TakamolProvider())->withToken($token);
    if ($csrf) {
        $provider = $provider->withCsrfToken($csrf);
    }

    $endpoints = [
        'Profile' => fn() => $provider->profile(),
        'Permissions' => fn() => $provider->permissions(),
        'CertificatePrice' => fn() => $provider->certificatePrice(),
        'ExamSessions' => fn() => $provider->examSessions(),
        'AvailableDates' => fn() => $provider->availableDates(),
        'Reservations' => fn() => $provider->reservations(),
        'ValidateReservation' => fn() => $provider->validateReservation(),
        'Occupations' => fn() => $provider->occupations(),
        'Cities' => fn() => $provider->cities(),
        'Categories' => fn() => $provider->categories(),
        'ExamConstraints' => fn() => $provider->examConstraints(),
        'ValidatePendingPayment' => fn() => $provider->validatePendingPayment(),
        'Notifications' => fn() => $provider->notifications(),
        'VerificationRequests' => fn() => $provider->verificationRequests(),
    ];

    echo "\n=== ENDPOINT AUDIT ===\n";
    foreach ($endpoints as $name => $callable) {
        try {
            $response = $callable();
            $status = $response->getStatusCode();
            $data = $response->getData(true);

            if ($status >= 200 && $status < 300) {
                $keys = is_array($data) ? implode(', ', array_slice(array_keys($data), 0, 5)) : 'N/A';
                $count = is_array($data) ? count($data) : (isset($data['data']) ? count($data['data']) : 'N/A');
                echo "[OK]   $name | $status | Keys: $keys | Count: $count\n";
            } else {
                echo "[FAIL] $name | $status | " . substr(json_encode($data), 0, 100) . "\n";
            }
        } catch (Throwable $e) {
            echo "[ERROR] $name | " . substr($e->getMessage(), 0, 100) . "\n";
        }
    }

    echo "\n=== AUDIT COMPLETE ===\n";
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
}
