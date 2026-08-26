<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        // Allow all authenticated users to view the employee directory (read-only)
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        // 1. Can manage all employees
        // 2. Is their manager
        // 3. Is the employee themselves
        return $user->canManageEmployees()
            || ($user->isManager() && $employee->manager_id === $user->employee?->id)
            || ($user->employee?->id === $employee->id);
    }

    public function create(User $user): bool
    {
        return $user->canManageEmployees();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->canManageEmployees();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->canManageEmployees();
    }

    /**
     * Erasing a deleted employee for good, along with their leave, attendance,
     * payslips and audit trail.
     *
     * Narrower than delete() on purpose: a soft delete is recoverable and a
     * permanent one is not, so it takes the dedicated delete_employee
     * permission rather than general employee management.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        return $user->hasPermission('delete_employee');
    }
}
