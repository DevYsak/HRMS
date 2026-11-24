<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Attendance\MissedPunchPopup;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceScoreSetting;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\MissedPunchNotification;
use App\Notifications\MissedPunchWeeklyWarningNotification;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\AttendanceWorkbook;
use App\Services\Attendance\MissedPunchAlerts;
use App\Services\Attendance\MissedPunchTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Missed punches — two INs in a row (missed OUT), two OUTs in a row (missed
 * IN), or no final check-out once overdue — highlighted on My Attendance,
 * popped up, notified, counted per week (a warning from 3) and charged per
 * punch in the attendance score.
 */

function mpPunch(Employee $employee, string $at, string $method): void
{
    AttendancePunch::create(['employee_id' => $employee->id, 'employee_code' => $employee->employee_code,
        'punched_at' => $at, 'punch_date' => substr($at, 0, 10), 'method' => $method, 'source' => 'biometric']);
}

function mpDay(Employee $employee, string $date, string $in, ?string $out): Attendance
{
    return Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'check_in' => "{$date} {$in}",
        'check_out' => $out ? "{$date} {$out}" : null, 'status' => 'on_time', 'work_mode' => 'office']);
}

beforeEach(function () {
    Cache::flush();
    // Wed 25 Nov 2026, 20:45 — today's 19:30 shift end + 1 hour has passed.
    $this->travelTo(Carbon::parse('2026-11-25 20:45:00'));

    $shift = ShiftSetting::create(['name' => 'IT Shift', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 6, 'standard_hours' => 9]);
    $this->manager = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Mazhar Manager']);
    $this->user = User::factory()->create(['name' => 'Yogesh Sapkal']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'employee_code' => 17, 'shift_id' => $shift->id,
        'status' => 'active', 'joining_date' => '2024-01-01', 'manager_id' => $this->manager->id]);

    // Mon 23: a stray card tap, then Face IN, Face IN again (missed OUT), Card OUT.
    mpPunch($this->employee, '2026-11-23 09:00:00', 'id_card');
    mpPunch($this->employee, '2026-11-23 10:30:00', 'face');
    mpPunch($this->employee, '2026-11-23 12:39:00', 'face');
    mpPunch($this->employee, '2026-11-23 19:35:00', 'id_card');
    mpDay($this->employee, '2026-11-23', '10:30:00', '19:35:00');

    // Tue 24: Face IN, Card OUT, Card OUT again (missed IN), then nothing.
    mpPunch($this->employee, '2026-11-24 10:30:00', 'face');
    mpPunch($this->employee, '2026-11-24 13:00:00', 'id_card');
    mpPunch($this->employee, '2026-11-24 14:00:00', 'id_card');
    mpDay($this->employee, '2026-11-24', '10:30:00', '14:00:00');

    // Wed 25 (today): Face IN only — the check-out is now overdue.
    mpPunch($this->employee, '2026-11-25 10:30:00', 'face');
    mpDay($this->employee, '2026-11-25', '10:30:00', null);
});

test('missed punches come from the punch sequence: IN→IN, OUT→OUT and an overdue check-out — a stray card tap is not one', function () {
    $missed = app(MissedPunchTracker::class)->between($this->employee, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-25'));

    expect(array_keys($missed))->toBe(['2026-11-23', '2026-11-24', '2026-11-25'])
        ->and($missed['2026-11-23'])->toHaveCount(1)
        ->and($missed['2026-11-23'][0])->toMatchArray(['kind' => 'out', 'resolved' => false])
        ->and($missed['2026-11-23'][0]['label'])->toStartWith('Missed OUT between')->toContain('10:30')->toContain('12:39')
        ->and($missed['2026-11-24'][0])->toMatchArray(['kind' => 'in'])
        ->and($missed['2026-11-24'][0]['label'])->toStartWith('Missed IN between')->toContain('01:00')->toContain('02:00')
        ->and($missed['2026-11-25'][0])->toMatchArray(['kind' => 'checkout'])
        ->and($missed['2026-11-25'][0]['label'])->toStartWith('No check-out after')
        ->and(app(MissedPunchTracker::class)->weekCount($this->employee))->toBe(3);

    // Before the overdue point, today's open IN is just someone at work.
    $this->travelTo(Carbon::parse('2026-11-25 15:00:00'));
    expect(app(MissedPunchTracker::class)->between($this->employee, Carbon::parse('2026-11-25'), Carbon::parse('2026-11-25')))->toBe([]);

    // A regularised day still shows what was missed, as resolved.
    Attendance::where('employee_id', $this->employee->id)->whereDate('date', '2026-11-23')->update(['is_regularized' => true]);
    expect(app(MissedPunchTracker::class)->between($this->employee, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-23'))['2026-11-23'][0]['resolved'])->toBeTrue();
});

test('each mid-day missed punch notifies the employee and their manager once, and 3 in a week warns them', function () {
    Notification::fake();

    $result = app(MissedPunchAlerts::class)->scan($this->employee, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-25'));

    // 3 missed; the 2 mid-day ones go to both people (the overdue check-out has its own alert).
    expect($result)->toBe(['missed' => 3, 'notified' => 4, 'warned' => 2]);
    Notification::assertSentToTimes($this->user, MissedPunchNotification::class, 2);
    Notification::assertSentToTimes($this->manager, MissedPunchNotification::class, 2);
    Notification::assertSentTo($this->user, MissedPunchWeeklyWarningNotification::class,
        fn ($n) => $n->toArray($this->user)['title'] === 'Warning: 3 missed punches this week');

    // Nobody is told twice.
    expect(app(MissedPunchAlerts::class)->scan($this->employee, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-25')))
        ->toBe(['missed' => 3, 'notified' => 0, 'warned' => 0]);

    $this->artisan('attendance:missed-punches', ['--from' => '2026-11-23', '--to' => '2026-11-25'])
        ->expectsOutputToContain('3 found, 0 notified, 0 weekly warnings')
        ->assertSuccessful();
});

test('every missed punch costs the missing-punch penalty in the attendance score', function () {
    $penalty = AttendanceScoreSetting::current()->missing_punch_penalty;

    // Mon 23: one missed OUT.
    $line = collect(app(AttendanceScoreEngine::class)->scoreDay($this->employee, '2026-11-23')->breakdown)->firstWhere('factor', 'missing_punch');
    expect($line['points'])->toEqual(-$penalty)
        ->and($line['detail'])->toContain('Missed OUT between');

    // A second missed punch the same day costs twice as much: Card OUT at 15:00
    // then the 19:35 Card OUT — a missed IN between them.
    mpPunch($this->employee, '2026-11-23 15:00:00', 'id_card');
    $line = collect(app(AttendanceScoreEngine::class)->scoreDay($this->employee, '2026-11-23')->breakdown)->firstWhere('factor', 'missing_punch');
    expect($line['points'])->toEqual(-2 * $penalty)
        ->and($line['label'])->toBe('Missed punches (2)');
});

test('My Attendance highlights the missed punches, lists them, counts the week and warns at 3', function () {
    Livewire::actingAs($this->user)->test(AttendanceTracker::class)
        ->assertSet('missedWeekCount', 3)
        ->assertSee('Missed OUT punch')
        ->assertSee('Missed IN punch')
        ->assertSee('Warning — 3 missed punches this week')
        ->assertSeeHtml('data-activity-event="missed"')
        ->assertSeeHtml('data-missed-week="3"')
        ->assertSeeHtml('data-history-row="2026-11-23"')
        ->assertSeeHtml('data-history-missed')
        ->assertSee('Missed OUT');
});

test('a missed-punch link opens the regularisation request for that day', function () {
    Livewire::actingAs($this->user)->withQueryParams(['regularise' => '2026-11-23'])
        ->test(AttendanceTracker::class)
        ->assertSet('regDate', '2026-11-23');
});

test('the popup shows new missed punches and the weekly warning until the employee dismisses them', function () {
    Livewire::actingAs($this->user)->test(MissedPunchPopup::class)
        ->assertSee('You missed 3 punches')
        ->assertSee('Missed OUT between')
        ->assertSee('3 missed punches this week')
        ->assertSee('warning')
        ->call('dismiss')
        ->assertDontSeeHtml('data-missed-punch-dialog');

    // A new missed punch pops up on its own.
    mpPunch($this->employee, '2026-11-25 12:00:00', 'face');
    Cache::flush();
    Cache::forever('missed-punch-popup:seen:'.$this->user->id, collect(app(MissedPunchTracker::class)
        ->between($this->employee, Carbon::parse('2026-11-19'), Carbon::parse('2026-11-25')))->flatten(1)
        ->reject(fn ($m) => $m['date'] === '2026-11-25' && $m['kind'] === 'out')->pluck('key')->push('week:2026-11-23')->all());

    Livewire::actingAs($this->user)->test(MissedPunchPopup::class)
        ->assertSee('You missed a punch')
        ->assertSee('Missed OUT between');

    // Someone with no employee record never sees it.
    Livewire::actingAs($this->manager)->test(MissedPunchPopup::class)->assertDontSeeHtml('data-missed-punch-dialog');
});

test('the Excel workbook counts missed punches per day and the weeks with 3 or more', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    $sheets = collect(app(AttendanceWorkbook::class)->sheets(['from' => '2026-11-23', 'to' => '2026-11-25', 'employee_id' => $this->employee->id]))->keyBy('name');

    $detail = collect($sheets['Detailed Attendance']['rows'])->keyBy(3);
    expect($detail['2026-11-23'][24])->toBe(1)
        ->and($detail['2026-11-23'][25])->toStartWith('Missed OUT between')
        ->and($sheets['Summary']['rows'][0][20])->toBe(3)
        ->and($sheets['Summary']['rows'][0][21])->toBe(1);
});
