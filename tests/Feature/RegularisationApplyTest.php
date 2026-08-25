<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\BreakLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\AttendanceService;
use Livewire\Livewire;

/**
 * Regularisation actually correcting the day.
 *
 * The bugs behind these: a night-shift correction booked zero hours, and the
 * break was taken from the pre-correction row, so a regularised absent day
 * recorded a full shift with no lunch deducted. Both are unchanged.
 *
 * BUSINESS RULE CHANGE — how HR applies a correction.
 *
 *   OLD  HR was made the final approver, so approveRegularisation() applied
 *        the change immediately when called by HR. That silently redefined
 *        what "approve" meant for every reviewer and made admin_approval
 *        unreachable for new requests.
 *
 *   NEW  The staged chain is intact — Manager → HR → Admin → Apply — and HR
 *        approving still only clears hr_review. Applying immediately is now
 *        fastTrackRegularisation(): a separate action, separately authorised
 *        (manage_attendance, which managers do not hold), and separately
 *        audited via applied_via = 'hr_fast_path'.
 *
 * These tests therefore call fastTrackRegularisation() where they previously
 * called approveRegularisation() as HR. What is being asserted — the corrected
 * hours, the break arithmetic, the original-value snapshot — is unchanged;
 * only the route HR takes to apply it has. See RegularisationFastPathTest for
 * the workflow itself and RegularisationWorkflowTest for the untouched chain.
 */
function regApplyShift(string $start = '09:00:00', string $end = '18:00:00', int $break = 60): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'Reg Test Shift',
        'start_time' => $start,
        'end_time' => $end,
        'break_duration' => $break,
    ]);
}

function regApplyEmployee(?ShiftSetting $shift = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'shift_id' => ($shift ?? regApplyShift())->id,
    ]);
}

function regApplyRequest(Employee $employee, string $date, string $in, string $out): AttendanceRegularisation
{
    return AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'requested_check_in' => "{$date} {$in}:00",
        'requested_check_out' => "{$date} {$out}:00",
        'reason' => 'Forgot to punch',
        'status' => 'pending',
    ]);
}

test('HR fast-tracking applies the correction instead of parking it', function () {
    // Renamed: under the old rule an HR *approval* applied the change. Now
    // approving clears hr_review like any other stage, and this is what HR
    // does when the day must be corrected now.
    $employee = regApplyEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $date = now()->subDay()->toDateString();

    $request = regApplyRequest($employee, $date, '09:00', '18:00');

    $attendance = app(AttendanceService::class)->fastTrackRegularisation($request, $hr->id);

    expect($attendance)->not->toBeNull()
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->applied_via)->toBe('hr_fast_path')
        ->and(Attendance::where('employee_id', $employee->id)->where('date', $date)->exists())->toBeTrue();
});

test('an HR approval alone does NOT apply the correction', function () {
    // The other half of the rule change, asserted directly: the chain is real.
    $employee = regApplyEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $date = now()->subDay()->toDateString();

    $request = regApplyRequest($employee, $date, '09:00', '18:00');

    expect(app(AttendanceService::class)->approveRegularisation($request, $hr->id))->toBeNull()
        ->and($request->fresh()->stage)->toBe('admin_approval')
        ->and($request->fresh()->status)->toBe('pending')
        ->and(Attendance::where('employee_id', $employee->id)->where('date', $date)->exists())->toBeFalse();
});

test('a manager approval still advances rather than finalising', function () {
    $employee = regApplyEmployee();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $date = now()->subDay()->toDateString();

    $request = regApplyRequest($employee, $date, '09:00', '18:00');

    $result = app(AttendanceService::class)->approveRegularisation($request, $manager->id);

    expect($result)->toBeNull()
        ->and($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->stage)->toBe('hr_review');
});

test('the approval trail records who applied it, with their name', function () {
    $employee = regApplyEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Approving Officer']);
    $date = now()->subDay()->toDateString();

    $request = regApplyRequest($employee, $date, '09:00', '18:00');
    app(AttendanceService::class)->fastTrackRegularisation($request, $hr->id, 'Checked the door log');

    $trail = $request->fresh()->approval_trail;

    expect($trail)->toHaveCount(1)
        // 'fast_tracked', not 'approved': the shortcut is named rather than
        // disguised as a chain the request never climbed.
        ->and($trail[0]['action'])->toBe('fast_tracked')
        ->and($trail[0]['name'])->toBe('Approving Officer')
        ->and($trail[0]['comment'])->toBe('Checked the door log')
        ->and($trail[0]['at'])->not->toBeEmpty();
});

test('a night shift correction records the hours actually worked', function () {
    // 22:00 -> 06:00 crosses midnight. Both columns are TIME values anchored
    // to the work date, so this used to span minus sixteen hours and clamp to
    // zero — a whole night shift booked as no work at all.
    $employee = regApplyEmployee(regApplyShift('22:00:00', '06:00:00', 60));
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $date = now()->subDay()->toDateString();

    $request = regApplyRequest($employee, $date, '22:00', '06:00');
    $attendance = app(AttendanceService::class)->fastTrackRegularisation($request, $hr->id);

    // 8h gross, 60m shift break -> 7h net.
    expect((float) $attendance->total_hours)->toBe(7.0);
});

test('a regularised absent day still has the shift break deducted', function () {
    // A fully-absent day carries no break logs, so the pre-correction row said
    // zero break and the whole span was booked as worked.
    $employee = regApplyEmployee(regApplyShift('09:00:00', '18:00:00', 60));
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $date = now()->subDay()->toDateString();

    expect(Attendance::where('employee_id', $employee->id)->where('date', $date)->exists())->toBeFalse();

    $request = regApplyRequest($employee, $date, '09:00', '18:00');
    $attendance = app(AttendanceService::class)->fastTrackRegularisation($request, $hr->id);

    expect((int) $attendance->break_minutes)->toBe(60)
        ->and((float) $attendance->total_hours)->toBe(8.0);
});

test('real logged breaks beat the shift default', function () {
    $employee = regApplyEmployee(regApplyShift('09:00:00', '18:00:00', 60));
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $date = now()->subDay()->toDateString();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => "{$date} 09:00:00",
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    // A 30-minute break actually taken inside the corrected window.
    BreakLog::create([
        'attendance_id' => $attendance->id,
        'employee_id' => $employee->id,
        'break_start' => "{$date} 13:00:00",
        'break_end' => "{$date} 13:30:00",
        'duration_minutes' => 30,
    ]);

    $request = regApplyRequest($employee, $date, '09:00', '18:00');
    $request->update(['attendance_id' => $attendance->id]);

    $corrected = app(AttendanceService::class)->fastTrackRegularisation($request, $hr->id);

    expect((int) $corrected->break_minutes)->toBe(30)
        ->and((float) $corrected->total_hours)->toBe(8.5);
});

test('the break never exceeds the corrected span', function () {
    // A correction shorter than the recorded break must not go negative.
    $employee = regApplyEmployee(regApplyShift('09:00:00', '18:00:00', 120));
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $date = now()->subDay()->toDateString();

    $request = regApplyRequest($employee, $date, '09:00', '10:00');
    $attendance = app(AttendanceService::class)->fastTrackRegularisation($request, $hr->id);

    expect((float) $attendance->total_hours)->toBeGreaterThanOrEqual(0.0)
        ->and((int) $attendance->break_minutes)->toBeLessThanOrEqual(60);
});

test('HR marking attendance applies immediately and names HR in the trail', function () {
    $employee = regApplyEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'HR Officer']);
    $date = now()->subDay()->toDateString();

    Livewire::actingAs($hr)
        ->test(AllAttendance::class)
        ->set('markEmployeeId', $employee->id)
        ->set('markDate', $date)
        ->set('markCheckIn', '09:00')
        ->set('markCheckOut', '18:00')
        ->set('markReason', 'Device was offline all morning')
        ->call('submitMarkAttendance');

    $request = AttendanceRegularisation::where('employee_id', $employee->id)->latest('id')->first();

    expect($request->status)->toBe('approved')
        ->and($request->approval_trail[0]['name'])->toBe('HR Officer');

    $attendance = Attendance::where('employee_id', $employee->id)->where('date', $date)->first();
    expect($attendance)->not->toBeNull()
        ->and($attendance->is_regularized)->toBeTrue();
});

test('the original punch is snapshotted before the first correction', function () {
    $employee = regApplyEmployee();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $date = now()->subDay()->toDateString();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => "{$date} 10:45:00",
        'check_out' => "{$date} 18:00:00",
        'status' => 'late',
        'work_mode' => 'office',
    ]);

    $request = regApplyRequest($employee, $date, '09:00', '18:00');
    $request->update(['attendance_id' => $attendance->id]);

    app(AttendanceService::class)->fastTrackRegularisation($request, $hr->id);

    // The raw punch is never lost, only superseded.
    expect($attendance->fresh()->original_check_in->format('H:i'))->toBe('10:45');
});
