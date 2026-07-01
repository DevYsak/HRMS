<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Per-notification delivery configuration controlled by admins.
 *
 * Keyed by the notification's fully-qualified class name. Lookups go through
 * {@see self::for()} which caches the full keyed map; the cache is flushed
 * automatically whenever a row is saved or deleted.
 */
class NotificationSetting extends Model
{
    private const CACHE_KEY = 'notification_settings_map';

    protected $fillable = [
        'key',
        'label',
        'group',
        'description',
        'mail_enabled',
        'database_enabled',
        'is_automatic',
        'custom_subject',
        'custom_body',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'mail_enabled' => 'boolean',
            'database_enabled' => 'boolean',
            'is_automatic' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    /**
     * Resolve the setting for a notification/mailable key (FQCN), or null when
     * none is configured. Reads from a cached, keyed map to avoid per-send queries.
     */
    public static function for(string $key): ?self
    {
        return self::cachedMap()->get($key);
    }

    /**
     * @return Collection<string, self>
     */
    public static function cachedMap(): Collection
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
            return self::query()->get()->keyBy('key');
        });
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
