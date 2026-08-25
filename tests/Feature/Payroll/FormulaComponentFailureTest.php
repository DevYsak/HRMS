<?php

use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\SalaryCalculationService;
use Illuminate\Support\Carbon;

/**
 * A broken formula must stop the payroll, not quietly pay nothing.
 *
 * The regression: resolveFormulaAmount() caught the evaluator's RuntimeException
 * and returned 0.0, so a typo'd expression underpaid every employee holding the
 * component with no error surfaced anywhere.
 */
function formulaEmployee(string $expression): Employee
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a',
    ]);

    $basic = SalaryComponent::create([
        'name' => 'Basic Salary', 'code' => 'BASIC', 'type' => 'earning',
        'component_type' => 'earning', 'calculation_type' => 'fixed',
        'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);

    $bonus = SalaryComponent::create([
        'name' => 'Performance Bonus', 'code' => 'PERF_BONUS', 'type' => 'earning',
        'component_type' => 'earning', 'calculation_type' => 'formula',
        'formula_expression' => $expression,
        'default_amount' => 0, 'is_active' => true, 'display_order' => 2,
    ]);

    EmployeeSalary::create([
        'employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => 40000,
    ]);
    EmployeeSalary::create([
        'employee_id' => $employee->id, 'salary_component_id' => $bonus->id, 'amount' => 0,
    ]);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id],
    ));

    return $employee->fresh();
}

function runFormulaPayroll(Employee $employee)
{
    $payroll = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'draft',
    ]);

    return app(SalaryCalculationService::class)->calculate(
        $employee, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), '2026-07', $payroll,
    );
}

test('a valid formula component is paid', function () {
    $result = runFormulaPayroll(formulaEmployee('BASIC * 0.1'));

    expect($result->gross)->toBe(44000.0);
});

test('a malformed formula aborts the run instead of paying zero', function () {
    expect(fn () => runFormulaPayroll(formulaEmployee('BASIC * * 0.1')))
        ->toThrow(DomainException::class, "Salary component 'Performance Bonus' has an invalid formula");
});
