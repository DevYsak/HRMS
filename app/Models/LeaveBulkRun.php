<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bulk leave operation (rollover, reconciliation, bulk provisioning,
 * bulk add-on): who ran it, preview or applied, and what it reported.
 */
#[Fillable([
    'kind', 'leave_year_id', 'target_leave_year_id', 'dry_run', 'status', 'summary', 'parameters',
    'created_by', 'completed_at',
])]
class LeaveBulkRun extends Model
{
    public const KIND_ROLLOVER = 'rollover';

    public const KIND_RECONCILIATION = 'reconciliation';

    public const KIND_BULK_PROVISION = 'bulk_provision';

    public const KIND_BULK_ADD_ON = 'bulk_add_on';

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'summary' => 'array',
            'parameters' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function leaveYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class);
    }

    public function targetLeaveYear(): BelongsTo
    {
        return $this->belongsTo(LeaveYear::class, 'target_leave_year_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
