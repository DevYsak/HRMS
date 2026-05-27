<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'device_id', 'device_user_id', 'employee_id',
    'punched_at', 'punch_type', 'verify_type',
    'is_processed', 'attendance_id', 'process_error',
])]
class BiometricLog extends Model
{
    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'is_processed' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /** @return Builder<static> */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->where('is_processed', false);
    }

    /** @return Builder<static> */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('employee_id');
    }
}
