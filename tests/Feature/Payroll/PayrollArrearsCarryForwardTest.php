<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\Incentive;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Reimbursement;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Notification;

/**
 * D8 (8 Oct 2026): approved money — reimbursement, OT, incentive, leave
 * encashment — approved after its own period's payroll run closed is carried
 * into the next OPEN run. Never dropped, never paid twice; the source period,
 * approval date, paying run and settlement time are all on record.
 *
 * Scenario: the August (cycle A) run is finalised, THEN items for August are
 * approved. September's run must pay them as arrears.
 */
beforeEach(fn () => Notification::fake());

function arrEmployee(float $basic = 26000): Employee
{
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'salary_cycle' => 'cycle_a', 'manager_id' => null, 'joining_date' => '2024-01-08',
    ]);
    $component = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $component->id, 'amount' => $basic]);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), [
        'employee_id' => $employee->id, 'ot_eligible' => true, 'incentive_eligible' => true, 'reimbursement_eligible' => true,
    ]));

    return $employee->fresh('user');
}

/** Generate, submit and finance-approve a cycle A run (maker ≠ checker). */
function arrCloseRun(string $month, int $year): Payroll
{
    $maker = User::factory()->create(['role' => UserRole::HrAdmin]);
    $checker = User::factory()->create(['role' => UserRole::Finance]);
    $service = app(PayrollService::class);

    $payroll = $service->generateDraft($month, $year, 'cycle_a', $maker->id);
    $service->submitForFinanceApproval($payroll);

    return $service->approveFinance($payroll->fresh(), $checker->id);
}

function arrOvertime(Employee $employee, string $date, float $amount): OvertimeRecord
{
    $request = OtRequest::create(['employee_id' => $employee->id, 'work_date' => $date, 'start_time' => '19:30', 'end_time' => '21:30',
        'requested_hours' => 2, 'reason' => 'Release', 'status' => 'approved', 'reviewed_at' => now()]);

    return OvertimeRecord::create(['employee_id' => $employee->id, 'ot_request_id' => $request->id, 'work_date' => $date,
        'total_hours_worked' => 11, 'standard_hours' => 9, 'ot_hours' => 2, 'rate_per_hour' => $amount / 2, 'ot_amount' => $amount, 'is_paid' => false]);
}

function arrLines(Payroll $payroll, Employee $employee): array
{
    return Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->firstOrFail()
        ->items()->pluck('amount', 'name')->map(fn ($a) => (float) $a)->all();
}

test('items approved after their run closed are paid as arrears by the next open run', function () {
    $employee = arrEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $august = arrCloseRun('August', 2026);
    expect($august->status)->toBe('finalized');

    // Approved only now, after August closed.
    $incentive = Incentive::create(['employee_id' => $employee->id, 'title' => 'Aug bonus', 'amount' => 5000, 'month' => '2026-08', 'status' => 'approved', 'requested_by' => $admin->id, 'approved_at' => now()]);
    $claim = Reimbursement::create(['employee_id' => $employee->id, 'title' => 'Travel', 'amount' => 1200, 'expense_date' => '2026-08-28', 'month' => '2026-08', 'category' => 'travel', 'status' => 'approved', 'approved_at' => now()]);
    $type = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true, 'allow_encashment' => true]);
    $encash = LeaveEncashment::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'requested_days' => 1, 'status' => 'approved', 'payout_month' => '2026-08', 'reviewed_at' => now()]);
    $ot = arrOvertime($employee, '2026-08-27', 200);

    $september = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', $admin->id);
    $lines = arrLines($september, $employee);

    expect($lines)->toHaveKey('Incentives — arrears (2026-08)', 5000.0)
        ->and($lines)->toHaveKey('Reimbursements — arrears (2026-08)', 1200.0)
        ->and($lines)->toHaveKey('Leave Encashment (1d) — arrears (2026-08)', 1000.0)
        ->and($lines)->toHaveKey('OT — arrears (2h, 2026-08)', 200.0)
        ->and($incentive->fresh()->payroll_id)->toBe($september->id)
        ->and($claim->fresh()->payroll_id)->toBe($september->id)
        ->and($encash->fresh()->payroll_id)->toBe($september->id)
        ->and($ot->fresh()->payslip_id)->not->toBeNull();

    // The closed August run is untouched.
    expect(Payslip::where('payroll_id', $august->id)->first()->items()->where('name', 'like', '%arrears%')->exists())->toBeFalse();
});

test('carried items are paid exactly once: re-running the draft keeps them, a later run never repeats them', function () {
    $employee = arrEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    arrCloseRun('August', 2026);
    $incentive = Incentive::create(['employee_id' => $employee->id, 'title' => 'Aug bonus', 'amount' => 5000, 'month' => '2026-08', 'status' => 'approved', 'requested_by' => $admin->id]);
    $ot = arrOvertime($employee, '2026-08-27', 200);

    $service = app(PayrollService::class);
    $service->generateDraft('September', 2026, 'cycle_a', $admin->id);
    $september = $service->generateDraft('September', 2026, 'cycle_a', $admin->id);   // re-run the draft

    expect(arrLines($september, $employee))->toHaveKey('Incentives — arrears (2026-08)', 5000.0);

    $service->submitForFinanceApproval($september);
    $service->approveFinance($september->fresh(), User::factory()->create(['role' => UserRole::Finance])->id);

    $october = $service->generateDraft('October', 2026, 'cycle_a', $admin->id);
    $octoberLines = arrLines($october, $employee);

    expect(collect($octoberLines)->keys()->filter(fn ($name) => str_contains($name, 'arrears'))->all())->toBe([])
        ->and($incentive->fresh()->payroll_id)->toBe($september->id)
        ->and($ot->fresh()->is_paid)->toBeTrue();
});

test('settlement is recorded: source period, approval date, paying run and settled time', function () {
    $employee = arrEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    arrCloseRun('August', 2026);
    $approvedAt = now()->subDay();
    $claim = Reimbursement::create(['employee_id' => $employee->id, 'title' => 'Internet', 'amount' => 999, 'expense_date' => '2026-08-30', 'month' => '2026-08', 'category' => 'internet', 'status' => 'approved', 'approved_at' => $approvedAt]);
    $ot = arrOvertime($employee, '2026-08-29', 200);

    $september = arrCloseRun('September', 2026);
    $claim->refresh();
    $ot->refresh();

    expect($claim->month)->toBe('2026-08')                                   // source period
        ->and($claim->approved_at->toDateString())->toBe($approvedAt->toDateString())
        ->and($claim->payroll_id)->toBe($september->id)                     // paying run
        ->and($claim->settled_at)->not->toBeNull()                          // settled
        ->and($ot->work_date->format('Y-m'))->toBe('2026-08')
        ->and(Payslip::find($ot->payslip_id)->payroll_id)->toBe($september->id)
        ->and($ot->settled_at)->not->toBeNull();
});

test('an item for a future period is not pulled into an earlier run', function () {
    $employee = arrEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $later = Incentive::create(['employee_id' => $employee->id, 'title' => 'Nov bonus', 'amount' => 4000, 'month' => '2026-11', 'status' => 'approved', 'requested_by' => $admin->id]);

    $september = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', $admin->id);

    expect(collect(arrLines($september, $employee))->keys()->filter(fn ($n) => str_contains($n, 'Incentive'))->all())->toBe([])
        ->and($later->fresh()->payroll_id)->toBeNull();
});

test('the same period run still shows its own items on the normal line, not as arrears', function () {
    $employee = arrEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Incentive::create(['employee_id' => $employee->id, 'title' => 'Sep bonus', 'amount' => 3000, 'month' => '2026-09', 'status' => 'approved', 'requested_by' => $admin->id]);

    $september = app(PayrollService::class)->generateDraft('September', 2026, 'cycle_a', $admin->id);

    expect(arrLines($september, $employee))->toHaveKey('Incentives', 3000.0);
});

test('the arrears preview lists what will be carried and changes nothing', function () {
    $employee = arrEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Incentive::create(['employee_id' => $employee->id, 'title' => 'Aug bonus', 'amount' => 5000, 'month' => '2026-08', 'status' => 'approved', 'requested_by' => $admin->id]);

    $this->artisan('payroll:pending-arrears', ['--before' => '2026-09'])
        ->expectsOutputToContain('2026-08')
        ->expectsOutputToContain('1 item(s)')
        ->assertSuccessful();

    expect(Incentive::first()->payroll_id)->toBeNull();
});
