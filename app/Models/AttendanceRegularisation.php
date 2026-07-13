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
    'regularisation_type',
    'half_day_period',
    'requested_check_in',
    'requested_check_out',
    'check_in_method',
    'check_out_method',
    'reason',
    'attachment_path',
    'status',
    'stage',
    'reviewer_id',
    'reviewer_comment',
    'approval_trail',
    'reviewed_at',
])]
class AttendanceRegularisation extends Model
{
    /** Ordered approval chain: every stage must be cleared to reach approval. */
    public const STAGES = ['manager_review' => 1, 'hr_review' => 2, 'admin_approval' => 3];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'reviewed_at' => 'datetime',
            'approval_trail' => 'array',
            'claimed_at' => 'datetime',
        ];
    }

    /** Human label for the current workflow position. */
    public function stageLabel(): string
    {
        if ($this->status === 'approved') {
            return 'Approved';
        }
        if ($this->status === 'rejected') {
            return 'Rejected';
        }

        return match ($this->stage) {
            'hr_review' => 'HR Review',
            'admin_approval' => 'Admin Approval',
            default => 'Manager Review',
        };
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

    /** The HR admin currently handling this request (claim-lock). */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
