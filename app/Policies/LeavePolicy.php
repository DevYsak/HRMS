<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeavePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // everyone sees their own via scoping in components
    }

    public function view(User $user, LeaveRequest $leave): bool
    {
        return $user->canApproveLeave()
            || $user->employee?->id === $leave->employee_id;
    }

    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    public function update(User $user, LeaveRequest $leave): bool
    {
        // Only pending requests can be edited by the owner
        return $leave->status === 'pending'
            && $user->employee?->id === $leave->employee_id;
    }

    public function approve(User $user, LeaveRequest $leave): bool
    {
        return $user->canApproveLeave();
    }

    public function cancel(User $user, LeaveRequest $leave): bool
    {
        return $user->employee?->id === $leave->employee_id
            && in_array($leave->status, ['pending', 'approved']);
    }
}
