<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\HolidayWorkRequest;
use App\Models\LeaveBalance;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\AttendanceStatusResolver as Status;
use App\Services\Biometric\BiometricSyncService;
use App\Services\HolidayWorkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * A holiday or weekly off never produces made-up attendance. No punches = no
 * attendance; genuine punches count exactly like any other day (Face IN,
 * final ID Card OUT, missing-checkout cutoff); holiday pay settles from the
 * ACTUAL duration; and old synthetic rows are found by
 * attendance:rebuild-punch-timelines and removed only with --apply.
 *
 * Shift 09:00–18:00. Holiday: Thursday 15 Oct 2026. Weekly off: Saturday 17 Oct.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
    $this->shift = ShiftSetting::create([
        'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 8, 'ot_threshold_hours' => 9,
    ]);
    PublicHoliday::factory()->create(['date' => '2026-10-15', 'country' => 'UK', 'is_active' => true, 'name' => 'Founders Day']);
});

function hatEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => test()->shift->id, 'employee_code' => fake()->unique()->numberBetween(1000, 9999),
        'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

/** @param  array<int, array{0: string, 1: string, 2: int}>  $logs  [datetime, state, verify] — 15 = Face, 4 = ID Card */
function hatDeviceSync(Employee $employee, array $logs): void
{
    $device = BiometricDevice::firstOrCreate(['name' => 'AIFACE'], ['ip_address' => '10.0.0.1', 'port' => 4370, 'timeout_seconds' => 5]);
    foreach ($logs as [$at, $state, $verify]) {
        BiometricLog::create(['device_id' => $device->id, 'device_user_id' => (string) $employee->employee_code, 'employee_id' => $employee->id,
            'punched_at' => $at, 'punch_type' => $state, 'verify_type' => $verify, 'is_processed' => false]);
    }
    app(BiometricSyncService::class)->applyPendingLogs($device);
}

/** An approved holiday-work request, as an HR approval leaves it. */
function hatRequest(Employee $employee, string $date, string $pay = 'overtime', float $expected = 8): HolidayWorkRequest
{
    $reviewer = User::factory()->create(['role' => UserRole::HrAdmin]);
    $service = app(HolidayWorkService::class);
    $request = $service->submit($employee, ['work_date' => $date, 'reason' => 'Release cover', 'expected_hours' => $expected, 'pay_type' => $pay]);
    $service->approve($request, $reviewer->id);

    return $request->fresh();
}

/** The row the old holiday-work approval wrote: shift-start check-in, fabricated check-out, full hours. */
function hatSyntheticRow(Employee $employee, string $date): Attendance
{
    return Attendance::create([
        'employee_id' => $employee->id, 'date' => $date, 'check_in' => "$date 09:00:00", 'check_out' => "$date 17:00:00",
        'total_hours' => 8.0, 'status' => 'holiday_worked', 'work_mode' => 'office', 'missing_checkout' => false,
    ]);
}

function hatState(Employee $employee, string $date): array
{
    return app(Status::class)->resolve($employee, $date);
}

test('a holiday with no punches creates no attendance, no times and no hours', function () {
    $e = hatEmployee();

    $this->artisan('hrms:auto-punch-out', ['--date' => '2026-10-15'])->assertSuccessful();
    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();
    $row = app(AttendanceDayRebuilder::class)->rebuild($e, Carbon::parse('2026-10-15'));

    expect(Attendance::count())->toBe(0)
        ->and(AttendanceDailySummary::count())->toBe(0)
        ->and($row['skip'])->toBe('no valid Face IN')
        ->and(hatState($e, '2026-10-15')['state'])->toBe(Status::NOT_IN)
        ->and(hatState($e, '2026-10-15')['reason'])->toBe('holiday');
});

test('approving holiday work does not create attendance either', function () {
    $e = hatEmployee();

    hatRequest($e, '2026-10-15');

    expect(Attendance::count())->toBe(0)
        ->and(AttendancePunch::count())->toBe(0)
        ->and(OtRequest::count())->toBe(0)
        ->and(hatState($e, '2026-10-15')['state'])->toBe(Status::NOT_IN);
});

test('a holiday with a genuine Face IN and final ID Card OUT counts exactly what was worked', function () {
    $e = hatEmployee();
    hatRequest($e, '2026-10-15', expected: 8);

    hatDeviceSync($e, [['2026-10-15 10:00:00', 'check_in', 15], ['2026-10-15 15:30:00', 'check_out', 4]]);

    $row = Attendance::firstOrFail();
    $day = app(AttendanceCalculator::class)->forDay($e, '2026-10-15', $row);
    $record = OvertimeRecord::firstOrFail();

    expect($row->check_in->format('H:i'))->toBe('10:00')
        ->and($row->check_out->format('H:i'))->toBe('15:30')
        ->and($day->workedMinutes)->toBe(330)
        ->and($row->status)->toBe('holiday_worked')
        ->and(hatState($e, '2026-10-15')['state'])->toBe(Status::COMPLETED)
        ->and((float) $record->ot_hours)->toBe(5.5)                 // actual, not the 8 expected
        ->and(AttendanceDailySummary::firstOrFail()->first_punch->format('H:i'))->toBe('10:00')
        ->and(AttendancePunch::where('source', '!=', 'biometric')->count())->toBe(0);
});

test('a holiday Face IN with no OUT becomes Missing Checkout — no invented OUT, no payable overtime', function () {
    $this->travelTo(Carbon::parse('2026-10-15 19:30:00'));   // past 18:00 + 1h
    $e = hatEmployee();
    $request = hatRequest($e, '2026-10-15');
    hatDeviceSync($e, [['2026-10-15 10:00:00', 'check_in', 15]]);

    $this->artisan('hrms:auto-punch-out')->assertSuccessful();

    $row = Attendance::firstOrFail();
    expect($row->check_out)->toBeNull()
        ->and($row->missing_checkout)->toBeTrue()
        ->and((float) $row->total_hours)->toBe(0.0)
        ->and(hatState($e, '2026-10-15')['state'])->toBe(Status::MISSING_CHECKOUT)
        ->and($request->fresh()->settled_at)->toBeNull()
        ->and(OtRequest::count())->toBe(0)
        ->and(OvertimeRecord::count())->toBe(0);

    // The real OUT arrives later (a regularisation / retry): now it settles — once.
    hatDeviceSync($e, [['2026-10-15 16:00:00', 'check_out', 4]]);

    expect($request->fresh()->settled_at)->not->toBeNull()
        ->and((float) OvertimeRecord::firstOrFail()->ot_hours)->toBe(6.0);
});

test('a weekly off with no punches has no attendance', function () {
    $e = hatEmployee();

    $this->artisan('hrms:auto-punch-out', ['--date' => '2026-10-17'])->assertSuccessful();
    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();

    expect(Attendance::count())->toBe(0)
        ->and(hatState($e, '2026-10-17')['reason'])->toBe('weekly_off');
});

test('genuine weekly-off work is real attendance; a missing OUT is Missing Checkout', function () {
    $e = hatEmployee();
    $other = hatEmployee();
    hatDeviceSync($e, [['2026-10-17 10:00:00', 'check_in', 15], ['2026-10-17 13:00:00', 'check_out', 4]]);
    $this->travelTo(Carbon::parse('2026-10-17 19:30:00'));
    hatDeviceSync($other, [['2026-10-17 10:00:00', 'check_in', 15]]);
    $this->artisan('hrms:auto-punch-out')->assertSuccessful();

    $done = Attendance::where('employee_id', $e->id)->firstOrFail();
    $open = Attendance::where('employee_id', $other->id)->firstOrFail();

    expect($done->check_out->format('H:i'))->toBe('13:00')
        ->and(hatState($e, '2026-10-17')['state'])->toBe(Status::COMPLETED)
        ->and($open->check_out)->toBeNull()
        ->and($open->missing_checkout)->toBeTrue()
        ->and(hatState($other, '2026-10-17')['state'])->toBe(Status::MISSING_CHECKOUT);
});

test('the rebuild preview names a synthetic holiday row and changes nothing', function () {
    $e = hatEmployee();
    $row = hatSyntheticRow($e, '2026-10-15');
    AttendanceDailySummary::create(['employee_id' => $e->id, 'employee_code' => $e->employee_code, 'date' => '2026-10-15',
        'first_punch' => '2026-10-15 09:00:00', 'last_punch' => '2026-10-15 17:00:00', 'working_hours' => 8.0, 'raw_punch_count' => 0, 'synced_at' => now()]);

    $this->artisan('attendance:rebuild-punch-timelines')
        ->expectsOutputToContain('REMOVE: synthetic holiday row')
        ->expectsOutputToContain('DRY RUN — nothing changed')
        ->assertSuccessful();

    expect($row->fresh())->not->toBeNull()
        ->and(AttendanceDailySummary::first()->working_hours)->toBe('8.00');
});

test('--apply removes a synthetic holiday row, its fake summary and the overtime it produced — audited', function () {
    $e = hatEmployee();
    $row = hatSyntheticRow($e, '2026-10-15');
    AttendanceDailySummary::create(['employee_id' => $e->id, 'employee_code' => $e->employee_code, 'date' => '2026-10-15',
        'first_punch' => '2026-10-15 09:00:00', 'last_punch' => '2026-10-15 17:00:00', 'working_hours' => 8.0, 'raw_punch_count' => 0, 'synced_at' => now()]);
    $ot = OtRequest::create(['employee_id' => $e->id, 'attendance_id' => $row->id, 'work_date' => '2026-10-15', 'start_time' => '09:00', 'end_time' => '17:00',
        'requested_hours' => 8, 'reason' => 'Holiday worked', 'status' => 'approved', 'source' => 'holiday']);
    $record = OvertimeRecord::create(['employee_id' => $e->id, 'ot_request_id' => $ot->id, 'attendance_id' => $row->id, 'work_date' => '2026-10-15',
        'total_hours_worked' => 8, 'standard_hours' => 9, 'ot_hours' => 8, 'rate_per_hour' => 100, 'ot_amount' => 800, 'is_paid' => false]);

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])
        ->expectsOutputToContain('REMOVED: synthetic holiday row')
        ->assertSuccessful();

    $summary = AttendanceDailySummary::first();
    expect(Attendance::count())->toBe(0)
        ->and($summary->first_punch)->toBeNull()
        ->and($summary->last_punch)->toBeNull()
        ->and((float) $summary->working_hours)->toBe(0.0)
        ->and((float) $record->fresh()->ot_hours)->toBe(0.0)
        ->and((float) $record->fresh()->ot_amount)->toBe(0.0)
        ->and(AuditLog::where('event', 'ATTENDANCE_SYNTHETIC_ROW_CLEARED')->count())->toBe(1)
        ->and(AuditLog::where('event', 'ATTENDANCE_SYNTHETIC_ROW_CLEARED')->first()->old_values['check_out'])->toContain('17:00');
});

test('a synthetic weekly-off row is detected and removed too', function () {
    $e = hatEmployee();
    hatSyntheticRow($e, '2026-10-17');

    $this->artisan('attendance:rebuild-punch-timelines')->expectsOutputToContain('synthetic weekly-off row')->assertSuccessful();
    expect(Attendance::count())->toBe(1);

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();
    expect(Attendance::count())->toBe(0);
});

test('a holiday row with a genuine punch is rebuilt from it, never removed', function () {
    $e = hatEmployee();
    $row = hatSyntheticRow($e, '2026-10-15');   // fabricated 09:00–17:00 …
    AttendancePunch::factory()->create(['employee_id' => $e->id, 'punched_at' => '2026-10-15 11:00:00', 'punch_date' => '2026-10-15', 'method' => 'face', 'direction' => 'in', 'source' => 'biometric']);
    AttendancePunch::factory()->create(['employee_id' => $e->id, 'punched_at' => '2026-10-15 14:00:00', 'punch_date' => '2026-10-15', 'method' => 'id_card', 'direction' => 'out', 'source' => 'biometric']);   // … but real punches exist

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();

    $row->refresh();
    expect($row->check_in->format('H:i'))->toBe('11:00')
        ->and($row->check_out->format('H:i'))->toBe('14:00')
        ->and((float) $row->total_hours)->toBe(3.0);
});

test('a regularised holiday is protected', function () {
    $e = hatEmployee();
    $row = hatSyntheticRow($e, '2026-10-15');
    $row->update(['is_regularized' => true, 'original_check_in' => '2026-10-15 09:00:00']);

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();   // never even a candidate

    expect($row->fresh())->not->toBeNull()
        ->and($row->fresh()->check_out->format('H:i'))->toBe('17:00');
});

test('a holiday inside a finalised payroll is protected', function () {
    $e = hatEmployee();
    $row = hatSyntheticRow($e, '2026-10-15');
    $payroll = Payroll::create(['month' => 'October', 'year' => 2026, 'status' => 'finalized', 'cycle' => 'cycle_a', 'total_payout' => 0]);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $e->id, 'gross_salary' => 0, 'total_deductions' => 0, 'net_salary' => 0]);

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->expectsOutputToContain('SKIP: inside approved payroll')->assertSuccessful();

    expect($row->fresh())->not->toBeNull();
});

test('a no-punch row on a normal working day is only reported, never removed', function () {
    $e = hatEmployee();
    $row = Attendance::create(['employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 09:00:00', 'check_out' => '2026-10-14 18:00:00', 'total_hours' => 9, 'status' => 'on_time', 'work_mode' => 'office']);

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])
        ->expectsOutputToContain('no supporting genuine punches')
        ->assertSuccessful();

    expect($row->fresh())->not->toBeNull();
});

test('holiday pay uses the actual duration only, and settling again never pays twice', function () {
    $e = hatEmployee();
    hatRequest($e, '2026-10-15', 'comp_off', expected: 8);
    hatDeviceSync($e, [['2026-10-15 10:00:00', 'check_in', 15], ['2026-10-15 13:00:00', 'check_out', 4]]);   // a real 3h

    $balance = fn () => (float) LeaveBalance::whereHas('leaveType', fn ($q) => $q->where('category', 'comp_off'))->where('employee_id', $e->id)->first()->allocated_days;
    $first = $balance();

    app(AttendanceDayRebuilder::class)->rebuild($e, Carbon::parse('2026-10-15'));
    app(HolidayWorkService::class)->settleForDay($e, Carbon::parse('2026-10-15'));

    expect((float) HolidayWorkRequest::first()->actual_hours)->toBe(3.0)
        ->and($balance())->toBe($first);
});

test('repeated rebuilds of a holiday day are idempotent', function () {
    $e = hatEmployee();
    hatRequest($e, '2026-10-15');
    hatDeviceSync($e, [['2026-10-15 10:00:00', 'check_in', 15], ['2026-10-15 15:30:00', 'check_out', 4]]);
    $snapshot = fn () => [
        Attendance::orderBy('id')->get()->map->only(['date', 'check_in', 'check_out', 'total_hours', 'status'])->toArray(),
        OvertimeRecord::orderBy('id')->get()->map->only(['ot_hours', 'ot_amount'])->toArray(),
        AttendanceDailySummary::orderBy('id')->get()->map->only(['first_punch', 'last_punch', 'working_hours'])->toArray(),
        AttendancePunch::count(),
    ];
    $first = $snapshot();

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();
    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();
    app(AttendanceDayRebuilder::class)->rebuild($e, Carbon::parse('2026-10-15'));

    expect($snapshot())->toEqual($first)
        ->and(Attendance::count())->toBe(1)
        ->and(OvertimeRecord::count())->toBe(1);
});
