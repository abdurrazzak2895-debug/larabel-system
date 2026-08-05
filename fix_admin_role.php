<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$admin = App\Models\Admin::first();

if (!$admin) {
    echo "No admin found\n";
    exit(1);
}

echo "Admin: {$admin->email}\n";
$admin->assignRole('Super Admin');
echo "Assigned Super Admin role\n";
echo "Roles: " . $admin->roles()->pluck('name')->implode(', ') . "\n";
echo "hasPermission('manage_agencies'): " . ($admin->hasPermission('manage_agencies') ? 'yes' : 'no') . "\n";
