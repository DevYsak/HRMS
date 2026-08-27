<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Leave\LeaveProvisioningService;
use App\Services\OnboardingService;

class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        AuditLog::record($employee, 'created', null, $employee->toArray());

        app(OnboardingService::class)->assignTemplate($employee);

        // Every new hire — form, import, API or seeder — starts with the annual
        // leave their policy and working pattern produce.
        //
        // This used to seed a flat allocation per leave type keyed on
        // now()->year: a calendar year, in a company whose leave year runs
        // 1 July to 30 June, with no leave policy assigned at all. Where the
        // policy or the pattern is missing, provisioning reports the gap rather
        // than defaulting past it — an entitlement resting on an assumed
        // pattern is a guess with a number in front of it.
        //
        // No previous-year balance and no carry-forward: a new employee has no
        // history, and carry forward is a decision HR makes about a year that
        // actually happened.
        app(LeaveProvisioningService::class)->provision($employee);
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
