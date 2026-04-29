<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Reimbursement;

class ReimbursementObserver
{
    public function created(Reimbursement $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(Reimbursement $model): void
    {
        AuditLog::record($model, 'updated', $model->getOriginal(), $model->getDirty());
    }

    public function deleted(Reimbursement $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
