<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['leave_request_id', 'changed_by', 'from_status', 'to_status', 'reason', 'stage'])]
class LeavePaymentAuditLog extends Model
{
    // Immutable audit log — no SoftDeletes, no update after creation

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
