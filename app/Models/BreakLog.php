<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakLog extends Model
{
    protected $fillable = [
        'attendance_id',
        'employee_id',
        'break_start',
        'break_end',
        'duration_minutes',
        'pause_code',
    ];

    protected function casts(): array
    {
        return [
            'break_start' => 'datetime',
            'break_end' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isActive(): bool
    {
        return $this->break_end === null;
    }
}
