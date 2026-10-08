<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\ShiftSetting;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\OvertimeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Nightly auto checkout (hrms:auto-checkout): after 11 PM IST a day with a
 * valid IN and no final OUT is closed at the employee's assigned shift end —
 * on the attendance row only, audited, never as a punch, never as overtime —
 * and a later genuine OUT replaces it.
 */
function acShift(string $start, string $end, string $code): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'Shift '.$code, 'code' => $code, 'start_time' => $start, 'end_time' => $end,
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);
}

function acEmployee(ShiftSetting $shift): Employee
{
    return Employee::factory()->create(['shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
}

/** Punches for the day, built into the attendance row the way a sync does. */
function acPunches(Employee $employee, string $date, array $punches): ?Attendance
{
    foreach ($punches as $time => $method) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => "{$date} {$time}", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric',
        ]);
    }
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse($date));

    return Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
}

test('a normal shift with no final OUT is closed at its shift end after 11 PM', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    acPunches($employee, '2026-07-14', ['10:25:00' => 'face']);
    $punches = AttendancePunch::count();

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    $row = Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-07-14')->first();
    expect($row->check_out->format('Y-m-d H:i'))->toBe('2026-07-14 19:30')
        ->and($row->is_auto_checkout)->toBeTrue()
        ->and($row->auto_checkout_reason)->toBe(Attendance::AUTO_CHECKOUT_REASON)
        ->and($row->missing_checkout)->toBeFalse()
        ->and((float) $row->total_hours)->toBe(9.08)            // 10:25 → 19:30, breaks never deducted
        ->and(AttendancePunch::count())->toBe($punches)          // no OUT punch invented
        ->and(AuditLog::where('event', 'ATTENDANCE_AUTO_CHECKOUT')->where('auditable_id', $row->id)->count())->toBe(1);

    $day = app(AttendanceCalculator::class)->forAttendance($row);
    expect($day->source)->toBe(AttendanceCalculator::SOURCE_AUTO_CHECKOUT)
        ->and($day->lastOut->format('H:i'))->toBe('19:30')
        ->and($day->missingCheckout)->toBeFalse();
});

test('a custom shift closes at its own shift end, never a hardcoded time', function () {
    $employee = acEmployee(acShift('13:00:00', '22:00:00', 'AC_LATE'));
    $this->travelTo(Carbon::parse('2026-07-14 14:00:00'));
    acPunches($employee, '2026-07-14', ['13:10:00' => 'face']);

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    expect(Attendance::where('employee_id', $employee->id)->first()->check_out->format('H:i'))->toBe('22:00');
});

test('a night shift is closed the following night, at its morning shift end', function () {
    $employee = acEmployee(acShift('22:00:00', '07:00:00', 'AC_NIGHT'));
    $this->travelTo(Carbon::parse('2026-07-14 22:30:00'));
    acPunches($employee, '2026-07-14', ['22:05:00' => 'face']);

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();
    expect(Attendance::where('employee_id', $employee->id)->first()->check_out)->toBeNull();   // shift not ended

    $this->travelTo(Carbon::parse('2026-07-15 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();
    expect(Attendance::where('employee_id', $employee->id)->first()->check_out->format('Y-m-d H:i'))->toBe('2026-07-15 07:00');
});

test('a day that already has a final OUT is left exactly as it is', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 20:00:00'));
    acPunches($employee, '2026-07-14', ['10:25:00' => 'face', '19:41:00' => 'id_card']);

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    $row = Attendance::where('employee_id', $employee->id)->first();
    expect($row->check_out->format('H:i'))->toBe('19:41')
        ->and($row->is_auto_checkout)->toBeFalse()
        ->and(AuditLog::where('event', 'ATTENDANCE_AUTO_CHECKOUT')->exists())->toBeFalse();
});

test('no attendance is created for an employee with no IN', function () {
    acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    expect(Attendance::count())->toBe(0);
});

test('nothing is closed before 11 PM', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    acPunches($employee, '2026-07-14', ['10:25:00' => 'face']);

    $this->travelTo(Carbon::parse('2026-07-14 22:40:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    expect(Attendance::where('employee_id', $employee->id)->first()->check_out)->toBeNull();
});

test('running it again changes nothing and writes no second audit', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    acPunches($employee, '2026-07-14', ['10:25:00' => 'face']);

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();
    $first = Attendance::where('employee_id', $employee->id)->first()->only(['check_out', 'total_hours', 'is_auto_checkout', 'updated_at']);

    $this->travelTo(Carbon::parse('2026-07-14 23:50:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    expect(Attendance::where('employee_id', $employee->id)->first()->only(['check_out', 'total_hours', 'is_auto_checkout', 'updated_at']))->toEqual($first)
        ->and(Attendance::count())->toBe(1)
        ->and(AuditLog::where('event', 'ATTENDANCE_AUTO_CHECKOUT')->count())->toBe(1);
});

test('the next sync keeps the auto checkout, and a later genuine OUT replaces it', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    acPunches($employee, '2026-07-14', ['10:25:00' => 'face']);
    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    // A sync with nothing new must not undo it.
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse('2026-07-14'));
    expect(Attendance::where('employee_id', $employee->id)->first()->is_auto_checkout)->toBeTrue();

    // The device delivers the real OUT late.
    $this->travelTo(Carbon::parse('2026-07-15 09:00:00'));
    $row = acPunches($employee, '2026-07-14', ['20:15:00' => 'id_card']);

    expect($row->check_out->format('H:i'))->toBe('20:15')
        ->and($row->is_auto_checkout)->toBeFalse()
        ->and($row->auto_checkout_reason)->toBeNull()
        ->and((float) $row->total_hours)->toBe(9.83)          // 10:25 → 20:15
        ->and(AuditLog::where('event', 'ATTENDANCE_AUTO_CHECKOUT_SUPERSEDED')->where('auditable_id', $row->id)->exists())->toBeTrue()
        ->and(app(AttendanceCalculator::class)->forAttendance($row)->source)->not->toBe(AttendanceCalculator::SOURCE_AUTO_CHECKOUT);
});

test('an auto checkout never creates payable overtime, even with an approved OT request', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    acPunches($employee, '2026-07-14', ['08:00:00' => 'face']);   // 11h30m to the shift end
    $request = OtRequest::factory()->create(['employee_id' => $employee->id, 'work_date' => '2026-07-14', 'status' => 'approved', 'start_time' => '19:30', 'end_time' => '21:30', 'requested_hours' => 2]);

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    $row = Attendance::where('employee_id', $employee->id)->first();
    $day = app(AttendanceCalculator::class)->forAttendance($row);

    expect($row->is_auto_checkout)->toBeTrue()
        ->and($day->approvedOtMinutes)->toBe(0)
        ->and($day->beyondShiftMinutes)->toBe(0)
        ->and(app(OvertimeService::class)->calculateOtHours($request->fresh()))->toBe(0.0)
        ->and(OvertimeRecord::where('employee_id', $employee->id)->where('ot_hours', '>', 0)->exists())->toBeFalse();
});

test('a regularised day is never auto checked out', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    $row = acPunches($employee, '2026-07-14', ['10:25:00' => 'face']);
    $row->update(['is_regularized' => true, 'original_check_in' => '2026-07-14 10:25:00']);

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    expect($row->fresh()->check_out)->toBeNull()->and($row->fresh()->is_auto_checkout)->toBeFalse();
});

test('My Attendance shows Auto Checkout, not Missing OUT, and explains why', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    acPunches($employee, '2026-07-14', ['10:25:00' => 'face']);
    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    // The same night: the Today card.
    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('Auto Checkout')
        ->assertSee('07:30 PM')
        ->assertDontSee('Missing Checkout')
        ->assertDontSee('Missing OUT');

    // The next day: the history row and its "Why?".
    $this->travelTo(Carbon::parse('2026-07-15 10:00:00'));
    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-history-row="2026-07-14"')
        ->assertSee('Auto Checkout')
        ->assertDontSee('Missing OUT')
        ->call('showScoreDecision', '2026-07-14')
        ->assertSet('decision.auto_checkout_note', 'No final checkout was recorded. The system closed the day at your scheduled shift end.')
        ->assertSee('No final checkout was recorded. The system closed the day at your scheduled shift end.');
});

test('a dry run reports without changing anything', function () {
    $employee = acEmployee(acShift('10:30:00', '19:30:00', 'AC_IT'));
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    acPunches($employee, '2026-07-14', ['10:25:00' => 'face']);

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout', ['--dry-run' => true])
        ->expectsOutputToContain('1 would be closed')
        ->assertSuccessful();

    expect(Attendance::where('employee_id', $employee->id)->first()->check_out)->toBeNull();
});

test('the scheduler runs the auto checkout after 11 PM IST', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'hrms:auto-checkout'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15,45 23 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Kolkata');
});
