<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Admin override for one employee-sidebar item. Read through the cached map;
 * the cache is flushed automatically on save/delete.
 */
class MenuSetting extends Model
{
    private const CACHE_KEY = 'menu_settings_map';

    protected $fillable = [
        'key',
        'label',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    /**
     * @return Collection<string, self>
     */
    public static function cachedMap(): Collection
    {
        // Fail-open even if the table has not been migrated yet: a missing
        // menu_settings table means "no overrides", not a broken sidebar.
        try {
            return Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => self::query()->get()->keyBy('key'));
        } catch (\Throwable) {
            return collect();
        }
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
