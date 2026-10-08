<?php

use App\Enums\UserRole;
use App\Livewire\Settings\ApprovalPolicySettings;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\PayrollApprovalPolicy;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Spec §3.5 / §4.1: Finance signs off payroll. HR configures the approval
 * chain (R12) but cannot route payroll around Finance, and nothing changes a
 * payslip once its run is with Finance or finalised.
 */
beforeEach(function () {
    Notification::fake();
    Mail::fake();
});

function fsoEmployee(): Employee
{
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'salary_cycle' => 'cycle_a', 'manager_id' => null, 'joining_date' => '2024-01-08',
    ]);
    $basic = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => 20000]);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id]));

    return $employee;
}

function fsoAddStep(User $actor, string $type, string $label)
{
    return Livewire::actingAs($actor)->test(ApprovalPolicySettings::class)
        ->call('openCreate')
        ->set('label', $label)
        ->set('approver_type', $type)
        ->set('is_active', true)
        ->call('save');
}

test('HR cannot save an approval chain that has no Finance step', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    fsoAddStep($hr, 'hr_admin', 'HR review')->assertHasErrors('approver_type');

    expect(PayrollApprovalPolicy::count())->toBe(0);
});

test('a chain that includes Finance can be built in any order', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    fsoAddStep($hr, 'finance', 'Finance sign-off')->assertHasNoErrors();
    fsoAddStep($hr, 'hr_admin', 'HR review')->assertHasNoErrors();

    expect(PayrollApprovalPolicy::activeSteps()->pluck('approver_type')->all())->toBe(['finance', 'hr_admin']);
});

test('the last Finance step cannot be deleted or switched off', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $finance = PayrollApprovalPolicy::create(['level' => 1, 'label' => 'Finance', 'approver_type' => 'finance', 'is_active' => true]);
    PayrollApprovalPolicy::create(['level' => 2, 'label' => 'HR', 'approver_type' => 'hr_admin', 'is_active' => true]);

    Livewire::actingAs($hr)->test(ApprovalPolicySettings::class)->call('toggleActive', $finance->id);
    expect($finance->fresh()->is_active)->toBeTrue();

    Livewire::actingAs($hr)->test(ApprovalPolicySettings::class)->call('delete', $finance->id);
    expect(PayrollApprovalPolicy::find($finance->id))->not->toBeNull();
});

test('a named Finance user counts as the Finance step', function () {
    $emad = User::factory()->create(['role' => UserRole::Finance]);
    $step = PayrollApprovalPolicy::create(['level' => 1, 'label' => 'Emad', 'approver_type' => 'specific_user', 'specific_user_id' => $emad->id, 'is_active' => true]);

    expect(PayrollApprovalPolicy::chainHasFinanceSignOff())->toBeTrue();

    $step->update(['specific_user_id' => User::factory()->create(['role' => UserRole::HrAdmin])->id]);
    expect(PayrollApprovalPolicy::chainHasFinanceSignOff())->toBeFalse();
});

test('a stored chain without Finance cannot be used to submit payroll', function () {
    fsoEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    PayrollApprovalPolicy::create(['level' => 1, 'label' => 'HR only', 'approver_type' => 'hr_admin', 'is_active' => true]);

    $payroll = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', $hr->id);

    expect(fn () => app(PayrollService::class)->submitForFinanceApproval($payroll))
        ->toThrow(DomainException::class, 'no Finance step');

    expect($payroll->fresh()->status)->toBe('draft');
});

test('payslips cannot be edited or deleted once the run is with Finance', function () {
    $employee = fsoEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('September', 2026, 'cycle_a', $hr->id);
    $payslip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->first();

    $service->submitForFinanceApproval($payroll);
    $this->actingAs($hr);

    expect(fn () => $service->updatePayslipItems($payslip->fresh(), [['name' => 'Basic Salary', 'amount' => 99999, 'type' => 'earning']], 'late change'))
        ->toThrow(DomainException::class, 'submitted for Finance approval')
        ->and(fn () => $service->deletePayslip($payslip->fresh()))
        ->toThrow(DomainException::class, 'submitted for Finance approval');

    expect((float) $payslip->fresh()->net_salary)->toBe(20000.0);
});

test('a draft run can still be corrected before submission', function () {
    $employee = fsoEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('September', 2026, 'cycle_a', $hr->id);
    $payslip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->first();
    $this->actingAs($hr);

    $service->updatePayslipItems($payslip, [['name' => 'Basic Salary', 'amount' => 21000, 'type' => 'earning']], 'arrears fix');

    expect((float) $payslip->fresh()->net_salary)->toBe(21000.0);
});

test('a payslip is emailed only after Finance approval', function () {
    $employee = fsoEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $payroll = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', $hr->id);
    $payslip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->first();

    expect(fn () => app(PayrollService::class)->emailPayslip($payslip))->toThrow(DomainException::class, 'finance-approved');
});
