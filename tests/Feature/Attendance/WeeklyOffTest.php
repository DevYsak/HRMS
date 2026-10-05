<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\TeamAttendance;
use App\Livewire\ManagerDashboard;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceMonthlySummary;
use App\Models\AttendanceSetting;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\LateArrivalNotification;
use App\Notifications\MissingCheckoutNotification;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\AttendanceReportBuilder;
use App\Services\AttendanceService;
use App\Services\Biometric\EngineAttendanceSyncService;
use App\Services\EmployeeDashboardService;
use App\Services\LeaveService;
use App\Services\LwpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Conexus weekly off: Saturday and Sunday (HR-confirmed). One resolver
 * decides it for attendance, absence jobs, leave, payroll, reports and
 * dashboards; work actually done on a weekly off is kept and shown as such.
 *
 * Calendar: Sat 3 Oct 2026, Sun 4 Oct, Mon 5 Oct, Fri 9 Oct, Mon 12 Oct.
 */
function woUser(UserRole $role = UserRole::Employee, array $employee = []): User
{
    $shift = ShiftSetting::firstOrCreate(['name' => 'IT Shift'], [
        'start_time' => '10:30:00', 'end_time' => '19:30:00', 'break_duration' => 60,
        'grace_minutes' => 5, 'standard_hours' => 8, 'ot_threshold_hours' => 9,
    ]);
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(array_merge([
        'user_id' => $user->id, 'status' => 'active', 'manager_id' => null,
        'joining_date' => '2024-01-08', 'shift_id' => $shift->id, 'holiday_calendar' => 'IN',
    ], $employee));

    return $user->fresh();
}

function woDays(): WorkingDayResolver
{
    return app(WorkingDayResolver::class);
}

beforeEach(function () {
    Notification::fake();
    // The settings row as production has it: nothing configured, so the
    // Conexus default applies.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '10:30', 'shift_end' => '19:30', 'weekly_off_days' => null]);
    LeaveType::firstOrCreate(['category' => 'unauthorized'], ['name' => 'Unauthorized Absence', 'code' => 'UNA', 'is_paid' => false]);
});

// ── 1–4. The classification ────────────────────────────────────────────────

test('Saturday and Sunday are the weekly off; Monday is a working day', function () {
    $employee = woUser()->employee;

    expect(AttendanceSetting::weeklyOffDays())->toEqualCanonicalizing([Carbon::SATURDAY, Carbon::SUNDAY])
        ->and(woDays()->classify($employee, Carbon::parse('2026-10-03')))->toBe(WorkingDayResolver::WEEKLY_OFF)
        ->and(woDays()->classify($employee, Carbon::parse('2026-10-04')))->toBe(WorkingDayResolver::WEEKLY_OFF)
        ->and(woDays()->classify($employee, Carbon::parse('2026-10-05')))->toBe(WorkingDayResolver::WORKING_DAY);
});

test('a Saturday or Sunday with no punch reads Weekly Off, never Absent', function (string $date) {
    $this->travelTo(Carbon::parse("{$date} 21:00"));
    $user = woUser();

    $today = app(EmployeeDashboardService::class)->build($user)['today'];

    expect($today['state'])->toBe('weekly_off')
        ->and($today['state'])->not->toBe('absent')
        ->and($today['is_working_day'])->toBeFalse();
})->with(['saturday' => '2026-10-03', 'sunday' => '2026-10-04']);

test('the employee classification knows joining, leaving, holidays, MDL and leave', function () {
    $employee = woUser(employee: ['joining_date' => '2026-10-07'])->employee;

    expect(woDays()->classify($employee, Carbon::parse('2026-10-05')))->toBe(WorkingDayResolver::EMPLOYMENT_NOT_STARTED)
        ->and(woDays()->classify($employee, Carbon::parse('2026-10-08')))->toBe(WorkingDayResolver::WORKING_DAY)
        ->and(woDays()->classify($employee, Carbon::parse('2026-12-28')))->toBe(WorkingDayResolver::WORKING_DAY);

    DecemberMandatoryDay::create(['year' => 2026, 'date' => '2026-12-28', 'description' => 'Shutdown']);
    expect(woDays()->classify($employee, Carbon::parse('2026-12-28')))->toBe(WorkingDayResolver::MDL_SHUTDOWN);
});

// ── 5–6. Absence automation and LWP ────────────────────────────────────────

test('no unauthorized absence and no LWP is created for a weekend', function () {
    $employee = woUser()->employee;

    $this->artisan('hrms:flag-unauthorized-absences', ['--date' => '2026-10-03'])->assertSuccessful();
    $this->artisan('hrms:flag-unauthorized-absences', ['--date' => '2026-10-04'])->assertSuccessful();

    $lwp = app(LwpService::class)->calculate($employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-04'), 30000);

    expect(LeaveRequest::where('employee_id', $employee->id)->count())->toBe(0)
        ->and($lwp['days'])->toEqual(0.0);

    // A weekday with no punch is still an absence (the rule did not go away).
    $this->artisan('hrms:flag-unauthorized-absences', ['--date' => '2026-10-02'])->assertSuccessful();
    expect(LeaveRequest::where('employee_id', $employee->id)->count())->toBe(1);
});

// ── 7–8. Notifications ─────────────────────────────────────────────────────

test('no missing-checkout alert on a weekly off', function () {
    $this->travelTo(Carbon::parse('2026-10-03 23:10'));
    $employee = woUser()->employee;
    $row = Attendance::create(['employee_id' => $employee->id, 'date' => '2026-10-03', 'check_in' => '2026-10-03 11:00:00', 'status' => 'on_time']);

    $this->artisan('hrms:flag-missing-checkouts')->assertSuccessful();

    expect($row->fresh()->missing_checkout)->toBeFalse();
    Notification::assertNotSentTo($employee->user, MissingCheckoutNotification::class);
});

test('no late flag and no late alert on a weekly off', function () {
    $this->travelTo(Carbon::parse('2026-10-03 11:30'));
    $manager = woUser(UserRole::Manager);
    $employee = woUser(employee: ['manager_id' => $manager->id])->employee;

    $row = app(AttendanceService::class)->checkIn($employee, $employee->shift, ['ip' => '127.0.0.1']);
    $this->artisan('hrms:check-late-arrivals')->assertSuccessful();

    expect($row->fresh()->is_late)->toBeFalse()
        ->and((int) $row->fresh()->late_minutes)->toBe(0);
    Notification::assertNotSentTo($manager, LateArrivalNotification::class);
});

// ── 9. Monthly denominator ─────────────────────────────────────────────────

test('weekends are excluded from the monthly scheduled-day count, and a rerun replaces the row', function () {
    $employee = woUser()->employee;
    Attendance::create(['employee_id' => $employee->id, 'date' => '2026-09-12', 'check_in' => '2026-09-12 11:00:00', 'check_out' => '2026-09-12 15:00:00', 'status' => 'on_time']);

    $this->artisan('hrms:generate-attendance-summary', ['--month' => '2026-09'])->assertSuccessful();
    $this->artisan('hrms:generate-attendance-summary', ['--month' => '2026-09'])->assertSuccessful();

    $summary = AttendanceMonthlySummary::where('employee_id', $employee->id)->sole();
    // September 2026: 30 days, 8 of them Saturdays/Sundays.
    expect($summary->scheduled_days)->toBe(22)
        ->and($summary->weekly_off_days)->toBe(8)
        ->and($summary->weekly_off_worked_days)->toBe(1);
});

// ── 10. Leave day counting ─────────────────────────────────────────────────

test('leave Friday to Monday consumes exactly two working days', function () {
    expect(app(LeaveService::class)->calculateLeaveDays(Carbon::parse('2026-10-09'), Carbon::parse('2026-10-12')))->toEqual(2.0)
        ->and(app(LeaveService::class)->calculateLeaveDays(Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11')))->toEqual(5.0);
});

// ── 11–13. Work actually done on a weekly off ──────────────────────────────

test('weekend work is kept as it happened and shown as Worked on Weekly Off', function () {
    $this->travelTo(Carbon::parse('2026-10-03 11:00'));
    $employee = woUser()->employee;

    $row = app(AttendanceService::class)->checkIn($employee, $employee->shift, ['ip' => '127.0.0.1', 'work_mode' => 'office']);
    $this->travelTo(Carbon::parse('2026-10-03 16:00'));
    app(AttendanceService::class)->checkOut($row->fresh(), ['ip' => '127.0.0.1']);

    $row = $row->fresh();
    expect($row->check_in->format('H:i'))->toBe('11:00')
        ->and($row->check_out->format('H:i'))->toBe('16:00')
        ->and($row->work_mode)->toBe('office')
        ->and($row->weeklyOffLabel())->toBe('Worked on Weekly Off');
});

test('weekend work never becomes paid overtime on its own', function () {
    $this->travelTo(Carbon::parse('2026-10-03 09:00'));
    $employee = woUser()->employee;
    $row = app(AttendanceService::class)->checkIn($employee, $employee->shift, ['ip' => '127.0.0.1']);
    $this->travelTo(Carbon::parse('2026-10-03 21:00'));
    app(AttendanceService::class)->checkOut($row->fresh(), ['ip' => '127.0.0.1']);

    expect(OtRequest::where('employee_id', $employee->id)->where('status', 'approved')->exists())->toBeFalse()
        ->and(OvertimeRecord::where('employee_id', $employee->id)->whereNotNull('payslip_id')->exists())->toBeFalse();
});

test('biometric sync stores weekend attendance, not late, and an empty weekend as weekly off', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    $worked = woUser(employee: ['employee_code' => 9101])->employee;
    woUser(employee: ['employee_code' => 9102]);
    Http::fake(['*/api/dashboard*' => Http::response(['table' => [
        ['emp_id' => 9101, 'first_punch' => '11:40:00', 'last_punch' => '15:00:00', 'working_min' => 200, 'break_min' => 0, 'late' => true, 'delay_min' => 65, 'punch_count' => 2],
        ['emp_id' => 9102, 'punch_count' => 0],
    ]], 200)]);

    app(EngineAttendanceSyncService::class)->syncDate('2026-10-03');

    $row = Attendance::where('employee_id', $worked->id)->whereDate('date', '2026-10-03')->sole();
    expect($row->check_in->format('H:i'))->toBe('11:40')
        ->and($row->is_late)->toBeFalse()
        ->and(AttendanceDailySummary::whereDate('date', '2026-10-03')->where('employee_code', 9102)->value('status'))->toBe('weekly_off');
});

// ── 14–15. Overlaps ────────────────────────────────────────────────────────

test('a holiday or MDL date on a weekend is one non-working day, never counted twice', function () {
    $employee = woUser()->employee;
    PublicHoliday::factory()->create(['date' => '2026-09-19', 'name' => 'Saturday holiday', 'is_active' => true,
        'country' => app(HolidayResolver::class)->resolveCountry($employee)]);
    DecemberMandatoryDay::create(['year' => 2026, 'date' => '2026-12-26', 'description' => 'Shutdown (a Saturday)']);
    DecemberMandatoryDay::create(['year' => 2026, 'date' => '2026-12-28', 'description' => 'Shutdown (a Monday)']);

    expect(woDays()->classify($employee, Carbon::parse('2026-09-19')))->toBe(WorkingDayResolver::PUBLIC_HOLIDAY)
        ->and(woDays()->scheduledDaysBetween($employee, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))->toBe(22)
        ->and(woDays()->classify($employee, Carbon::parse('2026-12-26')))->toBe(WorkingDayResolver::MDL_SHUTDOWN)
        // December 2026: 23 weekdays, one of them (28th) an MDL date.
        ->and(woDays()->scheduledDaysBetween($employee, Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31')))->toBe(22);
});

// ── 16. Reruns ─────────────────────────────────────────────────────────────

test('absence flagging reruns create nothing twice', function () {
    $employee = woUser()->employee;

    $this->artisan('hrms:flag-unauthorized-absences', ['--date' => '2026-10-02'])->assertSuccessful();
    $this->artisan('hrms:flag-unauthorized-absences', ['--date' => '2026-10-02'])->assertSuccessful();
    $this->artisan('hrms:flag-unauthorized-absences', ['--date' => '2026-10-03'])->assertSuccessful();

    expect(LeaveRequest::where('employee_id', $employee->id)->count())->toBe(1);
});

// ── 17. Every screen agrees ────────────────────────────────────────────────

test('the attendance screens agree on Weekly Off and Worked on Weekly Off', function () {
    $this->travelTo(Carbon::parse('2026-10-03 12:00'));
    $hr = woUser(UserRole::HrAdmin);
    $manager = woUser(UserRole::Manager);
    $worker = woUser(employee: ['manager_id' => $manager->id]);
    woUser(employee: ['manager_id' => $manager->id]);
    Attendance::create(['employee_id' => $worker->employee->id, 'date' => '2026-10-03', 'check_in' => '2026-10-03 11:00:00', 'status' => 'on_time']);

    Livewire::actingAs($hr)->test(AllAttendance::class)->set('date', '2026-10-03')
        ->assertSee('Worked on Weekly Off')
        ->assertViewHas('stats', fn ($s) => $s['absent'] === 0 && $s['weekly_off_worked'] === 1);
    Livewire::actingAs($manager)->test(TeamAttendance::class)
        ->assertSee('Weekly Off')
        ->assertSee('Worked on Weekly Off')
        ->assertViewHas('boardStats', fn ($s) => $s['absent'] === 0 && $s['late'] === 0);
    Livewire::actingAs($manager)->test(ManagerDashboard::class)
        ->assertViewHas('absentCount', 0)
        ->assertSee('WEEKLY OFF');

    $report = app(AttendanceReportBuilder::class)->build('daily', ['from' => '2026-10-03', 'to' => '2026-10-03']);
    expect(collect($report['rows'])->pluck(7)->all())->toBe(['Worked on Weekly Off'])
        ->and(app(EmployeeDashboardService::class)->build($worker)['today']['state'])->not->toBe('absent');
});
