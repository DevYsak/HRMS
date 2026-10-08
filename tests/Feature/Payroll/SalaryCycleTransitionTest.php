<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\EmployeeSalary;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryCycle;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Spec §3.5: moving an employee between salary cycles takes effect from the
 * next cycle start. Each run pays one monthly salary, so an already-paid
 * employee must be in exactly one run per payroll month — never two (double
 * pay), never none (missed pay) — and absences (LWP) counted once each across
 * the switch.
 *
 * Cycle A = 1st–last of the month; Cycle B = 21st of the previous month – 20th.
 */
beforeEach(function () {
    Notification::fake();
    $this->cycleA = SalaryCycle::firstOrCreate(['slug' => 'cycle-a'], ['name' => 'Cycle A', 'start_day' => 1, 'end_day' => 0, 'pay_day' => 1, 'is_active' => true]);
    $this->cycleB = SalaryCycle::firstOrCreate(['slug' => 'cycle-b'], ['name' => 'Cycle B', 'start_day' => 21, 'end_day' => 20, 'pay_day' => 21, 'is_active' => true]);
    $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
});

function sctEmployee(SalaryCycle $cycle): Employee
{
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-08',
        'salary_cycle_id' => $cycle->id,
    ]);
    $basic = SalaryComponent::firstOrCreate(['code' => 'BASIC'], [
        'name' => 'Basic Salary', 'type' => 'earning', 'component_type' => 'earning',
        'calculation_type' => 'fixed', 'default_amount' => 0, 'is_active' => true, 'display_order' => 1,
    ]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $basic->id, 'amount' => 26000]);
    EmployeePayrollSettings::create(array_merge(EmployeePayrollSettings::defaults($employee->id)->toArray(), ['employee_id' => $employee->id]));

    return $employee->fresh();
}

function sctRun(string $month, string $cycle): void
{
    app(PayrollService::class)->generateDraft($month, 2026, $cycle, test()->admin->id);
}

/** How many payslips the employee has for a payroll month, across both cycles. */
function sctPayslipsFor(Employee $employee, string $month): int
{
    return Payslip::where('employee_id', $employee->id)->whereHas('payroll', fn ($q) => $q->where('month', $month)->where('year', 2026))->count();
}

function sctLwpDay(Employee $employee, string $date): void
{
    $type = LeaveType::firstOrCreate(['code' => 'LWP'], ['name' => 'Leave Without Pay', 'category' => 'unpaid', 'is_paid' => false]);
    LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $date, 'end_date' => $date,
        'days' => 1, 'reason' => 'Personal', 'status' => 'approved', 'requested_leave_status' => 'unpaid']);
}

function sctLwpLine(Employee $employee, string $month): ?string
{
    return Payslip::where('employee_id', $employee->id)->whereHas('payroll', fn ($q) => $q->where('month', $month)->where('year', 2026))
        ->first()?->items()->where('name', 'like', 'LWP%')->value('name');
}

test('an employee never paid yet moves cycle immediately', function () {
    $employee = sctEmployee($this->cycleA);

    $employee->update(['salary_cycle_id' => $this->cycleB->id]);

    expect($employee->fresh()->salary_cycle)->toBe('cycle_b')
        ->and($employee->fresh()->pending_salary_cycle_id)->toBeNull();
});

test('A→B: the move waits for the next payroll month, and October is paid exactly once', function () {
    $employee = sctEmployee($this->cycleA);
    sctRun('September', 'cycle_a');

    $employee->update(['salary_cycle_id' => $this->cycleB->id]);
    $fresh = $employee->fresh();

    expect($fresh->salary_cycle)->toBe('cycle_a')                       // not switched yet
        ->and($fresh->pending_salary_cycle_id)->toBe($this->cycleB->id)
        ->and($fresh->salary_cycle_effective_month)->toBe('2026-10')
        ->and($fresh->salary_cycle_paid_through->toDateString())->toBe('2026-09-30');

    // Whichever October run is generated first, the employee lands in B only.
    sctRun('October', 'cycle_a');
    sctRun('October', 'cycle_b');

    expect($employee->fresh()->salary_cycle)->toBe('cycle_b')
        ->and(sctPayslipsFor($employee, 'September'))->toBe(1)
        ->and(sctPayslipsFor($employee, 'October'))->toBe(1)
        ->and(Payslip::where('employee_id', $employee->id)->whereHas('payroll', fn ($q) => $q->where('month', 'October')->where('cycle', 'cycle_b'))->exists())->toBeTrue();
});

test('A→B: an absence already counted in A is not deducted again in the first B run', function () {
    $employee = sctEmployee($this->cycleA);
    sctLwpDay($employee, '2026-09-24');      // inside A-September (1–30 Sep)
    sctRun('September', 'cycle_a');
    expect(sctLwpLine($employee, 'September'))->toBe('LWP (1 day)');

    $employee->update(['salary_cycle_id' => $this->cycleB->id]);
    sctLwpDay($employee, '2026-10-06');      // a new absence in B-October (21 Sep–20 Oct)
    sctRun('October', 'cycle_b');

    // B-October covers 21 Sep–20 Oct, but 21–30 Sep were already paid by A.
    expect(sctLwpLine($employee, 'October'))->toBe('LWP (1 day)');
});

test('B→A: the move waits, October is paid once, and absences in the bridge days are not missed', function () {
    $employee = sctEmployee($this->cycleB);
    sctRun('September', 'cycle_b');          // B-September = 21 Aug–20 Sep

    $employee->update(['salary_cycle_id' => $this->cycleA->id]);
    expect($employee->fresh()->salary_cycle_paid_through->toDateString())->toBe('2026-09-20')
        ->and($employee->fresh()->salary_cycle_effective_month)->toBe('2026-10');

    sctLwpDay($employee, '2026-09-25');      // after B paid through 20 Sep, before A-October starts
    sctRun('October', 'cycle_b');
    sctRun('October', 'cycle_a');

    expect($employee->fresh()->salary_cycle)->toBe('cycle_a')
        ->and(sctPayslipsFor($employee, 'October'))->toBe(1)
        ->and(sctLwpLine($employee, 'October'))->toBe('LWP (1 day)');   // 25 Sep counted once, not lost
});

test('a run never pays someone the other cycle already paid for that month', function () {
    $employee = sctEmployee($this->cycleA);
    sctRun('October', 'cycle_a');

    // Simulate a cycle key changed outside the form (import, legacy data).
    DB::table('employees')->where('id', $employee->id)->update(['salary_cycle' => 'cycle_b']);
    sctRun('October', 'cycle_b');

    expect(sctPayslipsFor($employee, 'October'))->toBe(1);
});

test('HR keeping the employee in their current cycle withdraws a pending move', function () {
    $employee = sctEmployee($this->cycleA);
    sctRun('September', 'cycle_a');

    $employee->update(['salary_cycle_id' => $this->cycleB->id]);
    expect($employee->fresh()->pending_salary_cycle_id)->toBe($this->cycleB->id);

    // The form shows the current cycle (A); saving it keeps them in A.
    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))
        ->test(EmployeeEdit::class, ['employee' => $employee->fresh()])
        ->assertSet('salary_cycle_id', (string) $this->cycleA->id)
        ->call('save');

    $fresh = $employee->fresh();
    expect($fresh->pending_salary_cycle_id)->toBeNull()
        ->and($fresh->salary_cycle)->toBe('cycle_a')
        ->and(AuditLog::where('event', 'SALARY_CYCLE_CHANGE_CANCELLED')->where('subject_employee_id', $employee->id)->exists())->toBeTrue();

    sctRun('October', 'cycle_a');
    sctRun('October', 'cycle_b');
    expect(Payslip::where('employee_id', $employee->id)->whereHas('payroll', fn ($q) => $q->where('month', 'October')->where('cycle', 'cycle_a'))->exists())->toBeTrue()
        ->and(sctPayslipsFor($employee, 'October'))->toBe(1);
});

test('scheduling and applying a move are both audited', function () {
    $employee = sctEmployee($this->cycleA);
    sctRun('September', 'cycle_a');

    $employee->update(['salary_cycle_id' => $this->cycleB->id]);
    sctRun('October', 'cycle_b');

    $scheduled = AuditLog::where('event', 'SALARY_CYCLE_CHANGE_SCHEDULED')->where('subject_employee_id', $employee->id)->first();
    $applied = AuditLog::where('event', 'SALARY_CYCLE_CHANGE_APPLIED')->where('subject_employee_id', $employee->id)->first();

    expect($scheduled->new_values['effective_month'])->toBe('2026-10')
        ->and($applied->old_values['salary_cycle'])->toBe('cycle_a')
        ->and($applied->new_values['salary_cycle'])->toBe('cycle_b');
});
