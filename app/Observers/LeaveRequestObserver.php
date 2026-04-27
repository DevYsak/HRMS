<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\LeaveRequest;

class LeaveRequestObserver
{
    public function created(LeaveRequest $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(LeaveRequest $model): void
    {
        AuditLog::record(
            $model,
            'updated',
            $model->getOriginal(),
            $model->getDirty(),
        );
    }

    public function deleted(LeaveRequest $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
