<?php

use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\SalaryCalculationService;
use Illuminate\Support\Carbon;

/**
 * LWP must deduct against the earnings the payroll engine actually paid.
 *
 * The regression: LwpService derived its own monthly salary from every
 * EmployeeSalary row on the employee, unfiltered by effective date. Once an
 * increment closed the old rows and opened new ones, both generations matched,
 * so the per-day rate — and the deduction — was inflated by the superseded row.
 */
function lwpEmployeeWithIncrement(): Employee
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

    // Superseded generation — closed the day before the July cycle opens.
    EmployeeSalary::create([
        'employee_id' => $employee->id, 'salary_component_id' => $basic->id,
        'amount' => 26000,
        'effective_from' => Carbon::parse('2026-01-01'),
        'effective_to' => Carbon::parse('2026-06-30'),
    ]);

    // Post-increment generation — the only one in force for July.
    EmployeeSalary::create([
        'employee_id' => $employee->id, 'salary_component_id' => $basic->id,
        'amount' => 52000,
        'effective_from' => Carbon::parse('2026-07-01'),
    ]);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id],
    ));

    $unpaid = LeaveType::create([
        'name' => 'Unpaid Leave', 'is_paid' => false, 'category' => 'unpaid',
    ]);

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $unpaid->id,
        'start_date' => Carbon::parse('2026-07-10'), 'end_date' => Carbon::parse('2026-07-10'),
        'days' => 1, 'status' => 'approved', 'reason' => 'test',
    ]);

    return $employee->fresh();
}

test('LWP after an increment deducts one current day, not a double-counted one', function () {
    $payroll = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'draft',
    ]);

    $result = app(SalaryCalculationService::class)->calculate(
        lwpEmployeeWithIncrement(),
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-07-31'),
        '2026-07',
        $payroll,
    );

    // Engine pays the post-increment 52,000. One LWP day at 52000/26 = 2,000.
    // The old double-counted base (26000 + 52000 = 78000) would deduct 3,000.
    expect($result->gross)->toBe(52000.0)
        ->and($result->totalDeductions)->toBe(2000.0)
        ->and($result->net)->toBe(50000.0);
});
