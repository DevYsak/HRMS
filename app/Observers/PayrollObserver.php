<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Payroll;

class PayrollObserver
{
    public function created(Payroll $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(Payroll $model): void
    {
        AuditLog::record($model, 'updated', $model->getOriginal(), $model->getDirty());
    }

    public function deleted(Payroll $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
