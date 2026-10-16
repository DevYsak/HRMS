<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Security\ScopeResolver;

/**
 * Who may see whose payroll (Phase 4 policy, approved 16 Oct 2026).
 * Permission = what action; scope = whose payroll data — never one implied
 * by the other, never by role name, and an unknown scope fails closed.
 *
 *  - view_payroll's data scope is the payroll reach (Super Admin, HR Admin,
 *    Finance: company; a Director only when explicitly granted, department).
 *  - Running payroll, finance sign-off, payroll / salary exports and the
 *    bank-transfer file act on everyone: company-wide view_payroll reach.
 *  - The bank-transfer file also needs export_bank_transfer.
 *  - An employee sees their own payslips (view_payslips) and nothing else.
 */
class PayrollAccess
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    /** @return array<int, int>|null payroll reach; null = company-wide, [] = none */
    public function reach(User $user): ?array
    {
        if (! $user->hasPermission('view_payroll')) {
            return [];
        }

        return $this->scopes->employeeIds($user, 'view_payroll');
    }

    public function isCompanyWide(User $user): bool
    {
        return $user->hasPermission('view_payroll') && $this->reach($user) === null;
    }

    public function covers(User $user, Employee|int $employee): bool
    {
        $reach = $this->reach($user);
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return $user->hasPermission('view_payroll') && ($reach === null || in_array($id, $reach, true));
    }

    /** Own payslip (with view_payslips), or one inside the payroll reach. */
    public function canViewPayslip(User $user, Payslip $payslip): bool
    {
        $own = $payslip->employee?->user_id === $user->id;

        return ($own && $user->hasPermission('view_payslips')) || $this->covers($user, (int) $payslip->employee_id);
    }

    public function assertCompanyWide(User $user): void
    {
        abort_unless($this->isCompanyWide($user), 403, 'This payroll action needs company-wide payroll access.');
    }
}
