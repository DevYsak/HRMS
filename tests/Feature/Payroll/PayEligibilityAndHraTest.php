<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Incentive;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * OT / incentive / reimbursement eligibility and HRA used to be fixed at
 * creation — new hires defaulted to "not eligible", HRA paid ₹0 for everyone —
 * with no screen to change them. Defaults are now deliberate and HR/Finance
 * (payroll users) edit them, never for their own pay.
 */
beforeEach(function () {
    Notification::fake();
    Mail::fake();
});

function pehComponent(string $code, string $name): SalaryComponent
{
    return SalaryComponent::firstOrCreate(['code' => $code], [
        'name' => $name, 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => $code === 'BASIC' ? 1 : 2,
    ]);
}

function pehEmployee(array $settings = [], float $basic = 20000, ?float $hra = null): Employee
{
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'salary_cycle' => 'cycle_a', 'manager_id' => null, 'joining_date' => '2024-01-08',
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => pehComponent('BASIC', 'Basic Salary')->id, 'amount' => $basic]);
    if ($hra !== null) {
        EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => pehComponent('HRA', 'House Rent Allowance')->id, 'amount' => $hra]);
    }
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id], $settings));

    return $employee->fresh(['user', 'payrollSettings']);
}

function pehPayslipLines(Employee $employee, string $month = 'September'): array
{
    $payroll = app(PayrollService::class)->generateDraft($month, 2026, 'cycle_a', User::factory()->create(['role' => UserRole::SuperAdmin])->id);

    return Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->firstOrFail()
        ->items()->pluck('amount', 'name')->map(fn ($a) => (float) $a)->all();
}

test('a new hire is eligible for OT, incentives and reimbursements and HRA is on by default', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(EmployeeCreate::class)
        ->set('name', 'Default Dana')
        ->set('email', 'default.dana@conexus-ns.com')
        ->call('save')
        ->assertHasNoErrors();

    $settings = User::where('email', 'default.dana@conexus-ns.com')->first()->employee->payrollSettings;

    expect($settings->ot_eligible)->toBeTrue()
        ->and($settings->incentive_eligible)->toBeTrue()
        ->and($settings->reimbursement_eligible)->toBeTrue()
        ->and($settings->hra_enabled)->toBeTrue();
});

test('a payroll user switches eligibility and HRA on the Payroll tab, and the change is audited', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = pehEmployee(['ot_eligible' => false, 'incentive_eligible' => false, 'hra_enabled' => false]);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->assertSet('payOtEligible', false)
        ->set('payOtEligible', true)
        ->set('payIncentiveEligible', true)
        ->set('payHraEnabled', true)
        ->set('payHraPercentage', '40')
        ->call('savePayrollSettings')
        ->assertHasNoErrors();

    $settings = $employee->payrollSettings->fresh();
    $audit = AuditLog::where('event', 'EMPLOYEE_PAY_SETTINGS_CHANGED')->latest('id')->first();

    expect($settings->ot_eligible)->toBeTrue()
        ->and($settings->incentive_eligible)->toBeTrue()
        ->and($settings->hra_enabled)->toBeTrue()
        ->and((float) $settings->hra_percentage)->toBe(40.0)
        ->and($audit->user_id)->toBe($hr->id)
        ->and($audit->old_values['ot_eligible'])->toBeFalse()
        ->and($audit->new_values['ot_eligible'])->toBeTrue();
});

test('someone who can edit the employee but has no payroll rights cannot change pay settings', function () {
    // A Director edits employee records in their own department (D1) but
    // does not run payroll.
    $director = User::factory()->create(['role' => UserRole::Director]);
    expect($director->canRunPayroll())->toBeFalse();
    $employee = pehEmployee(['ot_eligible' => false]);
    $department = \App\Models\Department::factory()->create(['head_id' => $director->id]);
    $employee->update(['department_id' => $department->id]);

    Livewire::actingAs($director)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('payOtEligible', true)
        ->call('savePayrollSettings')
        ->assertForbidden();

    expect($employee->payrollSettings->fresh()->ot_eligible)->toBeFalse();
});

test('nobody changes their own pay settings', function () {
    $hrUser = User::factory()->create(['role' => UserRole::HrAdmin]);
    $self = pehEmployee(['incentive_eligible' => false]);
    $self->update(['user_id' => $hrUser->id]);

    Livewire::actingAs($hrUser)->test(EmployeeEdit::class, ['employee' => $self->fresh()])
        ->set('payIncentiveEligible', true)
        ->call('savePayrollSettings')
        ->assertForbidden();

    expect($self->payrollSettings->fresh()->incentive_eligible)->toBeFalse();
});

test('an approved incentive waits while the employee is ineligible and is paid once switched back on', function () {
    $employee = pehEmployee(['incentive_eligible' => false]);
    $incentive = Incentive::create(['employee_id' => $employee->id, 'title' => 'Bonus', 'amount' => 3000, 'month' => '2026-09', 'status' => 'approved',
        'requested_by' => User::factory()->create(['role' => UserRole::HrAdmin])->id]);

    $septemberLines = pehPayslipLines($employee, 'September');
    expect(collect($septemberLines)->keys()->filter(fn ($n) => str_contains($n, 'Incentive'))->all())->toBe([])
        ->and($incentive->fresh()->status)->toBe('approved');

    $employee->payrollSettings->update(['incentive_eligible' => true]);

    expect(pehPayslipLines($employee, 'October'))->toHaveKey('Incentives — arrears (2026-09)', 3000.0);
});

test('HRA pays the assigned amount when enabled, a percentage of Basic when set, and nothing when off', function () {
    $off = pehEmployee(['hra_enabled' => false], basic: 20000, hra: 8000);
    $assigned = pehEmployee(['hra_enabled' => true], basic: 20000, hra: 8000);
    $percent = pehEmployee(['hra_enabled' => true, 'hra_percentage' => 40], basic: 20000, hra: 8000);

    $payroll = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', User::factory()->create(['role' => UserRole::SuperAdmin])->id);
    $hra = fn (Employee $e) => (float) Payslip::where('payroll_id', $payroll->id)->where('employee_id', $e->id)->firstOrFail()
        ->items()->where('name', 'House Rent Allowance')->value('amount');

    expect($hra($off))->toBe(0.0)
        ->and($hra($assigned))->toBe(8000.0)
        ->and($hra($percent))->toBe(8000.0);   // 40% of 20000

    $percent->payrollSettings->update(['hra_percentage' => 50]);
    $rerun = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', User::factory()->create(['role' => UserRole::SuperAdmin])->id);
    expect((float) Payslip::where('payroll_id', $rerun->id)->where('employee_id', $percent->id)->first()->items()->where('name', 'House Rent Allowance')->value('amount'))->toBe(10000.0);
});

test('the edit screen warns when an HRA component is assigned but HRA is off', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = pehEmployee(['hra_enabled' => false], hra: 8000);

    $component = Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee]);

    expect($component->instance()->hraAssignedButOff())->toBeTrue();
});

test('the review command lists employees whose settings block pay, and changes nothing', function () {
    $blocked = pehEmployee(['reimbursement_eligible' => false, 'hra_enabled' => false], hra: 5000);
    pehEmployee();

    $this->artisan('payroll:review-pay-settings')
        ->expectsOutputToContain('Reimbursements off, HRA assigned but off')
        ->expectsOutputToContain('1 employee(s)')
        ->assertSuccessful();

    expect($blocked->payrollSettings->fresh()->reimbursement_eligible)->toBeFalse();
});
