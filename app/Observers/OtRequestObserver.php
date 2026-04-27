<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\OtRequest;

class OtRequestObserver
{
    public function created(OtRequest $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(OtRequest $model): void
    {
        AuditLog::record(
            $model,
            'updated',
            $model->getOriginal(),
            $model->getDirty(),
        );
    }

    public function deleted(OtRequest $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
