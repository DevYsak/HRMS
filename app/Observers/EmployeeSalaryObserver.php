<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\EmployeeSalary;

class EmployeeSalaryObserver
{
    public function created(EmployeeSalary $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(EmployeeSalary $model): void
    {
        AuditLog::record(
            $model,
            'updated',
            $model->getOriginal(),
            $model->getDirty(),
        );
    }

    public function deleted(EmployeeSalary $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
