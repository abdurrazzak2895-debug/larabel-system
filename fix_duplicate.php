<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Directly delete the known duplicate by ID
$duplicate = App\Models\Permission::find(36);

if ($duplicate) {
    echo "Found duplicate: {$duplicate->name} (slug: {$duplicate->slug}, id: {$duplicate->id})\n";
    
    \Illuminate\Support\Facades\DB::table('admin_permission')->where('permission_id', $duplicate->id)->delete();
    \Illuminate\Support\Facades\DB::table('permission_role')->where('permission_id', $duplicate->id)->delete();
    \Illuminate\Support\Facades\DB::table('permission_user')->where('permission_id', $duplicate->id)->delete();
    
    $duplicate->delete();
    echo "Deleted duplicate permission\n";
} else {
    echo "No duplicate found with id 36\n";
}

echo "\nTotal permissions: " . App\Models\Permission::count() . "\n";

$admin = App\Models\Admin::where('email', 'admin@takamol.com')->first();
if ($admin) {
    echo "Admin has manage_agencies: " . ($admin->hasPermission('manage_agencies') ? 'YES' : 'NO') . "\n";
}
