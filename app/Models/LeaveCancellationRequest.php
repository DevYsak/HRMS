<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee asking HR to cancel leave that is already approved. Until HR
 * approves it the leave stays approved and its days stay deducted.
 */
#[Fillable([
    'leave_request_id', 'employee_id', 'requested_by', 'reason', 'status',
    'decided_by', 'decided_at', 'decision_note',
])]
class LeaveCancellationRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
