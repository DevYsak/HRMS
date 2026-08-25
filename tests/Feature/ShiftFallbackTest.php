<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\ShiftResolver;
use App\Services\OvertimeService;
use Illuminate\Support\Carbon;

/**
 * Shift resolution and the business rules that hang off it.
 *
 * The bug behind these: resolve() fell back to ShiftSetting::query()->first()
 * whenever an employee had no shift, so an unassigned UK Sales employee was
 * silently judged against the 10:30 IT window and marked hours late every day.
 * Refusing to guess is the fix; these pin both the refusal and the real shift
 * windows so neither can drift.
 */
function itShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'IT Shift', 'code' => 'IT',
        'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);
}

function ukSalesShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'UK Sales Shift', 'code' => 'UK_SALES',
        'start_time' => '13:00:00', 'end_time' => '22:00:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);
}

function shiftEmployee(?ShiftSetting $shift): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'shift_id' => $shift?->id,
    ]);
}

test('the IT shift resolves to 10:30-19:30 with 5 minutes grace and a 9 hour standard', function () {
    $employee = shiftEmployee(itShift());

    $resolved = app(ShiftResolver::class)->resolve($employee, '2026-08-03');

    expect($resolved)->not->toBeNull()
        ->and($resolved->start->format('H:i'))->toBe('10:30')
        ->and($resolved->end->format('H:i'))->toBe('19:30')
        ->and($resolved->graceMinutes)->toBe(5)
        ->and($resolved->standardHours)->toBe(9.0)
        ->and($resolved->expectedMinutes())->toBe(540);
});

test('the UK Sales shift resolves to 13:00-22:00 with 5 minutes grace and a 9 hour standard', function () {
    $employee = shiftEmployee(ukSalesShift());

    $resolved = app(ShiftResolver::class)->resolve($employee, '2026-08-03');

    expect($resolved->start->format('H:i'))->toBe('13:00')
        ->and($resolved->end->format('H:i'))->toBe('22:00')
        ->and($resolved->graceMinutes)->toBe(5)
        ->and($resolved->standardHours)->toBe(9.0);
});

test('IT grace: 10:30 and 10:35 are on time, 10:36 is late', function () {
    $employee = shiftEmployee(itShift());
    $shift = app(ShiftResolver::class)->resolve($employee, '2026-08-03');

    expect($shift->isLate(Carbon::parse('2026-08-03 10:30:00')))->toBeFalse()
        ->and($shift->isLate(Carbon::parse('2026-08-03 10:35:00')))->toBeFalse()
        ->and($shift->isLate(Carbon::parse('2026-08-03 10:36:00')))->toBeTrue()
        ->and($shift->lateMinutes(Carbon::parse('2026-08-03 10:35:00')))->toBe(0)
        ->and($shift->lateMinutes(Carbon::parse('2026-08-03 10:45:00')))->toBe(10);
});

test('UK Sales grace: 13:00 and 13:05 are on time, 13:06 is late', function () {
    $employee = shiftEmployee(ukSalesShift());
    $shift = app(ShiftResolver::class)->resolve($employee, '2026-08-03');

    expect($shift->isLate(Carbon::parse('2026-08-03 13:00:00')))->toBeFalse()
        ->and($shift->isLate(Carbon::parse('2026-08-03 13:05:00')))->toBeFalse()
        ->and($shift->isLate(Carbon::parse('2026-08-03 13:06:00')))->toBeTrue();
});

test('a UK Sales arrival is NOT judged against the IT window', function () {
    // The exact regression: with IT as the first row in the table, an unassigned
    // or mis-resolved UK Sales employee arriving at 13:00 was 2.5 hours "late".
    itShift();                          // id 1 — the row the old code reached for
    $employee = shiftEmployee(ukSalesShift());

    $shift = app(ShiftResolver::class)->resolve($employee, '2026-08-03');

    expect($shift->name)->toBe('UK Sales Shift')
        ->and($shift->isLate(Carbon::parse('2026-08-03 13:00:00')))->toBeFalse();
});

test('an employee with no shift gets no invented window, even when shifts exist', function () {
    itShift();
    ukSalesShift();
    $employee = shiftEmployee(null);

    expect(app(ShiftResolver::class)->resolve($employee, '2026-08-03'))->toBeNull()
        ->and(ShiftResolver::hasResolvableShift($employee))->toBeFalse();
});

test('an unassigned employee is flagged for HR rather than silently mis-scored', function () {
    itShift();
    $employee = shiftEmployee(null);

    expect($employee->dataFlags())->toContain('Shift Not Assigned');
});

test('a nominated company default covers employees without an assignment', function () {
    // The explicit replacement for the arbitrary first-row fallback: HR states
    // which shift applies, so the behaviour is a policy rather than an accident.
    itShift();
    ukSalesShift()->makeCompanyDefault();

    $employee = shiftEmployee(null);

    $resolved = app(ShiftResolver::class)->resolve($employee, '2026-08-03');

    expect($resolved)->not->toBeNull()
        ->and($resolved->name)->toBe('UK Sales Shift')
        ->and(ShiftResolver::hasResolvableShift($employee))->toBeTrue()
        ->and($employee->dataFlags())->not->toContain('Shift Not Assigned');
});

test('code and is_default are mass-assignable', function () {
    // They were not, so create()/update() dropped them silently and the
    // nominated default never took effect.
    $shift = ShiftSetting::create([
        'name' => 'Fillable Check', 'code' => 'CHECK', 'is_default' => true,
        'start_time' => '09:00:00', 'end_time' => '18:00:00',
    ]);

    expect($shift->fresh()->code)->toBe('CHECK')
        ->and($shift->fresh()->is_default)->toBeTrue();
});

test('only one shift can be the company default', function () {
    $it = itShift();
    $it->makeCompanyDefault();
    ukSalesShift()->makeCompanyDefault();

    expect(ShiftSetting::where('is_default', true)->count())->toBe(1)
        ->and($it->fresh()->is_default)->toBeFalse();
});

test('an assigned shift always wins over the company default', function () {
    ukSalesShift()->makeCompanyDefault();

    $employee = shiftEmployee(itShift());

    expect(app(ShiftResolver::class)->resolve($employee, '2026-08-03')->name)->toBe('IT Shift');
});

test('the global 09:00-18:00 attendance setting can no longer produce a shift window', function () {
    // It exists for grace and auto-checkout buffers, but its shift_start /
    // shift_end match no shift the company runs and must never be scored on.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create([
        'shift_start' => '09:00:00', 'shift_end' => '18:00:00', 'late_grace_period' => 15,
    ]);

    $employee = shiftEmployee(null);

    expect(app(ShiftResolver::class)->resolve($employee, '2026-08-03'))->toBeNull();
});

test('OT threshold follows the 9 hour standard', function () {
    $employee = shiftEmployee(itShift());
    $shift = app(ShiftResolver::class)->resolve($employee, '2026-08-03');

    // 9h standard: a 10-hour day is one hour over, and OT is only ever payable
    // through an approved request (asserted in the overtime suite).
    expect($shift->otThresholdHours)->toBe(9.0)
        ->and(OvertimeService::RATE_PER_HOUR)->toBe(100.0);
});

test('an overnight shift still ends on the following day', function () {
    $night = ShiftSetting::create([
        'name' => 'Night', 'code' => 'NIGHT',
        'start_time' => '22:00:00', 'end_time' => '06:00:00',
        'grace_minutes' => 5, 'standard_hours' => 8, 'ot_threshold_hours' => 8, 'break_duration' => 60,
    ]);

    $shift = app(ShiftResolver::class)->resolve(shiftEmployee($night), '2026-08-03');

    expect($shift->start->toDateString())->toBe('2026-08-03')
        ->and($shift->end->toDateString())->toBe('2026-08-04')
        ->and($shift->expectedMinutes())->toBe(480);
});

test('the employee sees a shift-not-assigned notice instead of a wrong window', function () {
    itShift();
    $employee = shiftEmployee(null);

    Livewire\Livewire::actingAs($employee->user)
        ->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSet('shiftLabel', null)
        ->assertSee('Shift not assigned')
        // ...and specifically not a working day they do not work.
        ->assertDontSee('9:00 AM – 6:00 PM');
});

test('an employee on a real shift sees that window, with no notice', function () {
    $employee = shiftEmployee(ukSalesShift());

    Livewire\Livewire::actingAs($employee->user)
        ->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('1:00 PM – 10:00 PM')
        ->assertDontSee('Shift not assigned');
});

test('the attendance score engine declines to score a day it cannot judge', function () {
    // Refusing is the point: no shift means no late mark, no early-exit
    // penalty, and no confidently wrong score.
    itShift();
    $employee = shiftEmployee(null);
    $date = '2026-08-03';

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => "{$date} 12:00:00",
        'check_out' => "{$date} 20:00:00",
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    $explained = app(AttendanceScoreEngine::class)->explainDay($employee, Carbon::parse($date));

    expect($explained['shift'])->toBeNull();
});
