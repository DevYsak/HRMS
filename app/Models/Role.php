<?php

namespace App\Models;

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
}
