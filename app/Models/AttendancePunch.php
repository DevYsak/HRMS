<?php

namespace App\Models;

use App\Enums\PunchMethod;
use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single biometric/web punch event — the building block of the Attendance
 * Journey timeline. Many per employee per day; deduped on (employee, time).
 */
#[Fillable([
    'employee_id', 'employee_code', 'punched_at', 'punch_date',
    'method', 'verify_raw', 'source', 'device_serial', 'location', 'lat', 'lng',
])]
class AttendancePunch extends Model
{
    /** @use HasFactory<AttendancePunchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'punch_date' => 'date',
            'employee_code' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function methodEnum(): ?PunchMethod
    {
        return PunchMethod::tryFrom((string) $this->method);
    }
}
