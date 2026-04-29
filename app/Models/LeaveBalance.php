<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'leave_type_id',
    'allocated_days',
    'used_days',
    'carried_forward_days',
    'encashed_days',
    'comp_off_credits',
    'year',
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
        return $this->belongsTo(LeaveType::class);
    }
}
