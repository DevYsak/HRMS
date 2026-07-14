<?php

use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Services\OvertimeService;

test('the overtime pay rate is read from attendance settings', function () {
    AttendanceSetting::create([
        'shift_start' => '09:00:00',
        'shift_end' => '18:00:00',
        'late_grace_period' => 15,
        'ot_rate_per_hour' => 150.00,
    ]);

    $employee = Employee::factory()->create();
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => today()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '21:00',
        'requested_hours' => 3,
        'reason' => 'Release deployment',
        'status' => 'approved',
    ]);

    $record = app(OvertimeService::class)->createOvertimeRecordFromApprovedRequest($request);

    expect((float) $record->rate_per_hour)->toBe(150.0)
        ->and((float) $record->ot_amount)->toBe(450.0);
});

test('the overtime rate falls back to the default when no setting exists', function () {
    $employee = Employee::factory()->create();
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => today()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '20:00',
        'requested_hours' => 2,
        'reason' => 'Support cover',
        'status' => 'approved',
    ]);

    $record = app(OvertimeService::class)->createOvertimeRecordFromApprovedRequest($request);

    expect((float) $record->rate_per_hour)->toBe(100.0)
        ->and((float) $record->ot_amount)->toBe(200.0);
});
