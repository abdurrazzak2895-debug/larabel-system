<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$session = DB::table('sessions')->where('id', 'SyqzPmFi5WEkgVIZF746S2Xc69sjfniTvTzhaFti')->first();

if ($session) {
    echo 'SESSION_ID=' . $session->id . PHP_EOL;
    echo 'SESSION_USER_ID=' . ($session->user_id ?? 'NULL') . PHP_EOL;
    echo 'SESSION_IP=' . ($session->ip_address ?? 'NULL') . PHP_EOL;
    
    // Decode payload
    $payload = unserialize($session->payload);
    echo 'PAYLOAD_KEYS=' . implode(',', array_keys($payload)) . PHP_EOL;
    
    if (isset($payload['login_admin_59ba36addc2b2f9401580f014c7f58ea4e30989d'])) {
        echo 'ADMIN_LOGIN_KEY_EXISTS=YES' . PHP_EOL;
        echo 'ADMIN_ID=' . $payload['login_admin_59ba36addc2b2f9401580f014c7f58ea4e30989d'] . PHP_EOL;
    } else {
        echo 'ADMIN_LOGIN_KEY_EXISTS=NO' . PHP_EOL;
    }
    
    if (isset($payload['login_web_'])) {
        echo 'WEB_LOGIN_KEY_EXISTS=YES' . PHP_EOL;
        echo 'WEB_ID=' . $payload['login_web_'] . PHP_EOL;
    } else {
        echo 'WEB_LOGIN_KEY_EXISTS=NO' . PHP_EOL;
    }
    
    // Show all keys
    echo 'ALL_KEYS=' . PHP_EOL;
    foreach ($payload as $key => $value) {
        echo '  ' . $key . ' = ' . var_export($value, true) . PHP_EOL;
    }
} else {
    echo 'SESSION_NOT_FOUND' . PHP_EOL;
}
