<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'sync_date',
    'net_hours',
    'shift_threshold',
    'ot_hours_detected',
    'ot_request_id',
    'action',
    'skip_reason',
])]
class NexflowOtSyncLog extends Model
{
    protected function casts(): array
    {
        return [
            'sync_date' => 'date',
            'net_hours' => 'decimal:2',
            'shift_threshold' => 'decimal:2',
            'ot_hours_detected' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function otRequest(): BelongsTo
    {
        return $this->belongsTo(OtRequest::class);
    }
}
