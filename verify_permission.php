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
echo "Roles: " . $admin->roles()->pluck('name')->implode(', ') . "\n";
echo "hasPermission('manage_agencies'): " . ($admin->hasPermission('manage_agencies') ? 'yes' : 'no') . "\n";

// Check what the query is doing
$permissions = $admin->permissions()->get();
echo "Direct permissions count: " . $permissions->count() . "\n";

$rolePerms = $admin->roles()->first()->permissions()->get();
echo "Role permissions count: " . $rolePerms->count() . "\n";
echo "Role permission slugs: " . $rolePerms->pluck('slug')->implode(', ') . "\n";
