<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'leave_type_id', 'requested_days',
    'status', 'reviewer_id', 'reviewer_comment', 'reviewed_at', 'payout_month',
    'source_leave_year',
    'finance_reviewer_id', 'finance_reviewer_comment', 'finance_reviewed_at',
])]
class LeaveEncashment extends Model
{
    protected $casts = [
        'requested_days' => 'float',
        'reviewed_at' => 'datetime',
        'finance_reviewed_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

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

    public function financeReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_reviewer_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPendingFinance(): bool
    {
        return $this->status === 'pending_finance';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    /** The HR admin currently handling this request (claim-lock). */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
