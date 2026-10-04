<?php

use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Payroll\FinanceApproval;
use App\Livewire\Payroll\Incentives;
use App\Livewire\Payroll\Reimbursements;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\ExitRecord;
use App\Models\Incentive;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Reimbursement;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\IncentiveService;
use App\Services\PayrollService;
use App\Services\ReimbursementService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Spec v3.1 §3.5 — Net pay = gross + OT + incentives + reimbursements +
 * encashment − deductions, approved by Finance, and nobody signs off their
 * own money. These tests pin the money that must survive a re-run and the
 * approvals that must never be self-granted.
 */
function payEmployee(UserRole $role = UserRole::Employee, float $basic = 26000): Employee
{
    $user = User::factory()->create(['role' => $role]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'salary_cycle' => 'cycle_a', 'manager_id' => null,
        // Joined before every run below: a run pays only people employed in its cycle.
        'joining_date' => '2024-01-08',
    ]);

    $component = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $component->id, 'amount' => $basic]);
    EmployeePayrollSettings::create(array_merge(
        EmployeePayrollSettings::defaults($employee->id)->toArray(),
        ['employee_id' => $employee->id],
    ));

    return $employee->fresh('user');
}

beforeEach(fn () => Notification::fake());

// ── Money that must survive a draft re-run ──────────────────────────────────

test('re-running a draft keeps every approved incentive, reimbursement, encashment and settlement', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $employee = payEmployee();
    $type = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true, 'allow_encashment' => true]);

    Incentive::create(['employee_id' => $employee->id, 'title' => 'Q1 bonus', 'amount' => 5000, 'month' => '2026-07', 'status' => 'approved', 'requested_by' => $admin->id]);
    Reimbursement::create(['employee_id' => $employee->id, 'title' => 'Travel', 'amount' => 1200, 'expense_date' => '2026-07-03', 'month' => '2026-07', 'category' => 'travel', 'status' => 'approved']);
    LeaveEncashment::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'requested_days' => 1, 'status' => 'approved', 'payout_month' => '2026-07']);
    ExitRecord::create(['employee_id' => $employee->id, 'last_working_day' => '2026-07-31', 'exit_type' => 'resignation', 'final_settlement_done' => true, 'final_settlement_amount' => 10000]);

    $service = app(PayrollService::class);
    $first = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $firstNet = (float) Payslip::where('payroll_id', $first->id)->where('employee_id', $employee->id)->value('net_salary');

    // 26000 basic + 5000 + 1200 + 1000 (1 day at 26000/26) + 10000.
    expect($firstNet)->toBe(43200.0);

    $second = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    $payslip = Payslip::where('payroll_id', $second->id)->where('employee_id', $employee->id)->first();

    expect((float) $payslip->net_salary)->toBe($firstNet)
        ->and((float) $second->total_payout)->toBe($firstNet)
        ->and(Incentive::first()->payroll_id)->toBe($second->id)
        ->and(Reimbursement::first()->payroll_id)->toBe($second->id)
        ->and(LeaveEncashment::first()->payroll_id)->toBe($second->id)
        ->and(ExitRecord::first()->payroll_id)->toBe($second->id);

    // Regenerating one payslip keeps them too.
    $single = $service->regenerateSinglePayslip($second, $employee, $admin->id);
    expect((float) $single->net_salary)->toBe($firstNet);
});

test('deleting a draft payslip releases its money for the next run instead of stranding it', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $employee = payEmployee();
    $incentive = Incentive::create(['employee_id' => $employee->id, 'title' => 'Referral', 'amount' => 3000, 'month' => '2026-07', 'status' => 'approved', 'requested_by' => $admin->id]);

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $admin->id);
    expect($incentive->fresh()->status)->toBe('included');

    $service->deletePayslip(Payslip::where('payroll_id', $payroll->id)->first());

    expect($incentive->fresh()->status)->toBe('approved')
        ->and($incentive->fresh()->payroll_id)->toBeNull();
});

// ── Nobody approves their own money ─────────────────────────────────────────

test('HR cannot approve an incentive raised for themselves', function () {
    $hr = payEmployee(UserRole::HrAdmin);
    $incentive = app(IncentiveService::class)->submit($hr, ['title' => 'Self bonus', 'amount' => 9000, 'month' => '2026-07'], $hr->user_id);

    Livewire::actingAs($hr->user)->test(Incentives::class)->call('approve', $incentive->id)->assertForbidden();

    expect($incentive->fresh()->status)->toBe('pending');
});

test('whoever raised an incentive cannot also approve it', function () {
    $hr = payEmployee(UserRole::HrAdmin);
    $employee = payEmployee();
    $incentive = app(IncentiveService::class)->submit($employee, ['title' => 'Bonus', 'amount' => 2000, 'month' => '2026-07'], $hr->user_id);

    expect(fn () => app(IncentiveService::class)->approve($incentive, $hr->user_id))->toThrow(ApprovalNotPermitted::class);
    expect($incentive->fresh()->status)->toBe('pending');
});

test('an incentive already paid through payroll cannot be rejected or re-approved', function () {
    $finance = payEmployee(UserRole::Finance);
    $employee = payEmployee();
    $incentive = Incentive::create(['employee_id' => $employee->id, 'title' => 'Bonus', 'amount' => 2000, 'month' => '2026-07', 'status' => 'included', 'requested_by' => $finance->user_id]);

    expect(fn () => app(IncentiveService::class)->reject($incentive, $finance->user_id))->toThrow(DomainException::class)
        ->and(fn () => app(IncentiveService::class)->approve($incentive, $finance->user_id))->toThrow(DomainException::class);

    expect($incentive->fresh()->status)->toBe('included');
});

test('Finance cannot approve their own reimbursement claim', function () {
    $finance = payEmployee(UserRole::Finance);
    $claim = app(ReimbursementService::class)->submit($finance, [
        'title' => 'Internet', 'amount' => 999, 'expense_date' => '2026-07-02', 'month' => '2026-07', 'category' => 'internet',
    ]);

    Livewire::actingAs($finance->user)->test(Reimbursements::class)->call('approve', $claim->id)->assertForbidden();

    expect($claim->fresh()->status)->toBe('pending');
});

test('an HR Admin who can run payroll cannot finalize it in Finance\'s place', function () {
    $finance = payEmployee(UserRole::Finance);
    $hr = payEmployee(UserRole::HrAdmin);
    payEmployee();

    $service = app(PayrollService::class);
    $payroll = $service->generateDraft('July', 2026, 'cycle_a', $finance->user_id);
    $service->submitForFinanceApproval($payroll->fresh());

    Livewire::actingAs($hr->user)->test(FinanceApproval::class)->call('approve', $payroll->id)->assertForbidden();

    expect(Payroll::find($payroll->id)->status)->toBe('pending_finance');
});

// ── Salary components ───────────────────────────────────────────────────────

test('a Director cannot change anyone\'s salary components', function () {
    $director = payEmployee(UserRole::Director);
    $employee = payEmployee();
    $row = EmployeeSalary::where('employee_id', $employee->id)->first();

    Livewire::actingAs($director->user)->test(EmployeeEdit::class, ['employee' => $employee])
        ->call('openEditSalary', $row->id)
        ->assertForbidden();

    Livewire::actingAs($director->user)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('salaryComponentId', (string) $row->salary_component_id)->set('salaryAmount', '99999')
        ->call('saveSalary')
        ->assertForbidden();

    expect(EmployeeSalary::where('employee_id', $employee->id)->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([26000.0]);
});

test('HR cannot raise their own salary', function () {
    $hr = payEmployee(UserRole::HrAdmin);
    $row = EmployeeSalary::where('employee_id', $hr->id)->first();

    Livewire::actingAs($hr->user)->test(EmployeeEdit::class, ['employee' => $hr])
        ->call('openEditSalary', $row->id)
        ->assertForbidden();

    Livewire::actingAs($hr->user)->test(EmployeeEdit::class, ['employee' => $hr])
        ->set('salaryComponentId', (string) $row->salary_component_id)->set('salaryAmount', '99999')
        ->call('saveSalary')
        ->assertForbidden();

    expect((float) $row->fresh()->amount)->toBe(26000.0);
});

test('HR still sets salary for other employees', function () {
    $hr = payEmployee(UserRole::HrAdmin);
    $employee = payEmployee();
    $row = EmployeeSalary::where('employee_id', $employee->id)->first();

    Livewire::actingAs($hr->user)->test(EmployeeEdit::class, ['employee' => $employee])
        ->call('openEditSalary', $row->id)
        ->set('salaryAmount', '30000')
        ->call('saveSalary')
        ->assertHasNoErrors();

    expect((float) $row->fresh()->amount)->toBe(30000.0);
});
