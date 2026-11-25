<?php

namespace App\Models;

use App\Services\Notifications\NotificationCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * One department's rule for one notification event (or stage): on or off
 * for that department's people, extra recipients, exceptions, and "only
 * these people". See NotificationRecipientPolicy.
 */
#[Fillable(['notification_key', 'department_id', 'is_enabled', 'replace_defaults', 'include_roles', 'include_user_ids', 'exclude_roles', 'exclude_user_ids'])]
class NotificationDepartmentRule extends Model
{
    private const CACHE_KEY = 'notification_department_rules_map';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'replace_defaults' => 'boolean',
            'include_roles' => 'array',
            'include_user_ids' => 'array',
            'exclude_roles' => 'array',
            'exclude_user_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The rule for this event key and department: the stage's own rule when
     * it has one, else the event's. Null = no department rule.
     */
    public static function for(string $key, ?int $departmentId): ?self
    {
        if ($departmentId === null) {
            return null;
        }

        $map = self::cachedMap();

        return $map->get($key.'|'.$departmentId) ?? $map->get(NotificationCatalog::parentKey($key).'|'.$departmentId);
    }

    /** Whether the rule changes anything (an empty rule is the same as none). */
    public function isEmpty(): bool
    {
        return $this->is_enabled && ! $this->replace_defaults
            && empty($this->include_roles) && empty($this->include_user_ids)
            && empty($this->exclude_roles) && empty($this->exclude_user_ids);
    }

    /** @return Collection<string, self> keyed "key|department_id" */
    public static function cachedMap(): Collection
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addHour(),
                fn () => self::query()->get()->keyBy(fn (self $r) => $r->notification_key.'|'.$r->department_id));
        } catch (\Throwable) {
            return collect();   // not migrated yet: no department rules (fail open)
        }
    }
}
