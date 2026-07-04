<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[Fillable(['name', 'slug', 'description', 'is_system', 'is_active'])]
class Role extends Model
{
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    /**
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        return Cache::remember(
            "role_{$this->id}_permission_keys",
            300,
            fn () => $this->permissions()->pluck('key')->all(),
        );
    }

    public function flushPermissionCache(): void
    {
        Cache::forget("role_{$this->id}_permission_keys");
    }

    /**
     * The legacy UserRole bucket this role maps to, for sidebar navigation
     * branching (which is still keyed to the fixed 6-case enum, not the
     * DB role/permission system). A built-in role (slug matches an enum
     * case) maps 1:1. A genuinely custom role — the whole point of the
     * Role Manager — has no enum case, so it's bucketed by what its
     * permissions imply, broadest capability first. Real feature gates
     * everywhere else still check hasPermission()/the real role, so this
     * only decides which sidebar section a custom-role user lands in.
     */
    public function legacyBucket(): UserRole
    {
        if ($known = UserRole::tryFrom($this->slug)) {
            return $known;
        }

        $keys = $this->permissionKeys();

        return match (true) {
            in_array('manage_settings', $keys, true) || in_array('manage_employees', $keys, true) => UserRole::HrAdmin,
            in_array('approve_finance', $keys, true) || in_array('run_payroll', $keys, true) => UserRole::Finance,
            in_array('approve_leave', $keys, true) || in_array('approve_overtime', $keys, true)
                || in_array('approve_wfh', $keys, true) || in_array('approve_regularisation', $keys, true) => UserRole::Manager,
            default => UserRole::Employee,
        };
    }
}
