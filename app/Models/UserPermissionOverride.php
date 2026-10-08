<?php

namespace App\Models;

use App\Enums\DataScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * One person's exception to their role for one permission: grant it (at a
 * scope) or revoke it. Wins over the role. Audited when changed.
 */
#[Fillable(['user_id', 'permission_id', 'effect', 'scope', 'department_ids', 'reason', 'created_by'])]
class UserPermissionOverride extends Model
{
    public const GRANT = 'grant';

    public const REVOKE = 'revoke';

    protected static function booted(): void
    {
        $flush = fn (UserPermissionOverride $override) => Cache::forget(self::cacheKey($override->user_id));

        static::saved($flush);
        static::deleted($flush);
    }

    public static function cacheKey(int $userId): string
    {
        return "user_{$userId}_permission_overrides";
    }

    protected function casts(): array
    {
        return [
            'scope' => DataScope::class,
            'department_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoke(): bool
    {
        return $this->effect === self::REVOKE;
    }
}
