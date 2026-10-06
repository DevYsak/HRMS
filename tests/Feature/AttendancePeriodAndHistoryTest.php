<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Attendance\WorkingDayResolver;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * My Attendance: periods resolve to the right dates, comparisons use a
 * window of the same length, weekends / holidays / leave are never
 * absences, a mode filter never invents absences, and the month-by-month
 * history shows every day with one status.
 *
 * Saturday and Sunday are the weekly off (AttendanceSetting default).
 */
function aphEmployee(): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

function aphDay(Employee $employee, string $date, array $attributes = []): Attendance
{
    return Attendance::create($attributes + [
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => "$date 09:00:00", 'check_out' => "$date 18:00:00",
        'status' => 'on_time', 'work_mode' => 'office', 'break_minutes' => 30,
    ]);
}

function aphLeave(Employee $employee, string $date, bool $halfDay = false): LeaveRequest
{
    $type = LeaveType::firstOrCreate(['code' => 'APH'], ['name' => 'Test Leave', 'category' => 'other', 'color' => '#999999', 'allow_paid_request' => true]);

    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $date, 'end_date' => $date,
        'days' => $halfDay ? 0.5 : 1, 'is_half_day' => $halfDay, 'reason' => 'Test', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);
}

// ── Period ranges ──────────────────────────────────────────────────────────

test('last month on the 31st is the previous month, not the current one', function () {
    $this->travelTo(Carbon::parse('2026-03-31 10:00:00'));
    $employee = aphEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('statsPeriod', 'last_month')
        ->assertSet('stats.from', '2026-02-01')
        ->assertSet('stats.to', '2026-02-28');
});

test('each period resolves to its own dates, counted up to today', function (string $period, string $from, string $to) {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00')); // Wednesday
    $employee = aphEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('statsPeriod', $period)
        ->assertSet('stats.from', $from)
        ->assertSet('stats.to', $to);
})->with([
    'today' => ['today', '2026-10-14', '2026-10-14'],
    'this week (Sun–Sat)' => ['this_week', '2026-10-11', '2026-10-14'],
    'this month' => ['this_month', '2026-10-01', '2026-10-14'],
    'quarter' => ['quarter', '2026-10-01', '2026-10-14'],
    'three months' => ['3_months', '2026-08-01', '2026-10-14'],
    'year' => ['year', '2026-01-01', '2026-10-14'],
]);

test('a reversed custom range is put in order', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    Livewire::actingAs(aphEmployee()->user)->test(AttendanceTracker::class)
        ->set('rangeFrom', '2026-09-30')
        ->set('rangeTo', '2026-09-01')
        ->assertSet('stats.from', '2026-09-01')
        ->assertSet('stats.to', '2026-09-30');
});

test('the comparison window is as long as the current one', function (string $mode, string $from, string $to) {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    Livewire::actingAs(aphEmployee()->user)->test(AttendanceTracker::class)
        ->set('compareMode', $mode)
        ->assertSet('comparison.from', $from)
        ->assertSet('comparison.to', $to);
})->with([
    // Month to date (1–14 Oct, 14 days) against the 14 days before it…
    'previous period' => ['prev_period', '2026-09-17', '2026-09-30'],
    // …or the same days of last month / last year.
    'last month' => ['last_month', '2026-09-01', '2026-09-14'],
    'last year' => ['last_year', '2025-10-01', '2025-10-14'],
]);

// ── What counts as absent ──────────────────────────────────────────────────

test('weekends, holidays and leave are never absences; past empty working days are', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $employee = aphEmployee();
    PublicHoliday::create(['name' => 'Company Day', 'date' => '2026-10-05', 'country' => 'UK']);
    aphLeave($employee, '2026-10-06');

    // 1–13 Oct: working days are 1, 2, 5–9, 12, 13 (9). Minus the holiday
    // (5th) and the leave (6th) leaves 7 scheduled days, all absent; today
    // is still open, so it is neither scheduled nor absent yet.
    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('stats.absent', 7)
        ->assertSet('stats.leaves', 1.0)
        ->assertSet('stats.scheduled', 7);
});

test('a mode filter never turns another mode\'s day into an absence', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $employee = aphEmployee();
    foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13'] as $date) {
        aphDay($employee, $date);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('stats.absent', 0)
        ->assertSet('stats.present', 9)
        ->set('analyticsMode', 'wfh')
        ->assertSet('stats.present', 0)
        ->assertSet('stats.absent', 0);
});

test('weekend work is Worked on Weekly Off, not present and not absent', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $employee = aphEmployee();
    aphDay($employee, '2026-10-10'); // Saturday

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('stats.weekly_off_worked', 1)
        ->assertSet('stats.present', 0);
});

test('today is not shown as absent before clock-in', function () {
    $this->travelTo(Carbon::parse('2026-10-14 08:00:00'));
    $employee = aphEmployee();

    $day = collect(Livewire::actingAs($employee->user)->test(AttendanceTracker::class)->get('calendarDays'))
        ->firstWhere('date', '2026-10-14');

    expect($day['status'])->not->toBe('absent');
});

test('a leave span over a weekend shows the weekend as weekly off', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $employee = aphEmployee();
    $type = LeaveType::firstOrCreate(['code' => 'APH'], ['name' => 'Test Leave', 'category' => 'other', 'color' => '#999999', 'allow_paid_request' => true]);
    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-09', 'end_date' => '2026-10-12',
        'days' => 2, 'reason' => 'Long weekend', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);

    $days = collect(Livewire::actingAs($employee->user)->test(AttendanceTracker::class)->get('calendarDays'))->keyBy('date');

    expect($days['2026-10-09']['status'])->toBe('leave')
        ->and($days['2026-10-10']['status'])->toBe('weekly_off')
        ->and($days['2026-10-11']['status'])->toBe('weekly_off')
        ->and($days['2026-10-12']['status'])->toBe('leave');
});

test('bulk classification matches the single-day classification', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $employee = aphEmployee();
    PublicHoliday::create(['name' => 'Company Day', 'date' => '2026-10-05', 'country' => 'UK']);
    aphLeave($employee, '2026-10-06');
    $resolver = app(WorkingDayResolver::class);

    $bulk = $resolver->classifyRange($employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    foreach ($bulk as $date => $day) {
        expect($day['state'])->toBe($resolver->classify($employee, Carbon::parse($date)), $date);
    }
});

// ── Month-by-month history ─────────────────────────────────────────────────

test('the month history shows every day with one status and the month totals', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $employee = aphEmployee();
    PublicHoliday::create(['name' => 'Company Day', 'date' => '2026-10-05', 'country' => 'UK']);
    aphLeave($employee, '2026-10-06');
    aphDay($employee, '2026-10-01');
    aphDay($employee, '2026-10-02', ['is_late' => true, 'status' => 'late', 'late_minutes' => 12]);
    aphDay($employee, '2026-10-07', ['work_mode' => 'wfh']);
    aphDay($employee, '2026-10-08', ['status' => 'half_day']);
    aphDay($employee, '2026-10-09', ['check_out' => null]);
    aphDay($employee, '2026-10-10'); // Saturday
    OtRequest::create(['employee_id' => $employee->id, 'work_date' => '2026-10-01', 'start_time' => '18:00', 'end_time' => '20:00', 'requested_hours' => 2, 'reason' => 'Release', 'status' => 'approved']);

    $history = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('historyMonth', '2026-10')
        ->get('monthHistory');
    $rows = collect($history['rows'])->keyBy('date');

    expect($rows)->toHaveCount(31)
        ->and($rows['2026-10-01']['status'])->toBe('Present')
        ->and($rows['2026-10-01']['ot_hours'])->toBe(2.0)
        ->and($rows['2026-10-02']['status'])->toBe('Late')
        ->and($rows['2026-10-03']['status'])->toBe('Weekly Off')
        ->and($rows['2026-10-05']['status'])->toBe('Holiday')
        ->and($rows['2026-10-06']['status'])->toBe('Leave')
        ->and($rows['2026-10-07']['status'])->toBe('WFH')
        ->and($rows['2026-10-08']['status'])->toBe('Half day')
        ->and($rows['2026-10-09']['missing_checkout'])->toBeTrue()
        ->and($rows['2026-10-10']['status'])->toBe('Worked on Weekly Off')
        ->and($rows['2026-10-12']['status'])->toBe('Absent')
        ->and($rows['2026-10-14']['status'])->toBe('Today')
        ->and($rows['2026-10-20']['status'])->toBe('Upcoming')
        ->and($history['totals']['absent'])->toBe(2)
        ->and($history['totals']['leave'])->toBe(1.0)
        ->and($history['totals']['worked_off'])->toBe(1)
        ->and($history['totals']['ot_hours'])->toBe(2.0);
});

test('history navigation moves month by month and never into the future', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    Livewire::actingAs(aphEmployee()->user)->test(AttendanceTracker::class)
        ->set('historyMonth', '2026-10')
        ->call('historyPreviousMonth')->assertSet('historyMonth', '2026-09')
        ->call('historyNextMonth')->assertSet('historyMonth', '2026-10')
        ->call('historyNextMonth')->assertSet('historyMonth', '2026-10')
        ->call('setHistoryMonth', 3)->assertSet('historyMonth', '2026-03')
        ->call('setHistoryYear', 2025)->assertSet('historyMonth', '2025-03')
        ->call('setHistoryYear', 2027)->assertSet('historyMonth', '2026-10')
        ->assertSee('Monthly history');
});
