<?php

namespace App\Observers;

use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;

class AttendanceRegularisationObserver
{
    public function created(AttendanceRegularisation $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(AttendanceRegularisation $model): void
    {
        AuditLog::record($model, 'updated', $model->getOriginal(), $model->getDirty());
    }

    public function deleted(AttendanceRegularisation $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
