<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;

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

        AuditLog::record(
            $user,
            'updated',
            array_intersect_key($user->getOriginal(), $dirty),
            $dirty,
        );

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
