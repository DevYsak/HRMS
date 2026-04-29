<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\DocumentAcknowledgement;

class DocumentAcknowledgementObserver
{
    public function created(DocumentAcknowledgement $model): void
    {
        AuditLog::record($model, 'created', null, $model->toArray());
    }

    public function updated(DocumentAcknowledgement $model): void
    {
        AuditLog::record($model, 'updated', $model->getOriginal(), $model->getDirty());
    }

    public function deleted(DocumentAcknowledgement $model): void
    {
        AuditLog::record($model, 'deleted', $model->toArray(), null);
    }
}
