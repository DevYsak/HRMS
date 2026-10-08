<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Services\Attendance\AttendanceDayRebuilder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * My Attendance (Oct 2026 redesign): header → 4 KPI cards → Today → Attention
 * (only when needed) → This Month → History → 2 trends → Quick actions. The
 * page shows canonical values only — worked = last OUT − first IN, breaks never
 * deducted, Face = IN / ID card = OUT — and repeats no metric.
 */
function myAttEmployee(): Employee
{
    $shift = ShiftSetting::create([
        'name' => 'Redesign Day', 'code' => 'RD_DAY', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);

    return Employee::factory()->create(['shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
}

function myAttPunch(Employee $employee, Carbon $day, string $time, string $method): void
{
    $at = $day->copy()->setTimeFromTimeString($time);
    AttendancePunch::create([
        'employee_id' => $employee->id, 'punched_at' => $at, 'punch_date' => $at->toDateString(),
        'method' => $method, 'source' => 'biometric',
    ]);
}

test('today shows canonical worked time with the break informational, never deducted', function () {
    $this->travelTo(Carbon::today()->setTime(20, 0));
    $employee = myAttEmployee();
    foreach ([['09:00', 'face'], ['13:00', 'id_card'], ['14:00', 'face'], ['18:00', 'id_card']] as [$t, $m]) {
        myAttPunch($employee, Carbon::today(), $t, $m);
    }
    // As a sync does: build the day from its punches.
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::today());

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('todayCalc.worked_minutes', 540)
        ->assertSee('9h 00m')                     // 18:00 − 09:00, the hour away not taken off
        ->assertSee('1h 00m')                     // the break, shown for information
        ->assertSee('Completed')
        ->assertSeeInOrder(['09:00 AM', 'Face • IN', '01:00 PM', 'ID Card • OUT', '02:00 PM', 'Face • IN', '06:00 PM', 'ID Card • OUT'])
        ->assertSee('9:00 AM – 6:00 PM')
        ->assertSee('No attendance issues today')
        ->assertDontSee('Attendance Needs Attention');
});

test('an open session reads as currently working, in the KPI and the history', function () {
    $this->travelTo(Carbon::today()->setTime(12, 0));
    $employee = myAttEmployee();
    myAttPunch($employee, Carbon::today(), '09:10', 'face');

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('Currently working')
        ->assertSee('Started 09:10 AM')
        ->assertSee('Working');
});

test('a past missing check-out is listed under Attention and in the history', function () {
    $this->travelTo(Carbon::parse('2026-07-20 10:00:00'));
    $employee = myAttEmployee();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => '2026-07-10',
        'check_in' => '2026-07-10 09:00:00', 'check_out' => null, 'status' => 'on_time', 'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('Attendance Needs Attention')
        ->assertSee('Missing Check-Out')
        ->assertSee('Request Regularisation')
        ->assertSee('Missing OUT');
});

test('a regularised day carries its marker in the history', function () {
    $this->travelTo(Carbon::parse('2026-07-20 10:00:00'));
    $employee = myAttEmployee();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => '2026-07-14',
        'check_in' => '2026-07-14 09:00:00', 'check_out' => '2026-07-14 18:00:00',
        'status' => 'on_time', 'work_mode' => 'office', 'is_regularized' => true,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-history-row="2026-07-14"')
        ->assertSee('Regularised');
});

test('the history never lists dates that have not happened yet', function () {
    $this->travelTo(Carbon::parse('2026-07-08 10:00:00'));
    $employee = myAttEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-history-row="2026-07-08"')
        ->assertSeeHtml('data-history-row="2026-07-01"')
        ->assertDontSeeHtml('data-history-row="2026-07-09"')
        ->assertDontSeeHtml('data-history-row="2026-07-31"');
});

test('one month selector moves the history and the figures together', function () {
    $this->travelTo(Carbon::parse('2026-07-20 10:00:00'));
    $employee = myAttEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('statsPeriod', 'this_month')
        ->call('historyPreviousMonth')
        ->assertSet('historyMonth', '2026-06')
        ->assertSet('statsPeriod', 'custom')
        ->assertSet('stats.from', '2026-06-01')
        ->assertSet('stats.to', '2026-06-30')
        ->assertSee('June 2026')
        ->call('historyNextMonth')
        ->assertSet('historyMonth', '2026-07')
        ->assertSet('statsPeriod', 'this_month')
        ->assertSet('stats.from', '2026-07-01');
});

test('the page shows each metric once and none of the removed panels', function () {
    $employee = myAttEmployee();
    $html = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)->html();

    expect(substr_count($html, 'Attendance Rate'))->toBe(1)
        ->and(substr_count($html, 'Worked Today'))->toBe(1)
        ->and(substr_count($html, '>First In</div>'))->toBe(1);   // the KPI card; History has its own column

    foreach (['Attendance Score', 'AI Attendance Coach', 'Attendance health', 'Shift Progress', 'Biometric Status',
        'Recent Activity', 'Working Hours Breakdown', 'Session summary', 'Punch In / Out Timeline', 'Predicted score'] as $removed) {
        expect($html)->not->toContain($removed);
    }
});

test('the weekly punctuality counts come from the month history statuses', function () {
    $this->travelTo(Carbon::parse('2026-07-08 12:00:00'));   // Wednesday
    $employee = myAttEmployee();
    foreach (['2026-07-01' => false, '2026-07-02' => true, '2026-07-06' => false] as $date => $late) {
        Attendance::create([
            'employee_id' => $employee->id, 'date' => $date,
            'check_in' => "{$date} ".($late ? '09:40:00' : '08:55:00'), 'check_out' => "{$date} 18:00:00",
            'status' => $late ? 'late' : 'on_time', 'is_late' => $late, 'work_mode' => 'office',
        ]);
    }

    $weeks = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)->instance()->monthPunctuality;

    // Week of 29 Jun (from 1 Jul): Wed present, Thu late, Fri absent. Week of 6 Jul: Mon present, Tue absent.
    expect($weeks)->toHaveCount(2)
        ->and($weeks[0])->toMatchArray(['present' => 1, 'late' => 1, 'absent' => 1])
        ->and($weeks[1])->toMatchArray(['present' => 1, 'late' => 0, 'absent' => 1]);
});
