<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OnboardingTask;

class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        AuditLog::record($employee, 'created', null, $employee->toArray());

        $this->seedOnboardingTasks($employee);
    }

    protected function seedOnboardingTasks(Employee $employee): void
    {
        $tasks = [
            ['title' => 'Complete personal profile', 'category' => 'hr', 'owner_role' => 'employee', 'sort_order' => 1],
            ['title' => 'Upload KYC documents', 'category' => 'hr', 'owner_role' => 'employee', 'sort_order' => 2],
            ['title' => 'Bank account setup', 'category' => 'finance', 'owner_role' => 'finance', 'sort_order' => 3],
            ['title' => 'IT hardware assignment', 'category' => 'it_setup', 'owner_role' => 'it', 'sort_order' => 4],
            ['title' => 'Email & Slack setup', 'category' => 'it_setup', 'owner_role' => 'it', 'sort_order' => 5],
            ['title' => 'Company policy induction', 'category' => 'hr', 'owner_role' => 'hr', 'sort_order' => 6],
            ['title' => 'Team introduction', 'category' => 'general', 'owner_role' => 'manager', 'sort_order' => 7],
            ['title' => 'Role expectation setting', 'category' => 'general', 'owner_role' => 'manager', 'sort_order' => 8],
            ['title' => 'Access card issuance', 'category' => 'hr', 'owner_role' => 'hr', 'sort_order' => 9],
            ['title' => 'Welcome kit handover', 'category' => 'hr', 'owner_role' => 'hr', 'sort_order' => 10],
        ];

        foreach ($tasks as $task) {
            OnboardingTask::create(array_merge($task, [
                'employee_id' => $employee->id,
                'phase' => 'onboarding',
                'due_date' => now()->addDays(7),
            ]));
        }
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
    }

    public function deleted(Employee $employee): void
    {
        AuditLog::record($employee, 'deleted', $employee->toArray(), null);
    }
}
