<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Total permissions: " . App\Models\Permission::count() . "\n";
echo "Total roles: " . App\Models\Role::count() . "\n\n";

$admin = App\Models\Admin::where('email', 'admin@takamol.com')->first();
echo "Admin: " . ($admin ? $admin->email : 'NOT FOUND') . "\n";
if ($admin) {
    echo "Role: " . $admin->roles()->pluck('name')->first() . "\n";
    echo "Has manage_agencies: " . ($admin->hasPermission('manage_agencies') ? 'YES' : 'NO') . "\n";
}
echo "\n";

echo "All permissions:\n";
foreach (App\Models\Permission::orderBy('name')->get() as $p) {
    echo "  - {$p->name} (slug: {$p->slug})\n";
}
echo "\n";

echo "All roles:\n";
foreach (App\Models\Role::with('permissions')->get() as $role) {
    echo "  - {$role->name} ({$role->slug})\n";
    echo "    Permissions: " . $role->permissions()->pluck('name')->implode(', ') . "\n";
}
