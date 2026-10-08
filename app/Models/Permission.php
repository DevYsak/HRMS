<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Once;

#[Fillable(['key', 'label', 'module', 'description', 'is_scoped'])]
class Permission extends Model
{
    protected static function booted(): void
    {
        // Gate::before memoises the key set per request.
        static::saved(fn () => Once::flush());
        static::deleted(fn () => Once::flush());
    }

    protected function casts(): array
    {
        return ['is_scoped' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
