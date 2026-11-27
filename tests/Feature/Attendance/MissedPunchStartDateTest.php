<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceSettings;
use App\Livewire\Attendance\MissedPunchAllowance;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceScoreSetting;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\MissedPunchFlag;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\MissedPunchNotification;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\MissedPunchAlerts;
use App\Services\Attendance\MissedPunchTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;
use Livewire\Livewire;

/*
 * Missed-punch tracking starts on the day set on Attendance Settings
 * (Conexus: 1 December 2026). Before it there are no missed punches — none
 * counted toward the monthly allowance, shown, alerted, flagged or charged
 * per punch — and the attendance score keeps the flat daily penalty it had.
 *
 * Clock: Tue 1 Dec 2026, 21:00 (the 19:30 shift end + 1 hour has passed).
 */

function mpsPunch(Employee $employee, string $at, string $method): void
{
    AttendancePunch::create(['employee_id' => $employee->id, 'employee_code' => $employee->employee_code,
        'punched_at' => $at, 'punch_date' => substr($at, 0, 10), 'method' => $method, 'source' => 'biometric']);
}

/** Face IN, Face IN again (a missed OUT), Card OUT, Card OUT again (a missed IN). */
function mpsTwoMissed(Employee $employee, string $date): void
{
    foreach (['10:30:00' => 'face', '12:30:00' => 'face', '15:00:00' => 'id_card', '19:35:00' => 'id_card'] as $time => $method) {
        mpsPunch($employee, "{$date} {$time}", $method);
    }
    Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'check_in' => "{$date} 10:30:00",
        'check_out' => "{$date} 19:35:00", 'status' => 'on_time', 'work_mode' => 'office']);
}

function mpsStartsOn(?string $date): void
{
    AttendanceSetting::query()->firstOrCreate([])->update(['missed_punch_starts_on' => $date]);
    Once::flush();
}

beforeEach(function () {
    Cache::flush();
    $this->travelTo(Carbon::parse('2026-12-01 21:00:00'));

    $shift = ShiftSetting::create(['name' => 'IT Shift', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 6, 'standard_hours' => 9]);
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Shivani HR']);
    $this->user = User::factory()->create(['name' => 'Yogesh Sapkal']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'employee_code' => 17, 'shift_id' => $shift->id,
        'status' => 'active', 'joining_date' => '2024-01-01']);

    mpsTwoMissed($this->employee, '2026-11-30');   // Mon — before tracking starts
    mpsTwoMissed($this->employee, '2026-12-01');   // Tue — the first tracked day
    mpsStartsOn('2026-12-01');
});

test('before the start date there are no missed punches; from it they are counted as before', function () {
    $tracker = app(MissedPunchTracker::class);

    expect(MissedPunchTracker::startsOn()?->toDateString())->toBe('2026-12-01')
        ->and(MissedPunchTracker::tracks(Carbon::parse('2026-11-30')))->toBeFalse()
        ->and(MissedPunchTracker::tracks(Carbon::parse('2026-12-01')))->toBeTrue()
        ->and(array_keys($tracker->between($this->employee, Carbon::parse('2026-11-01'), Carbon::parse('2026-12-01'))))->toBe(['2026-12-01'])
        ->and($tracker->monthCount($this->employee, Carbon::parse('2026-11-15')))->toBe(0)
        ->and($tracker->monthCount($this->employee))->toBe(2)
        ->and(collect($tracker->dayTrail($this->employee, Carbon::parse('2026-11-30'))['rows'])->where('state', 'missed'))->toBeEmpty();

    // Empty = every day tracked.
    mpsStartsOn(null);
    expect(array_keys($tracker->between($this->employee, Carbon::parse('2026-11-01'), Carbon::parse('2026-12-01'))))->toBe(['2026-11-30', '2026-12-01']);
});

test('nothing before the start date is alerted or flagged', function () {
    Notification::fake();

    $result = app(MissedPunchAlerts::class)->scan($this->employee, Carbon::parse('2026-11-30'), Carbon::parse('2026-12-01'));

    // Only 1 December's two missed punches, each to the employee and HR.
    expect($result)->toMatchArray(['missed' => 2, 'notified' => 4, 'flagged' => 0]);
    Notification::assertSentToTimes($this->user, MissedPunchNotification::class, 2);
    Notification::assertSentTo($this->user, MissedPunchNotification::class, fn ($n) => str_contains(json_encode($n->toArray($this->user)), '01 Dec'));
    expect(MissedPunchFlag::count())->toBe(0);
});

test('the score keeps the flat daily penalty before the start date and charges per punch from it', function () {
    $this->travelTo(Carbon::parse('2026-12-02 10:00:00'));   // both days in the past (today is never scored)
    $penalty = AttendanceScoreSetting::current()->missing_punch_penalty;
    $line = fn (string $date) => collect(app(AttendanceScoreEngine::class)->scoreDay($this->employee, $date)->breakdown)->firstWhere('factor', 'missing_punch');

    expect($line('2026-11-30'))->toMatchArray(['label' => 'Missing punch', 'points' => -$penalty])
        ->and($line('2026-12-01'))->toMatchArray(['label' => 'Missed punches (2)', 'points' => -2 * $penalty]);
});

test('HR sees when tracking starts, and flags from before it are not listed', function () {
    MissedPunchFlag::create(['employee_id' => $this->employee->id, 'period' => MissedPunchFlag::PERIOD_MONTH, 'period_start' => '2026-11-01',
        'missed_count' => 9, 'allowance' => 5, 'flagged_at' => '2026-11-25 10:00:00']);
    MissedPunchFlag::create(['employee_id' => $this->employee->id, 'period' => MissedPunchFlag::PERIOD_WEEK, 'period_start' => '2026-11-23',
        'missed_count' => 6, 'allowance' => 5, 'flagged_at' => '2026-11-25 10:00:00']);

    $november = Livewire::actingAs($this->hr)->withQueryParams(['month' => '2026-11'])->test(MissedPunchAllowance::class)
        ->assertSeeHtml('data-missed-punch-not-started')
        ->assertSee('Missed-punch tracking starts on 01 Dec 2026');
    expect($november->viewData('history'))->toBeEmpty()
        ->and($november->viewData('rows')->firstWhere('employee.id', $this->employee->id))->toMatchArray(['count' => 0, 'flag' => null]);

    Livewire::actingAs($this->hr)->withQueryParams(['month' => '2026-12'])->test(MissedPunchAllowance::class)
        ->assertDontSeeHtml('data-missed-punch-not-started')
        ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('employee.id', $this->employee->id)['count'] === 2);
});

test('HR sets or clears the start date on Attendance Settings', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    Livewire::test(AttendanceSettings::class)
        ->assertSet('policy.missed_punch_starts_on', '2026-12-01')
        ->assertSee('Missed-punch tracking starts on')
        ->set('policy.missed_punch_starts_on', '2026-12-15')
        ->call('save')
        ->assertHasNoErrors();
    Once::flush();
    expect(MissedPunchTracker::startsOn()->toDateString())->toBe('2026-12-15');

    Livewire::test(AttendanceSettings::class)->set('policy.missed_punch_starts_on', '')->call('save')->assertHasNoErrors();
    Once::flush();
    expect(MissedPunchTracker::startsOn())->toBeNull();

    Livewire::test(AttendanceSettings::class)->set('policy.missed_punch_starts_on', 'not a date')->call('save')
        ->assertHasErrors('policy.missed_punch_starts_on');
});
