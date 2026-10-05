<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's attendance for one calendar month, written on the 1st by
 * hrms:generate-attendance-summary (spec §7). Keyed by (employee_id, month)
 * and upserted, so re-running the job for a month replaces its figures.
 */
#[Fillable([
    'employee_id', 'month', 'days_recorded', 'scheduled_days', 'weekly_off_days', 'weekly_off_worked_days', 'present_days', 'late_days',
    'excess_break_days', 'missing_checkouts', 'total_hours', 'total_break_minutes',
    'leave_days', 'generated_at',
])]
class AttendanceMonthlySummary extends Model
{
    protected function casts(): array
    {
        return [
            'total_hours' => 'decimal:2',
            'leave_days' => 'decimal:1',
            'generated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
