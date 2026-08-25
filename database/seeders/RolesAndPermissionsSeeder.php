<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds core RBAC permissions, roles, and the platform admin account.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Permissions ----
        $permissions = [
            'view bookings',
            'create bookings',
            'refund bookings',
            'manage agencies',
            'manage wallets',
            'approve deposits',
            'approve refunds',
            'view audit logs',
            'manage roles',
            'view reports',
            'manage agency users',
            'manage user wallets',
            'manage user pricing',
            'approve user deposits',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($name)], ['name' => $name]);
        }

        // ---- Roles ----
        RolesTableSeeder::ensure([
            'super-admin'      => ['name' => 'Super Admin', 'permissions' => $permissions],
            'support-agent'    => ['name' => 'Support Agent', 'permissions' => ['view bookings', 'refund bookings', 'view audit logs']],
            'agency-manager'   => ['name' => 'Agency Manager', 'permissions' => ['view bookings', 'create bookings', 'manage agency users', 'manage user wallets', 'manage user pricing', 'approve user deposits', 'view reports']],
            'agency-accountant'=> ['name' => 'Agency Accountant', 'permissions' => ['view bookings', 'view reports', 'manage user wallets', 'approve user deposits']],
            'agency-user'      => ['name' => 'Agency User', 'permissions' => ['view bookings', 'create bookings']],
        ]);

        // ---- Platform admin ----
        $email = env('ADMIN_EMAIL', 'admin@takamol.example.com');
        $admin = Admin::firstOrCreate(
            ['email' => $email],
            [
                'name'     => 'Platform Admin',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'ChangeMe123!')),
            ]
        );
        $admin->assignRole('Super Admin');
    }
}

/**
 * Helper — creates roles and links permissions.
 */
final class RolesTableSeeder
{
    public static function ensure(array $roles): void
    {
        foreach ($roles as $slug => $data) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $data['name']]);
            $permissionIds = Permission::whereIn('slug', array_map(fn ($p) => \Illuminate\Support\Str::slug($p), $data['permissions']))->pluck('id');
            $role->permissions()->sync($permissionIds);
        }
    }
}
