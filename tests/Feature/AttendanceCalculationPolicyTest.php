<?php

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDay;
use App\Services\Attendance\PunchTimeline;
use App\Services\AttendanceReportBuilder;
use App\Services\AttendanceService;
use App\Services\Biometric\BiometricSyncService;
use Illuminate\Support\Carbon;

/**
 * Pulse v3.1 attendance policy — the one canonical calculation.
 *
 * Clock: Wednesday 16 September 2026. Monday 14 Sep is a past working day;
 * Saturday 12 / Sunday 13 Sep are the weekly off.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));
    // This deployment: Face opens a session, Card closes it.
    config()->set('biometric.method_direction', ['face' => 'in', 'id_card' => 'out']);
});

function acpShift(string $name, string $start, string $end): ShiftSetting
{
    return ShiftSetting::create([
        'name' => $name.' '.random_int(100, 999), 'start_time' => $start, 'end_time' => $end,
        'grace_minutes' => 5, 'break_duration' => 60, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
}

function acpEmployee(?ShiftSetting $shift = null): Employee
{
    $shift ??= acpShift('IT Shift', '10:30', '19:30');

    return Employee::withoutEvents(fn () => Employee::factory()->create([
        'user_id' => User::factory()->create()->id,
        'shift_id' => $shift->id,
        'status' => 'active',
        'joining_date' => '2024-01-01',
        'manager_id' => null,
        'holiday_calendar' => 'IN',
    ]));
}

/** @param  array<int, array{0: string, 1: string}>  $punches  [time, method] */
function acpPunches(Employee $employee, string $date, array $punches): void
{
    foreach ($punches as [$time, $method]) {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => Carbon::parse("{$date} {$time}"),
            'punch_date' => $date,
            'method' => $method,
            'source' => 'biometric',
        ]);
    }
}

function acpRow(Employee $employee, string $date, ?string $in, ?string $out, array $extra = []): Attendance
{
    return Attendance::create(array_merge([
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => $in ? Carbon::parse("{$date} {$in}") : null,
        'check_out' => $out ? Carbon::parse("{$date} {$out}") : null,
        'status' => 'on_time',
        'work_mode' => 'office',
    ], $extra));
}

function acpDay(Employee $employee, string $date, ?Attendance $row = null, ?string $now = null): AttendanceDay
{
    return app(AttendanceCalculator::class)->forDay($employee->fresh(), $date, $row, now: $now ? Carbon::parse($now) : null);
}

// ── Worked hours: first in → final out, breaks never deducted ───────────────

test('10:30–19:30 with a 40-minute break is 9h00m worked', function () {
    $e = acpEmployee();
    acpPunches($e, '2026-09-14', [['10:30:00', 'face'], ['13:00:00', 'id_card'], ['13:40:00', 'face'], ['19:30:00', 'id_card']]);

    $day = acpDay($e, '2026-09-14');

    expect($day->workedMinutes)->toBe(540)
        ->and(AttendanceDay::hm($day->workedMinutes))->toBe('9h 00m')
        ->and($day->breakMinutes)->toBe(40)
        ->and($day->excessBreak)->toBeFalse()
        ->and($day->isLate)->toBeFalse()
        ->and($day->status)->toBe(AttendanceDay::STATUS_PRESENT);

    // The shared timeline every widget renders reports the same figure.
    $punches = AttendancePunch::where('employee_id', $e->id)->orderBy('punched_at')->get();
    expect(app(PunchTimeline::class)->process($punches, Carbon::parse('2026-09-14'))['working_minutes'])->toBe(540);
});

test('09:40–19:00 with a 57-minute break is 9h20m worked', function () {
    $e = acpEmployee();
    acpPunches($e, '2026-09-14', [['09:40:00', 'face'], ['13:30:00', 'id_card'], ['14:27:00', 'face'], ['19:00:00', 'id_card']]);

    $day = acpDay($e, '2026-09-14');

    expect($day->workedMinutes)->toBe(560)
        ->and(AttendanceDay::hm($day->workedMinutes))->toBe('9h 20m')
        ->and($day->breakMinutes)->toBe(57)
        ->and($day->excessBreak)->toBeFalse();
});

test('a break over 60 minutes is flagged as excess but does not reduce worked hours', function () {
    $e = acpEmployee();
    acpPunches($e, '2026-09-14', [['10:30:00', 'face'], ['13:00:00', 'id_card'], ['14:15:00', 'face'], ['19:30:00', 'id_card']]);

    $day = acpDay($e, '2026-09-14');

    expect($day->breakMinutes)->toBe(75)
        ->and($day->excessBreak)->toBeTrue()
        ->and($day->workedMinutes)->toBe(540);
});

test('exactly 60 minutes of break is not excess', function () {
    $e = acpEmployee();
    acpPunches($e, '2026-09-14', [['10:30:00', 'face'], ['13:00:00', 'id_card'], ['14:00:00', 'face'], ['19:30:00', 'id_card']]);

    expect(acpDay($e, '2026-09-14')->excessBreak)->toBeFalse();
});

test('several punch and break segments still count first in to final out', function () {
    $e = acpEmployee();
    acpPunches($e, '2026-09-14', [
        ['10:30:00', 'face'], ['12:00:00', 'id_card'],
        ['12:15:00', 'face'], ['14:00:00', 'id_card'],
        ['14:45:00', 'face'], ['19:30:00', 'id_card'],
    ]);

    $day = acpDay($e, '2026-09-14');

    expect($day->workedMinutes)->toBe(540)
        ->and($day->breakMinutes)->toBe(60)
        ->and($day->excessBreak)->toBeFalse();
});

test('web clock-out stores first in to final out, ignoring logged breaks', function () {
    $e = acpEmployee();
    $this->travelTo(Carbon::parse('2026-09-14 19:30:00'));
    $row = acpRow($e, '2026-09-14', '10:30', null, ['break_minutes' => 40]);

    $closed = app(AttendanceService::class)->checkOut($row);

    expect((float) $closed->total_hours)->toBe(9.0)
        ->and($closed->netHours())->toBe(9.0);
});

// ── Late (5-minute grace) ────────────────────────────────────────────────────

test('IT shift: 10:35 is on time, 10:36 is late', function () {
    $e = acpEmployee();

    $onTime = acpDay($e, '2026-09-14', acpRow($e, '2026-09-14', '10:35:59', '19:30'));
    $lateRow = acpRow(acpEmployee(), '2026-09-15', '10:36:00', '19:30');
    $late = app(AttendanceCalculator::class)->forAttendance($lateRow->fresh());

    expect($onTime->isLate)->toBeFalse()
        ->and($late->isLate)->toBeTrue()
        ->and($late->lateMinutes)->toBe(1)
        ->and($late->status)->toBe(AttendanceDay::STATUS_LATE);
});

test('UK shift: 13:05 is on time, 13:06 is late', function () {
    $uk = acpShift('UK Shift', '13:00', '22:00');
    $a = acpEmployee($uk);
    $b = acpEmployee($uk);

    expect(acpDay($a, '2026-09-14', acpRow($a, '2026-09-14', '13:05', '22:00'))->isLate)->toBeFalse()
        ->and(acpDay($b, '2026-09-14', acpRow($b, '2026-09-14', '13:06', '22:00'))->isLate)->toBeTrue();
});

// ── Overtime only with an approved request ──────────────────────────────────

test('time beyond 9h is overtime only with an approved OT request', function () {
    $e = acpEmployee();
    $row = acpRow($e, '2026-09-14', '10:30', '21:30');

    $without = acpDay($e, '2026-09-14', $row);
    expect($without->workedMinutes)->toBe(660)
        ->and($without->beyondShiftMinutes)->toBe(120)
        ->and($without->approvedOtMinutes)->toBe(0);

    OtRequest::create([
        'employee_id' => $e->id, 'attendance_id' => $row->id, 'work_date' => '2026-09-14',
        'start_time' => '19:30', 'end_time' => '21:30', 'requested_hours' => 2, 'reason' => 'Release', 'status' => 'pending',
    ]);
    expect(acpDay($e, '2026-09-14', $row)->approvedOtMinutes)->toBe(0);

    OtRequest::where('employee_id', $e->id)->update(['status' => 'approved']);
    $with = acpDay($e, '2026-09-14', $row);

    expect($with->hasApprovedOt)->toBeTrue()
        ->and($with->approvedOtMinutes)->toBe(120)
        ->and($with->approvedOtHours())->toBe(2.0);
});

// ── Weekly off, holidays, absence ────────────────────────────────────────────

test('Saturday and Sunday are weekly off, never absent', function () {
    $e = acpEmployee();

    expect(acpDay($e, '2026-09-12')->status)->toBe(AttendanceDay::STATUS_WEEKLY_OFF)
        ->and(acpDay($e, '2026-09-13')->status)->toBe(AttendanceDay::STATUS_WEEKLY_OFF)
        ->and(acpDay($e, '2026-09-13')->isAbsent())->toBeFalse()
        // A past working day with no punch is absent.
        ->and(acpDay($e, '2026-09-14')->status)->toBe(AttendanceDay::STATUS_ABSENT);
});

test('a public holiday is never absent and never late', function () {
    $e = acpEmployee();
    PublicHoliday::factory()->create(['date' => '2026-09-14', 'country' => 'IN', 'is_active' => true, 'holiday_type' => 'national']);

    expect(acpDay($e, '2026-09-14')->status)->toBe(AttendanceDay::STATUS_HOLIDAY);

    // Working on the holiday: present, but not late.
    $worked = acpDay($e, '2026-09-14', acpRow($e, '2026-09-14', '11:15', '19:30'));
    expect($worked->isLate)->toBeFalse()
        ->and($worked->status)->toBe(AttendanceDay::STATUS_PRESENT);
});

test('the HR absent report skips weekly offs and holidays', function () {
    $e = acpEmployee();
    PublicHoliday::factory()->create(['date' => '2026-09-14', 'country' => 'IN', 'is_active' => true, 'holiday_type' => 'national']);

    $report = app(AttendanceReportBuilder::class)->build('absent', [
        'from' => '2026-09-12', 'to' => '2026-09-15', 'employee_id' => $e->id,
    ]);

    // Sat 12, Sun 13 (weekly off) and Mon 14 (holiday) are excluded; Tue 15 is absent.
    expect(collect($report['rows'])->pluck(3)->all())->toBe(['15 Sep 2026']);
});

// ── Missing checkout ─────────────────────────────────────────────────────────

test('a checkout is missing only after shift end + 1 hour', function () {
    $e = acpEmployee();
    $row = acpRow($e, '2026-09-16', '10:30', null);

    $before = acpDay($e, '2026-09-16', $row, '2026-09-16 20:29:00');
    $after = acpDay($e, '2026-09-16', $row, '2026-09-16 20:31:00');

    expect($before->missingCheckout)->toBeFalse()
        ->and($before->status)->toBe(AttendanceDay::STATUS_WORKING)
        ->and($before->workedMinutes)->toBe(599)
        ->and($after->missingCheckout)->toBeTrue()
        ->and($after->status)->toBe(AttendanceDay::STATUS_MISSING_CHECKOUT);
});

test('auto punch-out waits until shift end + 1 hour before closing the day', function () {
    $e = acpEmployee();
    acpRow($e, '2026-09-16', '10:30', null);

    $this->travelTo(Carbon::parse('2026-09-16 20:15:00'));
    $this->artisan('hrms:auto-punch-out')->assertSuccessful();
    expect(Attendance::where('employee_id', $e->id)->first()->check_out)->toBeNull();

    $this->travelTo(Carbon::parse('2026-09-16 20:31:00'));
    $this->artisan('hrms:auto-punch-out')->assertSuccessful();
    $closed = Attendance::where('employee_id', $e->id)->first();

    expect($closed->check_out?->format('H:i'))->toBe('19:30')
        ->and((float) $closed->total_hours)->toBe(9.0)
        ->and($closed->missing_checkout)->toBeTrue();
});

// ── Regularisation ───────────────────────────────────────────────────────────

test('a regularised day uses the corrected first-in and final-out, not raw device punches', function () {
    $e = acpEmployee();
    // Raw device punches that would read as a short day…
    acpPunches($e, '2026-09-14', [['10:30:00', 'face'], ['14:00:00', 'id_card']]);
    // …but HR approved a correction to 10:30–19:30.
    $row = acpRow($e, '2026-09-14', '10:30', '19:30', [
        'is_regularized' => true, 'original_check_in' => Carbon::parse('2026-09-14 10:30'), 'original_check_out' => Carbon::parse('2026-09-14 14:00'),
    ]);

    $day = acpDay($e, '2026-09-14', $row);

    expect($day->regularised)->toBeTrue()
        ->and($day->workedMinutes)->toBe(540)
        ->and($day->lastOut->format('H:i'))->toBe('19:30');
});

test('later biometric sync does not overwrite a regularised day', function () {
    $e = acpEmployee();
    $row = acpRow($e, '2026-09-14', '10:30', '19:30', [
        'is_regularized' => true, 'total_hours' => 9.0,
        'original_check_in' => Carbon::parse('2026-09-14 10:30'), 'original_check_out' => null,
    ]);

    // The device sync's checkout path (a later 21:45 card tap).
    $sync = app(BiometricSyncService::class);
    $resolve = new ReflectionMethod($sync, 'resolveCheckOut');
    $resolve->invoke($sync, $e->fresh(), Carbon::parse('2026-09-14 21:45'), '2026-09-14', 'id_card');

    $fresh = $row->fresh();
    expect($fresh->check_out->format('H:i'))->toBe('19:30')
        ->and((float) $fresh->total_hours)->toBe(9.0);
});

test('a regularisation IN punch keeps its IN direction even with a card method', function () {
    $e = acpEmployee();
    AttendancePunch::create(['employee_id' => $e->id, 'punched_at' => Carbon::parse('2026-09-14 10:30'), 'punch_date' => '2026-09-14',
        'method' => 'id_card', 'direction' => 'in', 'source' => 'regularisation']);
    AttendancePunch::create(['employee_id' => $e->id, 'punched_at' => Carbon::parse('2026-09-14 19:30'), 'punch_date' => '2026-09-14',
        'method' => 'id_card', 'direction' => 'out', 'source' => 'regularisation']);

    $t = app(PunchTimeline::class)->process(AttendancePunch::where('employee_id', $e->id)->orderBy('punched_at')->get(), Carbon::parse('2026-09-14'));

    expect($t['working_minutes'])->toBe(540)
        ->and($t['needs_regularization'])->toBeFalse();
});

// ── Stored-hours correction ──────────────────────────────────────────────────

test('attendance:recalculate-hours previews by default and corrects only with --apply', function () {
    $e = acpEmployee();
    // Saved by the old break-deducting formula: 9h − 56m.
    $row = acpRow($e, '2026-09-14', '10:30', '19:30', ['break_minutes' => 56, 'total_hours' => 8.07]);

    $this->artisan('attendance:recalculate-hours')->assertSuccessful();
    expect((float) $row->fresh()->total_hours)->toBe(8.07);

    $this->artisan('attendance:recalculate-hours', ['--apply' => true])->assertSuccessful();
    expect((float) $row->fresh()->total_hours)->toBe(9.0)
        ->and((int) $row->fresh()->break_minutes)->toBe(56);
});
