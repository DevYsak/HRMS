<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\NotificationSetting;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\MissingCheckoutNotification;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\AttendanceStatusResolver as Status;
use App\Services\OvertimeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/**
 * hrms:auto-punch-out no longer punches anyone out. A Face IN with no valid
 * ID Card OUT becomes Missing Checkout at the employee's own shift end + 1
 * hour — check_out stays NULL, no shift is credited, no overtime is invented —
 * and regularised, HR-corrected and payroll-settled days are never touched.
 *
 * Day shift 09:00–18:00 (cutoff 19:00). Wednesday 14 October 2026.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
    NotificationSetting::updateOrCreate(['key' => MissingCheckoutNotification::class], [
        'label' => 'Missing checkout', 'group' => 'Attendance', 'mail_enabled' => false, 'database_enabled' => true, 'is_automatic' => true,
    ]);
    $this->day = ShiftSetting::create([
        'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 8, 'ot_threshold_hours' => 9,
    ]);
    $this->night = ShiftSetting::create([
        'name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00',
        'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 8, 'ot_threshold_hours' => 8,
    ]);
});

function apoEmployee(?ShiftSetting $shift = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => ($shift ?? test()->day)->id,
        'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

/** An open day as a device sync leaves it: the Face IN punch and the attendance row. */
function apoOpenDay(Employee $employee, string $date = '2026-10-14', string $in = '09:05:00', array $extra = []): Attendance
{
    AttendancePunch::factory()->create([
        'employee_id' => $employee->id, 'punched_at' => "$date $in", 'punch_date' => $date,
        'method' => 'face', 'direction' => 'in', 'source' => 'biometric',
    ]);

    return Attendance::create($extra + [
        'employee_id' => $employee->id, 'date' => $date, 'check_in' => "$date $in",
        'status' => 'on_time', 'work_mode' => 'office',
    ]);
}

function apoRun(string $at): void
{
    test()->travelTo(Carbon::parse($at));
    test()->artisan('hrms:auto-punch-out')->assertSuccessful();
}

function apoNotifications(Employee $employee): int
{
    return $employee->user->notifications()->where('type', MissingCheckoutNotification::class)->count();
}

test('a missing OUT is flagged only after shift end + 1 hour — check_out stays NULL, nothing is credited', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e);

    apoRun('2026-10-14 18:40:00');   // inside the +1h window
    expect($row->fresh()->missing_checkout)->toBeFalse()
        ->and(app(Status::class)->forAttendance($row->fresh())['state'])->toBe(Status::WORKING);

    apoRun('2026-10-14 19:05:00');
    $row->refresh();

    expect($row->missing_checkout)->toBeTrue()
        ->and($row->check_out)->toBeNull()                       // no fabricated shift-end checkout
        ->and($row->check_out_method)->toBeNull()
        ->and($row->is_auto_checkout)->toBeFalse()
        ->and((float) $row->total_hours)->toBe(0.0)               // no full-shift credit
        ->and(AttendancePunch::count())->toBe(1)                  // no system OUT punch
        ->and(AttendancePunch::where('source', 'system_auto')->count())->toBe(0)
        ->and(app(Status::class)->forAttendance($row)['state'])->toBe(Status::MISSING_CHECKOUT)
        ->and(app(Status::class)->forAttendance($row)['worked_minutes'])->toBe(0);
});

test('an approved OT day with no real OUT is not closed overnight and produces no payable OT', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $request = OtRequest::create([
        'employee_id' => $e->id, 'attendance_id' => $row->id, 'work_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '21:00',
        'requested_hours' => 3, 'reason' => 'Release', 'status' => 'pending',
    ]);
    app(OvertimeService::class)->approve($request, $admin->id);

    apoRun('2026-10-14 23:59:30');
    apoRun('2026-10-15 08:00:00');   // "tomorrow morning" — the old engine closed it here

    $row->refresh();
    $record = OvertimeRecord::where('ot_request_id', $request->id)->first();

    expect($row->check_out)->toBeNull()
        ->and($row->missing_checkout)->toBeTrue()
        ->and($row->is_auto_checkout)->toBeFalse()
        ->and(AttendancePunch::where('source', 'system_auto')->count())->toBe(0)
        ->and((float) ($record?->ot_hours ?? 0))->toBe(0.0)
        ->and((float) ($record?->ot_amount ?? 0))->toBe(0.0);
});

test('approved OT plus a real OUT later settles to the actual overtime', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $request = OtRequest::create([
        'employee_id' => $e->id, 'attendance_id' => $row->id, 'work_date' => '2026-10-14', 'start_time' => '18:00', 'end_time' => '21:00',
        'requested_hours' => 3, 'reason' => 'Release', 'status' => 'pending',
    ]);
    app(OvertimeService::class)->approve($request, $admin->id);
    apoRun('2026-10-14 19:30:00');
    expect((float) OvertimeRecord::first()->ot_hours)->toBe(0.0);

    // The real ID Card OUT arrives late (a sync retry).
    AttendancePunch::factory()->create([
        'employee_id' => $e->id, 'punched_at' => '2026-10-14 20:35:00', 'punch_date' => '2026-10-14',
        'method' => 'id_card', 'direction' => 'out', 'source' => 'biometric',
    ]);
    app(AttendanceDayRebuilder::class)->rebuild($e, Carbon::parse('2026-10-14'));

    $row->refresh();
    $record = OvertimeRecord::where('ot_request_id', $request->id)->firstOrFail();

    expect($row->check_out->format('H:i'))->toBe('20:35')
        ->and($row->missing_checkout)->toBeFalse()
        ->and((float) $row->total_hours)->toBe(11.5)                 // 09:05 → 20:35
        ->and((float) $record->ot_hours)->toBe(2.5)                  // beyond the 9h threshold
        ->and((float) $record->ot_amount)->toBeGreaterThan(0.0);
});

test('an overnight shift is not closed at midnight — it waits for its own shift end + 1 hour', function () {
    $e = apoEmployee($this->night);
    $row = apoOpenDay($e, '2026-10-13', '22:05:00');

    apoRun('2026-10-14 00:30:00');   // past calendar midnight
    apoRun('2026-10-14 05:00:00');
    apoRun('2026-10-14 06:50:00');   // before 06:00 + 1h
    expect($row->fresh()->missing_checkout)->toBeFalse()
        ->and($row->fresh()->check_out)->toBeNull();

    apoRun('2026-10-14 07:10:00');

    expect($row->fresh()->missing_checkout)->toBeTrue()
        ->and($row->fresh()->check_out)->toBeNull()
        ->and(Attendance::count())->toBe(1)                          // no next-day row
        ->and(Attendance::first()->date->toDateString())->toBe('2026-10-13')
        ->and(app(Status::class)->forAttendance($row->fresh())['state'])->toBe(Status::MISSING_CHECKOUT);
});

test('a real overnight OUT before the cutoff is respected, never replaced', function () {
    $e = apoEmployee($this->night);
    $row = apoOpenDay($e, '2026-10-13', '22:05:00');
    AttendancePunch::factory()->create([
        'employee_id' => $e->id, 'punched_at' => '2026-10-14 05:55:00', 'punch_date' => '2026-10-13',
        'method' => 'id_card', 'direction' => 'out', 'source' => 'biometric',
    ]);
    app(AttendanceDayRebuilder::class)->rebuild($e, Carbon::parse('2026-10-13'));

    apoRun('2026-10-14 08:00:00');

    $fresh = $row->fresh();
    expect($fresh->check_out->format('Y-m-d H:i'))->toBe('2026-10-14 05:55')
        ->and($fresh->missing_checkout)->toBeFalse();
});

test('a weekly off with no attendance is left untouched — nothing is created', function () {
    $e = apoEmployee();

    apoRun('2026-10-17 23:30:00');   // Saturday

    expect(Attendance::count())->toBe(0)
        ->and(AttendancePunch::count())->toBe(0)
        ->and(apoNotifications($e))->toBe(0);
});

test('a genuine session on a weekly off with no OUT is Missing Checkout after the cutoff', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e, '2026-10-17', '10:30:00');

    apoRun('2026-10-17 18:30:00');
    expect($row->fresh()->missing_checkout)->toBeFalse();

    apoRun('2026-10-17 19:30:00');

    expect($row->fresh()->missing_checkout)->toBeTrue()
        ->and($row->fresh()->check_out)->toBeNull();
});

test('a holiday with no attendance is untouched, and a real open session follows the rule', function () {
    PublicHoliday::create(['name' => 'Founders Day', 'date' => '2026-10-15', 'country' => 'UK']);
    $quiet = apoEmployee();
    $worked = apoEmployee();
    $row = apoOpenDay($worked, '2026-10-15', '10:00:00');

    apoRun('2026-10-15 19:30:00');

    expect(Attendance::where('employee_id', $quiet->id)->count())->toBe(0)
        ->and($row->fresh()->missing_checkout)->toBeTrue()
        ->and($row->fresh()->check_out)->toBeNull();
});

test('a regularised day is never modified', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e, extra: ['is_regularized' => true, 'total_hours' => 4.0]);

    apoRun('2026-10-14 20:00:00');

    expect($row->fresh()->missing_checkout)->toBeFalse()
        ->and((float) $row->fresh()->total_hours)->toBe(4.0)
        ->and(apoNotifications($e))->toBe(0);
});

test('a day with an approved HR correction is never modified', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e);
    AttendanceRegularisation::create([
        'employee_id' => $e->id, 'attendance_id' => $row->id, 'work_date' => '2026-10-14', 'regularisation_type' => 'punch',
        'requested_check_in' => '2026-10-14 09:00:00', 'requested_check_out' => '2026-10-14 17:00:00', 'reason' => 'HR fix',
        'status' => 'approved', 'applied_via' => 'hr_fast_path',
    ]);

    apoRun('2026-10-14 20:00:00');

    expect($row->fresh()->missing_checkout)->toBeFalse()
        ->and(apoNotifications($e))->toBe(0);
});

test('a day inside a payroll with finance or finalised is never modified', function (string $status) {
    $e = apoEmployee();
    $row = apoOpenDay($e);
    $payroll = Payroll::create(['month' => 'October', 'year' => 2026, 'status' => $status, 'cycle' => 'cycle_a', 'total_payout' => 0]);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $e->id, 'gross_salary' => 0, 'total_deductions' => 0, 'net_salary' => 0]);

    apoRun('2026-10-14 20:00:00');

    expect($row->fresh()->missing_checkout)->toBeFalse()
        ->and(apoNotifications($e))->toBe(0);
})->with(['pending_finance', 'finalized']);

test('a locked payroll is never modified', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e);
    $payroll = Payroll::create(['month' => 'October', 'year' => 2026, 'status' => 'draft', 'cycle' => 'cycle_a', 'total_payout' => 0, 'locked_at' => now()]);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $e->id, 'gross_salary' => 0, 'total_deductions' => 0, 'net_salary' => 0]);

    apoRun('2026-10-14 20:00:00');

    expect($row->fresh()->missing_checkout)->toBeFalse();
});

test('repeated scheduler runs are idempotent — same data, one notification per employee and day', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $e = apoEmployee();
    $e->update(['manager_id' => $manager->id]);
    $row = apoOpenDay($e);

    apoRun('2026-10-14 19:10:00');
    $first = ['row' => $row->fresh()->only(['check_in', 'check_out', 'total_hours', 'missing_checkout', 'status']), 'punches' => AttendancePunch::count()];

    foreach (['19:20:00', '19:30:00', '21:00:00', '23:59:00'] as $time) {
        apoRun("2026-10-14 $time");
    }
    // The other command shares the same notification key.
    $this->artisan('hrms:flag-missing-checkouts')->assertSuccessful();

    expect(['row' => $row->fresh()->only(['check_in', 'check_out', 'total_hours', 'missing_checkout', 'status']), 'punches' => AttendancePunch::count()])->toEqual($first)
        ->and(apoNotifications($e))->toBe(1)
        ->and($manager->notifications()->where('type', MissingCheckoutNotification::class)->count())->toBe(1);
});

test('the sweep runs all day on the scheduler so a night shift is caught at its morning cutoff', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'hrms:auto-punch-out'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/10 * * * *');
});

test('a day the retired auto punch-out had closed reads as Missing Checkout and the rebuild command clears the fake OUT', function () {
    $e = apoEmployee();
    $row = apoOpenDay($e, '2026-10-13', '09:05:00', [
        'check_out' => '2026-10-13 18:00:00', 'total_hours' => 8.92, 'is_auto_checkout' => true,
        'auto_checkout_reason' => 'missing_punchout', 'missing_checkout' => true, 'check_out_method' => 'auto',
    ]);
    AttendancePunch::create([
        'employee_id' => $e->id, 'punched_at' => '2026-10-13 18:00:00', 'punch_date' => '2026-10-13',
        'method' => 'auto', 'direction' => 'out', 'verify_raw' => 'auto', 'source' => 'system_auto',
    ]);

    expect(app(Status::class)->forAttendance($row->fresh())['state'])->toBe(Status::MISSING_CHECKOUT);

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])->assertSuccessful();

    $row->refresh();
    expect($row->check_out)->toBeNull()
        ->and((float) $row->total_hours)->toBe(0.0)
        ->and($row->missing_checkout)->toBeTrue()
        ->and(AttendancePunch::where('source', 'system_auto')->count())->toBe(1);   // raw rows are never deleted
});
