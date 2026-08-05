<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function assignRole(string|array ...$roles): static
    {
        $names = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        $this->roles()->syncWithoutDetaching(
            Role::whereIn('name', $names)->pluck('id')
        );

        return $this;
    }

    public function removeRole(string|array ...$roles): static
    {
        $names = is_array($roles[0] ?? null) ? $roles[0] : $roles;

        $this->roles()->detach(
            Role::whereIn('name', $names)->pluck('id')
        );

        return $this;
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->exists();
    }

    public function givePermissionTo(string|array ...$permissions): static
    {
        $names = is_array($permissions[0] ?? null) ? $permissions[0] : $permissions;

        $this->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', $names)->pluck('id')
        );

        return $this;
    }

    public function hasPermission(string $ability): bool
    {
        $slug = strtolower(str_replace(['_', ' '], '-', $ability));

        $direct = $this->permissions()->where('slug', $slug)->exists();

        if ($direct) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('slug', $slug))
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this instanceof \App\Models\Admin;
    }

    public function isAgencyAdmin(): bool
    {
        return $this instanceof \App\Models\User && $this->agency_id !== null;
    }

    public function isUser(): bool
    {
        return $this instanceof \App\Models\User && $this->agency_id === null;
    }
}
