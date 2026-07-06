<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a leave request's conversation thread. Posted by either the
 * employee or a reviewer (manager/HR), optionally carrying a single small
 * attachment. Used when a reviewer needs clarification before deciding.
 */
#[Fillable(['leave_request_id', 'user_id', 'body', 'attachment_path', 'attachment_name'])]
class LeaveMessage extends Model
{
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
