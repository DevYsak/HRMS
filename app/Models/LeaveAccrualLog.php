<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'leave_type_id', 'days_credited', 'year', 'month', 'credited_at'])]
class LeaveAccrualLog extends Model
{
    protected function casts(): array
    {
        return [
            'days_credited' => 'decimal:2',
            'year' => 'integer',
            'month' => 'integer',
            'credited_at' => 'datetime',
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
}
