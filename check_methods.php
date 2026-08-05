<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$m = get_class_methods(\Illuminate\Http\Request::class);
foreach ($m as $method) {
    if (stripos($method, 'user') !== false) {
        echo $method . PHP_EOL;
    }
}
