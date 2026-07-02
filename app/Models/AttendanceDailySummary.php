<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-day attendance roll-up calculated by the external Python attendance
 * engine and ingested via POST /api/v1/attendance/sync.
 *
 * Keyed by (employee_id, date); upserted on every sync so re-runs are idempotent.
 */
#[Fillable([
    'employee_id', 'employee_code', 'date',
    'first_punch', 'last_punch',
    'first_punch_method', 'last_punch_method',
    'break_minutes', 'working_hours',
    'late_minutes', 'early_leave_minutes', 'overtime_minutes',
    'status', 'device_serial', 'raw_punch_count', 'synced_at',
])]
class AttendanceDailySummary extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'first_punch' => 'datetime',
            'last_punch' => 'datetime',
            'synced_at' => 'datetime',
            'break_minutes' => 'integer',
            'working_hours' => 'decimal:2',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'raw_punch_count' => 'integer',
            'employee_code' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
