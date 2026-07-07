<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\PunchClassifier;
use App\Services\AttendanceService;

function pendingRegularisation(Employee $employee, array $overrides = []): AttendanceRegularisation
{
    return AttendanceRegularisation::create(array_merge([
        'employee_id' => $employee->id,
        'work_date' => '2026-07-06',
        'requested_check_in' => '2026-07-06 10:30:00',
        'requested_check_out' => '2026-07-06 19:30:00',
        'check_in_method' => 'face',
        'check_out_method' => 'id_card',
        'reason' => 'Forgot to punch — was on a client call.',
        'status' => 'pending',
    ], $overrides));
}

test('approving a regularisation writes the corrected punches into the journey with the chosen method', function () {
    $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id, 'employee_code' => 55, 'status' => 'active']);
    $reviewer = User::factory()->create(['role' => 'hr_admin']);

    $attendance = app(AttendanceService::class)->approveRegularisation(pendingRegularisation($employee), $reviewer->id);

    // Summary times + methods corrected.
    expect($attendance->check_in->format('H:i'))->toBe('10:30')
        ->and($attendance->check_out->format('H:i'))->toBe('19:30')
        ->and($attendance->check_in_method)->toBe('face')
        ->and($attendance->check_out_method)->toBe('id_card');

    // Journey punches written with the declared method + regularisation source.
    $punches = AttendancePunch::where('employee_id', $employee->id)->orderBy('punched_at')->get();
    expect($punches)->toHaveCount(2)
        ->and($punches->first()->method)->toBe('face')
        ->and($punches->first()->source)->toBe('regularisation')
        ->and($punches->last()->method)->toBe('id_card');

    // The shared classifier renders a clean in → out journey from them — the
    // same view the employee's Attendance Journey and the admin panel use.
    $events = collect(app(PunchClassifier::class)->classify($punches));
    expect($events)->toHaveCount(2)
        ->and($events->first()['type'])->toBe('in')
        ->and($events->last()['type'])->toBe('out');
});

test('re-approving the same regularisation does not duplicate journey punches', function () {
    $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id, 'employee_code' => 56, 'status' => 'active']);
    $reviewer = User::factory()->create(['role' => 'hr_admin']);
    $reg = pendingRegularisation($employee);

    app(AttendanceService::class)->approveRegularisation($reg, $reviewer->id);
    app(AttendanceService::class)->approveRegularisation($reg->fresh(), $reviewer->id);

    expect(AttendancePunch::where('employee_id', $employee->id)->count())->toBe(2);
});
