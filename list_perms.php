<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "All permissions:\n";
foreach (App\Models\Permission::all() as $p) {
    echo "  - {$p->name} (slug: {$p->slug}, id: {$p->id})\n";
}
