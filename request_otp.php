<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$svp = new App\Services\SvpApiService();

echo "=== REQUESTING FRESH OTP ===\n";

try {
    $result = $svp->login('ranakhansvp2465@yopmail.com', 'aRrazzak90#', 'email');
    echo "Login status: " . $result['status'] . "\n";

    if (isset($result['body']['required_2fa']) && $result['body']['required_2fa'] === true) {
        echo "SUCCESS: OTP sent\n";
        echo "Provide the OTP code to run the verification test.\n";
    } else {
        echo "Unexpected response: " . json_encode($result['body']) . "\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
