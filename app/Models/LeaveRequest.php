<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id', 'leave_type_id',
    'start_date', 'end_date', 'is_half_day', 'half_day_period',
    'days', 'reason',
    'requested_leave_status', 'approved_leave_status',
    'payment_status_changed_by', 'payment_status_changed_at', 'payment_status_change_reason',
    'hr_remark', 'attachment_path',
    'status',
    'reviewer_id', 'reviewer_comment',
    'hr_reviewer_id', 'hr_reviewer_comment', 'hr_reviewed_at',
])]
class LeaveRequest extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_half_day' => 'boolean',
            'payment_status_changed_at' => 'datetime',
            'hr_reviewed_at' => 'datetime',
            'days' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function hrReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_reviewer_id');
    }

    public function paymentStatusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_status_changed_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveAttachment::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(LeaveEscalation::class);
    }

    public function paymentAuditLogs(): HasMany
    {
        return $this->hasMany(LeavePaymentAuditLog::class);
    }

    public function isPendingHr(): bool
    {
        return $this->status === 'pending_hr';
    }

    public function isPending(): bool
    {
        return \in_array($this->status, ['pending', 'pending_hr'], true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** Effective paid/unpaid status — approved overrides requested. */
    public function effectiveLeaveStatus(): string
    {
        return $this->approved_leave_status
            ?? $this->requested_leave_status
            ?? ($this->leaveType?->is_paid ? 'paid' : 'unpaid');
    }
}
