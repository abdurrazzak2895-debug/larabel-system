<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$session = DB::table('sessions')->where('id', 'SyqzPmFi5WEkgVIZF746S2Xc69sjfniTvTzhaFti')->first();
if ($session) {
    $decoded = base64_decode($session->payload);
    echo 'DECODED=' . $decoded . PHP_EOL;
    
    // Try json decode
    $json = json_decode($decoded, true);
    if ($json !== null) {
        echo 'JSON_KEYS=' . implode(',', array_keys($json)) . PHP_EOL;
        foreach ($json as $key => $value) {
            echo '  ' . $key . ' = ' . var_export($value, true) . PHP_EOL;
        }
    } else {
        echo 'JSON_DECODE_FAILED' . PHP_EOL;
    }
}
