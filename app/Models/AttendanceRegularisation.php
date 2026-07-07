<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'attendance_id',
    'work_date',
    'requested_check_in',
    'requested_check_out',
    'check_in_method',
    'check_out_method',
    'reason',
    'status',
    'reviewer_id',
    'reviewer_comment',
    'reviewed_at',
])]
class AttendanceRegularisation extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return Builder<static> */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
