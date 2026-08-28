<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveCarryForward;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction as Transaction;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Str;

/**
 * The HR workflow around carrying leave forward.
 *
 * The calculation itself is covered by LeaveCarryOverTest and is not repeated
 * here — this is about the decision recorded on top of it: what HR approved
 * versus what was eligible, that a second click cannot double anyone's leave,
 * and that a reversal leaves the history standing.
 */
function lcfYears(): array
{
    $prev = LeaveYear::firstOrCreate(
        ['label' => '2025/26'],
        ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']
    );
    $curr = LeaveYear::firstOrCreate(
        ['label' => '2026/27'],
        ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']
    );

    return [$prev, $curr];
}

function lcfType(float $limit = 0): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_carry_forward' => true,
        'carry_forward_limit' => $limit,
    ]);
}

function lcfEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function lcfBalance(Employee $e, LeaveType $t, LeaveYear $y, float $allocated, float $used = 0, float $encashed = 0): LeaveBalance
{
    return LeaveBalance::create([
        'employee_id' => $e->id,
        'leave_type_id' => $t->id,
        'leave_year_id' => $y->id,
        'year' => $y->legacyYear(),
        'allocated_days' => $allocated,
        'used_days' => $used,
        'encashed_days' => $encashed,
    ]);
}

function lcfService(): LeaveCarryForwardService
{
    return app(LeaveCarryForwardService::class);
}

function lcfHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

// ── 1. Calculation comes from the existing engine ──────────────────────────

test('eligible days are allocated minus used minus encashed', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    $row = lcfService()->preview($prev, $curr)->firstWhere('employee_id', $employee->id);

    expect($row['eligible'])->toBe(8.0)
        ->and($row['allocated'])->toBe(28.0)
        ->and($row['used'])->toBe(20.0);
});

test('encashed days are not carried forward as well', function () {
    // They have already been paid out; carrying them would hand the employee
    // the same days twice.
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 15, encashed: 5);

    $row = lcfService()->preview($prev, $curr)->firstWhere('employee_id', $employee->id);

    expect($row['eligible'])->toBe(8.0);
});

test('nothing is carried when the previous year is fully used', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 28);

    expect(lcfService()->preview($prev, $curr)->firstWhere('employee_id', $employee->id))->toBeNull();
});

test('the policy limit caps what may be carried', function () {
    // Eligibility is not the same as entitlement to carry all of it — and the
    // cap now lives on the leave policy, not the leave type.
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $policy = LeavePolicy::create([
        'name' => 'Cap '.Str::random(5),
        'statutory_weeks' => 5.60,
        'bank_holiday_treatment' => 'additional',
        'max_carry_over_days' => 5,
        'is_default' => false,
        'is_active' => true,
    ]);
    $employee->update(['leave_policy_id' => $policy->id]);
    $employee = $employee->fresh();

    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    $row = lcfService()->preview($prev, $curr)->firstWhere('employee_id', $employee->id);

    expect($row['eligible'])->toBe(8.0)
        ->and($row['carry'])->toBe(5.0);
});

// ── 2. Applying ────────────────────────────────────────────────────────────

test('applying carries the full eligible amount', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    $tx = lcfService()->apply($employee, $type, $prev, $curr, lcfHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect($tx->applied_days)->toBe(8.0)
        ->and($tx->status)->toBe(Transaction::STATUS_APPLIED)
        ->and((float) $balance->carried_forward_days)->toBe(8.0)
        // Fresh entitlement survives: 28 + 8, not 8.
        ->and((float) $balance->allocated_days)->toBe(36.0);
});

test('a partial carry forward keeps the original eligibility', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    $tx = lcfService()->apply($employee, $type, $prev, $curr, lcfHr(), days: 5);

    expect($tx->eligible_days)->toBe(8.0)
        ->and($tx->applied_days)->toBe(5.0)
        ->and($tx->remainingEligible())->toBe(3.0)
        ->and($tx->status)->toBe(Transaction::STATUS_PARTIALLY_APPLIED);
});

test('more than the eligible amount is refused', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    expect(fn () => lcfService()->apply($employee, $type, $prev, $curr, lcfHr(), days: 12))
        ->toThrow(RuntimeException::class);
});

test('the previous year position is recorded on the transaction', function () {
    // Those balances move on. Without a snapshot the preview cannot be
    // reconstructed later to explain the decision.
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20, encashed: 0);

    $tx = lcfService()->apply($employee, $type, $prev, $curr, lcfHr());

    expect($tx->previous_allocated_days)->toBe(28.0)
        ->and($tx->previous_used_days)->toBe(20.0)
        ->and($tx->previous_leave_year_id)->toBe($prev->id)
        ->and($tx->current_leave_year_id)->toBe($curr->id);
});

// ── 3. Idempotency ─────────────────────────────────────────────────────────

test('applying twice does not double the days', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    $service = lcfService();
    $service->apply($employee, $type, $prev, $curr, lcfHr());
    $service->apply($employee, $type, $prev, $curr, lcfHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(8.0)
        ->and((float) $balance->allocated_days)->toBe(36.0)
        ->and(Transaction::where('employee_id', $employee->id)->count())->toBe(1);
});

test('applying in bulk twice is also safe', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    $service = lcfService();
    $service->applyAll($prev, $curr, lcfHr());
    $second = $service->applyAll($prev, $curr, lcfHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(8.0)
        ->and($second['applied'])->toBe(0)
        ->and($second['skipped'])->toBe(1);
});

test('re-applying a partial at a new figure replaces rather than stacks', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    $service = lcfService();
    $service->apply($employee, $type, $prev, $curr, lcfHr(), days: 3);
    $service->apply($employee, $type, $prev, $curr, lcfHr(), days: 6);

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(6.0)
        ->and((float) $balance->allocated_days)->toBe(34.0);
});

// ── 4. Reversal ────────────────────────────────────────────────────────────

test('reversing returns the balance to its fresh entitlement', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    $service = lcfService();
    $tx = $service->apply($employee, $type, $prev, $curr, lcfHr());
    $service->reverse($tx, lcfHr(), 'Carried in error');

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(0.0)
        ->and((float) $balance->allocated_days)->toBe(28.0);
});

test('a reversal keeps the record rather than deleting it', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    $service = lcfService();
    $hr = lcfHr();
    $tx = $service->apply($employee, $type, $prev, $curr, $hr);
    $reversed = $service->reverse($tx->fresh(), $hr, 'Carried in error');

    expect(Transaction::find($tx->id))->not->toBeNull()
        ->and($reversed->status)->toBe(Transaction::STATUS_REVERSED)
        ->and($reversed->reversed_days)->toBe(8.0)
        ->and($reversed->eligible_days)->toBe(8.0)
        ->and($reversed->reversed_by)->toBe($hr->id)
        ->and($reversed->reversal_reason)->toBe('Carried in error')
        ->and($reversed->reversed_at)->not->toBeNull();
});

test('a reversal needs a reason', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    $service = lcfService();
    $tx = $service->apply($employee, $type, $prev, $curr, lcfHr());

    expect(fn () => $service->reverse($tx, lcfHr(), '  '))->toThrow(RuntimeException::class);
});

test('something never applied cannot be reversed', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    $tx = new Transaction([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'previous_leave_year_id' => $prev->id,
        'current_leave_year_id' => $curr->id,
        'eligible_days' => 8,
        'applied_days' => 0,
        'status' => Transaction::STATUS_ELIGIBLE,
    ]);
    $tx->save();

    expect(fn () => lcfService()->reverse($tx, lcfHr(), 'No'))->toThrow(RuntimeException::class);
});

test('re-applying after a reversal clears the reversal', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    $service = lcfService();
    $hr = lcfHr();
    $tx = $service->apply($employee, $type, $prev, $curr, $hr);
    $service->reverse($tx->fresh(), $hr, 'Wrong');
    $again = $service->apply($employee, $type, $prev, $curr, $hr);

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect($again->reversed_days)->toBe(0.0)
        ->and($again->status)->toBe(Transaction::STATUS_APPLIED)
        ->and((float) $balance->carried_forward_days)->toBe(8.0);
});

// ── 5. The leave year ──────────────────────────────────────────────────────

test('the leave year runs July to June, not January to December', function () {
    lcfYears();
    $resolver = app(LeaveYearResolver::class);

    $year = $resolver->forDate(now()->parse('2026-08-15'));

    expect($year->label)->toBe('2026/27')
        ->and($year->starts_on->toDateString())->toBe('2026-07-01')
        ->and($year->ends_on->toDateString())->toBe('2027-06-30');
});

test('30 June and 1 July fall in different leave years', function () {
    lcfYears();
    $resolver = app(LeaveYearResolver::class);

    expect($resolver->forDate(now()->parse('2026-06-30'))->label)->toBe('2025/26')
        ->and($resolver->forDate(now()->parse('2026-07-01'))->label)->toBe('2026/27');
});

test('the previous leave year resolves from the current one', function () {
    [$prev, $curr] = lcfYears();

    expect(app(LeaveYearResolver::class)->previous($curr)->id)->toBe($prev->id);
});

// ── 6. Carried days stay distinguishable from fresh entitlement ────────────

test('carried days are visible separately from the fresh allocation', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    lcfService()->apply($employee, $type, $prev, $curr, lcfHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();
    $fresh = (float) $balance->allocated_days - (float) $balance->carried_forward_days;

    expect($fresh)->toBe(28.0)
        ->and((float) $balance->carried_forward_days)->toBe(8.0);
});

test('the history says which year the days came from', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    lcfService()->apply($employee, $type, $prev, $curr, lcfHr());

    $history = lcfService()->historyFor($employee);

    expect($history)->toHaveCount(1)
        ->and($history->first()->previousLeaveYear->label)->toBe('2025/26')
        ->and($history->first()->currentLeaveYear->label)->toBe('2026/27');
});

// ── 7. Audit ───────────────────────────────────────────────────────────────

test('applying is audited with both sides of the change', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);
    $hr = lcfHr();

    $this->actingAs($hr);
    lcfService()->apply($employee, $type, $prev, $curr, $hr);

    $log = AuditLog::where('action', 'leave.carry_forward_applied')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['carried_forward_days'])->toBe(0)
        ->and($log->new_values['carried_forward_days'])->toBe(8)
        ->and($log->new_values['eligible_days'])->toBe(8)
        ->and($log->new_values['previous_leave_year'])->toBe('2025/26')
        ->and($log->subject_employee_id)->toBe($employee->id);
});

test('a partial application is audited as partial', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    $hr = lcfHr();

    $this->actingAs($hr);
    lcfService()->apply($employee, $type, $prev, $curr, $hr, days: 5, reason: 'Policy cap agreed with director');

    $log = AuditLog::where('action', 'leave.carry_forward_applied')->latest('id')->first();

    expect($log->new_values['partial'])->toBeTrue()
        ->and($log->new_values['applied_days'])->toBe(5)
        ->and($log->reason)->toBe('Policy cap agreed with director');
});

test('a reversal is audited separately', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);
    $hr = lcfHr();

    $this->actingAs($hr);
    $service = lcfService();
    $tx = $service->apply($employee, $type, $prev, $curr, $hr);
    $service->reverse($tx->fresh(), $hr, 'Carried in error');

    $log = AuditLog::where('action', 'leave.carry_forward_reversed')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['carried_forward_days'])->toBe(8)
        ->and($log->new_values['carried_forward_days'])->toBe(0)
        ->and($log->new_values['reversed_days'])->toBe(8);
});

// ── 8. Scale ───────────────────────────────────────────────────────────────

test('several employees carry forward independently', function () {
    [$prev, $curr] = lcfYears();
    $type = lcfType();

    $a = lcfEmployee();
    $b = lcfEmployee();
    $c = lcfEmployee();

    lcfBalance($a, $type, $prev, allocated: 28, used: 20);   // 8 eligible
    lcfBalance($b, $type, $prev, allocated: 28, used: 28);   // nothing
    lcfBalance($c, $type, $prev, allocated: 28, used: 25);   // 3 eligible

    $result = lcfService()->applyAll($prev, $curr, lcfHr());

    expect($result['applied'])->toBe(2)
        ->and($result['days'])->toBe(11.0)
        ->and(Transaction::where('employee_id', $b->id)->exists())->toBeFalse();
});

test('several leave types for one employee stay separate', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $annual = lcfType();
    $sick = lcfType();

    lcfBalance($employee, $annual, $prev, allocated: 28, used: 20);  // 8
    lcfBalance($employee, $sick, $prev, allocated: 10, used: 7);     // 3

    lcfService()->applyAll($prev, $curr, lcfHr());

    $rows = Transaction::where('employee_id', $employee->id)->get()->keyBy('leave_type_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$annual->id]->applied_days)->toBe(8.0)
        ->and($rows[$sick->id]->applied_days)->toBe(3.0);
});

// ── 9. Preview reflects what has already been decided ──────────────────────

test('the preview shows what remains after a partial carry forward', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    $service = lcfService();
    $service->apply($employee, $type, $prev, $curr, lcfHr(), days: 5);

    $row = $service->preview($prev, $curr)->firstWhere('employee_id', $employee->id);

    expect($row['eligible'])->toBe(8.0)
        ->and($row['applied'])->toBe(5.0)
        ->and($row['remaining_eligible'])->toBe(3.0)
        ->and($row['status'])->toBe(Transaction::STATUS_PARTIALLY_APPLIED);
});

test('the preview can be filtered to one employee', function () {
    [$prev, $curr] = lcfYears();
    $type = lcfType();
    $a = lcfEmployee();
    $b = lcfEmployee();
    lcfBalance($a, $type, $prev, allocated: 28, used: 20);
    lcfBalance($b, $type, $prev, allocated: 28, used: 20);

    $rows = lcfService()->preview($prev, $curr, ['employee_id' => $a->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['employee_id'])->toBe($a->id);
});

// ── 10. The screen and its permissions ─────────────────────────────────────

test('HR can open the carry forward screen', function () {
    lcfYears();

    Livewire\Livewire::actingAs(lcfHr())
        ->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('currentYearId', LeaveYear::where('label', '2026/27')->value('id'))
        // The previous year is resolved, not chosen.
        ->assertSet('previousYearId', LeaveYear::where('label', '2025/26')->value('id'));
});

test('an employee cannot open the carry forward screen', function () {
    lcfYears();

    Livewire\Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(LeaveCarryForward::class)
        ->assertForbidden();
});

test('an employee cannot apply carry forward through the component', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);

    Livewire\Livewire::actingAs(lcfHr())
        ->test(LeaveCarryForward::class)
        ->call('applyRow', $employee->id, $type->id)
        ->assertOk();

    // The same call as somebody without the permission must not go through.
    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]));

    expect(auth()->user()->hasPermission('manage_leave_carry_forward'))->toBeFalse();
});

test('the screen previews without applying anything', function () {
    // The whole point: opening the page must not change a single balance.
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    Livewire\Livewire::actingAs(lcfHr())
        ->test(LeaveCarryForward::class)
        ->assertOk();

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(0.0)
        ->and((float) $balance->allocated_days)->toBe(28.0)
        ->and(Transaction::count())->toBe(0);
});

test('applying from the screen carries the days', function () {
    [$prev, $curr] = lcfYears();
    $employee = lcfEmployee();
    $type = lcfType();
    lcfBalance($employee, $type, $prev, allocated: 28, used: 20);
    lcfBalance($employee, $type, $curr, allocated: 28);

    Livewire\Livewire::actingAs(lcfHr())
        ->test(LeaveCarryForward::class)
        ->call('applyRow', $employee->id, $type->id);

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(8.0);
});
