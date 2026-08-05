<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Find duplicate: permission with slug 'manage_agencies' and name 'manage_agencies'
$duplicate = App\Models\Permission::where('slug', 'manage_agencies')->where('name', 'manage_agencies')->first();

if ($duplicate) {
    echo "Found duplicate permission: {$duplicate->name} (slug: {$duplicate->slug}, id: {$duplicate->id})\n";
    
    // Remove from admin_permission pivot
    $adminPermCount = \Illuminate\Support\Facades\DB::table('admin_permission')->where('permission_id', $duplicate->id)->count();
    echo "Removing from {$adminPermCount} admin_permission records\n";
    \Illuminate\Support\Facades\DB::table('admin_permission')->where('permission_id', $duplicate->id)->delete();
    
    // Remove from permission_role pivot
    $permRoleCount = \Illuminate\Support\Facades\DB::table('permission_role')->where('permission_id', $duplicate->id)->count();
    echo "Removing from {$permRoleCount} permission_role records\n";
    \Illuminate\Support\Facades\DB::table('permission_role')->where('permission_id', $duplicate->id)->delete();
    
    // Remove from permission_user pivot
    $permUserCount = \Illuminate\Support\Facades\DB::table('permission_user')->where('permission_id', $duplicate->id)->count();
    echo "Removing from {$permUserCount} permission_user records\n";
    \Illuminate\Support\Facades\DB::table('permission_user')->where('permission_id', $duplicate->id)->delete();
    
    // Delete the permission
    $duplicate->delete();
    echo "Deleted duplicate permission\n";
} else {
    echo "No duplicate permission found\n";
}

echo "\nTotal permissions after cleanup: " . App\Models\Permission::count() . "\n";

// Verify admin still has access
$admin = App\Models\Admin::where('email', 'admin@takamol.com')->first();
if ($admin) {
    echo "Admin has manage_agencies: " . ($admin->hasPermission('manage_agencies') ? 'YES' : 'NO') . "\n";
}
