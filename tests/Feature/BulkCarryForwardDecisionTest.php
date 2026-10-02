<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveCarryForward;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction as Transaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\LeaveBalanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Carrying migrated years forward in bulk.
 *
 * The years being migrated have a closing balance and, usually, no usage
 * figure. Nothing can be derived from that, so HR states the amount per
 * employee and the system's job is to apply exactly those amounts — never to
 * fill a gap with a calculation it cannot make, and never to record the
 * absence as a zero.
 */
function bcfYears(): array
{
    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $curr = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    return [$prev, $curr];
}

function bcfType(): LeaveType
{
    return LeaveType::firstOrCreate(['code' => 'BCF'], [
        'name' => 'Bulk Annual', 'category' => 'annual',
        'allow_paid_request' => true, 'allow_carry_forward' => true,
    ]);
}

function bcfEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function bcfHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

/** A closed year with a closing balance and, optionally, a known usage. */
function bcfHistorical(Employee $e, LeaveType $t, LeaveYear $y, float $closing, ?float $used, ?float $encashed = null): LeaveBalance
{
    app(LeaveBalanceService::class)->setHistoricalBalance(
        $e, $t, $y, $closing, $used, $used === null ? null : ($encashed ?? 0),
        'Historical migration', null, bcfHr(),
    );

    return LeaveBalance::where('employee_id', $e->id)
        ->where('leave_type_id', $t->id)->where('year', $y->legacyYear())->firstOrFail();
}

function bcfService(): LeaveCarryForwardService
{
    return app(LeaveCarryForwardService::class);
}

function bcfCarried(Employee $e, LeaveType $t, LeaveYear $y): float
{
    return (float) LeaveBalance::where('employee_id', $e->id)
        ->where('leave_type_id', $t->id)->where('year', $y->legacyYear())->value('carried_forward_days');
}

// ── The worked example from the requirement ────────────────────────────────

test('closing 10 with unknown usage, HR approves 6, and 6 is carried', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();
    $hr = bcfHr();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    $result = bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], $hr, 'Migrated from the 2025/26 sheet');

    expect($result['applied'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and(bcfCarried($employee, $type, $curr))->toBe(6.0);

    $tx = Transaction::where('employee_id', $employee->id)->firstOrFail();

    expect((float) $tx->applied_days)->toBe(6.0)
        // Nothing was calculable, so no eligible figure is claimed.
        ->and($tx->eligible_days)->toBeNull()
        ->and($tx->used_status)->toBe(Transaction::FIGURE_UNKNOWN)
        ->and((float) $tx->historical_closing_balance)->toBe(10.0);
});

test('an unknown year records the absence rather than a zero usage', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], bcfHr());

    $tx = Transaction::where('employee_id', $employee->id)->firstOrFail();

    // The transaction must not assert that nobody took leave that year.
    expect($tx->historicalFiguresKnown())->toBeFalse()
        ->and($tx->isCalculable())->toBeFalse()
        ->and($tx->encashed_status)->toBe(Transaction::FIGURE_UNKNOWN);
});

// ── Known usage is calculable ──────────────────────────────────────────────

test('closing 28 used 10 makes 18 eligible, and HR may take all of it', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 28, used: 10, encashed: 0);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 18],
    ], bcfHr());

    $tx = Transaction::where('employee_id', $employee->id)->firstOrFail();

    expect((float) $tx->eligible_days)->toBe(18.0)
        ->and((float) $tx->applied_days)->toBe(18.0)
        ->and($tx->used_status)->toBe(Transaction::FIGURE_KNOWN)
        ->and(bcfCarried($employee, $type, $curr))->toBe(18.0);
});

test('HR may approve a partial amount of a calculable entitlement', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 28, used: 10, encashed: 0);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 5],
    ], bcfHr());

    $tx = Transaction::where('employee_id', $employee->id)->firstOrFail();

    expect((float) $tx->applied_days)->toBe(5.0)
        ->and((float) $tx->eligible_days)->toBe(18.0)
        ->and($tx->status)->toBe(Transaction::STATUS_PARTIALLY_APPLIED)
        ->and(bcfCarried($employee, $type, $curr))->toBe(5.0);
});

test('zero is a decision, recorded rather than skipped', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    $result = bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 0],
    ], bcfHr(), 'HR decided nothing carries');

    expect($result['applied'])->toBe(1)
        ->and(bcfCarried($employee, $type, $curr))->toBe(0.0)
        ->and(Transaction::where('employee_id', $employee->id)->exists())->toBeTrue();
});

// ── Ceilings ───────────────────────────────────────────────────────────────

test('more than the recorded closing balance is refused', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    $result = bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 25],
    ], bcfHr());

    expect($result['applied'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and(implode(' ', $result['errors']))->toContain('10')
        ->and(bcfCarried($employee, $type, $curr))->toBe(0.0);
});

test('more than the calculated eligible amount is refused', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 28, used: 10, encashed: 0);

    $result = bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 20],
    ], bcfHr());

    expect($result['applied'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and(implode(' ', $result['errors']))->toContain('18');
});

// ── Bulk ───────────────────────────────────────────────────────────────────

test('many employees are decided in one action, each at its own amount', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $hr = bcfHr();

    $a = bcfEmployee();
    $b = bcfEmployee();
    $c = bcfEmployee();

    bcfHistorical($a, $type, $prev, closing: 10, used: null);
    bcfHistorical($b, $type, $prev, closing: 28, used: 10, encashed: 0);
    bcfHistorical($c, $type, $prev, closing: 6, used: null);

    $result = bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $a->id, 'leave_type_id' => $type->id, 'days' => 6],
        ['employee_id' => $b->id, 'leave_type_id' => $type->id, 'days' => 18],
        ['employee_id' => $c->id, 'leave_type_id' => $type->id, 'days' => 0],
    ], $hr);

    expect($result['applied'])->toBe(3)
        ->and($result['days'])->toBe(24.0)
        ->and(bcfCarried($a, $type, $curr))->toBe(6.0)
        ->and(bcfCarried($b, $type, $curr))->toBe(18.0)
        ->and(bcfCarried($c, $type, $curr))->toBe(0.0);
});

test('one bad figure does not cost the rest of the batch', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();

    $good = bcfEmployee();
    $bad = bcfEmployee();

    bcfHistorical($good, $type, $prev, closing: 10, used: null);
    bcfHistorical($bad, $type, $prev, closing: 5, used: null);

    $result = bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $bad->id, 'leave_type_id' => $type->id, 'days' => 99],
        ['employee_id' => $good->id, 'leave_type_id' => $type->id, 'days' => 4],
    ], bcfHr());

    expect($result['applied'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and(bcfCarried($good, $type, $curr))->toBe(4.0)
        ->and(bcfCarried($bad, $type, $curr))->toBe(0.0);
});

test('bulk apply still refuses to guess an amount for an unknown year', function () {
    // applyAll derives amounts; it must leave the undecidable rows alone.
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    $result = bcfService()->applyAll($prev, $curr, bcfHr());

    expect($result['applied'])->toBe(0)
        ->and($result['skipped'])->toBeGreaterThan(0)
        ->and(bcfCarried($employee, $type, $curr))->toBe(0.0);
});

// ── Duplicate protection ───────────────────────────────────────────────────

test('the same employee, type and year pair never makes a second transaction', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();
    $hr = bcfHr();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    $decision = [['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6]];

    bcfService()->applyDecisions($prev, $curr, $decision, $hr);
    bcfService()->applyDecisions($prev, $curr, $decision, $hr);

    expect(Transaction::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('previous_leave_year_id', $prev->id)
        ->where('current_leave_year_id', $curr->id)
        ->count())->toBe(1)
        // And the balance is not doubled by the second run.
        ->and(bcfCarried($employee, $type, $curr))->toBe(6.0);
});

test('re-deciding at a new amount replaces the figure rather than adding to it', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();
    $hr = bcfHr();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], $hr);
    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 3],
    ], $hr);

    expect(bcfCarried($employee, $type, $curr))->toBe(3.0)
        ->and(Transaction::where('employee_id', $employee->id)->count())->toBe(1);
});

// ── Reversal ───────────────────────────────────────────────────────────────

test('a reversal returns the balance and leaves the record standing', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();
    $hr = bcfHr();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], $hr);

    $tx = Transaction::where('employee_id', $employee->id)->firstOrFail();
    bcfService()->reverse($tx, $hr, 'Wrong figure on the sheet');

    expect(bcfCarried($employee, $type, $curr))->toBe(0.0)
        ->and($tx->fresh()->status)->toBe(Transaction::STATUS_REVERSED)
        ->and($tx->fresh()->reversal_reason)->toBe('Wrong figure on the sheet');
});

// ── Pending is not usage ───────────────────────────────────────────────────

test('a pending request in the closed year does not reduce what may be carried', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 28, used: 10, encashed: 0);

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-01-05', 'end_date' => '2026-01-09', 'days' => 5,
        'reason' => 'Pending', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ]);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 18],
    ], bcfHr());

    // Eligible is closing minus used and encashed. The five pending days are
    // not usage and must not have reduced it to 13.
    expect((float) Transaction::where('employee_id', $employee->id)->value('eligible_days'))->toBe(18.0);
});

// ── Fresh entitlement and the previous year ────────────────────────────────

test('carrying forward adds to the fresh entitlement rather than replacing it', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    // The new year already holds its own entitlement.
    LeaveBalance::updateOrCreate(
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $curr->legacyYear()],
        ['leave_year_id' => $curr->id, 'allocated_days' => 28, 'used_days' => 0],
    );

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], bcfHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->firstOrFail();

    // 28 fresh + 6 carried = 34 available, with the carried part still visible
    // separately rather than folded into the allocation.
    expect((float) $balance->carried_forward_days)->toBe(6.0)
        ->and((float) $balance->allocated_days - (float) $balance->carried_forward_days)->toBe(28.0);
});

test('the closed year is left exactly as it was recorded', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();

    $before = bcfHistorical($employee, $type, $prev, closing: 10, used: null);
    $snapshot = $before->only(['allocated_days', 'used_days', 'encashed_days', 'used_days_unknown']);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], bcfHr());

    expect($before->fresh()->only(['allocated_days', 'used_days', 'encashed_days', 'used_days_unknown']))
        ->toBe($snapshot);
});

// ── Audit ──────────────────────────────────────────────────────────────────

test('the decision is audited with who made it and that it was not calculated', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();
    $hr = bcfHr();
    test()->actingAs($hr);

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);

    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], $hr, 'Agreed with the director');

    $log = AuditLog::where('action', 'leave.carry_forward_applied')
        ->where('subject_employee_id', $employee->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values['carry_forward_decided_by'])->toBe('hr_approved')
        ->and($log->new_values['historical_figures_known'])->toBeFalse()
        // Cast: the audit payload round-trips through JSON, where 6.0 is 6.
        ->and((float) $log->new_values['applied_days'])->toBe(6.0);
});

test('a reversal is audited too', function () {
    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $employee = bcfEmployee();
    $hr = bcfHr();
    test()->actingAs($hr);

    bcfHistorical($employee, $type, $prev, closing: 10, used: null);
    bcfService()->applyDecisions($prev, $curr, [
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'days' => 6],
    ], $hr);

    $tx = Transaction::where('employee_id', $employee->id)->firstOrFail();
    bcfService()->reverse($tx, $hr, 'Sheet was wrong');

    expect(AuditLog::where('action', 'leave.carry_forward_reversed')
        ->where('subject_employee_id', $employee->id)->exists())->toBeTrue();
});

// ── The screen ─────────────────────────────────────────────────────────────

test('HR ticks rows, types an amount each, and applies them in one action', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);

    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $a = bcfEmployee();
    $b = bcfEmployee();

    bcfHistorical($a, $type, $prev, closing: 10, used: null);
    bcfHistorical($b, $type, $prev, closing: 8, used: null);

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->set('previousYearId', $prev->id)
        ->set('currentYearId', $curr->id)
        ->set('selected', [$a->id.':'.$type->id, $b->id.':'.$type->id])
        ->set('decisions', [$a->id.':'.$type->id => '6', $b->id.':'.$type->id => '2'])
        ->call('applySelected')
        ->assertOk();

    expect(bcfCarried($a, $type, $curr))->toBe(6.0)
        ->and(bcfCarried($b, $type, $curr))->toBe(2.0);
});

test('a ticked row with no amount entered is left undecided, not carried at zero', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);

    [$prev, $curr] = bcfYears();
    $type = bcfType();
    $decided = bcfEmployee();
    $blank = bcfEmployee();

    bcfHistorical($decided, $type, $prev, closing: 10, used: null);
    bcfHistorical($blank, $type, $prev, closing: 10, used: null);

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->set('previousYearId', $prev->id)
        ->set('currentYearId', $curr->id)
        ->set('selected', [$decided->id.':'.$type->id, $blank->id.':'.$type->id])
        ->set('decisions', [$decided->id.':'.$type->id => '6'])
        ->call('applySelected');

    // Blank means "not decided yet". It must not become a zero-day decision.
    expect(bcfCarried($decided, $type, $curr))->toBe(6.0)
        ->and(Transaction::where('employee_id', $blank->id)->exists())->toBeFalse();
});
