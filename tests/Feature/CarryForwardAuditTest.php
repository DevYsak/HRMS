<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The audit that explains an empty Carry Forward screen.
 *
 * A screen showing four zeros can mean no active employees, no carry-forwardable
 * leave type, no previous-year balances, or balances that net to nothing. Those
 * are four different problems with four different answers, and the page cannot
 * tell them apart. This can.
 *
 * It must never write. Creating a zero balance to make the screen populate would
 * turn "we have no history for this employee" into "this employee earned
 * nothing", which is a different and much worse claim.
 */
function cfaYears(): array
{
    return [
        LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']),
        LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
    ];
}

test('the audit reports when no previous-year balances exist', function () {
    cfaYears();
    Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
    LeaveType::create([
        'name' => 'Annual '.Str::random(4), 'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual', 'allow_carry_forward' => true,
    ]);

    $this->artisan('leave:carry-forward-audit')
        ->expectsOutputToContain('Historical leave data not available')
        ->assertSuccessful();
});

test('the audit writes nothing at all', function () {
    cfaYears();
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
    LeaveType::create([
        'name' => 'Annual '.Str::random(4), 'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual', 'allow_carry_forward' => true,
    ]);

    $before = LeaveBalance::count();

    $this->artisan('leave:carry-forward-audit')->assertSuccessful();

    expect(LeaveBalance::count())->toBe($before)
        ->and(LeaveBalance::where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('the audit names the missing precondition rather than saying zero', function () {
    // No carry-forwardable leave type: a different cause, a different answer.
    //
    // The canonical migration seeds Annual Leave, which is carry-forwardable,
    // so the condition has to be created deliberately. Retiring it rather than
    // deleting keeps this consistent with how the application treats types.
    cfaYears();
    Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
    LeaveType::where('allow_carry_forward', true)->get()->each->delete();

    LeaveType::create([
        'name' => 'Sick '.Str::random(4), 'code' => 'S'.strtoupper(Str::random(3)),
        'category' => 'sick', 'allow_carry_forward' => false,
    ]);

    $this->artisan('leave:carry-forward-audit')
        ->expectsOutputToContain('No leave type is marked carry-forwardable')
        ->assertSuccessful();
});

test('the audit stops reporting a problem once the history exists', function () {
    [$prev] = cfaYears();
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
    $type = LeaveType::create([
        'name' => 'Annual '.Str::random(4), 'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual', 'allow_carry_forward' => true,
    ]);

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id,
        'year' => $prev->legacyYear(),
        'allocated_days' => 28,
        'used_days' => 20,
    ]);

    $this->artisan('leave:carry-forward-audit')
        ->doesntExpectOutputToContain('Historical leave data not available')
        ->assertSuccessful();
});
