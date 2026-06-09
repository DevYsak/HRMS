<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Notifications\OffboardingCompletedNotification;
use App\Notifications\OnboardingCompletedNotification;

class OnboardingService
{
    /**
     * Hardcoded fallback tasks used when no templates exist yet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fallbackTasks(): array
    {
        return [
            ['title' => 'Complete personal profile',  'category' => 'hr',       'owner_role' => 'employee', 'sort_order' => 1,  'auto_trigger' => 'account_create', 'due_days' => 1],
            ['title' => 'Upload KYC documents',       'category' => 'hr',       'owner_role' => 'employee', 'sort_order' => 2,  'auto_trigger' => 'kyc_upload',     'due_days' => 3],
            ['title' => 'Bank account setup',         'category' => 'finance',  'owner_role' => 'finance',  'sort_order' => 3,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'IT hardware assignment',     'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 4,  'auto_trigger' => 'asset_assign',   'due_days' => 5],
            ['title' => 'Email & Slack setup',        'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 5,  'auto_trigger' => 'account_create', 'due_days' => 1],
            ['title' => 'Biometric enrollment',       'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 6,  'auto_trigger' => 'biometric_sync', 'due_days' => 5],
            ['title' => 'Company policy induction',   'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 7,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'Team introduction',          'category' => 'general',  'owner_role' => 'manager',  'sort_order' => 8,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'Role expectation setting',   'category' => 'general',  'owner_role' => 'manager',  'sort_order' => 9,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'Access card issuance',       'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 10, 'auto_trigger' => null,             'due_days' => 3],
            ['title' => 'Welcome kit handover',       'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 11, 'auto_trigger' => null,             'due_days' => 1],
        ];
    }

    /**
     * Hardcoded fallback tasks used when no templates exist yet for offboarding.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fallbackOffboardingTasks(): array
    {
        return [
            ['title' => 'Submit resignation letter / Exit Form', 'category' => 'hr',       'owner_role' => 'employee', 'sort_order' => 1,  'auto_trigger' => null,             'due_days' => 1],
            ['title' => 'Knowledge Transfer (KT) sign-off',      'category' => 'general',  'owner_role' => 'manager',  'sort_order' => 2,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'Return IT Assets (Laptop, Peripherals)', 'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 3,  'auto_trigger' => 'asset_return',   'due_days' => 1],
            ['title' => 'Revoke Email & System Access',          'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 4,  'auto_trigger' => null,             'due_days' => 1],
            ['title' => 'Clear pending travel/expense dues',     'category' => 'finance',  'owner_role' => 'finance',  'sort_order' => 5,  'auto_trigger' => null,             'due_days' => 3],
            ['title' => 'Final Settlement Calculation',          'category' => 'finance',  'owner_role' => 'finance',  'sort_order' => 6,  'auto_trigger' => null,             'due_days' => 15],
            ['title' => 'Exit Interview',                        'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 7,  'auto_trigger' => null,             'due_days' => 5],
            ['title' => 'Issue Relieving & Experience Letters',  'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 8,  'auto_trigger' => null,             'due_days' => 15],
        ];
    }

    /**
     * Assign the best-matching template tasks to a newly created employee.
     * No-op if the employee already has tasks (prevents duplicate seeding).
     */
    public function assignTemplate(Employee $employee): void
    {
        if ($employee->onboardingTasks()->where('phase', 'onboarding')->exists()) {
            return;
        }

        $template = $this->resolveTemplate($employee);
        $joiningDate = $employee->joining_date ?? now()->toDateString();

        if ($template !== null) {
            foreach ($template->tasks()->where('phase', 'onboarding')->orderBy('sort_order')->get() as $task) {
                OnboardingTask::create([
                    'employee_id' => $employee->id,
                    'phase' => 'onboarding',
                    'title' => $task->title,
                    'description' => $task->description,
                    'category' => $task->category,
                    'owner_role' => $task->owner_role,
                    'sort_order' => $task->sort_order,
                    'auto_trigger' => $task->auto_trigger,
                    'template_task_id' => $task->id,
                    'due_date' => now()->parse($joiningDate)->addDays($task->due_days)->toDateString(),
                    'status' => 'pending',
                ]);
            }

            return;
        }

        // No templates configured yet — use built-in fallback list.
        foreach ($this->fallbackTasks() as $task) {
            OnboardingTask::create([
                'employee_id' => $employee->id,
                'phase' => 'onboarding',
                'title' => $task['title'],
                'category' => $task['category'],
                'owner_role' => $task['owner_role'],
                'sort_order' => $task['sort_order'],
                'auto_trigger' => $task['auto_trigger'],
                'due_date' => now()->parse($joiningDate)->addDays($task['due_days'])->toDateString(),
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Assign offboarding tasks for an exiting employee.
     * No-op if the employee already has offboarding tasks (prevents duplicate seeding).
     */
    public function assignOffboardingTasks(Employee $employee, string $lastWorkingDay): void
    {
        if ($employee->onboardingTasks()->where('phase', 'offboarding')->exists()) {
            return;
        }

        $template = $this->resolveTemplate($employee);

        if ($template !== null) {
            $offboardingTasks = $template->tasks()->where('phase', 'offboarding')->orderBy('sort_order')->get();
            if ($offboardingTasks->isNotEmpty()) {
                foreach ($offboardingTasks as $task) {
                    OnboardingTask::create([
                        'employee_id' => $employee->id,
                        'phase' => 'offboarding',
                        'title' => $task->title,
                        'description' => $task->description,
                        'category' => $task->category,
                        'owner_role' => $task->owner_role,
                        'sort_order' => $task->sort_order,
                        'auto_trigger' => $task->auto_trigger,
                        'template_task_id' => $task->id,
                        'due_date' => now()->parse($lastWorkingDay)->addDays($task->due_days)->toDateString(),
                        'status' => 'pending',
                    ]);
                }

                return;
            }
        }

        // Fallback list
        foreach ($this->fallbackOffboardingTasks() as $task) {
            OnboardingTask::create([
                'employee_id' => $employee->id,
                'phase' => 'offboarding',
                'title' => $task['title'],
                'category' => $task['category'],
                'owner_role' => $task['owner_role'],
                'sort_order' => $task['sort_order'],
                'auto_trigger' => $task['auto_trigger'],
                'due_date' => now()->parse($lastWorkingDay)->addDays($task['due_days'])->toDateString(),
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Auto-complete tasks that match the given trigger for this employee.
     */
    public function autoComplete(Employee $employee, string $trigger, int $completedBy): void
    {
        $updated = OnboardingTask::where('employee_id', $employee->id)
            ->where('phase', 'onboarding')
            ->where('is_completed', false)
            ->where('auto_trigger', $trigger)
            ->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $completedBy ?: null,
                'status' => 'completed',
            ]);

        if ($updated > 0) {
            $this->checkAndNotifyCompletion($employee);
        }
    }

    /**
     * Mark all past-due incomplete tasks as 'overdue'.
     * Returns the number of tasks updated.
     */
    public function markOverdue(): int
    {
        return OnboardingTask::where('is_completed', false)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereNotIn('status', ['overdue', 'blocked', 'completed'])
            ->update(['status' => 'overdue']);
    }

    /**
     * Send completion notification if all onboarding tasks for this employee are done.
     */
    public function checkAndNotifyCompletion(Employee $employee): void
    {
        $hasPending = $employee->onboardingTasks()
            ->where('phase', 'onboarding')
            ->where('is_completed', false)
            ->exists();

        if ($hasPending) {
            return;
        }

        $notification = new OnboardingCompletedNotification($employee);

        $employee->user->notify($notification);

        User::whereIn('role', ['super_admin', 'hr_admin'])->each(
            fn (User $user) => $user->notify($notification)
        );
    }

    /**
     * Send completion notification if all offboarding tasks for this employee are done.
     */
    public function checkAndNotifyOffboardingCompletion(Employee $employee): void
    {
        $hasPending = $employee->onboardingTasks()
            ->where('phase', 'offboarding')
            ->where('is_completed', false)
            ->exists();

        if ($hasPending) {
            return;
        }

        $notification = new OffboardingCompletedNotification($employee);

        if ($employee->user) {
            $employee->user->notify($notification);
        }

        User::whereIn('role', ['super_admin', 'hr_admin'])->each(
            fn (User $user) => $user->notify($notification)
        );
    }

    /**
     * Find the best-matching active template for an employee.
     * Priority: employment_type+dept+job → employment_type only → dept only → job_title only → default.
     */
    private function resolveTemplate(Employee $employee): ?OnboardingTemplate
    {
        $empTypeId = $employee->employment_type_id;
        $deptId = $employee->department_id;
        $jobTitleId = $employee->job_title_id;

        $base = OnboardingTemplate::active();

        // Most specific: all three criteria match.
        if ($empTypeId && $deptId && $jobTitleId) {
            $match = (clone $base)
                ->where('employment_type_id', $empTypeId)
                ->where('department_id', $deptId)
                ->where('job_title_id', $jobTitleId)
                ->first();
            if ($match) {
                return $match;
            }
        }

        // Two-criteria matches.
        foreach ([
            ['employment_type_id' => $empTypeId, 'department_id' => $deptId],
            ['employment_type_id' => $empTypeId, 'job_title_id' => $jobTitleId],
            ['department_id' => $deptId,     'job_title_id' => $jobTitleId],
        ] as $criteria) {
            if (array_filter($criteria)) {
                $match = (clone $base)->where($criteria)->whereNull(
                    array_keys(array_filter($criteria, fn ($v) => $v === null))
                )->first();
                if ($match) {
                    return $match;
                }
            }
        }

        // Single-criteria matches.
        if ($empTypeId) {
            $match = (clone $base)
                ->where('employment_type_id', $empTypeId)
                ->whereNull('department_id')
                ->whereNull('job_title_id')
                ->first();
            if ($match) {
                return $match;
            }
        }

        if ($deptId) {
            $match = (clone $base)
                ->where('department_id', $deptId)
                ->whereNull('employment_type_id')
                ->whereNull('job_title_id')
                ->first();
            if ($match) {
                return $match;
            }
        }

        if ($jobTitleId) {
            $match = (clone $base)
                ->where('job_title_id', $jobTitleId)
                ->whereNull('employment_type_id')
                ->whereNull('department_id')
                ->first();
            if ($match) {
                return $match;
            }
        }

        // Default template.
        return (clone $base)->where('is_default', true)->first();
    }
}
