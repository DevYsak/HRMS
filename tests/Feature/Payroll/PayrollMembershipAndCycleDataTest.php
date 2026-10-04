<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryCycle;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\PayrollHistoricalImportService;
use App\Services\PayrollService;
use Database\Seeders\BiometricEmployeeMasterSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Follow-ups from the review of the payroll batch: a run pays the people
 * employed in its cycle (by joining date as well as status), every way
 * employees are loaded writes the run key payroll reads, existing mismatches
 * are surfaced before anyone is moved, and "already paid" history is judged
 * by the pay period's own last day.
 */
function memberEmployee(array $attributes): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(array_merge([
        'user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a', 'manager_id' => null,
    ], $attributes));

    $component = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $component->id, 'amount' => 30000]);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id]));

    return $employee;
}

beforeEach(fn () => Notification::fake());

test('a run pays people employed in its cycle: never a later joiner, and a joined hire still marked onboarding', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $regular = memberEmployee(['joining_date' => '2024-01-08']);
    $laterJoiner = memberEmployee(['status' => 'probation', 'joining_date' => '2026-11-03']);
    $joinedOnboarding = memberEmployee(['status' => 'onboarding', 'joining_date' => '2026-10-01']);
    $notYetJoined = memberEmployee(['status' => 'onboarding', 'joining_date' => '2026-11-10']);

    $payroll = app(PayrollService::class)->generateDraft('October', 2026, 'cycle_a', $admin->id);
    $paid = Payslip::where('payroll_id', $payroll->id)->pluck('employee_id')->all();

    expect($paid)->toContain($regular->id, $joinedOnboarding->id)
        ->and($paid)->not->toContain($laterJoiner->id)
        ->and($paid)->not->toContain($notYetJoined->id);
});

test('an ended Cycle B period can be imported before its calendar month is over; an open period cannot', function () {
    $this->travelTo(Carbon::parse('2026-10-25 10:00'));
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = memberEmployee(['joining_date' => '2024-01-08', 'salary_cycle' => 'cycle_b']);
    $service = app(PayrollHistoricalImportService::class);
    $file = fn (string $cycle) => ['rows' => [[
        'line' => 2, 'status' => 'new', 'errors' => [],
        'data' => [
            'employee_id' => $employee->id, 'month' => 'October', 'year' => 2026, 'cycle' => $cycle,
            'gross_salary' => 30000, 'total_deductions' => 0, 'net_salary' => 30000, 'earnings' => [], 'deductions' => [],
        ],
    ]], 'summary' => ['total' => 1]];

    // Cycle B "October" is 21 Sep – 20 Oct: over, and paid outside Pulse.
    expect($service->import($file('cycle_b'), $hr)->imported)->toBe(1);
    // Cycle A "October" runs to 31 Oct: still open.
    expect($service->import($file('cycle_a'), $hr)->imported)->toBe(0);
});

test('the master seeder loads employees into the Cycle A run', function () {
    $cycleA = SalaryCycle::updateOrCreate(['slug' => 'cycle-a'], ['name' => 'Cycle A', 'start_day' => 1, 'end_day' => 0, 'pay_day' => 1, 'is_default' => true, 'is_active' => true]);
    ShiftSetting::create([
        'name' => 'IT Shift', 'start_time' => '10:30:00', 'end_time' => '19:30:00', 'break_duration' => 60,
        'grace_minutes' => 10, 'standard_hours' => 8, 'ot_threshold_hours' => 9,
    ]);

    $this->seed(BiometricEmployeeMasterSeeder::class);

    expect(Employee::where('salary_cycle', 'A')->count())->toBe(0)
        ->and(Employee::where('salary_cycle', 'cycle_a')->where('salary_cycle_id', $cycleA->id)->count())->toBeGreaterThan(0);
});

test('employees paid in a different run from their form are listed, and moved only when asked', function () {
    $cycleB = SalaryCycle::updateOrCreate(['slug' => 'cycle-b'], ['name' => 'Cycle B', 'start_day' => 21, 'end_day' => 20, 'pay_day' => 21, 'is_default' => false, 'is_active' => true]);
    $employee = memberEmployee(['joining_date' => '2024-01-08']);
    // Moved to Cycle B on the form before the observer kept the run key in step.
    Employee::withoutEvents(fn () => $employee->forceFill(['salary_cycle_id' => $cycleB->id])->save());

    $this->artisan('hrms:normalize-salary-cycles')
        ->expectsOutputToContain('paid in a different run')
        ->assertSuccessful();

    expect($employee->fresh()->salary_cycle)->toBe('cycle_a');

    $this->artisan('hrms:normalize-salary-cycles', ['--sync-from-form' => true])->assertSuccessful();

    expect($employee->fresh()->salary_cycle)->toBe('cycle_b');
});
