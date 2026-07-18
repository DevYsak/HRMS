<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\AttendanceService;

function regEmployeeOnShift(): Employee
{
    $shift = ShiftSetting::create([
        'name' => 'Reg Shift',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'break_duration' => 60,
        'grace_minutes' => 10,
        'standard_hours' => 8,
        'ot_threshold_hours' => 9,
    ]);

    return Employee::factory()->create(['status' => 'active', 'shift_id' => $shift->id]);
}

test('approving a regularisation preserves the original punch and does not overwrite it', function () {
    $employee = regEmployeeOnShift();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => today()->subDay(),
        'check_in' => today()->subDay()->setTime(9, 5),
        'check_out' => today()->subDay()->setTime(17, 0),   // wrong early out
        'status' => 'on_time',
        'work_mode' => 'office',
        'total_hours' => 7.9,
    ]);

    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'attendance_id' => $attendance->id,
        'work_date' => today()->subDay()->toDateString(),
        'regularisation_type' => 'punch',
        'requested_check_in' => today()->subDay()->toDateString().' 09:05:00',
        'requested_check_out' => today()->subDay()->toDateString().' 18:00:00',
        'reason' => 'Forgot to punch out',
        'status' => 'pending',
        'stage' => 'admin_approval',
    ]);

    app(AttendanceService::class)->approveRegularisation($reg, $admin->id);

    $attendance->refresh();
    // Original preserved immutably…
    expect($attendance->original_check_out->format('H:i'))->toBe('17:00')
        // …corrected value applied…
        ->and($attendance->check_out->format('H:i'))->toBe('18:00')
        ->and($attendance->is_regularized)->toBeTrue();
});

test('a punch corrected to after the grace cutoff is marked late, not forced on-time', function () {
    $employee = regEmployeeOnShift();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => today()->subDay(),
        'check_in' => today()->subDay()->setTime(9, 5),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    // Corrected IN = 10:40, well past 09:00 + 10 min grace → still late.
    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'attendance_id' => $attendance->id,
        'work_date' => today()->subDay()->toDateString(),
        'regularisation_type' => 'punch',
        'requested_check_in' => today()->subDay()->toDateString().' 10:40:00',
        'requested_check_out' => today()->subDay()->toDateString().' 18:00:00',
        'reason' => 'Correcting a wrong device time',
        'status' => 'pending',
        'stage' => 'admin_approval',
    ]);

    app(AttendanceService::class)->approveRegularisation($reg, $admin->id);

    $attendance->refresh();
    expect($attendance->is_late)->toBeTrue()
        ->and($attendance->status)->toBe('late')
        ->and($attendance->late_minutes)->toBe(90);   // 10:40 − 09:10 cutoff
});
