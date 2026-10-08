<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\AttendanceStatusResolver as Status;
use App\Services\Attendance\PunchTimeline;
use App\Services\Attendance\RegularisationManager;
use App\Services\Attendance\ShiftResolver;
use App\Services\AttendanceService;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\EngineAttendanceSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * A regularisation corrects one IN / OUT boundary. It must not freeze the day:
 * genuine punches around it — a later Face IN / Card OUT pair, several
 * sessions — still belong to the work date. The day is always its whole
 * canonical timeline (PunchTimeline → AttendanceCalculator →
 * AttendanceStatusResolver, written by AttendanceDayRebuilder), worked =
 * final valid OUT − first valid IN, and raw punches and the audit trail are
 * never lost.
 *
 * Day shift 09:00–18:00, grace 10 (cutoff 19:00). Work date: Tuesday 13 October
 * 2026. Default clock: Wednesday 14 October, 10:00.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $this->shift = ShiftSetting::create([
        'name' => 'Timeline Shift', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin]);
});

const RTL_DATE = '2026-10-13';

function rtlEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => test()->shift->id,
        'employee_code' => fake()->unique()->numberBetween(1000, 9999), 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

/** A raw device punch, stored as the device sent it. */
function rtlDevice(Employee $employee, string $time, string $method, ?string $direction = null): AttendancePunch
{
    return AttendancePunch::factory()->create([
        'employee_id' => $employee->id, 'punched_at' => RTL_DATE.' '.$time, 'punch_date' => RTL_DATE,
        'method' => $method, 'direction' => $direction ?? ($method === 'face' ? 'in' : 'out'), 'source' => 'biometric',
    ]);
}

/** The device sync's own step: rebuild the work date from the punches HRMS holds. */
function rtlSync(Employee $employee): void
{
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse(RTL_DATE));
}

function rtlApprove(Employee $employee, string $in, string $out): AttendanceRegularisation
{
    $request = AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'work_date' => RTL_DATE, 'regularisation_type' => 'punch',
        'requested_check_in' => RTL_DATE." {$in}:00", 'requested_check_out' => RTL_DATE." {$out}:00",
        'reason' => 'Forgot to punch at the gate', 'status' => 'pending', 'stage' => 'hr_review',
        'attendance_id' => rtlRow($employee)?->id,
    ]);
    app(AttendanceService::class)->approveRegularisation($request, test()->hr->id);

    return $request->fresh();
}

function rtlRow(Employee $employee): ?Attendance
{
    return Attendance::where('employee_id', $employee->id)->whereDate('date', RTL_DATE)->first();
}

/** @return array<string, mixed> */
function rtlTimeline(Employee $employee): array
{
    $punches = AttendancePunch::where('employee_id', $employee->id)->whereDate('punch_date', RTL_DATE)->orderBy('punched_at')->get();
    $shift = app(ShiftResolver::class)->resolve($employee, RTL_DATE);

    return app(PunchTimeline::class)->process($punches, Carbon::parse(RTL_DATE), null, $shift);
}

/** @return array<int, string> */
function rtlDeviceTimes(Employee $employee): array
{
    return AttendancePunch::where('employee_id', $employee->id)->where('source', '!=', 'regularisation')
        ->orderBy('punched_at')->get()->map(fn (AttendancePunch $p) => $p->punched_at->format('H:i:s'))->all();
}

/**
 * The reported shape: morning corrected 09:00–12:00 at lunchtime, then the
 * employee keeps working — Face IN 13:00, Card OUT 14:00, Face IN 14:30,
 * Card OUT 18:15 — synced after the approval.
 */
function rtlMorningCorrectedThenTwoSessions(Employee $employee): AttendanceRegularisation
{
    test()->travelTo(Carbon::parse(RTL_DATE.' 12:30:00'));
    $request = rtlApprove($employee, '09:00', '12:00');

    test()->travelTo(Carbon::parse(RTL_DATE.' 18:30:00'));
    rtlDevice($employee, '13:00:00', 'face');
    rtlDevice($employee, '14:00:00', 'id_card');
    rtlDevice($employee, '14:30:00', 'face');
    rtlDevice($employee, '18:15:00', 'id_card');
    rtlSync($employee);

    test()->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    return $request;
}

// ── 1. Regularised first IN + later real OUT ────────────────────────────────

test('a regularised first IN makes the later real Card OUT and session count', function () {
    $e = rtlEmployee();
    // Forgot the morning Face IN: the 13:00 Card OUT was a stray tap until now.
    rtlDevice($e, '13:00:00', 'id_card');
    rtlDevice($e, '14:00:00', 'face');
    rtlDevice($e, '18:30:00', 'id_card');
    rtlSync($e);
    expect(rtlRow($e)->check_in->format('H:i'))->toBe('14:00');

    // The form keeps the untouched check-out at its recorded 18:30.
    rtlApprove($e, '09:00', '18:30');

    $row = rtlRow($e);
    $t = rtlTimeline($e);

    expect($row->check_in->format('H:i'))->toBe('09:00')
        ->and($row->check_out->format('H:i'))->toBe('18:30')
        ->and((float) $row->total_hours)->toBe(9.5)            // 09:00 → 18:30, break not deducted
        ->and($row->break_minutes)->toBe(60)                    // 13:00 → 14:00, informational
        ->and($row->is_late)->toBeFalse()
        ->and($row->status)->toBe('on_time')
        ->and($row->is_regularized)->toBeTrue()
        ->and($row->original_check_in->format('H:i'))->toBe('14:00')
        ->and(rtlDeviceTimes($e))->toBe(['13:00:00', '14:00:00', '18:30:00'])
        ->and(collect($t['raw_events'])->firstWhere('time', '01:00:00 PM')['flag'])->toBe('kept')
        ->and($t['sessions'])->toHaveCount(2)
        ->and($t['needs_regularization'])->toBeFalse();
});

// ── 2. Regularised OUT + later Face IN / Card OUT pair ──────────────────────

test('a regularised OUT keeps the Face IN / Card OUT pair synced from the device afterwards', function () {
    $e = rtlEmployee();
    $device = BiometricDevice::create(['name' => 'AIFACE', 'ip_address' => '10.0.0.1', 'port' => 4370, 'timeout_seconds' => 5]);
    $log = fn (string $at, string $state, int $verify) => BiometricLog::create([
        'device_id' => $device->id, 'device_user_id' => (string) $e->employee_code, 'employee_id' => $e->id,
        'punched_at' => RTL_DATE.' '.$at, 'punch_type' => $state, 'verify_type' => $verify, 'is_processed' => false,
    ]);

    $this->travelTo(Carbon::parse(RTL_DATE.' 09:10:00'));
    $log('09:05:00', 'check_in', 15);
    app(BiometricSyncService::class)->applyPendingLogs($device);

    // Forgot the Card OUT at lunch; HR fixes it at 13:30.
    $this->travelTo(Carbon::parse(RTL_DATE.' 13:30:00'));
    rtlApprove($e, '09:05', '13:00');
    expect(rtlRow($e)->check_out->format('H:i'))->toBe('13:00');

    // Back from lunch: the real punches arrive through the device sync.
    $this->travelTo(Carbon::parse(RTL_DATE.' 18:40:00'));
    $log('14:00:00', 'check_in', 15);
    $log('18:30:00', 'check_out', 4);
    app(BiometricSyncService::class)->applyPendingLogs($device);

    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $row = rtlRow($e);
    $status = app(Status::class)->forAttendance($row);

    expect($row->check_in->format('H:i'))->toBe('09:05')
        ->and($row->check_out->format('H:i'))->toBe('18:30')
        ->and((float) $row->total_hours)->toBe(9.42)
        ->and($row->break_minutes)->toBe(60)
        ->and($row->is_regularized)->toBeTrue()
        ->and($status['state'])->toBe(Status::COMPLETED)
        ->and($status['worked_minutes'])->toBe(565)
        ->and($status['day']->regularised)->toBeTrue();
});

// ── 3. Multiple later sessions ──────────────────────────────────────────────

test('every later session counts after a regularised morning', function () {
    $e = rtlEmployee();
    rtlMorningCorrectedThenTwoSessions($e);

    $row = rtlRow($e);
    $t = rtlTimeline($e);
    $status = app(Status::class)->forAttendance($row);
    $nodes = collect($t['nodes']);

    expect($row->check_in->format('H:i'))->toBe('09:00')
        ->and($row->check_out->format('H:i'))->toBe('18:15')
        ->and((float) $row->total_hours)->toBe(9.25)
        ->and($row->break_minutes)->toBe(90)                     // 12:00→13:00 + 14:00→14:30
        ->and($status['state'])->toBe(Status::COMPLETED)
        ->and($status['worked_minutes'])->toBe(555)
        ->and($t['sessions'])->toHaveCount(3)
        ->and($t['needs_regularization'])->toBeFalse()
        // The timeline shows the correction and every real punch after it.
        ->and($nodes->pluck('source')->all())->toBe(['regularisation', 'regularisation', 'biometric', 'biometric', 'biometric', 'biometric'])
        ->and($nodes->first()['type'])->toBe('first_in')
        ->and($nodes->last()['type'])->toBe('last_out')
        ->and($t['raw_events'])->toHaveCount(6);
});

// ── 4. Approved edit ────────────────────────────────────────────────────────

test('correcting an approved regularisation rebuilds the whole day around the new boundary', function () {
    $e = rtlEmployee();
    $request = rtlMorningCorrectedThenTwoSessions($e);

    app(RegularisationManager::class)->update($request, $this->hr, ['check_in' => '08:45', 'check_out' => '12:00'], 'Gate log shows 08:45', confirmed: true);

    $row = rtlRow($e);
    $corrections = AttendancePunch::where('employee_id', $e->id)->where('source', 'regularisation')->orderBy('punched_at')->get();
    $audit = AuditLog::where('event', 'ATTENDANCE_REGULARISATION_CORRECTED')->latest('id')->first();

    expect($row->check_in->format('H:i'))->toBe('08:45')
        ->and($row->check_out->format('H:i'))->toBe('18:15')
        ->and((float) $row->total_hours)->toBe(9.5)
        ->and($row->is_regularized)->toBeTrue()
        ->and($corrections->map(fn ($p) => $p->punched_at->format('H:i'))->all())->toBe(['08:45', '12:00'])
        ->and(rtlDeviceTimes($e))->toBe(['13:00:00', '14:00:00', '14:30:00', '18:15:00'])
        ->and($audit->old_values['attendance']['check_out'])->toBe(RTL_DATE.' 18:15')
        ->and($audit->new_values['attendance']['check_in'])->toBe(RTL_DATE.' 08:45')
        // The approval that came first is still on record.
        ->and(AuditLog::where('event', 'ATTENDANCE_REGULARISATION_APPROVED')->where('auditable_id', $request->id)->exists())->toBeTrue();
});

// ── 5. Approved delete / revert ─────────────────────────────────────────────

test('deleting an approved regularisation leaves the genuine later sessions as the day', function () {
    $e = rtlEmployee();
    $request = rtlMorningCorrectedThenTwoSessions($e);

    app(RegularisationManager::class)->delete($request, $this->hr, 'Raised against the wrong day', confirmed: true);

    $row = rtlRow($e);

    expect($row->check_in->format('H:i'))->toBe('13:00')
        ->and($row->check_out->format('H:i'))->toBe('18:15')
        ->and((float) $row->total_hours)->toBe(5.25)
        ->and($row->break_minutes)->toBe(30)
        ->and($row->is_late)->toBeTrue()
        ->and($row->is_regularized)->toBeFalse()
        ->and($row->original_check_in)->toBeNull()
        ->and(AttendancePunch::where('employee_id', $e->id)->where('source', 'regularisation')->count())->toBe(0)
        ->and(rtlDeviceTimes($e))->toBe(['13:00:00', '14:00:00', '14:30:00', '18:15:00'])
        ->and(AuditLog::where('event', 'ATTENDANCE_REGULARISATION_REVERTED')->exists())->toBeTrue()
        ->and(AuditLog::where('event', 'ATTENDANCE_REGULARISATION_DELETED')->exists())->toBeTrue()
        ->and(AttendanceRegularisation::withTrashed()->find($request->id)->trashed())->toBeTrue();
});

// ── 6. Missing checkout after regularisation ────────────────────────────────

test('a Face IN after a regularised morning with no Card OUT is a missing checkout', function () {
    $e = rtlEmployee();
    $this->travelTo(Carbon::parse(RTL_DATE.' 12:30:00'));
    rtlApprove($e, '09:00', '12:00');

    rtlDevice($e, '13:00:00', 'face');
    rtlSync($e);

    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    rtlSync($e);   // the next sync, after the 19:00 cutoff

    $row = rtlRow($e);
    $status = app(Status::class)->forAttendance($row);
    $t = rtlTimeline($e);

    // The corrected 12:00 OUT closed the morning; the 13:00 IN opened a
    // session nobody closed. Nothing is counted past it and nothing invented.
    expect($row->check_in->format('H:i'))->toBe('09:00')
        ->and($row->check_out)->toBeNull()
        ->and($row->missing_checkout)->toBeTrue()
        ->and((float) $row->total_hours)->toBe(0.0)
        ->and($status['state'])->toBe(Status::MISSING_CHECKOUT)
        ->and($t['missing_out'])->toBeTrue()
        ->and(collect($t['nodes'])->last()['type'])->toBe('missing')
        ->and($t['sessions'][0]['in'])->toBe('09:00 AM')
        ->and($t['sessions'][0]['out'])->toBe('12:00 PM');
});

// ── 7. Duplicate punch around the regularised boundary ──────────────────────

test('device reads around a corrected boundary collapse into the correction', function () {
    $e = rtlEmployee();
    rtlDevice($e, '09:00:00', 'face');
    // The lunch Card OUT read twice, either side of the corrected 13:00:00.
    rtlDevice($e, '12:59:40', 'id_card');
    rtlDevice($e, '13:00:25', 'id_card');
    rtlDevice($e, '14:00:00', 'face');
    rtlDevice($e, '18:00:00', 'id_card');
    rtlSync($e);

    rtlApprove($e, '09:00', '13:00');

    $row = rtlRow($e);
    $t = rtlTimeline($e);
    $raw = collect($t['raw_events'])->keyBy('time');

    expect($row->check_in->format('H:i'))->toBe('09:00')
        ->and($row->check_out->format('H:i'))->toBe('18:00')
        ->and((float) $row->total_hours)->toBe(9.0)
        ->and($row->break_minutes)->toBe(60)
        ->and($t['sessions'])->toHaveCount(2)
        ->and($t['sessions'][0]['out'])->toBe('01:00 PM')
        ->and($t['needs_regularization'])->toBeFalse()
        ->and($raw['12:59:40 PM']['flag'])->toBe('duplicate')
        ->and($raw['01:00:25 PM']['flag'])->toBe('duplicate')
        ->and($raw['01:00:00 PM']['source'])->toBe('regularisation')
        ->and($raw['01:00:00 PM']['flag'])->toBe('kept')
        ->and(rtlDeviceTimes($e))->toBe(['09:00:00', '12:59:40', '13:00:25', '14:00:00', '18:00:00']);
});

test('a device boundary replaced by a correction is kept for audit, flagged superseded', function () {
    $e = rtlEmployee();
    // The employee says they arrived at 09:00, not when the reader caught them.
    rtlDevice($e, '09:25:00', 'face');
    rtlDevice($e, '13:00:00', 'id_card');
    rtlDevice($e, '14:00:00', 'face');
    rtlDevice($e, '18:00:00', 'id_card');
    rtlSync($e);

    rtlApprove($e, '09:00', '13:00');

    $row = rtlRow($e);
    $t = rtlTimeline($e);
    $superseded = collect($t['raw_events'])->firstWhere('time', '09:25:00 AM');

    expect($row->check_in->format('H:i'))->toBe('09:00')
        ->and($row->check_out->format('H:i'))->toBe('18:00')
        ->and($row->is_late)->toBeFalse()
        ->and($row->original_check_in->format('H:i'))->toBe('09:25')
        ->and($superseded['flag'])->toBe('superseded')
        ->and($superseded['note'])->toContain('approved correction')
        ->and($t['flags']['superseded'])->toBe(1)
        ->and($t['needs_regularization'])->toBeFalse();
});

// ── 8. Direction-corrected Face / Card punches ─────────────────────────────

test('later Face and Card punches the device mis-tagged still pair after a correction', function () {
    $e = rtlEmployee();
    $this->travelTo(Carbon::parse(RTL_DATE.' 12:30:00'));
    // Correction punches carry method id_card by default — the IN must stay an IN.
    rtlApprove($e, '09:00', '12:00');

    rtlDevice($e, '13:00:00', 'face', 'out');      // device said OUT; Face = IN
    rtlDevice($e, '18:00:00', 'id_card', 'in');    // device said IN; Card = OUT
    rtlSync($e);

    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $row = rtlRow($e);
    $t = rtlTimeline($e);

    expect($row->check_in->format('H:i'))->toBe('09:00')
        ->and($row->check_out->format('H:i'))->toBe('18:00')
        ->and((float) $row->total_hours)->toBe(9.0)
        ->and($row->break_minutes)->toBe(60)
        ->and($t['flags']['direction_corrected'])->toBe(2)
        ->and(collect($t['nodes'])->pluck('dir')->all())->toBe(['IN', 'OUT', 'IN', 'OUT'])
        ->and($t['nodes'][0]['source'])->toBe('regularisation')
        ->and($t['needs_regularization'])->toBeFalse();
});

// ── Sync paths after approval ───────────────────────────────────────────────

test('the engine sync adds later punches to a regularised day instead of skipping it', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    $e = rtlEmployee();
    $this->travelTo(Carbon::parse(RTL_DATE.' 12:30:00'));
    rtlApprove($e, '09:00', '12:00');

    $this->travelTo(Carbon::parse(RTL_DATE.' 18:30:00'));
    Http::fake(['*/api/dashboard*' => Http::response(['table' => [[
        'emp_id' => $e->employee_code, 'first_punch' => '13:00:00', 'last_punch' => '18:15:00',
        'break_min' => 0, 'late' => true, 'delay_min' => 240,
        'punches' => [['time' => '13:00:00', 'verify' => 'face'], ['time' => '18:15:00', 'verify' => 'card']],
        'events' => [['time' => '13:00:00', 'type' => 'in'], ['time' => '18:15:00', 'type' => 'out']],
    ]]], 200)]);

    app(EngineAttendanceSyncService::class)->syncDate(RTL_DATE);

    $row = rtlRow($e);
    expect($row->check_in->format('H:i'))->toBe('09:00')
        ->and($row->check_out->format('H:i'))->toBe('18:15')
        ->and((float) $row->total_hours)->toBe(9.25)
        ->and($row->is_late)->toBeFalse()
        ->and($row->is_regularized)->toBeTrue();
});

test('a correction that exists only as row times is still left alone by a sync', function () {
    $e = rtlEmployee();
    // Approved before corrections were written as punches: no correction punch.
    $row = Attendance::create([
        'employee_id' => $e->id, 'date' => RTL_DATE, 'check_in' => RTL_DATE.' 09:00:00', 'check_out' => RTL_DATE.' 18:00:00',
        'original_check_in' => RTL_DATE.' 09:40:00', 'is_regularized' => true, 'total_hours' => 9.0, 'status' => 'on_time', 'work_mode' => 'office',
    ]);
    rtlDevice($e, '09:40:00', 'face');
    rtlDevice($e, '21:45:00', 'id_card');

    rtlSync($e);

    expect($row->fresh()->check_out->format('H:i'))->toBe('18:00')
        ->and((float) $row->fresh()->total_hours)->toBe(9.0);
});
