<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\TimeOff\LeaveCarryForward;
use App\Livewire\TimeOff\LeaveRegularisation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Managing one employee's leave from their own record.
 *
 * The page previously offered a year selector of calendar years and a single
 * "Manage Balance" button, and printed allocated_days beside carried_forward
 * without subtracting one from the other — so the same days appeared in two
 * columns and the totals did not add up on screen.
 */
function elmEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function elmType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_carry_forward' => true,
    ]);
}

function elmHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

// ── The balance table ──────────────────────────────────────────────────────

test('fresh entitlement excludes carried days', function () {
    // allocated_days holds fresh + carried. Printing it as "Allocated" beside
    // "Carried Fwd" showed the carried days twice.
    $employee = elmEmployee();
    $type = elmType();
    $year = app(LeaveYearResolver::class)->legacyYearFor();

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => $year,
        'allocated_days' => 33,
        'carried_forward_days' => 5,
        'used_days' => 3,
    ]);

    $summary = app(LeaveBalanceService::class)->getBalanceSummary($employee, $year);
    $row = $summary->firstWhere('leave_type_id', $type->id);

    expect($row->allocated)->toBe(33.0)
        ->and($row->carried_forward)->toBe(5.0)
        ->and($row->fresh)->toBe(28.0)
        // 28 fresh + 5 carried - 3 used = 30
        ->and($row->available)->toBe(30.0);
});

test('the leave tab renders with the fresh column', function () {
    $employee = elmEmployee();
    $type = elmType();

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => app(LeaveYearResolver::class)->legacyYearFor(),
        'allocated_days' => 33,
        'carried_forward_days' => 5,
        'used_days' => 3,
    ]);

    Livewire::actingAs(elmHr())
        ->test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Leave')
        ->assertOk()
        ->assertSee('Fresh')
        ->assertSee('Carried Fwd');
});

// ── Leave years, not calendar years ────────────────────────────────────────

test('the year selector offers leave years', function () {
    LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    // Asserted on the data rather than the rendered markup: the select builds
    // its options through Alpine, so the labels are not in the server HTML.
    Livewire::actingAs(elmHr())
        ->test(EmployeeEdit::class, ['employee' => elmEmployee()])
        ->call('setTab', 'Leave')
        ->assertOk()
        ->assertViewHas('leaveYearOptions', function ($options) {
            $labels = collect($options)->pluck('label');

            return $labels->contains('2025/26')
                && $labels->contains('2026/27')
                // The old selector offered bare calendar years.
                && ! $labels->contains('2026');
        });
});

test('opening the leave tab creates the years the selector needs', function () {
    // A fresh database has no leave_years rows at all, and an empty selector is
    // indistinguishable from a broken page.
    LeaveYear::query()->delete();

    Livewire::actingAs(elmHr())
        ->test(EmployeeEdit::class, ['employee' => elmEmployee()])
        ->call('setTab', 'Leave')
        ->assertOk();

    expect(LeaveYear::count())->toBeGreaterThanOrEqual(3);
});

test('switching year shows that year balances', function () {
    $employee = elmEmployee();
    $type = elmType();

    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);

    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id, 'year' => $prev->legacyYear(),
        'allocated_days' => 28, 'used_days' => 20,
    ]);

    Livewire::actingAs(elmHr())
        ->test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Leave')
        ->set('leaveBalanceYear', (string) $prev->legacyYear())
        ->assertOk()
        ->assertViewHas('balanceSummary', fn ($s) => $s->firstWhere('leave_type_id', $type->id)->allocated === 28.0);
});

// ── Permissions ────────────────────────────────────────────────────────────

test('management actions are permission-based, not role-based', function () {
    $employee = elmEmployee();

    Livewire::actingAs(elmHr())
        ->test(EmployeeEdit::class, ['employee' => $employee])
        ->call('setTab', 'Leave')
        ->assertViewHas('canManageLeaveBalance', true)
        ->assertViewHas('canCarryForward', true)
        ->assertViewHas('canRegulariseLeave', true);
});

test('a manager cannot reach the employee record at all', function () {
    // The page itself is gated, so the leave actions on it are unreachable
    // before any of their own checks run.
    Livewire::actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->test(EmployeeEdit::class, ['employee' => elmEmployee()])
        ->assertForbidden();
});

test('leave management permissions are held by HR and not by a manager', function () {
    $hr = elmHr();
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    expect($hr->hasPermission('manage_leave_balances'))->toBeTrue()
        ->and($hr->hasPermission('manage_leave_carry_forward'))->toBeTrue()
        ->and($hr->hasPermission('create_leave_regularisation'))->toBeTrue()
        ->and($manager->hasPermission('manage_leave_balances'))->toBeFalse()
        ->and($manager->hasPermission('manage_leave_carry_forward'))->toBeFalse();
});

// ── Employee context carries through ───────────────────────────────────────

test('carry forward opens pre-filtered to the employee', function () {
    LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    $employee = elmEmployee();

    Livewire::actingAs(elmHr())
        ->withQueryParams(['employeeId' => $employee->id])
        ->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('employeeId', $employee->id);
});

test('regularisation opens pre-filtered to the employee', function () {
    $employee = elmEmployee();

    Livewire::actingAs(elmHr())
        ->withQueryParams(['employeeId' => $employee->id])
        ->test(LeaveRegularisation::class)
        ->assertOk()
        ->assertSet('employeeFilter', $employee->id);
});

// ── Pending is bounded by the leave year ───────────────────────────────────

test('pending days are counted inside the leave year, not the calendar year', function () {
    $employee = elmEmployee();
    $type = elmType();

    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);

    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id, 'year' => $prev->legacyYear(),
        'allocated_days' => 28, 'used_days' => 0,
    ]);

    // June 2026 sits in leave year 2025/26 but calendar year 2026.
    LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-10',
        'days' => 1,
        'reason' => 'Pending',
        'status' => 'pending',
    ]);

    $summary = app(LeaveBalanceService::class)->getBalanceSummary($employee, $prev->legacyYear());

    expect($summary->firstWhere('leave_type_id', $type->id)->pending)->toBe(1.0);
});
