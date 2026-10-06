<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * HR's choice for one self-service profile field (or KYC document type,
 * keyed "kyc:<type>"): Required, Optional or HR-only. No row means the coded
 * default in ProfileFieldRegistry.
 */
#[Fillable(['field_key', 'requirement', 'updated_by'])]
class ProfileFieldSetting extends Model
{
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const HR_ONLY = 'hr_only';

    public const REQUIREMENTS = [self::REQUIRED, self::OPTIONAL, self::HR_ONLY];

    private const CACHE_KEY = 'profile_field_settings_map';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * field_key => requirement, for every field HR has set. Fails open to an
     * empty map (the coded defaults) if the table is not there yet.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('requirement', 'field_key')->all());
        } catch (\Throwable) {
            return [];
        }
    }
}
