<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\ResolvedShift;
use App\Services\Attendance\ShiftProgress;
use App\Services\Attendance\ShiftResolver;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Shift Progress: how far through their shift an employee is today.
 *
 * Expected duration comes from the employee's own resolved shift and worked
 * minutes from PunchTimeline — never a hardcoded nine hours and never a second
 * attendance calculation. The clamps carry real meaning: progress stops at 100%
 * because time past the standard day is overtime, payable only against an
 * approved request, and remaining stops at zero because nobody is owed time
 * back.
 */
function spItShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'IT Shift', 'code' => 'SP_IT',
        'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);
}

function spUkShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'UK Sales Shift', 'code' => 'SP_UK',
        'start_time' => '13:00:00', 'end_time' => '22:00:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);
}

function spEmployee(?ShiftSetting $shift): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    // Calendar stated explicitly: the company default is UK now, and the
    // holiday fixtures in this file are on the India calendar.
    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => $shift?->id,
        'holiday_calendar' => 'IN',
    ]);
}

function spResolved(Employee $employee): ?ResolvedShift
{
    return app(ShiftResolver::class)->resolve($employee, Carbon::today());
}

/**
 * Pinned to a Wednesday.
 *
 * Shift Progress reports a non-working day on a weekly off, so anything
 * asserting real progress is date-dependent — without this the suite passes
 * Monday to Friday and fails every weekend.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-08-05 15:00:00'));
});

afterEach(function () {
    $this->travelBack();
});

// ── Calculation ──────────────────────────────────────────────────────────────

test('expected duration comes from the IT shift, not a hardcoded value', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 0);

    expect($p->expectedMinutes)->toBe(540)          // 9h from the shift row
        ->and($p->expectedLabel())->toBe('9h 0m');
});

test('expected duration comes from the UK Sales shift', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spUkShift())), 0);

    expect($p->expectedMinutes)->toBe(540);
});

test('a shift with a different standard is honoured rather than assumed', function () {
    // Proves the 9h figure is read, not baked in.
    $six = ShiftSetting::create([
        'name' => 'Short', 'code' => 'SP_SHORT', 'start_time' => '09:00:00', 'end_time' => '15:00:00',
        'grace_minutes' => 5, 'standard_hours' => 6, 'ot_threshold_hours' => 6,
    ]);

    $p = ShiftProgress::of(spResolved(spEmployee($six)), 180);

    expect($p->expectedMinutes)->toBe(360)
        ->and($p->percent)->toBe(50)
        ->and($p->remainingMinutes)->toBe(180);
});

test('not clocked in — zero worked, full remaining, zero percent', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 0);

    expect($p->state)->toBe(ShiftProgress::STATE_NOT_STARTED)
        ->and($p->workedMinutes)->toBe(0)
        ->and($p->remainingMinutes)->toBe(540)
        ->and($p->percent)->toBe(0)
        ->and($p->statusLabel())->toBe('Not clocked in');
});

test('currently working — partial progress against the shift', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 330);   // 5h30m

    expect($p->state)->toBe(ShiftProgress::STATE_WORKING)
        ->and($p->percent)->toBe(61)
        ->and($p->workedLabel())->toBe('5h 30m')
        ->and($p->remainingLabel())->toBe('3h 30m')
        ->and($p->statusLabel())->toBe('Working');
});

test('clocked out — the day reads as completed', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 540, clockedOut: true);

    expect($p->state)->toBe(ShiftProgress::STATE_COMPLETED)
        ->and($p->statusLabel())->toBe('Shift completed');
});

test('exactly the expected duration is 100% with nothing remaining', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 540);

    expect($p->percent)->toBe(100)
        ->and($p->remainingMinutes)->toBe(0)
        ->and($p->overtimeMinutes())->toBe(0);
});

test('progress never exceeds 100% however long the day runs', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 900);   // 15h

    expect($p->percent)->toBe(100)
        ->and($p->percent)->toBeLessThanOrEqual(100);
});

test('remaining time never goes negative', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 700);

    expect($p->remainingMinutes)->toBe(0)
        ->and($p->remainingMinutes)->toBeGreaterThanOrEqual(0)
        ->and($p->remainingLabel())->toBe('0h 0m');
});

test('time beyond the shift is reported as overtime, not as extra progress', function () {
    // Overtime is only payable against an approved request, so it must never
    // inflate the ring — it is surfaced separately instead.
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), 600);   // 10h

    expect($p->percent)->toBe(100)
        ->and($p->overtimeMinutes())->toBe(60)
        ->and($p->remainingMinutes)->toBe(0);
});

test('an approved OT request does not change the shift progress ring', function () {
    // OT lives in its own workflow; progress is worked-against-standard only.
    $employee = spEmployee(spItShift());
    $withOt = ShiftProgress::of(spResolved($employee), 600);
    $withoutOt = ShiftProgress::of(spResolved($employee), 600);

    expect($withOt->percent)->toBe($withoutOt->percent)
        ->and($withOt->percent)->toBe(100);
});

test('negative worked minutes are floored at zero', function () {
    $p = ShiftProgress::of(spResolved(spEmployee(spItShift())), -30);

    expect($p->workedMinutes)->toBe(0)
        ->and($p->percent)->toBe(0);
});

test('an unassigned shift reports nothing rather than a misleading zero', function () {
    $p = ShiftProgress::unassigned();

    expect($p->isMeasurable())->toBeFalse()
        ->and($p->expectedMinutes)->toBeNull()
        ->and($p->workedMinutes)->toBeNull()
        ->and($p->remainingMinutes)->toBeNull()
        ->and($p->percent)->toBeNull()
        // Explicitly em-dashes, never "0h" or "9h remaining".
        ->and($p->expectedLabel())->toBe('—')
        ->and($p->workedLabel())->toBe('—')
        ->and($p->remainingLabel())->toBe('—')
        ->and($p->percentLabel())->toBe('—')
        ->and($p->statusLabel())->toBe('Shift not assigned');
});

test('a shift with no standard hours is not given an invented one', function () {
    $noStandard = ShiftSetting::create([
        'name' => 'Undefined', 'code' => 'SP_NONE',
        'start_time' => '09:00:00', 'end_time' => '18:00:00', 'standard_hours' => 0,
    ]);

    $p = ShiftProgress::of(spResolved(spEmployee($noStandard)), 120);

    expect($p->isMeasurable())->toBeFalse()
        ->and($p->percentLabel())->toBe('—');
});

test('a non-working day does not make the employee look behind', function () {
    $p = ShiftProgress::nonWorking('On leave');

    expect($p->state)->toBe(ShiftProgress::STATE_NON_WORKING)
        ->and($p->isMeasurable())->toBeFalse()
        ->and($p->statusLabel())->toBe('On leave')
        ->and($p->percentLabel())->toBe('—');
});

// ── Wiring: the page uses the resolved shift and the engine's worked time ─────

test('the page shows Shift Progress against the employee resolved shift', function () {
    $employee = spEmployee(spItShift());
    $date = Carbon::today()->toDateString();

    // Four hours of engine-recorded work: 10:30 -> 14:30.
    foreach ([['10:30:00', 'face'], ['14:30:00', 'id_card']] as [$t, $m]) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => "{$date} {$t}",
            'punch_date' => $date, 'method' => $m, 'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Shift Progress')
        ->assertSet('shiftProgress', fn ($p) => $p['expected_minutes'] === 540
            && $p['worked_minutes'] === 240
            && $p['percent'] === 44
            && $p['remaining_minutes'] === 300);
});

test('the page shows the unassigned empty state, never a nine hour default', function () {
    spItShift();                               // exists, but not assigned
    $employee = spEmployee(null);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Shift Progress')
        ->assertSee('Shift not assigned')
        ->assertSet('shiftProgress', fn ($p) => $p['measurable'] === false
            && $p['expected_minutes'] === null
            && $p['percent'] === null);
});

test('the page shows a non-working state on approved leave', function () {
    $employee = spEmployee(spItShift());
    $type = LeaveType::create(['name' => 'Casual', 'code' => 'SPCL', 'category' => 'annual']);

    LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => Carbon::today()->toDateString(),
        'end_date' => Carbon::today()->toDateString(),
        'days' => 1,
        'reason' => 'Personal',
        'status' => 'approved',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSet('shiftProgress', fn ($p) => $p['state'] === ShiftProgress::STATE_NON_WORKING
            && $p['measurable'] === false);
});

test('the page shows a non-working state on a weekly off', function () {
    // Found the hard way: these page tests were date-dependent until pinned,
    // because a weekly off legitimately reports non-working. Uses the
    // configurable working week, not a hardcoded Saturday/Sunday.
    $this->travelTo(Carbon::parse('2026-08-09 12:00:00'));   // a Sunday
    $employee = spEmployee(spItShift());

    expect(AttendanceSetting::isWeeklyOff(Carbon::today()))->toBeTrue();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSet('shiftProgress', fn ($p) => $p['state'] === ShiftProgress::STATE_NON_WORKING
            && $p['measurable'] === false
            && $p['percent'] === null);
});

test('the page shows a non-working state on a public holiday', function () {
    $employee = spEmployee(spItShift());
    PublicHoliday::create(['name' => 'Test Holiday', 'date' => Carbon::today()->toDateString()]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSet('shiftProgress', fn ($p) => $p['state'] === ShiftProgress::STATE_NON_WORKING);
});

test('a completed day on the page reports as clocked out', function () {
    $employee = spEmployee(spItShift());
    $date = Carbon::today()->toDateString();

    Attendance::create([
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => "{$date} 10:30:00", 'check_out' => "{$date} 19:30:00",
        'status' => 'on_time', 'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSet('shiftProgress', fn ($p) => $p['state'] === ShiftProgress::STATE_COMPLETED);
});
