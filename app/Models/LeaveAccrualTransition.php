<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Monthly Accrual Starts From" for one employee: the 1st of the month from
 * which automatic Earned Leave accrual is responsible for them. The balance
 * they held before is left exactly as it is; earlier months are never
 * backfilled. Changed only through LeaveAccrualTransitionService (audited).
 */
#[Fillable(['employee_id', 'starts_on', 'reason', 'set_by'])]
class LeaveAccrualTransition extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
