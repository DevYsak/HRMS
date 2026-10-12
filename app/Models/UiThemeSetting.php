<?php

namespace App\Models;

use App\Services\Appearance\UiTheme;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single row of company-wide UI settings. Read through UiTheme, which
 * validates every value and falls back to the current design.
 */
#[Fillable(['settings', 'updated_by'])]
class UiThemeSetting extends Model
{
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        // The generated stylesheet is cached; any change must show at once.
        static::saved(fn () => UiTheme::flush());
        static::deleted(fn () => UiTheme::flush());
    }

    /** The one row (created empty — i.e. "current design" — on first use). */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(['settings' => []]);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
