<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'SESSIONS_COUNT=' . DB::table('sessions')->count() . PHP_EOL;
$sessions = DB::table('sessions')->get();
foreach ($sessions as $session) {
    echo 'SESSION_ID=' . $session->id . PHP_EOL;
    echo 'SESSION_USER_ID=' . ($session->user_id ?? 'NULL') . PHP_EOL;
    echo 'SESSION_IP=' . ($session->ip_address ?? 'NULL') . PHP_EOL;
    echo 'SESSION_PAYLOAD_LENGTH=' . strlen($session->payload) . PHP_EOL;
}
