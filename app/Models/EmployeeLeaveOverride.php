<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee-specific entitlement exception (mode add: +days on top of the
 * policy; mode set: replaces it). Revoked, never deleted.
 */
#[Fillable([
    'employee_id', 'leave_type_id', 'leave_year_id', 'mode', 'days', 'effective_from', 'reason',
    'created_by', 'revoked_at', 'revoked_by', 'revoke_reason',
])]
class EmployeeLeaveOverride extends Model
{
    public const MODE_ADD = 'add';

    public const MODE_SET = 'set';

    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'effective_from' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    /** Live overrides that apply to a leave year: scoped to it, or open-ended and effective by its end. */
    public function scopeApplicableTo(Builder $query, LeaveYear $year): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)
                ->orWhere(fn ($w) => $w->whereNull('leave_year_id')
                    ->where(fn ($d) => $d->whereNull('effective_from')->orWhereDate('effective_from', '<=', $year->ends_on->toDateString()))));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function leaveYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
