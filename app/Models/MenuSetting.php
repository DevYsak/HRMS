<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Admin override for one employee-sidebar item.
 *
 * Read directly (not cached): the table is tiny and read once per render, and a
 * long-lived cache made saved changes reappear on the next request when the
 * cache was not invalidated across workers.
 */
class MenuSetting extends Model
{
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

    /**
     * Every override keyed by menu key. Fail-open if the table is absent
     * (unmigrated) — returns an empty map so the sidebar uses its defaults.
     *
     * @return Collection<string, self>
     */
    public static function map(): Collection
    {
        try {
            return self::query()->get()->keyBy('key');
        } catch (\Throwable) {
            return collect();
        }
    }
}
