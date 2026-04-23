<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Payslip;

class PayslipObserver
{
    public function created(Payslip $payslip): void
    {
        AuditLog::record($payslip, 'created', null, $payslip->toArray());
    }

    public function updated(Payslip $payslip): void
    {
        AuditLog::record(
            $payslip,
            'updated',
            $payslip->getOriginal(),
            $payslip->getDirty(),
        );
    }

    public function deleted(Payslip $payslip): void
    {
        AuditLog::record($payslip, 'deleted', $payslip->toArray(), null);
    }
}
