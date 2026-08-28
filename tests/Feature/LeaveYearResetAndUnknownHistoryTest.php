<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveCarryForward;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The July reset, and what happens when history is incomplete.
 *
 * We hold closing balances and pending requests for closed years, not a
 * reliable count of days actually taken. Recording that gap as used_days = 0
 * makes the system assert something nobody measured — and carry forward would
 * then compute allocated - 0 - 0 and offer HR the entire year as eligible.
 *
 * So an unknown stays unknown: it is shown as unknown, it is excluded from
 * bulk apply, and the amount that carries is the one HR states rather than one
 * the system derives.
 */
function lyrYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

function lyrEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function lyrType(?float $allocation = 28): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_carry_forward' => true,
        'annual_allocation_days' => $allocation,
    ]);
}

function lyrHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

/** A closed year with a closing balance but no usage figure. */
function lyrUnknownHistory(Employee $e, LeaveType $t, LeaveYear $prev, float $closing = 10): void
{
    app(LeaveBalanceService::class)->setHistoricalBalance(
        $e, $t, $prev, $closing, null, null, 'Closing balance from HR sheet; usage not recorded', null, lyrHr()
    );
}

// ── 12 & 13. The boundary ──────────────────────────────────────────────────

test('30 June and 1 July fall in different leave years', function () {
    $resolver = app(LeaveYearResolver::class);

    expect($resolver->forDate(Carbon::parse('2026-06-30'))->label)->toBe('2025/26')
        ->and($resolver->forDate(Carbon::parse('2026-07-01'))->label)->toBe('2026/27');
});

test('the new leave year runs 1 July to 30 June', function () {
    [, $curr] = lyrYears();

    expect($curr->starts_on->toDateString())->toBe('2026-07-01')
        ->and($curr->ends_on->toDateString())->toBe('2027-06-30');
});

// ── 1 & 2. Fresh entitlement, previous year preserved ──────────────────────

test('the new year gets fresh entitlement without touching the old one', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType(allocation: 28);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);

    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    $previous = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $prev->legacyYear())->first();
    $current = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $current->allocated_days)->toBe(28.0)
        ->and((float) $current->carried_forward_days)->toBe(0.0)
        // The closed year is untouched, and still says it does not know.
        ->and((float) $previous->allocated_days)->toBe(10.0)
        ->and((bool) $previous->used_days_unknown)->toBeTrue();
});

test('the previous year survives a carry forward into the new one', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 6);

    $previous = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $prev->legacyYear())->first();

    expect((float) $previous->allocated_days)->toBe(10.0)
        ->and($previous->leave_year_id)->toBe($prev->id);
});

// ── 3. Nothing carries automatically ───────────────────────────────────────

test('an unknown history is never carried automatically', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);

    $result = app(LeaveCarryForwardService::class)->applyAll($prev, $curr, $hr);

    expect($result['applied'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and(LeaveCarryForwardTransaction::count())->toBe(0);
});

test('applying without an amount is refused when usage is unknown', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);

    expect(fn () => app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr))
        ->toThrow(RuntimeException::class, 'not available');
});

test('the bulk button reports a decision is needed, not that work is done', function () {
    [$prev] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $this->actingAs(lyrHr());

    lyrUnknownHistory($employee, $type, $prev, closing: 10);

    Livewire::actingAs(lyrHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('hasEligibleRows', true)
        ->assertSet('hasDerivableRows', false)
        ->assertSee('Awaiting HR decision')
        ->assertDontSee('All eligible leave carried forward');
});

// ── 4 & 5. HR decides the amount ───────────────────────────────────────────

test('HR can approve a partial amount against an unknown history', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 6);

    $current = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $current->carried_forward_days)->toBe(6.0)
        // 28 fresh + 6 carried
        ->and((float) $current->allocated_days)->toBe(34.0);
});

test('HR can approve zero', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 0);

    $current = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $current->carried_forward_days)->toBe(0.0)
        ->and((float) $current->allocated_days)->toBe(28.0);
});

test('HR cannot approve more than the recorded closing balance', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);

    expect(fn () => app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 12))
        ->toThrow(RuntimeException::class);
});

// ── 6. Pending is not used ─────────────────────────────────────────────────

test('a pending request does not become used days', function () {
    [$prev] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $this->actingAs(lyrHr());

    lyrUnknownHistory($employee, $type, $prev, closing: 10);

    LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-11',
        'days' => 2,
        'reason' => 'Still pending',
        'status' => 'pending',
    ]);

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $prev->legacyYear())->first();

    expect((float) $balance->used_days)->toBe(0.0)
        ->and((bool) $balance->used_days_unknown)->toBeTrue();
});

test('a pending request keeps its own leave year', function () {
    [$prev] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();

    LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-10',
        'days' => 1,
        'reason' => 'June, previous leave year',
        'status' => 'pending',
    ]);

    $request = LeaveRequest::where('employee_id', $employee->id)->first();

    expect(app(LeaveYearResolver::class)->forDate($request->start_date)->id)->toBe($prev->id)
        ->and($request->status)->toBe('pending');
});

// ── 7. Unknown stays unknown ───────────────────────────────────────────────

test('an unknown usage figure is never presented as zero', function () {
    [$prev] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $this->actingAs(lyrHr());

    lyrUnknownHistory($employee, $type, $prev, closing: 10);

    Livewire::actingAs(lyrHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSee('Not available')
        ->assertSee('HR to decide');
});

test('a known usage figure is still shown as a number', function () {
    // The unknown path must not swallow the ordinary case.
    [$prev] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 10, 0, 'Complete record', null, $hr
    );

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertViewHas('rows', function ($rows) use ($employee) {
            $row = $rows->firstWhere('employee_id', $employee->id);

            return $row['figures_known'] === true && $row['eligible'] === 18.0;
        });
});

// ── 8. Carry forward stays a separate component ────────────────────────────

test('carried days remain separable from fresh entitlement', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());
    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 6);

    $summary = app(LeaveBalanceService::class)->getBalanceSummary($employee, $curr->legacyYear());
    $row = $summary->firstWhere('leave_type_id', $type->id);

    expect($row->fresh)->toBe(28.0)
        ->and($row->carried_forward)->toBe(6.0)
        ->and($row->used)->toBe(0.0)
        ->and($row->available)->toBe(34.0);
});

// ── 9 & 10. Reversal and idempotency ───────────────────────────────────────

test('an HR-decided carry forward can be reversed', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    $service = app(LeaveCarryForwardService::class);
    $tx = $service->apply($employee, $type, $prev, $curr, $hr, days: 6);
    $service->reverse($tx->fresh(), $hr, 'Approved in error');

    $current = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $current->carried_forward_days)->toBe(0.0)
        ->and((float) $current->allocated_days)->toBe(28.0)
        ->and($tx->fresh()->status)->toBe(LeaveCarryForwardTransaction::STATUS_REVERSED);
});

test('re-approving the same amount does not duplicate days', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    $service = app(LeaveCarryForwardService::class);
    $service->apply($employee, $type, $prev, $curr, $hr, days: 6);
    $service->apply($employee, $type, $prev, $curr, $hr, days: 6);

    $current = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $current->carried_forward_days)->toBe(6.0)
        ->and((float) $current->allocated_days)->toBe(34.0)
        ->and(LeaveCarryForwardTransaction::count())->toBe(1);
});

// ── 11. The audit must not claim knowledge it lacks ────────────────────────

test('the historical audit records unknown as unknown', function () {
    [$prev] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 10, null, null, 'Closing balance only', 'From HR spreadsheet', $hr
    );

    $log = AuditLog::where('action', 'leave.historical_balance_set')->latest('id')->first();

    expect($log->new_values['used_days'])->toBe('not_available')
        ->and($log->new_values['encashed_days'])->toBe('not_available')
        ->and($log->new_values['used_days_known'])->toBeFalse()
        ->and($log->new_values['eligible_for_carry_forward'])->toBe('not_derivable')
        ->and($log->new_values['leave_year_label'])->toBe('2025/26')
        ->and($log->reason)->toBe('Closing balance only')
        ->and($log->subject_employee_id)->toBe($employee->id);
});

test('the carry-forward audit says HR decided the amount', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    lyrUnknownHistory($employee, $type, $prev, closing: 10);
    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 6, reason: 'HR approved 6 days');

    $log = AuditLog::where('action', 'leave.carry_forward_applied')->latest('id')->first();

    expect($log->new_values['historical_figures_known'])->toBeFalse()
        ->and($log->new_values['carry_forward_decided_by'])->toBe('hr_approved')
        ->and((float) $log->new_values['applied_days'])->toBe(6.0)
        ->and($log->new_values['previous_leave_year'])->toBe('2025/26')
        ->and($log->new_values['current_leave_year'])->toBe('2026/27')
        ->and($log->reason)->toBe('HR approved 6 days');
});

test('a calculated carry forward is marked as calculated', function () {
    [$prev, $curr] = lyrYears();
    $employee = lyrEmployee();
    $type = lyrType();
    $hr = lyrHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 20, 0, 'Complete record', null, $hr
    );

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr);

    $log = AuditLog::where('action', 'leave.carry_forward_applied')->latest('id')->first();

    expect($log->new_values['historical_figures_known'])->toBeTrue()
        ->and($log->new_values['carry_forward_decided_by'])->toBe('calculated');
});
