<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;

class PayrollPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canRunPayroll();
    }

    public function view(User $user, Payroll $payroll): bool
    {
        return $user->canRunPayroll();
    }

    public function create(User $user): bool
    {
        return $user->canRunPayroll();
    }

    public function process(User $user, Payroll $payroll): bool
    {
        return $user->canRunPayroll()
            && $payroll->status === 'draft';
    }

    public function approveFinance(User $user, Payroll $payroll): bool
    {
        return $user->canApproveFinance()
            && $payroll->status === 'pending_finance';
    }
}
