<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'name', 'logo', 'favicon', 'website', 'industry', 'phone', 'email',
    'address', 'address_line2', 'cin', 'city', 'country', 'default_state_code', 'timezone', 'date_format',
    'currency', 'currency_symbol', 'primary_color', 'secondary_color',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function jobTitles(): HasMany
    {
        return $this->hasMany(JobTitle::class);
    }

    /**
     * Get the headquarters office for this company.
     */
    public function headquarters(): ?Office
    {
        return $this->offices()->where('is_headquarters', true)->first();
    }

    /**
     * Get a formatted display name with city.
     */
    public function displayName(): string
    {
        return $this->city
            ? "{$this->name} ({$this->city})"
            : $this->name;
    }

    /**
     * Bundled mark used until HR uploads one in Settings → General.
     *
     * This is a whitespace-trimmed copy of the supplied Nexcore_new_logo-01.png,
     * which carries ~30% transparent padding on every side. Rendering the
     * original at a 48px box would show only ~19px of actual wordmark, so the
     * padding is cropped once here rather than fought with negative margins on
     * every surface. The original file is kept untouched beside it.
     */
    public const FALLBACK_LOGO = 'nexcore-logo.png';

    /**
     * The company whose branding the chrome displays.
     *
     * Explicitly the oldest row, matching the Company::first() the sidebar used
     * before. Fetched once per request and shared by every branding accessor,
     * so the logo and the name it labels can never come from different rows.
     */
    public static function brandCompany(): ?self
    {
        return once(fn (): ?self => static::query()->oldest('id')->first());
    }

    /**
     * The logo every branded surface should render.
     *
     * The sidebar, the login page and any future surface all read the same
     * URL, so uploading a new logo in settings changes all of them at once and
     * none of them hardcode a filename. A row pointing at a file that no
     * longer exists falls back rather than rendering a broken image.
     */
    public static function brandLogoUrl(): string
    {
        $logo = self::brandCompany()?->logo;

        return $logo && Storage::disk('public')->exists($logo)
            ? Storage::disk('public')->url($logo)
            : asset(self::FALLBACK_LOGO);
    }

    /** Alt text for the logo — the configured company name, or the app name. */
    public static function brandName(): string
    {
        return self::brandCompany()?->name ?: config('app.name');
    }
}
