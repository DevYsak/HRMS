<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'employee_id', 'date', 'check_in', 'check_out',
    'check_in_ip', 'check_out_ip',
    'check_in_photo', 'check_out_photo',
    'check_in_lat', 'check_in_lng', 'check_out_lat', 'check_out_lng',
    'break_start', 'break_end', 'break_minutes',
    'status', 'work_mode', 'is_late', 'late_minutes',
    'is_verified', 'missing_checkout', 'excess_break_flag', 'total_hours', 'notes',
])]
class Attendance extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'break_start' => 'datetime',
            'break_end' => 'datetime',
            'is_verified' => 'boolean',
            'is_late' => 'boolean',
            'missing_checkout' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function regularisation(): HasOne
    {
        return $this->hasOne(AttendanceRegularisation::class);
    }

    public function breakLogs(): HasMany
    {
        return $this->hasMany(BreakLog::class);
    }

    public function activeBreak(): HasOne
    {
        return $this->hasOne(BreakLog::class)->whereNull('break_end');
    }

    /** Net hours worked after deducting break time. */
    public function netHours(): float
    {
        if (! $this->check_out) {
            return 0.0;
        }

        $gross = $this->check_in->floatDiffInHours($this->check_out);

        return max(0, round($gross - ($this->break_minutes / 60), 2));
    }

    /** Determine if this check-in is late relative to an expected time (default 09:00). */
    public function computeLate(string $expectedCheckIn = '09:00'): array
    {
        [$hour, $minute] = explode(':', $expectedCheckIn);
        $expected = $this->check_in->copy()->setTime((int) $hour, (int) $minute, 0);

        $late = $this->check_in->gt($expected);
        $lateMinutes = $late ? (int) $expected->diffInMinutes($this->check_in) : 0;

        return ['is_late' => $late, 'late_minutes' => $lateMinutes];
    }

    /** @return Builder<static> */
    public function scopeMissingCheckout(Builder $query): Builder
    {
        return $query->whereNull('check_out');
    }

    /** @return Builder<static> */
    public function scopeLate(Builder $query): Builder
    {
        return $query->where('is_late', true);
    }
}
