<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Find all permissions with underscore slugs (created by the bad seedPermissions call)
$underscorePerms = App\Models\Permission::where('slug', 'like', '%\_%')->get();

echo "Found {$underscorePerms->count()} permissions with underscore slugs:\n";
foreach ($underscorePerms as $p) {
    echo "  - {$p->name} (slug: {$p->slug}, id: {$p->id})\n";
}

foreach ($underscorePerms as $p) {
    // Remove from pivot tables
    \Illuminate\Support\Facades\DB::table('admin_permission')->where('permission_id', $p->id)->delete();
    \Illuminate\Support\Facades\DB::table('permission_role')->where('permission_id', $p->id)->delete();
    \Illuminate\Support\Facades\DB::table('permission_user')->where('permission_id', $p->id)->delete();
    $p->delete();
    echo "Deleted: {$p->name}\n";
}

echo "\nTotal permissions after cleanup: " . App\Models\Permission::count() . "\n";

// Verify admin still has access
$admin = App\Models\Admin::where('email', 'admin@takamol.com')->first();
if ($admin) {
    echo "Admin has manage_agencies: " . ($admin->hasPermission('manage_agencies') ? 'YES' : 'NO') . "\n";
}
