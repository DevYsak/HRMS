<?php

use App\Enums\UserRole;
use App\Livewire\Payroll\FinanceApproval;
use App\Livewire\Payroll\Process;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryCycle;
use App\Models\User;
use App\Services\PayrollHistoricalImportService;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Spec v3.1 §3.5 — two salary cycles (A: 1st–31st, B: 21st–20th) with a
 * separate run each, every employee of the cycle in its run, the structure in
 * force for the period, and Finance signing off the real net figure.
 */
function cycleEmployee(array $attributes = [], float $basic = 26000, ?string $effectiveFrom = null, ?string $effectiveTo = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(array_merge([
        'user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a', 'manager_id' => null,
        // Joined before every run below: a run pays only people employed in its cycle.
        'joining_date' => '2024-01-08',
    ], $attributes));

    $component = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create([
        'employee_id' => $employee->id, 'salary_component_id' => $component->id, 'amount' => $basic,
        'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo,
    ]);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id]));

    return $employee->fresh('user');
}

function cycles(): array
{
    return [
        'a' => SalaryCycle::updateOrCreate(['slug' => 'cycle-a'], ['name' => 'Cycle A', 'start_day' => 1, 'end_day' => 0, 'pay_day' => 1, 'is_default' => true, 'is_active' => true]),
        'b' => SalaryCycle::updateOrCreate(['slug' => 'cycle-b'], ['name' => 'Cycle B', 'start_day' => 21, 'end_day' => 20, 'pay_day' => 21, 'is_default' => false, 'is_active' => true]),
    ];
}

beforeEach(fn () => Notification::fake());

test('the month before a salary revision still pays the old salary (effective-date boundary)', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $employee = cycleEmployee([], 26000, '2026-01-01', '2026-07-31');
    EmployeeSalary::create([
        'employee_id' => $employee->id, 'salary_component_id' => SalaryComponent::where('code', 'BASIC')->value('id'),
        'amount' => 30000, 'effective_from' => '2026-08-01', 'effective_to' => null,
    ]);

    $july = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $august = app(PayrollService::class)->generateDraft('August', 2026, 'cycle_a', $admin->id);

    expect((float) Payslip::where('payroll_id', $july->id)->value('net_salary'))->toBe(26000.0)
        ->and((float) Payslip::where('payroll_id', $august->id)->value('net_salary'))->toBe(30000.0);
});

test('employees on probation, confirmed or serving notice are paid in the run', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $paid = collect(['active', 'probation', 'confirmed', 'notice_period'])
        ->map(fn ($status) => cycleEmployee(['status' => $status])->id);
    $left = cycleEmployee(['status' => 'inactive'])->id;

    $payroll = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $inRun = Payslip::where('payroll_id', $payroll->id)->pluck('employee_id');

    expect($inRun->sort()->values()->all())->toBe($paid->sort()->values()->all())
        ->and($inRun)->not->toContain($left);
});

test('moving an employee to Cycle B in the UI moves them into the Cycle B run', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    ['b' => $cycleB] = cycles();
    $employee = cycleEmployee();

    $employee->update(['salary_cycle_id' => $cycleB->id]);

    expect($employee->fresh()->salary_cycle)->toBe('cycle_b');

    $a = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $b = app(PayrollService::class)->generateDraft('July', 2026, 'cycle_b', $admin->id);

    expect(Payslip::where('payroll_id', $a->id)->where('employee_id', $employee->id)->exists())->toBeFalse()
        ->and(Payslip::where('payroll_id', $b->id)->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('Cycle B can be run from the Run Payroll screen; an unknown cycle cannot', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    cycleEmployee(['salary_cycle' => 'cycle_b']);

    Livewire::actingAs($hr)->test(Process::class)
        ->set('cycle', 'cycle_b')
        ->call('startProcessing');

    expect(Payroll::where('cycle', 'cycle_b')->exists())->toBeTrue();

    // Refused the moment the selector is tampered with (updatedCycle).
    Livewire::actingAs($hr)->test(Process::class)
        ->set('cycle', 'cycle_z')
        ->assertStatus(422);

    expect(Payroll::where('cycle', 'cycle_z')->exists())->toBeFalse();
});

test('Finance signs off the real net payout, in rupees, with the cycle shown', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $finance = User::factory()->create(['role' => UserRole::Finance]);
    cycleEmployee([], 26000);
    cycleEmployee([], 14000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $hr->id);
    $service->submitForFinanceApproval($payroll->fresh());

    Livewire::actingAs($finance)->test(FinanceApproval::class)
        ->assertSee('₹40,000')
        ->assertSee('Cycle A')
        ->assertDontSee('IDR');
});

test('historical import refuses the current or a future month — it would bypass Finance', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = cycleEmployee();
    $service = app(PayrollHistoricalImportService::class);
    $row = fn (string $month, int $year) => [
        'line' => 2, 'status' => 'new', 'errors' => [],
        'data' => [
            'employee_id' => $employee->id, 'month' => $month, 'year' => $year, 'cycle' => 'cycle_a',
            'gross_salary' => 26000, 'total_deductions' => 0, 'net_salary' => 26000, 'earnings' => [], 'deductions' => [],
        ],
    ];

    $log = $service->import(['rows' => [$row(now()->format('F'), (int) now()->format('Y'))], 'summary' => ['total' => 1]], $hr);

    expect($log->imported)->toBe(0)
        ->and(Payroll::where('month', now()->format('F'))->where('year', now()->year)->exists())->toBeFalse();
});
