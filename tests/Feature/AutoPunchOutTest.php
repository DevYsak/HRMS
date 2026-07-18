<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\ShiftSetting;
use Illuminate\Support\Carbon;

/** A 09:00–18:00 shift assigned to a fresh employee. */
function shiftDay(): array
{
    $shift = ShiftSetting::create([
        'name' => 'Test Day Shift',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'break_duration' => 60,
        'grace_minutes' => 10,
        'standard_hours' => 8,
        'ot_threshold_hours' => 9,
    ]);
    $employee = Employee::factory()->create(['status' => 'active', 'shift_id' => $shift->id]);

    return [$shift, $employee];
}

test('auto punch-out closes an open day AT shift-end once past the buffer', function () {
    [, $employee] = shiftDay();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => today(),
        'check_in' => today()->setTime(9, 5),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    // Now = 18:40 → past 18:00 end + 30 min buffer.
    Carbon::setTestNow(today()->setTime(18, 40));

    $this->artisan('hrms:auto-punch-out')->assertSuccessful();

    $attendance->refresh();
    expect($attendance->check_out->format('H:i'))->toBe('18:00')   // stamped at shift-end, not 18:40
        ->and($attendance->is_auto_checkout)->toBeTrue()
        ->and($attendance->auto_checkout_reason)->toBe('missing_punchout')
        ->and($attendance->missing_checkout)->toBeTrue();

    Carbon::setTestNow();
});

test('auto punch-out does NOT close before the buffer elapses', function () {
    [, $employee] = shiftDay();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => today(),
        'check_in' => today()->setTime(9, 5),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    // Now = 18:20 → only 20 min past end, under the 30 min buffer.
    Carbon::setTestNow(today()->setTime(18, 20));

    $this->artisan('hrms:auto-punch-out')->assertSuccessful();

    expect($attendance->fresh()->check_out)->toBeNull();

    Carbon::setTestNow();
});

test('an approved OT day is held open past shift-end and closed at the OT close time', function () {
    [, $employee] = shiftDay();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => today(),
        'check_in' => today()->setTime(9, 5),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);
    OtRequest::create([
        'employee_id' => $employee->id,
        'attendance_id' => $attendance->id,
        'work_date' => today()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '21:00',
        'requested_hours' => 3,
        'reason' => 'Release',
        'status' => 'approved',
    ]);

    // 19:00 — well past shift-end + buffer, but an approved OT holds it open.
    Carbon::setTestNow(today()->setTime(19, 0));
    $this->artisan('hrms:auto-punch-out')->assertSuccessful();
    expect($attendance->fresh()->check_out)->toBeNull();

    // 23:59 — the OT close boundary; now it closes with the OT reason.
    Carbon::setTestNow(today()->setTime(23, 59, 30));
    $this->artisan('hrms:auto-punch-out')->assertSuccessful();

    $attendance->refresh();
    expect($attendance->check_out)->not->toBeNull()
        ->and($attendance->auto_checkout_reason)->toBe('ot_auto_close')
        ->and($attendance->is_auto_checkout)->toBeTrue();

    Carbon::setTestNow();
});
