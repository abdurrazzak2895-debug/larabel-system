<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;

class PermissionService
{
    public static function rolePermissions(): array
    {
        return [
            'super_admin' => [
                'manage_agencies', 'manage_users', 'wallet_adjustments',
                'pricing', 'credit_limits', 'reports', 'api_settings',
                'global_notifications', 'audit_logs', 'system_configuration',
            ],
            'agency_admin' => [
                'agency_dashboard', 'agency_users', 'wallet_view',
                'deposits', 'refund_requests', 'bookings', 'reports',
                'notifications', 'profile', 'agency_staff',
            ],
            'user' => [
                'dashboard', 'bookings', 'wallet', 'deposit_requests',
                'booking_history', 'notifications', 'profile',
            ],
        ];
    }

    public static function seedPermissions(): void
    {
        foreach (self::rolePermissions() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'slug' => $roleName]);
            foreach ($permissions as $perm) {
                Permission::firstOrCreate(['name' => $perm, 'slug' => $perm, 'guard_name' => 'web']);
            }
        }
    }
}