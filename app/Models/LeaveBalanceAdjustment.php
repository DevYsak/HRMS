<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'leave_type_id',
    'action',
    'days',
    'previous_balance',
    'new_balance',
    'reason',
    'remarks',
    'adjusted_by',
    'adjusted_at',
])]
class LeaveBalanceAdjustment extends Model
{
    // Immutable audit log — no SoftDeletes, never update after creation

    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'previous_balance' => 'decimal:2',
            'new_balance' => 'decimal:2',
            'adjusted_at' => 'datetime',
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

    public function adjustedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
