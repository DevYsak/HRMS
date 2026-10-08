<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use Illuminate\Console\Command;

/**
 * Read-only: employees whose pay settings stop approved money reaching their
 * payslip — OT, incentive or reimbursement eligibility switched off, or an
 * HRA component assigned while HRA is off (it pays ₹0). Older hires were
 * created with eligibility off by default; HR reviews and changes them on the
 * employee's Payroll tab. Nothing is changed here.
 */
class ReviewPaySettings extends Command
{
    protected $signature = 'payroll:review-pay-settings';

    protected $description = 'List employees whose OT / incentive / reimbursement eligibility or HRA setting would block pay';

    public function handle(): int
    {
        $rows = Employee::with(['user', 'payrollSettings', 'salaries.component'])
            ->whereNotIn('status', ['resigned', 'terminated', 'absconded', 'archived', 'inactive'])
            ->get()
            ->map(function (Employee $employee) {
                $settings = $employee->payrollSettings ?? EmployeePayrollSettings::defaults($employee->id);
                $issues = array_keys(array_filter([
                    'OT off' => ! $settings->ot_eligible,
                    'Incentives off' => ! $settings->incentive_eligible,
                    'Reimbursements off' => ! $settings->reimbursement_eligible,
                    'HRA assigned but off' => ! $settings->hra_enabled
                        && $employee->salaries->contains(fn ($row) => $row->component?->code === 'HRA'),
                ]));

                return $issues === [] ? null : [$employee->employee_id, $employee->user?->name, implode(', ', $issues)];
            })
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No current employee has pay eligibility or HRA switched off.');

            return self::SUCCESS;
        }

        $this->table(['Employee ID', 'Name', 'Blocked'], $rows->all());
        $this->line($rows->count().' employee(s) to review on their Payroll tab. Nothing was changed.');

        return self::SUCCESS;
    }
}
