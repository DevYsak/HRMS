<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id', 'leave_type_id',
    'start_date', 'end_date', 'is_half_day', 'half_day_period',
    'days', 'reason',
    'requested_leave_status', 'approved_leave_status',
    'payment_status_changed_by', 'payment_status_changed_at', 'payment_status_change_reason',
    'hr_remark', 'attachment_path',
    'status', 'approved_at',
    'reviewer_id', 'reviewer_comment',
    'hr_reviewer_id', 'hr_reviewer_comment', 'hr_reviewed_at',
    // Set when HR applied on the employee's behalf (hr_internal_note is never shown to them).
    'applied_by_user_id', 'hr_internal_note',
    // Leave V2: the LWP portion a policy converted, and the approval steps it required.
    'lwp_days', 'approval_path',
    // Leave V2: leave_type_id is the reason (Sick, Casual…); these say which
    // category it was requested under and which balance it charges.
    'main_category', 'balance_leave_type_id',
    // Leave V2: 'combined' for a date-wise allocated request (see LeaveRequestDay).
    'request_mode',
])]
class LeaveRequest extends Model
{
    use SoftDeletes;

    /** A Combined Leave request: one request, each date allocated full/half, paid/unpaid. */
    public const MODE_COMBINED = 'combined';

    /** HR proposed a change; the employee confirms or rejects it before any decision. */
    public const AWAITING_EMPLOYEE = 'awaiting_employee_confirmation';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'claimed_at' => 'datetime',
            'is_half_day' => 'boolean',
            'payment_status_changed_at' => 'datetime',
            'hr_reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'days' => 'decimal:2',
            'lwp_days' => 'decimal:2',
            'approval_path' => 'array',
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

    /** Requests by the employee to cancel this leave after it was approved (HR decides). */
    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(LeaveCancellationRequest::class)->latest('id');
    }

    /** HR-proposed changes to this request, newest first (original and proposed versions kept). */
    public function revisions(): HasMany
    {
        return $this->hasMany(LeaveRequestRevision::class)->latest('id');
    }

    /** The HR proposal the employee has not answered yet, if any. */
    public function pendingRevision(): HasOne
    {
        return $this->hasOne(LeaveRequestRevision::class)->where('status', LeaveRequestRevision::AWAITING)->latestOfMany();
    }

    public function isAwaitingEmployee(): bool
    {
        return $this->status === self::AWAITING_EMPLOYEE;
    }

    /** The cancellation request HR has not decided yet, if any. */
    public function pendingCancellation(): HasOne
    {
        return $this->hasOne(LeaveCancellationRequest::class)->where('status', LeaveCancellationRequest::PENDING)->latestOfMany();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveAttachment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LeaveMessage::class);
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

    /**
     * Days of this request drawn from the leave balance: all of them, less
     * any portion a V2 policy converted to Leave Without Pay. Historical
     * requests have no LWP portion, so they are unchanged.
     */
    public function paidDays(): float
    {
        return round(max(0.0, (float) $this->days - (float) ($this->lwp_days ?? 0)), 2);
    }

    /**
     * The leave type whose balance this request charges. A V2 Earned Leave
     * request charges the shared Earned Leave balance whatever its reason;
     * every historical request charges its own type, exactly as before.
     */
    public function balanceTypeId(): int
    {
        return (int) ($this->balance_leave_type_id ?? $this->leave_type_id);
    }

    /** Requests that charge the given leave type's balance (see balanceTypeId()). */
    public function scopeChargedTo(Builder $query, int $leaveTypeId): Builder
    {
        return $query->whereRaw('COALESCE(balance_leave_type_id, leave_type_id) = ?', [$leaveTypeId]);
    }

    /** The date-wise allocation of a Combined Leave request (none for a standard request). */
    public function dayAllocations(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class)->orderBy('date');
    }

    public function isCombined(): bool
    {
        return $this->request_mode === self::MODE_COMBINED;
    }

    /** Units on Leave Without Pay: a Combined request's unpaid dates, or a V2 LWP portion. */
    public function unpaidUnits(): float
    {
        return round((float) ($this->lwp_days ?? 0), 2);
    }

    public function balanceLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'balance_leave_type_id')->withTrashed();
    }

    /** Effective paid/unpaid status — approved overrides requested. */
    public function effectiveLeaveStatus(): string
    {
        return $this->approved_leave_status
            ?? $this->requested_leave_status
            ?? ($this->leaveType?->is_paid ? 'paid' : 'unpaid');
    }

    /** The HR admin currently handling this request (claim-lock). */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
