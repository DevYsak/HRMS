<?php

use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;

/**
 * A payroll header must always agree with the payslips hanging off it.
 *
 * The regression: generateDraft() rebuilt payslips only for employees in the
 * current active+cycle set, but recomputed the header totals from that same set.
 * An employee deactivated between runs kept a stale payslip attached while their
 * pay silently dropped out of total_payout.
 */
function rerunEmployee(string $name, float $basicAmount): Employee
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a',
    ]);

    $basic = SalaryComponent::firstOrCreate(
        ['code' => 'BASIC'],
        [
            'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
            'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
        ],
    );

    EmployeeSalary::create([
        'employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => $basicAmount,
    ]);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id],
    ));

    return $employee;
}

test('re-running payroll after an employee leaves keeps the header total honest', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $staying = rerunEmployee('Staying', 40000);
    $leaving = rerunEmployee('Leaving', 25000);

    $service = app(PayrollService::class);

    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    expect((float) $payroll->total_payout)->toBe(65000.0)
        ->and(Payslip::where('payroll_id', $payroll->id)->count())->toBe(2);

    // The second employee leaves, then payroll is re-run for the same period.
    $leaving->update(['status' => 'inactive']);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);

    $payslips = Payslip::where('payroll_id', $payroll->id)->get();

    expect($payslips)->toHaveCount(1)
        ->and($payslips->first()->employee_id)->toBe($staying->id)
        ->and((float) $payroll->total_payout)->toBe(40000.0)
        ->and((float) $payroll->total_payout)->toBe((float) $payslips->sum('net_salary'));
});
