<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * My Attendance — Today Activity timeline, live clock, the Current Status
 * card, the 30-second refresh and the Attendance History filters. Everything
 * is read from the canonical services (AttendanceCalculator, PunchTimeline,
 * WorkingDayResolver); the page only arranges it.
 */
function tactEmployee(): Employee
{
    $shift = ShiftSetting::create([
        'name' => 'Activity Day', 'code' => 'TACT_DAY', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);

    return Employee::factory()->create(['shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
}

/** @param  array<int, array{0: string, 1: string}>  $punches  [time, method] */
function tactPunches(Employee $employee, string $date, array $punches): ?Attendance
{
    foreach ($punches as [$time, $method]) {
        $at = Carbon::parse("{$date} {$time}");
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => $at, 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric',
        ]);
    }
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse($date));

    return Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
}

function tactLeave(Employee $employee, string $date): LeaveRequest
{
    $type = LeaveType::firstOrCreate(['code' => 'TACT'], ['name' => 'Test Leave', 'category' => 'other', 'color' => '#999999', 'allow_paid_request' => true]);

    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $date, 'end_date' => $date,
        'days' => 1, 'is_half_day' => false, 'reason' => 'Test', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);
}

beforeEach(function () {
    // Monday 12 Oct 2026 — a working day for the default Mon–Fri week.
    $this->travelTo(Carbon::parse('2026-10-12 12:00:00'));
});

test('no activity today hides the Today Activity section', function () {
    $employee = tactEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertDontSeeHtml('data-today-activity')
        ->assertDontSeeHtml('data-live-clock')
        ->assertSeeInOrder(['Current Status', 'Not Clocked In']);
});

test('a Face IN only starts the live clock from that IN', function () {
    $employee = tactEmployee();
    tactPunches($employee, '2026-10-12', [['09:10:00', 'face']]);

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-activity-state="working"')
        ->assertSeeHtml('data-live-clock')
        ->assertSeeHtml('data-clock-start="'.Carbon::parse('2026-10-12 09:10:00')->getTimestampMs().'"')
        ->assertSee('Currently working since 09:10 AM')
        ->assertSeeHtml('title="Running since your last IN"')
        ->assertSee('02:50:00')                    // server-rendered first frame (12:00 − 09:10)
        ->assertSeeHtml('data-activity-event="in"')
        ->assertSeeInOrder(['09:10 AM', 'Face IN', 'IN'])
        ->assertSeeInOrder(['Current Status', 'At Work', 'Since 09:10 AM']);

    // The tooltip carries the event, exact time, source, effective direction and status.
    $component->assertSeeInOrder(['Event', 'Face Recognition', 'Time', '09:10:00 AM', 'Effective event', 'IN', 'Source', 'Biometric Face', 'Status', 'Late']);

    // The clock is visual only: no per-second live region.
    expect($component->html())->not->toMatch('/data-live-clock[^>]*aria-live/');
});

test('IN then OUT stops the clock and reads as Checked Out', function () {
    $this->travelTo(Carbon::parse('2026-10-12 19:30:00'));
    $employee = tactEmployee();
    tactPunches($employee, '2026-10-12', [['09:00:00', 'face'], ['18:00:00', 'id_card']]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-activity-state="out"')
        ->assertDontSeeHtml('data-live-clock')
        ->assertSeeInOrder(['09:00 AM', 'Face IN', '06:00 PM', 'ID Card OUT'])
        ->assertSeeInOrder(['Current Status', 'Checked Out', 'At 06:00 PM']);
});

test('break start and break end are both on the timeline', function () {
    $employee = tactEmployee();
    $row = tactPunches($employee, '2026-10-12', [['09:00:00', 'face']]);
    $row->breakLogs()->create(['employee_id' => $employee->id, 'break_start' => '2026-10-12 10:30:00', 'break_end' => '2026-10-12 10:45:00', 'duration_minutes' => 15]);
    $row->breakLogs()->create(['employee_id' => $employee->id, 'break_start' => '2026-10-12 11:30:00']);

    $html = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-activity-event="break_start"')
        ->assertSeeHtml('data-activity-event="break_end"')
        ->assertSeeInOrder(['09:00 AM', 'Face IN', '10:30 AM', 'Break Start', '10:45 AM', 'Break End', '11:30 AM', 'Break Start'])
        // On a running break the clock stops and the status says so.
        ->assertSeeHtml('data-activity-state="break"')
        ->assertDontSeeHtml('data-live-clock')
        ->assertSeeInOrder(['Current Status', 'On Break', 'Since 11:30 AM'])
        ->html();

    expect(substr_count($html, 'data-activity-event="break_start"'))->toBe(2)
        ->and(substr_count($html, 'data-activity-event="break_end"'))->toBe(1);
});

test('an approved regularisation shows as a purple Regularised node with its details', function () {
    $this->travelTo(Carbon::parse('2026-10-12 20:00:00'));
    $employee = tactEmployee();
    $reviewer = User::factory()->create(['role' => 'super_admin', 'name' => 'Rhea Reviewer']);
    $regularisation = AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'work_date' => '2026-10-12',
        'requested_check_in' => '2026-10-12 09:00:00', 'requested_check_out' => '2026-10-12 18:00:00',
        'check_in_method' => 'face', 'check_out_method' => 'id_card',
        'reason' => 'Device was offline in the morning', 'status' => 'pending',
    ]);
    app(AttendanceService::class)->approveRegularisation($regularisation, $reviewer->id);

    $html = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-activity-event="regularised"')
        ->assertSeeInOrder(['09:00 AM', 'Regularised IN', 'REG', '06:00 PM', 'Regularised OUT', 'REG'])
        ->assertSee('Manually corrected attendance entry')
        ->assertSee('Rhea Reviewer')
        ->assertSee('Device was offline in the morning')
        ->html();

    expect($html)->toMatch('/data-activity-event="regularised".*?bg-violet-500/s');
});

test('an auto checkout is labelled and never runs the clock', function () {
    $this->travelTo(Carbon::parse('2026-10-12 21:00:00'));
    $employee = tactEmployee();
    tactPunches($employee, '2026-10-12', [['09:05:00', 'face']]);
    Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-10-12')->update([
        'check_out' => '2026-10-12 18:00:00', 'is_auto_checkout' => true,
        'auto_checkout_reason' => Attendance::AUTO_CHECKOUT_REASON, 'check_out_method' => 'auto',
    ]);

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('todayCalc.source', 'auto_checkout')
        ->assertSeeHtml('data-activity-state="auto"')
        ->assertSeeHtml('data-activity-event="auto"')
        ->assertDontSeeHtml('data-live-clock')
        ->assertSeeInOrder(['09:05 AM', 'Face IN', '06:00 PM', 'Auto Checkout', 'AUTO'])
        ->assertSee(Attendance::AUTO_CHECKOUT_EXPLANATION)
        ->assertSeeInOrder(['Current Status', 'Auto Checkout', 'Closed at']);

    expect(substr_count($component->html(), 'data-activity-event="auto"'))->toBe(1);
});

test('a weekly off has no clock, reads Weekly Off and is never Absent', function () {
    $this->travelTo(Carbon::parse('2026-10-17 15:00:00'));   // Saturday
    $employee = tactEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertDontSeeHtml('data-today-activity')
        ->assertDontSeeHtml('data-live-clock')
        ->assertSeeInOrder(['Current Status', WorkingDayResolver::WEEKLY_OFF_LABEL])
        ->set('historyStatus', 'absent')
        ->assertDontSeeHtml('data-history-row="2026-10-17"')
        ->assertDontSeeHtml('data-history-row="2026-10-11"')       // Sunday
        ->set('historyStatus', 'weekly_off')
        ->assertSeeHtml('data-history-row="2026-10-17"')
        ->assertSeeHtml('data-history-row="2026-10-11"');
});

test('approved leave today reads On Leave and is never Absent', function () {
    $employee = tactEmployee();
    tactLeave($employee, '2026-10-12');

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertDontSeeHtml('data-today-activity')
        ->assertSeeInOrder(['Current Status', 'On Leave'])
        ->set('historyStatus', 'absent')
        ->assertDontSeeHtml('data-history-row="2026-10-12"')
        ->set('historyStatus', 'leave')
        ->assertSeeHtml('data-history-row="2026-10-12"');
});

test('the timeline scrolls inside its own track so a phone-width page never overflows', function () {
    $this->travelTo(Carbon::parse('2026-10-12 19:00:00'));
    $employee = tactEmployee();
    $punches = [];
    foreach (['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00'] as $i => $t) {
        $punches[] = [$t.':00', $i % 2 === 0 ? 'face' : 'id_card'];
    }
    tactPunches($employee, '2026-10-12', $punches);

    $html = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-activity-view-all')
        ->assertSeeHtml('data-activity-all')                      // the "View All Activity" drawer
        ->assertSee('Scroll to later activity')
        ->html();

    // The wide list lives in its own overflow-x-auto track inside an overflow-hidden card.
    expect($html)->toMatch('/class="[^"]*relative overflow-hidden"[^>]*data-today-activity/')
        ->and($html)->toMatch('/class="[^"]*overflow-x-auto[^"]*"[^>]*data-activity-track/')
        ->and($html)->toMatch('/data-activity-track[^>]*>\s*<div class="relative mx-auto w-max">/')
        ->and(substr_count($html, 'data-activity-event='))->toBe(10);
});

test('history filters narrow the rows live and clear back to the whole month', function () {
    $this->travelTo(Carbon::parse('2026-10-12 12:00:00'));
    $employee = tactEmployee();
    tactPunches($employee, '2026-10-05', [['09:00:00', 'face'], ['18:00:00', 'id_card']]);
    tactPunches($employee, '2026-10-06', [['09:40:00', 'face'], ['18:00:00', 'id_card']]);   // late
    Attendance::where('employee_id', $employee->id)->whereDate('date', '2026-10-06')->update(['work_mode' => 'wfh']);
    tactLeave($employee, '2026-10-07');

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('data-history-filters')
        ->assertSeeHtml('data-history-row="2026-10-05"')
        ->assertSeeHtml('data-history-row="2026-10-08"')
        ->set('historyFrom', '2026-10-05')
        ->set('historyTo', '2026-10-07')
        ->assertSeeHtml('data-history-row="2026-10-05"')
        ->assertSeeHtml('data-history-row="2026-10-07"')
        ->assertDontSeeHtml('data-history-row="2026-10-08"')
        ->assertDontSeeHtml('data-history-row="2026-10-01"')
        ->set('historyStatus', 'leave')
        ->assertSeeHtml('data-history-row="2026-10-07"')
        ->assertDontSeeHtml('data-history-row="2026-10-05"')
        ->set('historyStatus', '')
        ->set('historyMode', 'wfh')
        ->assertSeeHtml('data-history-row="2026-10-06"')
        ->assertDontSeeHtml('data-history-row="2026-10-05"')
        ->set('historyMode', 'hybrid')
        ->assertSeeHtml('data-history-no-match')
        ->call('clearHistoryFilters')
        ->assertSet('historyFrom', '')
        ->assertSet('historyStatus', '')
        ->assertSet('historyMode', '')
        ->assertSeeHtml('data-history-row="2026-10-03"')            // page 1: 12 → 3 Oct
        ->assertSeeHtml('data-history-row="2026-10-08"')
        // A new month drops the old month's dates but keeps the status.
        ->set('historyFrom', '2026-10-05')
        ->set('historyStatus', 'absent')
        ->call('historyPreviousMonth')
        ->assertSet('historyFrom', '')
        ->assertSet('historyStatus', 'absent');
});

test('status and worked time match the canonical calculation, and the refresh picks up a new punch', function () {
    $employee = tactEmployee();
    tactPunches($employee, '2026-10-12', [['09:00:00', 'face']]);

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('todayCalc.live', true)
        ->assertSet('todayCalc.worked_minutes', 180)
        ->assertSeeInOrder(['Worked Today', '3h 00m'])
        ->assertSeeInOrder(['Current Status', 'At Work']);

    // A card OUT arrives (as a sync would write it); the 30-second refresh reloads today only.
    $this->travelTo(Carbon::parse('2026-10-12 13:00:00'));
    tactPunches($employee, '2026-10-12', [['13:00:00', 'id_card']]);

    $component->call('refreshToday')
        ->assertSet('todayCalc.live', false)
        ->assertSet('todayCalc.worked_minutes', 240)
        ->assertSeeInOrder(['Worked Today', '4h 00m'])
        ->assertSeeInOrder(['Current Status', 'Checked Out', 'At 01:00 PM'])
        ->assertSeeHtml('data-activity-state="out"')
        ->assertDontSeeHtml('data-live-clock')
        ->assertSeeInOrder(['09:00 AM', 'Face IN', '01:00 PM', 'ID Card OUT']);
});

test('the page polls the today refresh every 30 seconds', function () {
    $employee = tactEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSeeHtml('wire:poll.30s.visible="refreshToday"');
});
