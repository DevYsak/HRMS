<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real period, with a start and an end.
 *
 * Leave years were a smallint holding 2026, which can only describe
 * 1 January to 31 December. Conexus runs 1 July to 30 June, so "2026" was
 * never able to say which twelve months a balance belonged to — and every
 * accrual, carry-over and pro-rata calculation depends on knowing that.
 */
#[Fillable(['label', 'starts_on', 'ends_on', 'is_closed'])]
class LeaveYear extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_closed' => 'boolean',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function contains(CarbonInterface $date): bool
    {
        return $date->betweenIncluded($this->starts_on, $this->ends_on);
    }

    /** Whole days in the year — 365, or 366 across a leap February. */
    public function lengthInDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** The legacy smallint, for code that has not moved across yet. */
    public function legacyYear(): int
    {
        return (int) $this->starts_on->year;
    }
}
