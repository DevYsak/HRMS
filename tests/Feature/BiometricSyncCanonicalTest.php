<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceStatusResolver as Status;
use App\Services\Attendance\PunchTimeline;
use App\Services\Biometric\BiometricSyncService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The legacy device sync must produce exactly what the canonical timeline,
 * calculator and status resolver produce: raw punches kept as received, the
 * method (Face = IN, ID Card = OUT) deciding direction, latest of a burst,
 * one attendance row per work date, protected days untouched, and a repeat
 * sync changing nothing.
 *
 * Day shift 09:00–18:00 (cutoff 19:00). Clock: Wednesday 14 October 2026, 21:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 21:00:00'));
    $this->day = ShiftSetting::create([
        'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
    $this->night = ShiftSetting::create([
        'name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 8, 'ot_threshold_hours' => 8,
    ]);
    $this->device = BiometricDevice::create(['name' => 'AIFACE', 'ip_address' => '10.0.0.1', 'port' => 4370, 'timeout_seconds' => 5]);
});

function bscEmployee(?ShiftSetting $shift = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => ($shift ?? test()->day)->id,
        'employee_code' => fake()->unique()->numberBetween(1000, 9999), 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

/**
 * Device logs as the ZKTeco state byte reports them: [datetime, state, verify].
 *
 * @param  array<int, array{0: string, 1: string, 2: int}>  $logs  verify 15 = Face, 4 = ID Card
 */
function bscLogs(Employee $employee, array $logs): void
{
    foreach ($logs as [$at, $state, $verify]) {
        BiometricLog::create([
            'device_id' => test()->device->id, 'device_user_id' => (string) $employee->employee_code, 'employee_id' => $employee->id,
            'punched_at' => $at, 'punch_type' => $state, 'verify_type' => $verify, 'is_processed' => false,
        ]);
    }
}

function bscSync(): int
{
    return app(BiometricSyncService::class)->applyPendingLogs(test()->device);
}

/** @return array<string, mixed> */
function bscSnapshot(): array
{
    return [
        'attendance' => Attendance::orderBy('id')->get()->map->only(['employee_id', 'date', 'check_in', 'check_out', 'total_hours', 'break_minutes', 'status', 'is_late', 'missing_checkout'])->toArray(),
        'summaries' => AttendanceDailySummary::orderBy('id')->get()->map->only(['employee_id', 'date', 'first_punch', 'last_punch', 'working_hours', 'break_minutes', 'status', 'raw_punch_count'])->toArray(),
        'punches' => AttendancePunch::orderBy('id')->get()->map->only(['id', 'punched_at', 'punch_date', 'method', 'direction', 'source'])->toArray(),
    ];
}

test('Face is IN and ID Card is OUT whatever state byte the device sent — raw direction kept for audit', function () {
    $e = bscEmployee();
    bscLogs($e, [['2026-10-14 09:02:00', 'check_out', 15], ['2026-10-14 17:45:00', 'check_in', 4]]);

    bscSync();

    $row = Attendance::firstOrFail();
    expect($row->check_in->format('H:i:s'))->toBe('09:02:00')
        ->and($row->check_out->format('H:i:s'))->toBe('17:45:00')
        ->and($row->check_in_method)->toBe('face')
        ->and($row->check_out_method)->toBe('id_card')
        ->and(AttendancePunch::orderBy('punched_at')->pluck('direction')->all())->toBe(['out', 'in']);   // as received
});

test('a Face burst keeps the latest read; every raw punch is stored', function () {
    $e = bscEmployee();
    bscLogs($e, [
        ['2026-10-14 09:05:01', 'check_in', 15], ['2026-10-14 09:05:22', 'check_in', 15], ['2026-10-14 09:05:48', 'check_in', 15],
        ['2026-10-14 17:55:00', 'check_out', 4],
    ]);

    bscSync();

    expect(Attendance::firstOrFail()->check_in->format('H:i:s'))->toBe('09:05:48')
        ->and(AttendancePunch::count())->toBe(4)
        ->and(AttendanceDailySummary::firstOrFail()->raw_punch_count)->toBe(4);
});

test('a valid Face IN and ID Card OUT a minute apart stay separate', function () {
    $e = bscEmployee();
    bscLogs($e, [['2026-10-14 10:30:00', 'check_in', 15], ['2026-10-14 10:31:00', 'check_out', 4]]);

    bscSync();

    $row = Attendance::firstOrFail();
    expect($row->check_in->format('H:i'))->toBe('10:30')
        ->and($row->check_out->format('H:i'))->toBe('10:31');
});

test('a stray ID Card OUT creates no attendance and no phantom hours', function () {
    $e = bscEmployee();
    bscLogs($e, [['2026-10-14 08:50:00', 'check_out', 4]]);

    expect(bscSync())->toBe(1);

    expect(Attendance::count())->toBe(0)
        ->and(AttendancePunch::count())->toBe(1)                          // the raw tap is kept
        ->and(BiometricLog::first()->is_processed)->toBeTrue();

    bscLogs($e, [['2026-10-14 09:05:00', 'check_in', 15]]);
    bscSync();
    expect(Attendance::firstOrFail()->check_in->format('H:i'))->toBe('09:05');
});

test('a repeated Face outside 60 seconds is a missing-OUT exception — no OUT is invented', function () {
    $e = bscEmployee();
    bscLogs($e, [['2026-10-14 09:05:00', 'check_in', 15], ['2026-10-14 13:00:00', 'check_in', 15], ['2026-10-14 18:00:00', 'check_out', 4]]);

    bscSync();

    $timeline = app(PunchTimeline::class)->process(AttendancePunch::orderBy('punched_at')->get(), Carbon::parse('2026-10-14'));

    expect($timeline['needs_regularization'])->toBeTrue()
        ->and($timeline['flags']['missing_out'])->toBeTrue()
        ->and(Attendance::firstOrFail()->check_out->format('H:i'))->toBe('18:00');
});

test('running the same sync twice changes nothing — even re-applying every log', function () {
    $e = bscEmployee();
    bscLogs($e, [
        ['2026-10-14 09:05:01', 'check_in', 15], ['2026-10-14 09:05:30', 'check_in', 15],
        ['2026-10-14 13:00:00', 'check_out', 4], ['2026-10-14 14:00:00', 'check_in', 15], ['2026-10-14 18:10:00', 'check_out', 4],
    ]);

    bscSync();
    $first = bscSnapshot();
    expect(bscSync())->toBe(0)->and(bscSnapshot())->toEqual($first);

    BiometricLog::query()->update(['is_processed' => false]);   // a retry of the whole history
    bscSync();

    expect(bscSnapshot())->toEqual($first)
        ->and(Attendance::count())->toBe(1)
        ->and(AttendancePunch::count())->toBe(5)
        ->and(AttendanceDailySummary::count())->toBe(1);
});

test('a late-arriving punch updates the same attendance row, never a second one', function () {
    $e = bscEmployee();
    bscLogs($e, [['2026-10-14 09:05:00', 'check_in', 15]]);
    bscSync();
    expect(Attendance::firstOrFail()->check_out)->toBeNull();

    bscLogs($e, [['2026-10-14 18:20:00', 'check_out', 4]]);
    bscSync();

    expect(Attendance::count())->toBe(1)
        ->and(Attendance::first()->check_out->format('H:i'))->toBe('18:20');
});

test('a regularised day is never overwritten by a later sync', function () {
    $e = bscEmployee();
    $row = Attendance::create([
        'employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'check_out' => '2026-10-14 18:00:00',
        'original_check_in' => '2026-10-14 09:40:00', 'is_regularized' => true, 'total_hours' => 9.0, 'status' => 'on_time', 'work_mode' => 'office',
    ]);
    bscLogs($e, [['2026-10-14 09:40:00', 'check_in', 15], ['2026-10-14 21:45:00', 'check_out', 4]]);

    bscSync();

    $fresh = $row->fresh();
    expect($fresh->check_in->format('H:i'))->toBe('09:00')
        ->and($fresh->check_out->format('H:i'))->toBe('18:00')
        ->and((float) $fresh->total_hours)->toBe(9.0)
        ->and(Attendance::count())->toBe(1)
        ->and(AttendancePunch::where('source', 'biometric')->count())->toBe(2);   // raw logs still kept
});

test('an HR-corrected day (approved correction) is never overwritten by a later sync', function () {
    $e = bscEmployee();
    $row = Attendance::create([
        'employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'check_out' => '2026-10-14 17:00:00',
        'total_hours' => 8.0, 'status' => 'on_time', 'work_mode' => 'office',
    ]);
    AttendanceRegularisation::create([
        'employee_id' => $e->id, 'attendance_id' => $row->id, 'work_date' => '2026-10-14', 'regularisation_type' => 'punch',
        'requested_check_in' => '2026-10-14 09:00:00', 'requested_check_out' => '2026-10-14 17:00:00', 'reason' => 'HR fix',
        'status' => 'approved', 'applied_via' => 'hr_fast_path',
    ]);
    bscLogs($e, [['2026-10-14 09:30:00', 'check_in', 15], ['2026-10-14 19:00:00', 'check_out', 4]]);

    bscSync();

    expect($row->fresh()->check_out->format('H:i'))->toBe('17:00')
        ->and((float) $row->fresh()->total_hours)->toBe(8.0);
});

test('a day inside a finalised payroll is never overwritten by a later sync', function () {
    $e = bscEmployee();
    $row = Attendance::create([
        'employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'check_out' => '2026-10-14 17:00:00',
        'total_hours' => 8.0, 'status' => 'on_time', 'work_mode' => 'office',
    ]);
    $payroll = Payroll::create(['month' => 'October', 'year' => 2026, 'status' => 'finalized', 'cycle' => 'cycle_a', 'total_payout' => 0]);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $e->id, 'gross_salary' => 0, 'total_deductions' => 0, 'net_salary' => 0]);
    bscLogs($e, [['2026-10-14 09:30:00', 'check_in', 15], ['2026-10-14 19:00:00', 'check_out', 4]]);

    bscSync();

    expect($row->fresh()->check_in->format('H:i'))->toBe('09:00')
        ->and($row->fresh()->check_out->format('H:i'))->toBe('17:00')
        ->and(Attendance::count())->toBe(1);
});

test('an overnight shift attaches after-midnight punches to the work date — one row, no next-day row', function () {
    $this->travelTo(Carbon::parse('2026-10-14 08:00:00'));
    $e = bscEmployee($this->night);
    bscLogs($e, [['2026-10-13 22:05:00', 'check_in', 15], ['2026-10-14 05:55:00', 'check_out', 4]]);

    bscSync();

    $row = Attendance::firstOrFail();
    expect(Attendance::count())->toBe(1)
        ->and($row->date->toDateString())->toBe('2026-10-13')
        ->and($row->check_out->format('Y-m-d H:i'))->toBe('2026-10-14 05:55')
        ->and((float) $row->total_hours)->toBe(7.83)                      // 22:05 → 05:55
        ->and(AttendancePunch::pluck('punch_date')->map->toDateString()->unique()->all())->toBe(['2026-10-13'])
        ->and(AttendanceDailySummary::firstOrFail()->date->toDateString())->toBe('2026-10-13');
});

test('a missing checkout gets no invented OUT and stops counting at the cutoff', function () {
    $this->travelTo(Carbon::parse('2026-10-14 19:30:00'));   // shift end 18:00 + 1h = 19:00
    $e = bscEmployee();
    bscLogs($e, [['2026-10-14 09:05:00', 'check_in', 15]]);

    bscSync();

    $row = Attendance::firstOrFail();
    $status = app(Status::class)->forAttendance($row);

    expect($row->check_out)->toBeNull()
        ->and($row->missing_checkout)->toBeTrue()
        ->and((float) $row->total_hours)->toBe(0.0)
        ->and($status['state'])->toBe(Status::MISSING_CHECKOUT)
        ->and(AttendanceDailySummary::firstOrFail()->last_punch)->toBeNull();
});

test('the daily summary matches the calculator: first punch, last OUT, hours, break, status, raw count', function () {
    $e = bscEmployee();
    bscLogs($e, [
        ['2026-10-14 09:20:00', 'check_in', 15], ['2026-10-14 12:00:00', 'check_out', 4],
        ['2026-10-14 13:00:00', 'check_in', 15], ['2026-10-14 18:30:00', 'check_out', 4],
        ['2026-10-14 18:30:20', 'check_out', 4],
    ]);

    bscSync();

    $row = Attendance::firstOrFail();
    $summary = AttendanceDailySummary::firstOrFail();
    $day = app(AttendanceCalculator::class)->forDay($e, '2026-10-14', $row);

    expect($summary->first_punch->format('H:i:s'))->toBe($day->firstIn->format('H:i:s'))
        ->and($summary->last_punch->format('H:i:s'))->toBe('18:30:20')                       // latest valid OUT
        ->and((float) $summary->working_hours)->toBe((float) $row->total_hours)
        ->and($summary->working_hours)->toBe(number_format($day->workedMinutes / 60, 2, '.', ''))
        ->and($summary->break_minutes)->toBe($day->breakMinutes)
        ->and($summary->break_minutes)->toBe(60)
        ->and($summary->status)->toBe('late')                                                // 09:20 > 09:00 + 5 grace
        ->and($summary->raw_punch_count)->toBe(5)
        ->and($row->break_minutes)->toBe(60)
        ->and($day->workedMinutes)->toBe(550);                                               // 09:20 → 18:30:20, break not deducted
});

test('the status resolver agrees with what the UI shows for a synced day', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $done = bscEmployee();
    $forgot = bscEmployee();
    bscLogs($done, [['2026-10-14 09:00:00', 'check_in', 15], ['2026-10-14 17:55:00', 'check_out', 4]]);
    bscLogs($forgot, [['2026-10-14 09:05:00', 'check_in', 15]]);

    bscSync();

    $doneRow = Attendance::where('employee_id', $done->id)->firstOrFail();
    $forgotRow = Attendance::where('employee_id', $forgot->id)->firstOrFail();

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertViewHas('rowStatus', fn ($rows) => $rows[$doneRow->id]['state'] === Status::COMPLETED
            && $rows[$forgotRow->id]['state'] === Status::MISSING_CHECKOUT)
        ->assertSeeHtml('data-attendance-state="missing_checkout"');

    expect(app(Status::class)->forAttendance($doneRow)['state'])->toBe(Status::COMPLETED);
});
