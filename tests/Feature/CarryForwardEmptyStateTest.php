<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveCarryForward;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveCarryForwardService;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * What the Carry Forward screen says when there is nothing to carry.
 *
 * Zero eligible rows and zero outstanding days are different situations with
 * the same four zeros on screen. The page reported both as "every eligible row
 * is already carried forward", which told HR an operation had completed when it
 * had never had anything to run against — and left the button live, so
 * confirming it did nothing and looked like success.
 */
function cfeYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

function cfeType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_carry_forward' => true,
    ]);
}

function cfeEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function cfeHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

/** An employee with a genuine previous-year position to carry. */
function cfeEligible(): array
{
    [$prev, $curr] = cfeYears();
    $employee = cfeEmployee();
    $type = cfeType();

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id,
        'year' => $prev->legacyYear(),
        'allocated_days' => 28,
        'used_days' => 20,
    ]);

    return [$employee, $type, $prev, $curr];
}

// ── Case 1: no eligible rows ───────────────────────────────────────────────

test('with no eligible rows the apply button is disabled', function () {
    cfeYears();
    cfeEmployee();
    cfeType();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('hasEligibleRows', false)
        ->assertSeeHtml('disabled');
});

test('with no eligible rows the button carries no confirmation', function () {
    // A disabled button that still holds wire:confirm opens a dialog asking to
    // confirm nothing.
    cfeYears();
    cfeEmployee();
    cfeType();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertDontSeeHtml('wire:confirm="Apply carry forward for all eligible rows?');
});

test('applying with no eligible rows writes nothing', function () {
    // The guard is server-side. A disabled button is a courtesy; this is what
    // actually stops the run.
    cfeYears();
    cfeEmployee();
    cfeType();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->call('applyAll')
        ->assertOk();

    expect(LeaveCarryForwardTransaction::count())->toBe(0)
        ->and(LeaveBalance::count())->toBe(0);
});

test('the message names the real situation, not a completed operation', function () {
    cfeYears();
    cfeEmployee();
    cfeType();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->call('applyAll')
        ->assertOk()
        // The old wording claimed the work was done.
        ->assertDontSee('already carried forward');
});

test('the empty state explains what zero means', function () {
    cfeYears();
    cfeEmployee();
    cfeType();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSee('No carry-forwardable leave')
        ->assertSee('No eligible 2025/26')
        ->assertSee('allocated');
});

test('zero KPIs accompany zero rows', function () {
    cfeYears();
    cfeEmployee();
    cfeType();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertViewHas('totals', fn ($t) => $t['employees'] === 0
            && $t['eligible'] === 0.0
            && $t['applied'] === 0.0
            && $t['outstanding'] === 0.0);
});

// ── Case 2: eligible rows exist ────────────────────────────────────────────

test('with eligible rows the apply button is live and confirms', function () {
    cfeEligible();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('hasEligibleRows', true)
        ->assertSeeHtml('Apply carry forward for all eligible rows?');
});

test('the confirmation states what will happen', function () {
    cfeEligible();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSeeHtml('1 employee(s) across 1 row(s)')
        ->assertSeeHtml('8 day(s) to carry');
});

test('applying with eligible rows carries the days', function () {
    [$employee, $type, , $curr] = cfeEligible();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)
        ->call('applyAll')
        ->assertOk();

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(8.0);
});

// ── Case 3: eligible rows exist but all are applied ────────────────────────

test('once everything is carried the button says so and is disabled', function () {
    cfeEligible();
    $hr = cfeHr();

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)->call('applyAll');

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('hasEligibleRows', true)
        ->assertSet('outstandingDays', 0.0)
        ->assertSee('Already carried forward');
});

test('applying again reports the work as done, not as impossible', function () {
    // This is the only situation in which that wording is true.
    cfeEligible();
    $hr = cfeHr();

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)->call('applyAll');

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->call('applyAll')
        ->assertOk();

    expect(LeaveCarryForwardTransaction::count())->toBe(1);
});

// ── Preview stays read-only, apply stays idempotent ────────────────────────

test('opening the page changes nothing', function () {
    [$employee, $type, , $curr] = cfeEligible();

    Livewire::actingAs(cfeHr())->test(LeaveCarryForward::class)->assertOk();

    expect(LeaveCarryForwardTransaction::count())->toBe(0)
        ->and(LeaveBalance::where('year', $curr->legacyYear())->count())->toBe(0);
});

test('applying twice does not double the days', function () {
    [$employee, , , $curr] = cfeEligible();
    $hr = cfeHr();

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)->call('applyAll');
    Livewire::actingAs($hr)->test(LeaveCarryForward::class)->call('applyAll');

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('year', $curr->legacyYear())->first();

    expect((float) $balance->carried_forward_days)->toBe(8.0)
        ->and(LeaveCarryForwardTransaction::count())->toBe(1);
});

test('a partial carry leaves the button live for the remainder', function () {
    [$employee, $type, $prev, $curr] = cfeEligible();
    $hr = cfeHr();

    app(LeaveCarryForwardService::class)
        ->apply($employee, $type, $prev, $curr, $hr, days: 5);

    Livewire::actingAs($hr)->test(LeaveCarryForward::class)
        ->assertOk()
        ->assertSet('hasEligibleRows', true)
        ->assertSet('outstandingDays', 3.0)
        ->assertSeeHtml('Apply carry forward for all eligible rows?');
});
