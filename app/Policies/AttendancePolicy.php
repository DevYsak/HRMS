<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $user->canManageEmployees()
            || $user->employee?->id === $attendance->employee_id;
    }

    public function regularise(User $user, Attendance $attendance): bool
    {
        return $user->employee?->id === $attendance->employee_id;
    }

    public function approveRegularisation(User $user): bool
    {
        return $user->canApproveLeave(); // managers + HR can approve regularisations
    }
}
