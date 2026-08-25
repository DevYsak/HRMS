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
    'original_check_in', 'original_check_out',
    'check_in_ip', 'check_out_ip',
    'check_in_photo', 'check_out_photo',
    'check_in_user_agent', 'check_out_user_agent',
    'check_in_method', 'check_out_method',
    'check_in_lat', 'check_in_lng', 'check_out_lat', 'check_out_lng',
    'break_start', 'break_end', 'break_minutes',
    'status', 'work_mode', 'is_late', 'late_minutes',
    'is_verified', 'is_regularized', 'missing_checkout',
    'is_auto_checkout', 'auto_checkout_reason', 'excess_break_flag', 'total_hours', 'notes',
])]
class Attendance extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'original_check_in' => 'datetime',
            'original_check_out' => 'datetime',
            'break_start' => 'datetime',
            'break_end' => 'datetime',
            'is_verified' => 'boolean',
            'is_late' => 'boolean',
            'is_regularized' => 'boolean',
            'missing_checkout' => 'boolean',
            'is_auto_checkout' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function regularisation(): HasOne
    {
        // Latest request for the day, so a re-submitted/approved one wins over
        // an earlier rejected attempt.
        return $this->hasOne(AttendanceRegularisation::class)->latestOfMany();
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

    /**
     * Determine if this check-in is late for the given shift window.
     * Late = check_in later than (shift start + grace). Both values come from
     * the employee's assigned shift — never a hardcoded clock time.
     */
    public function computeLate(string $shiftStart, int $graceMinutes = 0): array
    {
        [$hour, $minute] = array_pad(explode(':', $shiftStart), 2, '0');
        $cutoff = $this->check_in->copy()->setTime((int) $hour, (int) $minute, 0)->addMinutes($graceMinutes);

        $late = $this->check_in->gt($cutoff);
        $lateMinutes = $late ? (int) $cutoff->diffInMinutes($this->check_in) : 0;

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
