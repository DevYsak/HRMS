<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An HR edit to a leave request: the submitted version, the proposed version,
 * who proposed it and the employee's answer. Both versions are kept for good —
 * the request row only ever holds the version currently in force.
 */
#[Fillable([
    'leave_request_id', 'employee_id', 'proposed_by', 'original', 'proposed', 'changed_fields', 'reason',
    'requires_confirmation', 'status', 'responded_by', 'responded_at', 'response_note',
])]
class LeaveRequestRevision extends Model
{
    public const AWAITING = 'awaiting_employee';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    /** A non-material edit HR applied directly (no confirmation needed). */
    public const APPLIED = 'applied';

    protected function casts(): array
    {
        return [
            'original' => 'array',
            'proposed' => 'array',
            'changed_fields' => 'array',
            'requires_confirmation' => 'boolean',
            'responded_at' => 'datetime',
        ];
    }

    public function isAwaiting(): bool
    {
        return $this->status === self::AWAITING;
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
