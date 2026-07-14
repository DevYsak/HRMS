<?php

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\OvertimeService;

test('autoCreateFromAttendance files a pending OT request for hours over the threshold', function () {
    $employee = Employee::factory()->create(); // no shift → 9h threshold
    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => '2026-06-15',
        'check_in' => '2026-06-15 09:00:00',
        'check_out' => '2026-06-15 21:00:00',
        'total_hours' => 12,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    $ot = app(OvertimeService::class)->autoCreateFromAttendance($attendance);

    expect($ot)->not->toBeNull();
    expect($ot->status)->toBe('pending');
    expect($ot->source)->toBe('regularisation');
    expect($ot->attendance_id)->toBe($attendance->id);
    expect((float) $ot->requested_hours)->toBe(3.0); // 12 - 9
});

test('autoCreateFromAttendance does nothing when hours are within the threshold', function () {
    $employee = Employee::factory()->create();
    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => '2026-06-16',
        'check_in' => '2026-06-16 09:00:00',
        'check_out' => '2026-06-16 17:00:00',
        'total_hours' => 8,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    expect(app(OvertimeService::class)->autoCreateFromAttendance($attendance))->toBeNull();
    expect(OtRequest::count())->toBe(0);
});

test('autoCreateFromAttendance does not duplicate an existing OT request for the day', function () {
    $employee = Employee::factory()->create();
    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => '2026-06-17',
        'check_in' => '2026-06-17 09:00:00',
        'check_out' => '2026-06-17 21:00:00',
        'total_hours' => 12,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);
    OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => '2026-06-17',
        'start_time' => '18:00',
        'end_time' => '20:00',
        'requested_hours' => 2,
        'reason' => 'existing',
        'status' => 'pending',
        'source' => 'manual',
    ]);

    expect(app(OvertimeService::class)->autoCreateFromAttendance($attendance))->toBeNull();
    expect(OtRequest::where('employee_id', $employee->id)->count())->toBe(1);
});

test('approving a regularisation into overtime auto-approves the OT and materialises the record', function () {
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create(['role' => 'super_admin']);
    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => '2026-06-18',
        'check_in' => '2026-06-18 09:00:00',
        'check_out' => null,
        'missing_checkout' => true,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);
    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'attendance_id' => $attendance->id,
        'work_date' => '2026-06-18',
        'requested_check_in' => '2026-06-18 09:00:00',
        'requested_check_out' => '2026-06-18 21:00:00', // 12h → 3h OT
        'reason' => 'Forgot to clock out',
        'status' => 'pending',
    ]);

    app(AttendanceService::class)->approveRegularisation($reg, $reviewer->id);

    $attendance->refresh();
    expect($attendance->missing_checkout)->toBeFalse();
    expect((float) $attendance->total_hours)->toBe(12.0);

    $ot = OtRequest::where('employee_id', $employee->id)->where('work_date', '2026-06-18')->first();
    expect($ot)->not->toBeNull();
    expect($ot->source)->toBe('regularisation');
    expect($ot->status)->toBe('approved');           // auto-approved now
    expect($ot->reviewer_id)->toBe($reviewer->id);   // by the same HR reviewer
    expect((float) $ot->requested_hours)->toBe(3.0);

    // The approved OT materialises an OvertimeRecord for payroll.
    expect(OvertimeRecord::where('ot_request_id', $ot->id)->exists())->toBeTrue();
});

test('approving a regularisation within the threshold files no OT', function () {
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create(['role' => 'super_admin']);
    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'attendance_id' => null,
        'work_date' => '2026-06-19',
        'requested_check_in' => '2026-06-19 09:00:00',
        'requested_check_out' => '2026-06-19 17:00:00', // 8h
        'reason' => 'Missed punch',
        'status' => 'pending',
    ]);

    app(AttendanceService::class)->approveRegularisation($reg, $reviewer->id);

    expect(OtRequest::where('employee_id', $employee->id)->count())->toBe(0);
});
