<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'employee_id',
    'leave_type_id',
    'allocated_days',
    'used_days',
    'carried_forward_days',
    'encashed_days',
    'comp_off_credits',
    'year',
    // The authoritative link to the leave year. Absent from fillable, every
    // mass assignment left it null and the row was only ever found by the
    // legacy-integer fallback.
    'leave_year_id',
    // Whether the figure beside it is a measurement or a placeholder. A
    // closed year we never had usage data for must not claim zero.
    'used_days_unknown',
    'encashed_days_unknown',
])]
class LeaveBalance extends Model
{
    protected function casts(): array
    {
        return [
            'allocated_days' => 'decimal:2',
            'used_days' => 'decimal:2',
            'carried_forward_days' => 'decimal:2',
            'encashed_days' => 'decimal:2',
            'comp_off_credits' => 'decimal:2',
            'year' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LeaveBalanceAdjustment::class, 'leave_type_id', 'leave_type_id')
            ->where('employee_id', $this->employee_id);
    }

    /** Available balance = allocated - used - encashed. */
    public function available(): float
    {
        return max(0, (float) $this->allocated_days - (float) $this->used_days - (float) ($this->encashed_days ?? 0));
    }

    /** Days pending approval from current leave requests. */
    public function pendingDays(): float
    {
        return (float) LeaveRequest::where('employee_id', $this->employee_id)
            ->where('leave_type_id', $this->leave_type_id)
            ->whereIn('status', ['pending', 'pending_hr'])
            ->whereYear('start_date', $this->year)
            ->sum('days');
    }
}
