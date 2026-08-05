<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$session = DB::table('sessions')->where('id', 'SyqzPmFi5WEkgVIZF746S2Xc69sjfniTvTzhaFti')->first();
if ($session) {
    echo 'PAYLOAD_RAW=' . $session->payload . PHP_EOL;
    echo 'PAYLOAD_LENGTH=' . strlen($session->payload) . PHP_EOL;
    
    // Try base64 decode
    $decoded = base64_decode($session->payload);
    echo 'BASE64_DECODED_LENGTH=' . strlen($decoded) . PHP_EOL;
    
    // Try unserialize
    $unserialized = @unserialize($session->payload);
    if ($unserialized !== false || $session->payload === 'b:0;') {
        echo 'UNSERIALIZE_SUCCESS' . PHP_EOL;
        echo 'KEYS=' . implode(',', array_keys($unserialized)) . PHP_EOL;
    } else {
        echo 'UNSERIALIZE_FAILED' . PHP_EOL;
    }
}
