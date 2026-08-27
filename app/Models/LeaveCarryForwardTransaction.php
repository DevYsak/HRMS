<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's carried leave for one leave type, from one year into the next.
 *
 * eligible_days is the engine's calculation; applied_days is what HR approved.
 * They differ whenever policy allows less than the full amount, and the
 * difference is what remainingEligible() reports.
 */
#[Fillable([
    'employee_id', 'leave_type_id', 'previous_leave_year_id', 'current_leave_year_id',
    'previous_allocated_days', 'previous_used_days', 'previous_encashed_days',
    'eligible_days', 'applied_days', 'reversed_days', 'status', 'reason',
    'applied_by', 'applied_at', 'reversed_by', 'reversed_at', 'reversal_reason',
])]
class LeaveCarryForwardTransaction extends Model
{
    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PARTIALLY_APPLIED = 'partially_applied';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_NOT_ELIGIBLE = 'not_eligible';

    protected function casts(): array
    {
        return [
            'previous_allocated_days' => 'float',
            'previous_used_days' => 'float',
            'previous_encashed_days' => 'float',
            'eligible_days' => 'float',
            'applied_days' => 'float',
            'reversed_days' => 'float',
            'applied_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function previousLeaveYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class, 'previous_leave_year_id');
    }

    public function currentLeaveYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class, 'current_leave_year_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** Days still available to carry after what HR actually approved. */
    public function remainingEligible(): float
    {
        return round(max(0, $this->eligible_days - $this->netApplied()), 2);
    }

    /** What is currently sitting in the employee's balance from this decision. */
    public function netApplied(): float
    {
        return round($this->applied_days - $this->reversed_days, 2);
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function isApplied(): bool
    {
        return in_array($this->status, [self::STATUS_APPLIED, self::STATUS_PARTIALLY_APPLIED], true);
    }

    /**
     * The status implied by the numbers, so callers never have to set it by
     * hand and get it wrong.
     */
    public function deriveStatus(): string
    {
        return match (true) {
            $this->reversed_days > 0 && $this->netApplied() <= 0 => self::STATUS_REVERSED,
            $this->eligible_days <= 0 => self::STATUS_NOT_ELIGIBLE,
            $this->netApplied() <= 0 => self::STATUS_ELIGIBLE,
            $this->netApplied() < $this->eligible_days => self::STATUS_PARTIALLY_APPLIED,
            default => self::STATUS_APPLIED,
        };
    }

    public function scopeForYear(Builder $query, int $currentLeaveYearId): Builder
    {
        return $query->where('current_leave_year_id', $currentLeaveYearId);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_APPLIED, self::STATUS_PARTIALLY_APPLIED]);
    }
}
