<?php

use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;

/**
 * Phase 2: per-payslip lifecycle actions (regenerate/edit/delete/lock/unlock/
 * email a single employee's payslip without touching the rest of the run).
 */
function lifecycleEmployee(string $name, float $basicAmount): Employee
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

    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => $basicAmount]);

    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id],
    ));

    return $employee;
}

test('regenerating one payslip leaves the others untouched and recomputes the payroll header', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);
    $bob = lifecycleEmployee('Bob', 25000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);

    // Bob's salary changes after the run; regenerating only his payslip should
    // pick that up while Alice's payslip (and its id) stays exactly as it was.
    $bobSlipBefore = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $bob->id)->first();
    $aliceSlip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $alice->id)->first();

    EmployeeSalary::where('employee_id', $bob->id)->update(['amount' => 30000]);
    $service->regenerateSinglePayslip($payroll->fresh(), $bob, $admin->id);

    $bobSlipAfter = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $bob->id)->first();

    expect((float) $bobSlipAfter->gross_salary)->toBe(30000.0)
        ->and($bobSlipAfter->id)->not->toBe($bobSlipBefore->id) // old row deleted, new one created
        ->and(Payslip::find($aliceSlip->id)->gross_salary)->toEqual($aliceSlip->gross_salary) // Alice untouched
        ->and((float) $payroll->fresh()->total_payout)->toBe(40000.0 + 30000.0);
});

test('a locked payroll refuses to regenerate any of its payslips', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $payroll->update(['status' => 'finalized']);
    $service->lock($payroll->fresh(), $admin->id);

    expect(fn () => $service->regenerateSinglePayslip($payroll->fresh(), $alice, $admin->id))
        ->toThrow(DomainException::class, 'locked');
});

test('an individually-locked payslip survives a whole-batch regenerate untouched', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);
    $bob = lifecycleEmployee('Bob', 25000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);

    $aliceSlip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $alice->id)->first();
    $service->lockPayslip($aliceSlip, $admin->id);

    // Bob's salary changes, then the WHOLE payroll is regenerated.
    EmployeeSalary::where('employee_id', $bob->id)->update(['amount' => 50000]);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);

    $aliceAfter = Payslip::find($aliceSlip->id);
    $bobAfter = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $bob->id)->first();

    expect($aliceAfter->id)->toBe($aliceSlip->id) // same row, never deleted
        ->and((float) $aliceAfter->gross_salary)->toBe(40000.0)
        ->and((float) $bobAfter->gross_salary)->toBe(50000.0) // Bob DID regenerate
        ->and((float) $payroll->total_payout)->toBe(40000.0 + 50000.0);
});

test('editing a draft payslip recomputes gross, deductions and net from the new line items', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $alice->id)->first();

    $updated = $service->updatePayslipItems($slip, [
        ['name' => 'Basic Salary', 'amount' => 40000, 'type' => 'earning'],
        ['name' => 'Special Bonus', 'amount' => 5000, 'type' => 'earning'],
        ['name' => 'Loan Recovery', 'amount' => 2000, 'type' => 'deduction'],
    ], reason: 'Ad-hoc bonus + loan EMI');

    expect((float) $updated->gross_salary)->toBe(45000.0)
        ->and((float) $updated->total_deductions)->toBe(2000.0)
        ->and((float) $updated->net_salary)->toBe(43000.0)
        ->and(PayslipItem::where('payslip_id', $slip->id)->count())->toBe(3)
        ->and((float) $payroll->fresh()->total_payout)->toBe(43000.0);
});

test('editing a paid payslip is refused', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first();
    $slip->update(['status' => 'paid']);

    expect(fn () => $service->updatePayslipItems($slip->fresh(), [['name' => 'X', 'amount' => 1, 'type' => 'earning']]))
        ->toThrow(DomainException::class, 'Only draft payslips can be edited.');
});

test('deleting a draft payslip recomputes the payroll header', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);
    $bob = lifecycleEmployee('Bob', 25000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $bobSlip = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $bob->id)->first();

    $service->deletePayslip($bobSlip);

    expect(Payslip::find($bobSlip->id))->toBeNull()
        ->and((float) $payroll->fresh()->total_payout)->toBe(40000.0);
});

test('a locked payslip cannot be deleted', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first();
    $service->lockPayslip($slip, $admin->id);

    expect(fn () => $service->deletePayslip($slip->fresh()))
        ->toThrow(DomainException::class, 'locked');
});

test('locking then unlocking a payslip round-trips cleanly', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first();

    $locked = $service->lockPayslip($slip, $admin->id);
    expect($locked->isLocked())->toBeTrue();

    $unlocked = $service->unlockPayslip($locked);
    expect($unlocked->isLocked())->toBeFalse();
});

test('emailing a payslip with no employee email on file is refused', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $alice = lifecycleEmployee('Alice', 40000);
    $alice->user()->update(['email' => '']);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $slip = Payslip::where('payroll_id', $payroll->id)->first()->load('employee.user');

    expect(fn () => $service->emailPayslip($slip))
        ->toThrow(DomainException::class, 'no email address');
});
