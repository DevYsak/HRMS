<?php

namespace App\Models;

use App\Services\Appearance\UiTheme;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'name', 'logo', 'logo_dark', 'logo_mark', 'logo_mark_dark', 'favicon', 'website', 'industry', 'phone', 'email',
    'address', 'address_line2', 'cin', 'city', 'country', 'holiday_calendar', 'default_state_code', 'timezone', 'date_format',
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
     * Nexcore_new_logo-02.png (white wordmark for dark surfaces) placed on the
     * light logo's exact canvas, so swapping themes never moves or resizes it.
     */
    public const FALLBACK_LOGO_DARK = 'nexcore-logo-dark.png';

    /** The wordmark's X, squared, for the folded sidebar rail (light / dark). */
    public const FALLBACK_MARK = 'nexcore-mark.png';

    public const FALLBACK_MARK_DARK = 'nexcore-mark-dark.png';

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
        return self::storedBrandUrl(self::brandCompany()?->logo) ?? asset(self::FALLBACK_LOGO);
    }

    /**
     * The wordmark for dark surfaces, or null when the light logo should be
     * repainted white instead: an uploaded logo with no dark version must not
     * be swapped for the bundled Nexcore artwork.
     */
    public static function brandLogoDarkUrl(): ?string
    {
        $company = self::brandCompany();

        if ($dark = self::storedBrandUrl($company?->logo_dark)) {
            return $dark;
        }

        return self::storedBrandUrl($company?->logo) ? null : asset(self::FALLBACK_LOGO_DARK);
    }

    /**
     * The square icon for the folded sidebar, or null when there is none (an
     * uploaded logo without a mark) and the initial should stand in.
     */
    public static function brandMarkUrl(): ?string
    {
        $company = self::brandCompany();

        if ($mark = self::storedBrandUrl($company?->logo_mark)) {
            return $mark;
        }

        return self::storedBrandUrl($company?->logo) ? null : asset(self::FALLBACK_MARK);
    }

    /**
     * The folded-rail icon for dark surfaces, or null to repaint the light
     * mark (or show the initial) instead.
     */
    public static function brandMarkDarkUrl(): ?string
    {
        $company = self::brandCompany();

        if ($dark = self::storedBrandUrl($company?->logo_mark_dark)) {
            return $dark;
        }

        return self::storedBrandUrl($company?->logo_mark) || self::storedBrandUrl($company?->logo)
            ? null
            : asset(self::FALLBACK_MARK_DARK);
    }

    /** Public URL of an uploaded branding file, or null when unset or missing. */
    private static function storedBrandUrl(?string $path): ?string
    {
        return $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The brand colour documents use: Brand & Layout's primary — the colour the
     * app itself shows — so PDFs, letters and the screens never disagree.
     */
    public static function brandColor(): string
    {
        return (string) (rescue(fn () => app(UiTheme::class)->current()['primary_light'], null, false)
            ?: UiTheme::SCHEMA['primary_light']['default']);
    }

    /**
     * A filesystem path to the logo for PDFs (dompdf reads files, not URLs):
     * the uploaded logo when its file exists — never a missing file — else the
     * bundled Nexcore mark, the same choice as brandLogoUrl().
     *
     * The bundled mark is a PNG, and dompdf needs PHP's GD extension to embed
     * a PNG. Without GD it is left out, so a document renders without a logo
     * (as it always did with nothing uploaded) instead of failing.
     */
    public static function brandLogoPath(): ?string
    {
        $logo = self::brandCompany()?->logo;
        if ($logo && Storage::disk('public')->exists($logo)) {
            return Storage::disk('public')->path($logo);
        }

        $fallback = public_path(self::FALLBACK_LOGO);

        return extension_loaded('gd') && is_file($fallback) ? $fallback : null;
    }

    /** The uploaded favicon's URL when its file exists; null means use the bundled icons. */
    public static function brandFaviconUrl(?string $path = null): ?string
    {
        return self::storedBrandUrl($path ?? self::brandCompany()?->favicon);
    }

    /** Alt text for the logo — the configured company name, or the app name. */
    public static function brandName(): string
    {
        return self::brandCompany()?->name ?: config('app.name');
    }
}
