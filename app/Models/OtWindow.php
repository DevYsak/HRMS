<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'reason', 'starts_at', 'ends_at', 'is_active', 'created_by'])]
class OtWindow extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Check whether an OT window is currently open for the given date (defaults to today). */
    public static function isOpenFor(?CarbonInterface $date = null): bool
    {
        $date = ($date ?? now())->toDateString();

        return static::where('is_active', true)
            ->where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->exists();
    }

    /** Return the active window covering today, or null. */
    public static function activeToday(): ?self
    {
        $today = now()->toDateString();

        return static::where('is_active', true)
            ->where('starts_at', '<=', $today)
            ->where('ends_at', '>=', $today)
            ->latest()
            ->first();
    }
}
