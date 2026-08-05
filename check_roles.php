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

echo "Admin ID: {$admin->id}\n";
echo "Admin: {$admin->email}\n";

// Check raw pivot tables
$adminRoleCount = \Illuminate\Support\Facades\DB::table('admin_role')->where('admin_id', $admin->id)->count();
echo "admin_role records for this admin: {$adminRoleCount}\n";

$roleUserCount = \Illuminate\Support\Facades\DB::table('role_user')->where('user_id', $admin->id)->count();
echo "role_user records for this admin: {$roleUserCount}\n";

// Check all admin_role records
$allAdminRoles = \Illuminate\Support\Facades\DB::table('admin_role')->get();
echo "All admin_role records: " . $allAdminRoles->count() . "\n";
foreach ($allAdminRoles as $record) {
    echo "  - role_id: {$record->role_id}, admin_id: {$record->admin_id}\n";
}

// Check all role_user records
$allRoleUsers = \Illuminate\Support\Facades\DB::table('role_user')->get();
echo "All role_user records: " . $allRoleUsers->count() . "\n";
foreach ($allRoleUsers as $record) {
    echo "  - role_id: {$record->role_id}, user_id: {$record->user_id}\n";
}

// Check role permissions
$role = \App\Models\Role::where('slug', 'super-admin')->first();
if ($role) {
    echo "\nRole 'super-admin' ID: {$role->id}\n";
    $permCount = \Illuminate\Support\Facades\DB::table('permission_role')->where('role_id', $role->id)->count();
    echo "permission_role records for this role: {$permCount}\n";
}
