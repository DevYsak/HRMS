<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the year-end rollover did for one employee, leave type and closing
 * year. Unique per (employee, type, closing year): the idempotency anchor.
 */
#[Fillable([
    'employee_id', 'leave_type_id', 'from_leave_year_id', 'to_leave_year_id', 'bulk_run_id', 'status',
    'closing_days', 'carry_days', 'expired_days', 'new_base_days', 'carry_forward_transaction_id',
    'message', 'processed_by', 'processed_at',
])]
class LeaveRolloverRecord extends Model
{
    public const PROCESSED = 'processed';

    public const NEEDS_HR_REVIEW = 'needs_hr_review';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'closing_days' => 'decimal:2',
            'carry_days' => 'decimal:2',
            'expired_days' => 'decimal:2',
            'new_base_days' => 'decimal:2',
            'processed_at' => 'datetime',
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

    public function fromYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class, 'from_leave_year_id');
    }

    public function toYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class, 'to_leave_year_id');
    }
}
