<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Employee records (spec v3.1 §4 "Employee Profiles": Super Admin / HR full,
 * Director own department, Manager own team, employee own record).
 *
 * Reach comes from ApprovalGuard via User::coversEmployee(): company-wide for
 * Super Admin and unscoped HR, the configured department/shift scope plus
 * reporting line for everyone else — the same population approvals use.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        // Allow all authenticated users to view the employee directory (read-only)
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        // 1. Is the employee themselves
        // 2. Manages employees inside their reach
        // 3. Is their manager
        return $user->employee?->id === $employee->id
            || ($user->canManageEmployees() && $user->coversEmployee($employee))
            // employees.manager_id holds the manager's USER id, not an employee id.
            || ($user->isManager() && $employee->manager_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->canManageEmployees();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->canManageEmployees()
            && $user->coversEmployee($employee)
            && ! $this->isProtectedFrom($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->canManageEmployees()
            && $user->coversEmployee($employee)
            && $employee->user_id !== $user->id
            && ! $this->isProtectedFrom($user, $employee);
    }

    /**
     * Issuing somebody a login.
     *
     * Employee management, not a separate permission: whoever HR trusts to
     * create and edit employee records is the same person who decides that a
     * checked record is ready for access. An employee cannot invite anybody,
     * including themselves, because they do not hold manage_employees.
     */
    public function invite(User $user, Employee $employee): bool
    {
        return $user->canManageEmployees()
            && $user->coversEmployee($employee)
            && ! $this->isProtectedFrom($user, $employee);
    }

    /**
     * Erasing a deleted employee for good, along with their leave, attendance,
     * payslips and audit trail.
     *
     * Spec §3.1: records are archived, never deleted. Purging needs the
     * dedicated Permanently Delete Employees permission (Super Admin only by default)
     * on top of delete_employee, stays inside the actor's reach, and never
     * erases the actor or — for anyone but a Super Admin — a Super Admin.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        if (! $user->hasPermission('force_delete_employee') || ! $user->hasPermission('delete_employee')) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // The account is soft-deleted alongside the record, so look past that.
        $target = $employee->user()->withTrashed()->first();

        return $user->coversEmployee($employee)
            && $target?->id !== $user->id
            && ! ($target !== null && $this->isSuperAdmin($target));
    }

    /**
     * A Super Admin's record is the Super Admin's to change: anyone else
     * editing it could, for example, repoint its login email and take the
     * account over through a password reset.
     */
    private function isProtectedFrom(User $user, Employee $employee): bool
    {
        $target = $employee->user;

        return $target !== null && $target->id !== $user->id
            && $this->isSuperAdmin($target) && ! $this->isSuperAdmin($user);
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin() || $user->assignedRole?->slug === 'super_admin';
    }
}
