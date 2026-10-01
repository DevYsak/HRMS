<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;

class UserObserver
{
    public function updated(User $user): void
    {
        // Skip pure login tracking (last_login_at, remember_token), focus on profile changes.
        $ignored = ['remember_token', 'updated_at'];
        $dirty = array_diff_key($user->getDirty(), array_flip($ignored));

        if (empty($dirty)) {
            return;
        }

        // Queried, not read through $user->employee: touching the relation here
        // caches it on the model — as null when this fires during creation,
        // before the employee row exists — and every later read of that user
        // instance would then see no employee.
        $employeeId = Employee::where('user_id', $user->id)->value('id');

        AuditLog::record(
            $user,
            'updated',
            array_intersect_key($user->getOriginal(), $dirty),
            $dirty,
            subjectEmployeeId: $employeeId,
        );

        // A role change is a permission change — record it as its own,
        // categorised event with readable role names, whichever path made it.
        if ($user->wasChanged('role_id')) {
            app(AuditService::class)->event(
                'EMPLOYEE_ROLE_CHANGED',
                AuditService::PERMISSIONS,
                $user,
                old: ['role' => Role::find($user->getOriginal('role_id'))?->name],
                new: ['role' => Role::find($user->role_id)?->name],
                subjectEmployeeId: $employeeId,
                module: AuditService::EMPLOYEE,
            );
        }

        // Name is stored on the biometric device — mark the employee for re-sync.
        if ($user->wasChanged('name')) {
            $employee = $user->employee;

            if ($employee?->employee_code && $employee->sync_status !== 'pending') {
                Employee::withoutEvents(fn () => $employee->update(['sync_status' => 'pending']));
            }
        }
    }

    public function deleted(User $user): void
    {
        AuditLog::record($user, 'deleted', $user->toArray(), null);
    }
}
