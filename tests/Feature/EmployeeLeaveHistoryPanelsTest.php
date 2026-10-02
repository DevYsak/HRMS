<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\LeaveBalanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * The two read-only history panels on Employee → Leave.
 *
 * They read what the Carry Forward and Regularisation workflows already
 * wrote. The point of the tests is that they say what actually happened —
 * in particular that a year whose usage was never recorded reads as "Not
 * available" and not as 0, which would claim the employee took no leave.
 */
function elhYears(): array
{
    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $curr = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    return [$prev, $curr];
}

function elhType(): LeaveType
{
    return LeaveType::firstOrCreate(['code' => 'ELH'], [
        'name' => 'Panel Annual', 'category' => 'annual',
        'allow_paid_request' => true, 'allow_carry_forward' => true,
    ]);
}

function elhEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function elhHr(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);
}

/** A closed year plus an HR carry-forward decision against it. */
function elhCarryForward(Employee $employee, LeaveType $type, ?float $used, float $approved, User $hr): void
{
    [$prev, $curr] = elhYears();

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 10, $used, $used === null ? null : 0,
        'Historical migration', null, $hr,
    );

    app(LeaveCarryForwardService::class)->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => $approved],
    ], $hr, 'Migrated from the 2025/26 sheet');
}

// ── Carry Forward History ──────────────────────────────────────────────────

test('the carry forward history panel renders on the Leave tab', function () {
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    elhCarryForward($employee, $type, used: 4, approved: 6, hr: $hr);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('Carry Forward History')
        ->assertSee('2025/26')
        ->assertSee('2026/27')
        ->assertSee('Migrated from the 2025/26 sheet');
});

test('an unrecorded historical usage reads as Not available, never as zero', function () {
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    elhCarryForward($employee, $type, used: null, approved: 6, hr: $hr);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        // The absence is stated, and no eligible figure is claimed for a year
        // nothing could be derived from.
        ->assertSee('Not available')
        ->assertSee('Not calculable');
});

test('a known usage shows the figure rather than the absence', function () {
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    elhCarryForward($employee, $type, used: 4, approved: 6, hr: $hr);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('Applied')
        ->assertDontSee('Not calculable');
});

test('a zero used is shown as zero, not as unavailable', function () {
    // The distinction the panel exists to make: 0 is a record, and must not
    // be presented the same way as a missing figure.
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    elhCarryForward($employee, $type, used: 0, approved: 6, hr: $hr);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertDontSee('Not available');
});

test('a reversed carry forward shows the reversal and who made it', function () {
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    elhCarryForward($employee, $type, used: null, approved: 6, hr: $hr);

    $tx = LeaveCarryForwardTransaction::where('employee_id', $employee->id)->firstOrFail();
    app(LeaveCarryForwardService::class)->reverse($tx, $hr, 'Sheet was wrong');

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('Reversed')
        ->assertSee('Sheet was wrong');
});

test('an employee with no carried leave sees an empty state, not a blank table', function () {
    $hr = elhHr();
    $employee = elhEmployee();

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('No leave has been carried forward for this employee.');
});

// ── Regularisation History ─────────────────────────────────────────────────

test('the regularisation history panel renders the request and its stage', function () {
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => '2026-08-10',
        'category' => 'leave',
        'leave_type_id' => $type->id,
        'from_date' => '2026-08-10',
        'to_date' => '2026-08-12',
        'duration' => 3,
        'reason' => 'Was on approved leave, marked absent',
        'status' => 'pending',
        'stage' => 'manager_review',
    ]);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('Regularisation History')
        ->assertSee('Was on approved leave, marked absent')
        ->assertSee('Pending');
});

test('a rejected regularisation shows the reviewer comment as the reason', function () {
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => '2026-08-10',
        'category' => 'leave',
        'leave_type_id' => $type->id,
        'from_date' => '2026-08-10',
        'to_date' => '2026-08-10',
        'duration' => 1,
        'reason' => 'Requested correction',
        'status' => 'rejected',
        'stage' => 'hr_review',
        'reviewer_id' => $hr->id,
        'reviewer_comment' => 'No supporting record found',
        'reviewed_at' => now(),
    ]);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('Rejected')
        ->assertSee('No supporting record found');
});

test('attendance regularisations do not appear in the leave panel', function () {
    // The panel is about leave. Attendance corrections have their own tab, and
    // mixing them would misrepresent what was requested.
    $hr = elhHr();
    $employee = elhEmployee();

    AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => '2026-08-10',
        'category' => 'attendance',
        'from_date' => '2026-08-10',
        'to_date' => '2026-08-10',
        'duration' => 1,
        'reason' => 'ATTENDANCEONLYMARKER',
        'status' => 'pending',
        'stage' => 'manager_review',
    ]);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertDontSee('ATTENDANCEONLYMARKER');
});

test('an employee with no regularisations sees an empty state', function () {
    $hr = elhHr();
    $employee = elhEmployee();

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk()
        ->assertSee('No leave regularisation requests for this employee.');
});

// ── Permissions ────────────────────────────────────────────────────────────

test('an employee cannot open another employee history', function () {
    elhHr();
    $subject = elhEmployee();
    $other = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($other)->test(EmployeeEdit::class, ['employee' => $subject])
        ->assertForbidden();
});

test('the panels are read-only — opening the tab changes no record', function () {
    $hr = elhHr();
    $type = elhType();
    $employee = elhEmployee();

    elhCarryForward($employee, $type, used: null, approved: 6, hr: $hr);

    $txBefore = LeaveCarryForwardTransaction::where('employee_id', $employee->id)
        ->firstOrFail()->only(['applied_days', 'eligible_days', 'used_status', 'status']);
    $balancesBefore = LeaveBalance::where('employee_id', $employee->id)
        ->orderBy('id')->get()->map->only(['allocated_days', 'used_days', 'carried_forward_days'])->all();

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('activeTab', 'Leave')
        ->assertOk();

    expect(LeaveCarryForwardTransaction::where('employee_id', $employee->id)
        ->firstOrFail()->only(['applied_days', 'eligible_days', 'used_status', 'status']))->toBe($txBefore)
        ->and(LeaveBalance::where('employee_id', $employee->id)
            ->orderBy('id')->get()->map->only(['allocated_days', 'used_days', 'carried_forward_days'])->all())
        ->toBe($balancesBefore);
});
