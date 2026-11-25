<?php

namespace App\Models;

use App\Services\Attendance\MissedPunchTracker;
use Database\Factories\MissedPunchFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee over the missed-punch allowance in one period, and HR's review
 * of it. See MissedPunchAllowance. The allowance is per calendar month
 * (period "month", period_start the 1st); flags raised while it was per
 * Monday–Friday week keep period "week", period_start the Monday.
 */
#[Fillable(['employee_id', 'period', 'period_start', 'missed_count', 'allowance', 'flagged_at', 'reviewed_by', 'reviewed_at', 'review_note'])]
class MissedPunchFlag extends Model
{
    /** @use HasFactory<MissedPunchFlagFactory> */
    use HasFactory;

    public const PERIOD_MONTH = 'month';

    public const PERIOD_WEEK = 'week';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'flagged_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'missed_count' => 'integer',
            'allowance' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Flags HR has not reviewed yet. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('reviewed_at');
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /** "November 2026", or "Week of 23 Nov 2026" for a flag from the weekly allowance. */
    public function periodLabel(): string
    {
        return $this->period === self::PERIOD_WEEK
            ? 'Week of '.$this->period_start->toImmutable()->userDate()
            : MissedPunchTracker::monthLabel($this->period_start);
    }
}
