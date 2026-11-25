<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceSettings;
use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Attendance\MissedPunchAllowance;
use App\Livewire\Attendance\MissedPunchPopup;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MissedPunchFlag;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\MissedPunchAllowanceExceededNotification;
use App\Services\Attendance\MissedPunchAlerts;
use App\Services\Attendance\MissedPunchTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;
use Livewire\Livewire;

/*
 * The monthly missed-punch allowance: a calendar month — every day of it,
 * weekends included — allows a number of missed punches set on Attendance
 * Settings (default 5); one more flags the employee for HR review, HR is asked to
 * review, and the employee is told to contact HR. HR sees employee-wise
 * counts, reviews flags, and keeps the flag history.
 */

function mpaPunch(Employee $employee, string $at, string $method): void
{
    AttendancePunch::create(['employee_id' => $employee->id, 'employee_code' => $employee->employee_code,
        'punched_at' => $at, 'punch_date' => substr($at, 0, 10), 'method' => $method, 'source' => 'biometric']);
}

/** A day with a missed OUT for every extra Face IN: 10:30 IN, then $extraIns more INs, then an 19:35 card OUT. */
function mpaDay(Employee $employee, string $date, int $extraIns): void
{
    mpaPunch($employee, "{$date} 10:30:00", 'face');
    foreach (range(1, $extraIns) as $i) {
        mpaPunch($employee, sprintf('%s %02d:00:00', $date, 11 + $i), 'face');
    }
    mpaPunch($employee, "{$date} 19:35:00", 'id_card');
    Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'check_in' => "{$date} 10:30:00",
        'check_out' => "{$date} 19:35:00", 'status' => 'on_time', 'work_mode' => 'office']);
}

function mpaAllowance(int $allowance, int $warningAt = 0): void
{
    AttendanceSetting::query()->firstOrCreate([])->update(['missed_punch_monthly_allowance' => $allowance, 'missed_punch_warning_at' => $warningAt]);
    Once::flush();
}

beforeEach(function () {
    Cache::flush();
    $this->travelTo(Carbon::parse('2026-11-25 20:45:00'));   // Wed

    $shift = ShiftSetting::create(['name' => 'IT Shift', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 6, 'standard_hours' => 9]);
    $this->manager = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Mazhar Manager']);
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Shivani HR']);
    $this->user = User::factory()->create(['name' => 'Yogesh Sapkal']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'employee_code' => 17, 'shift_id' => $shift->id,
        'status' => 'active', 'joining_date' => '2024-01-01', 'manager_id' => $this->manager->id]);

    mpaDay($this->employee, '2026-11-23', 2);   // Mon: 2 missed
    mpaDay($this->employee, '2026-11-24', 1);   // Tue: 1 missed
});

test('HR sets the monthly allowance and the early warning on Attendance Settings, and the change is audited', function () {
    expect(MissedPunchTracker::allowance())->toBe(5)->and(MissedPunchTracker::warningAt())->toBe(3);

    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    Livewire::test(AttendanceSettings::class)
        ->assertSee('Missed punches allowed per month')
        ->set('policy.missed_punch_monthly_allowance', 4)
        ->set('policy.missed_punch_warning_at', 0)
        ->call('save')
        ->assertHasNoErrors();
    Once::flush();

    expect(MissedPunchTracker::allowance())->toBe(4)
        ->and(MissedPunchTracker::warningAt())->toBe(0)
        ->and(MissedPunchTracker::status(4))->toBe(MissedPunchTracker::STATUS_OK)
        ->and(MissedPunchTracker::status(5))->toBe(MissedPunchTracker::STATUS_EXCEEDED)
        ->and(AuditLog::where('auditable_type', AttendanceSetting::class)->latest('id')->first()->new_values)
        ->toMatchArray(['missed_punch_monthly_allowance' => 4, 'missed_punch_warning_at' => 0]);
});

test('one over the allowance flags the employee once, tells HR to review and the employee to contact HR — never the manager', function () {
    Notification::fake();
    mpaAllowance(2);

    $result = app(MissedPunchAlerts::class)->scan($this->employee, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-25'));

    expect($result['flagged'])->toBe(1);
    $flag = MissedPunchFlag::sole();
    expect($flag->only(['employee_id', 'missed_count', 'allowance']))->toBe(['employee_id' => $this->employee->id, 'missed_count' => 3, 'allowance' => 2])
        ->and($flag->period)->toBe(MissedPunchFlag::PERIOD_MONTH)
        ->and($flag->period_start->toDateString())->toBe('2026-11-01')
        ->and(AuditLog::where('action', 'attendance_missed_punch_flagged')->where('subject_employee_id', $this->employee->id)->exists())->toBeTrue();

    Notification::assertSentTo($this->user, MissedPunchAllowanceExceededNotification::class,
        fn ($n) => $n->toArray($this->user)['title'] === 'Missed-punch allowance used up — contact HR'
            && str_contains($n->toArray($this->user)['body'], 'in November 2026, over the 2 allowed in a month'));
    Notification::assertSentTo($this->hr, MissedPunchAllowanceExceededNotification::class,
        fn ($n) => str_starts_with($n->forRole('hr_admin')->toArray($this->hr)['title'], 'Review attendance: Yogesh Sapkal'));
    Notification::assertNotSentTo($this->manager, MissedPunchAllowanceExceededNotification::class);

    // Re-run: no second flag, nobody told twice; a further miss raises the count on the same flag.
    expect(app(MissedPunchAlerts::class)->scan($this->employee, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-25'))['flagged'])->toBe(0);
    Notification::assertSentToTimes($this->user, MissedPunchAllowanceExceededNotification::class, 1);

    mpaDay($this->employee, '2026-11-25', 1);
    app(MissedPunchAlerts::class)->scan($this->employee, Carbon::parse('2026-11-25'), Carbon::parse('2026-11-25'));
    expect(MissedPunchFlag::sole()->missed_count)->toBe(4);
});

test('every day of the calendar month counts, weekends included, and the allowance starts again on the 1st', function () {
    // Mon 2 Nov: an earlier week of the same month counts too.
    mpaDay($this->employee, '2026-11-02', 1);
    // Sat 28 Nov: a missed punch on a weekend day counts as well.
    $this->travelTo(Carbon::parse('2026-11-28 21:00:00'));
    mpaDay($this->employee, '2026-11-28', 2);
    $tracker = app(MissedPunchTracker::class);

    expect(collect($tracker->between($this->employee, Carbon::parse('2026-11-28'), Carbon::parse('2026-11-28')))->flatten(1))->toHaveCount(2)
        ->and($tracker->monthCount($this->employee))->toBe(6)   // 2 Nov 1 + Mon 23 2 + Tue 24 1 + Sat 28 2
        ->and(MissedPunchTracker::status(6))->toBe(MissedPunchTracker::STATUS_EXCEEDED);

    // 31 Oct (a Saturday) belongs to October, not November.
    mpaDay($this->employee, '2026-10-31', 1);
    expect($tracker->monthCount($this->employee))->toBe(6)
        ->and($tracker->monthCount($this->employee, Carbon::parse('2026-10-15')))->toBe(1);

    $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));   // the 1st of the next month
    expect($tracker->monthCount($this->employee))->toBe(0)
        ->and($tracker->monthCount($this->employee, Carbon::parse('2026-11-15')))->toBe(6);
});

test('HR sees employee-wise monthly counts, reviews a flag, and keeps the flag history', function () {
    Notification::fake();
    mpaAllowance(2);
    app(MissedPunchAlerts::class)->scan($this->employee, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-25'));
    $flag = MissedPunchFlag::sole();
    // A flag from when the allowance was weekly keeps its week in the history.
    MissedPunchFlag::factory()->reviewed()->create(['employee_id' => $this->employee->id,
        'period' => MissedPunchFlag::PERIOD_WEEK, 'period_start' => '2026-10-19', 'flagged_at' => '2026-10-23 20:00:00']);

    $this->actingAs($this->hr);
    // The page sits in the main area beside the sidebar, not under it.
    $this->withoutVite()->get(route('attendance.missed-punches'))->assertOk()->assertSee('Missed Punches')
        ->assertSee('data-flux-main', false);

    Livewire::test(MissedPunchAllowance::class)
        ->assertSet('month', '2026-11')
        ->assertSee('November 2026')
        ->assertSee('Yogesh Sapkal')
        ->assertSee('3 / 2')
        ->assertSee('Over allowance')
        ->assertSee('Flagged — to review')
        ->call('toggle', $this->employee->id)
        ->assertSee('Missed OUT between')
        ->call('openReview', $flag->id)
        ->set('reviewNote', 'Spoke to Yogesh; reminded him to card out at lunch.')
        ->call('saveReview')
        ->assertHasNoErrors()
        ->assertSee('Reviewed')
        ->set('status', 'open')
        ->assertDontSee('Flagged — to review');

    expect($flag->fresh()->reviewed_by)->toBe($this->hr->id)
        ->and($flag->fresh()->review_note)->toBe('Spoke to Yogesh; reminded him to card out at lunch.')
        ->and(AuditLog::where('action', 'attendance_missed_punch_reviewed')->exists())->toBeTrue();

    // A month with nothing missed lists nobody as over; the history keeps both
    // flags, each with its own period; a future month is never shown.
    Livewire::test(MissedPunchAllowance::class)->call('previousMonth')->assertSet('month', '2026-10')
        ->assertSee('October 2026')->assertDontSee('Flagged — to review')
        ->assertSee('Spoke to Yogesh')
        ->assertSee('Week of')
        ->call('nextMonth')->call('nextMonth')->assertSet('month', '2026-11');
    Livewire::withQueryParams(['month' => '2027-05'])->test(MissedPunchAllowance::class)->assertSet('month', '2026-11');
});

test('the page is for attendance managers only', function () {
    Livewire::actingAs($this->user)->test(MissedPunchAllowance::class)->assertForbidden();
    Livewire::actingAs($this->manager)->test(MissedPunchAllowance::class)->assertForbidden();
});

test('the employee is told on My Attendance and in the popup that the allowance is used up and to contact HR', function () {
    mpaAllowance(2);

    Livewire::actingAs($this->user)->test(AttendanceTracker::class)
        ->assertSee('Missed-punch allowance used up — 3 of 2 this month')
        ->assertSee('Please contact HR about your attendance');

    Livewire::actingAs($this->user)->test(MissedPunchPopup::class)
        ->assertSee('Missed-punch allowance used up')
        ->assertSee('flagged for HR review');
});
