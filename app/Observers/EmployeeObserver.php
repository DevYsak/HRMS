<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\OnboardingService;

class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        AuditLog::record($employee, 'created', null, $employee->toArray());

        app(OnboardingService::class)->assignTemplate($employee);
    }

    public function updated(Employee $employee): void
    {
        AuditLog::record(
            $employee,
            'updated',
            $employee->getOriginal(),
            $employee->getDirty(),
        );

        // Re-enrolment needed when biometric identity fields change.
        // Guard against infinite loop: skip if sync_status itself is what changed.
        if (
            ! $employee->wasChanged('sync_status')
            && $employee->wasChanged(['employee_code', 'biometric_device_id'])
            && $employee->employee_code
        ) {
            Employee::withoutEvents(fn () => $employee->update(['sync_status' => 'pending']));
        }

        // Auto-complete biometric enrollment task when sync succeeds.
        if ($employee->wasChanged('sync_status') && $employee->sync_status === 'synced') {
            app(OnboardingService::class)->autoComplete($employee, 'biometric_sync', 0);
        }
    }

    public function deleted(Employee $employee): void
    {
        AuditLog::record($employee, 'deleted', $employee->toArray(), null);
    }
}
