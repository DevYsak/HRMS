<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Support\Str;

/**
 * The console carry-forward must not be able to destroy balances.
 *
 * The old implementation applied immediately with no preview: it replaced each
 * employee's fresh entitlement with the carried figure and reset used_days to
 * zero, so a single mistyped command erased every booking in the target year.
 * These pin the two properties that stop that happening again — it previews by
 * default, and when it does write it writes correctly.
 */
function cfcSetup(): array
{
    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $curr = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);

    $type = LeaveType::create([
        'name' => 'Annual '.Str::random(4),
        'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual',
        'allow_carry_forward' => true,
    ]);

    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id, 'year' => $prev->legacyYear(),
        'allocated_days' => 28, 'used_days' => 20,
    ]);

    $target = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $curr->id, 'year' => $curr->legacyYear(),
        'allocated_days' => 28, 'used_days' => 3,
    ]);

    return [$employee, $type, $target];
}

test('the command previews and writes nothing', function () {
    [, , $target] = cfcSetup();

    $this->artisan('hrms:carry-forward-leaves', ['year' => 2026])
        ->expectsOutputToContain('PREVIEW')
        ->assertSuccessful();

    expect((float) $target->fresh()->allocated_days)->toBe(28.0)
        ->and((float) $target->fresh()->used_days)->toBe(3.0)
        ->and((float) $target->fresh()->carried_forward_days)->toBe(0.0);
});

test('the command refuses to apply at all', function () {
    // Carry forward is an HR decision per employee, recorded against whoever
    // made it. A console run is attributable to a shell, so the one thing this
    // must not offer is a way to carry everybody's leave with no decision
    // behind it.
    [, , $target] = cfcSetup();

    $this->artisan('hrms:carry-forward-leaves', ['year' => 2026, '--apply' => true])
        ->expectsOutputToContain('not supported')
        ->assertFailed();

    expect((float) $target->fresh()->allocated_days)->toBe(28.0)
        ->and((float) $target->fresh()->carried_forward_days)->toBe(0.0);
});

test('the engine preserves fresh entitlement instead of replacing it', function () {
    // The property the old command destroyed, now asserted where the work
    // actually happens rather than through a console path that no longer
    // writes.
    [, , $target] = cfcSetup();

    app(LeaveService::class)->carryForwardBalances(2026);

    // 28 fresh + 8 carried, not 8.
    expect((float) $target->fresh()->allocated_days)->toBe(36.0)
        ->and((float) $target->fresh()->carried_forward_days)->toBe(8.0);
});

test('the engine never resets used days', function () {
    // The single worst thing the old implementation did.
    [, , $target] = cfcSetup();

    app(LeaveService::class)->carryForwardBalances(2026);

    expect((float) $target->fresh()->used_days)->toBe(3.0);
});

test('the service method delegates to the correct engine', function () {
    // Anything still calling this directly gets the corrected behaviour.
    [, , $target] = cfcSetup();

    $result = app(LeaveService::class)->carryForwardBalances(2026);

    expect($result['rows'])->toBe(1)
        ->and((float) $target->fresh()->used_days)->toBe(3.0)
        ->and((float) $target->fresh()->allocated_days)->toBe(36.0);
});

test('an empty previous year points at the audit rather than writing', function () {
    LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);

    $this->artisan('hrms:carry-forward-leaves', ['year' => 2026])
        ->expectsOutputToContain('Nothing to carry forward')
        ->assertSuccessful();

    expect(LeaveBalance::count())->toBe(0);
});
