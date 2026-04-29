<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Incentive;

class IncentiveObserver
{
    public function created(Incentive $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(Incentive $model): void
    {
        AuditLog::record($model, 'updated', $model->getOriginal(), $model->getDirty());
    }

    public function deleted(Incentive $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
