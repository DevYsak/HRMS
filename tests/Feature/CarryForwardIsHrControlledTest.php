<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\LeaveBalanceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * Carry forward is a decision, not a consequence.
 *
 * Under the July–June process the previous year's balance is never carried
 * automatically. HR reviews what is known, decides an amount per employee, and
 * applies it where the decision is recorded against them.
 *
 * That has to hold structurally, not merely by nobody happening to press the
 * button. It ran unattended every 1 January at 02:00 — attributable to nobody,
 * on a date that is not a boundary of this leave year at all — and the console
 * command could carry everybody's leave in one line.
 *
 * A leave type marked allow_carry_forward, with no limit and a "no lapse"
 * history, is eligible for consideration. It is not an instruction.
 */
function chcYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

function chcEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

/** The most permissive configuration the old spec allowed. */
function chcUnlimitedType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Comp Off '.Str::random(4),
        'code' => 'C'.strtoupper(Str::random(3)),
        'category' => 'comp_off',
        'allow_carry_forward' => true,
        // No lapse, no cap — the configuration that used to mean "carries
        // forward indefinitely".
        'carry_forward_limit' => 0,
    ]);
}

function chcHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

// ── No unattended path exists ──────────────────────────────────────────────

test('carry forward is not scheduled to run on its own', function () {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->filter(fn (string $command) => str_contains($command, 'carry-forward'));

    expect($scheduled)->toBeEmpty();
});

test('the console command refuses to apply', function () {
    chcYears();

    $this->artisan('hrms:carry-forward-leaves', ['--apply' => true])
        ->expectsOutputToContain('not supported')
        ->assertFailed();
});

test('the console refusal does not depend on there being data', function () {
    // Whether anything happens to be carryable has no bearing on whether the
    // console may carry it.
    [$prev, $curr] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 0, 'Complete record', null, $hr
    );

    $this->artisan('hrms:carry-forward-leaves', ['--apply' => true])->assertFailed();

    expect(LeaveCarryForwardTransaction::count())->toBe(0)
        ->and(LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->exists())->toBeFalse();
});

test('the console preview still works and writes nothing', function () {
    [$prev] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 0, 'Complete record', null, $hr
    );

    $this->artisan('hrms:carry-forward-leaves')
        ->expectsOutputToContain('PREVIEW')
        ->assertSuccessful();

    expect(LeaveCarryForwardTransaction::count())->toBe(0);
});

// ── An unlimited leave type carries nothing by itself ──────────────────────

test('an unlimited no-lapse leave type does not carry itself forward', function () {
    [$prev, $curr] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 0, 'Complete record', null, $hr
    );

    // The new leave year opens with fresh entitlement and nothing else.
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    $current = LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->first();

    expect((float) ($current->carried_forward_days ?? 0))->toBe(0.0)
        ->and(LeaveCarryForwardTransaction::count())->toBe(0);
});

test('allow_carry_forward makes a type eligible, not carried', function () {
    [$prev, $curr] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 0, 'Complete record', null, $hr
    );

    $preview = app(LeaveCarryForwardService::class)->preview($prev, $curr);

    // It appears as eligible…
    expect($preview)->toHaveCount(1)
        ->and($preview->first()['eligible'])->toBe(20.0)
        // …and nothing has been carried.
        ->and($preview->first()['applied'])->toBe(0.0)
        ->and(LeaveCarryForwardTransaction::count())->toBe(0);
});

// ── Every application is attributable ──────────────────────────────────────

test('applying records the person who decided it', function () {
    [$prev, $curr] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 0, 'Complete record', null, $hr
    );

    $tx = app(LeaveCarryForwardService::class)
        ->apply($employee, $type, $prev, $curr, $hr, days: 6, reason: 'HR approved 6 of 20');

    expect($tx->applied_by)->toBe($hr->id)
        ->and($tx->applied_at)->not->toBeNull()
        ->and((float) $tx->applied_days)->toBe(6.0)
        ->and((float) $tx->eligible_days)->toBe(20.0)
        ->and($tx->reason)->toBe('HR approved 6 of 20');
});

test('HR may approve less than the unlimited eligible amount', function () {
    [$prev, $curr] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 0, 'Complete record', null, $hr
    );
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 6);

    $current = LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->first();

    expect((float) $current->carried_forward_days)->toBe(6.0);
});

test('HR may approve zero against an unlimited type', function () {
    [$prev, $curr] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 0, 'Complete record', null, $hr
    );
    app(LeaveBalanceService::class)->initializeForEmployee($employee, $curr->legacyYear());

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 0);

    $current = LeaveBalance::where('employee_id', $employee->id)->where('year', $curr->legacyYear())->first();

    expect((float) $current->carried_forward_days)->toBe(0.0)
        ->and(LeaveCarryForwardTransaction::where('employee_id', $employee->id)->exists())->toBeTrue();
});

// ── Historical data is left alone throughout ───────────────────────────────

test('carrying forward never edits the previous year', function () {
    [$prev, $curr] = chcYears();
    $employee = chcEmployee();
    $type = chcUnlimitedType();
    $hr = chcHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $prev, 28, 8, 2, 'Complete record', null, $hr
    );

    $before = LeaveBalance::where('employee_id', $employee->id)->where('year', $prev->legacyYear())->first()->toArray();

    app(LeaveCarryForwardService::class)->apply($employee, $type, $prev, $curr, $hr, days: 5);

    $after = LeaveBalance::where('employee_id', $employee->id)->where('year', $prev->legacyYear())->first();

    expect((float) $after->allocated_days)->toBe((float) $before['allocated_days'])
        ->and((float) $after->used_days)->toBe((float) $before['used_days'])
        ->and((float) $after->encashed_days)->toBe((float) $before['encashed_days']);
});
