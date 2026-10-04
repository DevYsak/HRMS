<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\OtRequest;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\LateArrivalNotification;
use App\Notifications\NewHireCheckInNotification;
use App\Notifications\OnboardingCompletedNotification;
use App\Notifications\OtRequestNotification;
use App\Notifications\ProbationDueNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Spec v3.1 §7 — the scheduled jobs run on time, reach the right people and
 * are idempotent: running a job twice never repeats its notifications.
 */
function jobUser(UserRole $role, array $employee = []): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(array_merge(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null], $employee));

    return $user->fresh();
}

beforeEach(fn () => Notification::fake());

test('a pending OT request is escalated to HR once, not every hour', function () {
    $hr = jobUser(UserRole::HrAdmin);
    $employee = jobUser(UserRole::Employee);
    $request = OtRequest::create([
        'employee_id' => $employee->employee->id, 'work_date' => now()->toDateString(),
        'start_time' => '19:30', 'end_time' => '21:30', 'requested_hours' => 2, 'reason' => 'Release', 'status' => 'pending',
    ]);
    OtRequest::whereKey($request->id)->update(['created_at' => now()->subHours(30)]);

    $this->artisan('hrms:escalate-ot')->assertSuccessful();
    $this->artisan('hrms:escalate-ot')->assertSuccessful();

    Notification::assertSentToTimes($hr, OtRequestNotification::class, 1);
    expect($request->fresh()->escalated_at)->not->toBeNull();
});

test('late arrivals are reported to the manager once, whichever shift run sees them', function () {
    $manager = jobUser(UserRole::Manager);
    $shift = ShiftSetting::create(['name' => 'IT Shift', 'start_time' => '10:30:00', 'end_time' => '19:30:00', 'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9]);
    $employee = jobUser(UserRole::Employee, ['manager_id' => $manager->id, 'shift_id' => $shift->id]);
    $this->travelTo(now()->setTime(10, 45));
    Attendance::create(['employee_id' => $employee->employee->id, 'date' => now()->toDateString(), 'check_in' => now()->setTime(10, 50), 'status' => 'on_time']);

    $this->artisan('hrms:check-late-arrivals')->assertSuccessful();
    $this->travelTo(now()->setTime(13, 15));
    $this->artisan('hrms:check-late-arrivals')->assertSuccessful();

    Notification::assertSentToTimes($manager, LateArrivalNotification::class, 1);
    expect(Attendance::first()->is_late)->toBeTrue()->and((int) Attendance::first()->late_minutes)->toBe(15);
});

test('probation review due goes to the manager and HR once per end date', function () {
    $hr = jobUser(UserRole::HrAdmin);
    $manager = jobUser(UserRole::Manager);
    $employee = jobUser(UserRole::Employee, [
        'manager_id' => $manager->id, 'status' => 'probation',
        'joining_date' => now()->subDays(85)->toDateString(), 'probation_end_date' => now()->addDays(5)->toDateString(),
    ]);

    $this->artisan('hrms:check-probation-due')->assertSuccessful();
    $this->artisan('hrms:check-probation-due')->assertSuccessful();

    Notification::assertSentToTimes($hr, ProbationDueNotification::class, 1);
    Notification::assertSentToTimes($manager, ProbationDueNotification::class, 1);

    // Extended to a new date → announced again for that date.
    $employee->employee->update(['probation_end_date' => now()->addDays(9)->toDateString()]);
    $this->artisan('hrms:check-probation-due')->assertSuccessful();
    Notification::assertSentToTimes($manager, ProbationDueNotification::class, 2);
});

test('the overdue probation job runs without the TypeError it used to throw', function () {
    $hr = jobUser(UserRole::HrAdmin);
    jobUser(UserRole::Employee, ['status' => 'probation', 'probation_end_date' => now()->subDays(3)->toDateString()]);

    $this->artisan('hrms:check-probation-expiry')->assertSuccessful();

    Notification::assertSentToTimes($hr, ProbationDueNotification::class, 1);
});

test('the 30-day new-hire check-in reaches the manager, whatever the status', function () {
    $manager = jobUser(UserRole::Manager);
    jobUser(UserRole::Employee, ['manager_id' => $manager->id, 'status' => 'probation', 'joining_date' => now()->subDays(30)->toDateString()]);

    $this->artisan('hrms:check-newhire-checkin')->assertSuccessful();

    Notification::assertSentTo($manager, NewHireCheckInNotification::class);
});

test('onboarding completion is announced once, not every day', function () {
    jobUser(UserRole::HrAdmin);
    $employee = jobUser(UserRole::Employee, ['status' => 'onboarding']);
    // The observer seeds a fresh checklist; start from one completed task.
    $employee->employee->onboardingTasks()->delete();
    OnboardingTask::create([
        'employee_id' => $employee->employee->id, 'title' => 'Sign contract', 'phase' => 'onboarding',
        'is_completed' => true, 'status' => 'completed', 'completed_at' => now(),
    ]);

    // First run announces it.
    $this->artisan('hrms:send-onboarding-reminders')->assertSuccessful();
    Notification::assertSentTo($employee, OnboardingCompletedNotification::class);

    // Once a notice for this employee is on record — in the new shape (keyed by
    // employee_id) or the old one (exact body text) — the job stays quiet.
    foreach ([
        ['employee_id' => $employee->employee->id, 'body' => 'anything'],
        ['body' => $employee->name.' has completed all onboarding tasks.'],
    ] as $data) {
        Notification::fake();
        DB::table('notifications')->delete();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => OnboardingCompletedNotification::class,
            'notifiable_type' => User::class, 'notifiable_id' => $employee->id,
            'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('hrms:send-onboarding-reminders')->assertSuccessful();
        Notification::assertNotSentTo($employee, OnboardingCompletedNotification::class);
    }
});

test('the monthly attendance summary is stored, and re-running replaces rather than duplicates', function () {
    $this->travelTo(Carbon::parse('2026-10-01 01:00'));
    $employee = jobUser(UserRole::Employee);
    Attendance::create([
        'employee_id' => $employee->employee->id, 'date' => '2026-09-15', 'check_in' => '2026-09-15 10:50:00',
        'check_out' => '2026-09-15 19:30:00', 'status' => 'late', 'is_late' => true, 'excess_break_flag' => true,
    ]);

    $this->artisan('hrms:generate-attendance-summary')->assertSuccessful();
    $this->artisan('hrms:generate-attendance-summary')->assertSuccessful();

    $summary = AttendanceMonthlySummary::where('employee_id', $employee->employee->id)->get();
    expect($summary)->toHaveCount(1)
        ->and($summary->first()->month)->toBe('2026-09')
        ->and($summary->first()->present_days)->toBe(1)
        ->and($summary->first()->late_days)->toBe(1)
        ->and($summary->first()->excess_break_days)->toBe(1);
});

test('notifications older than 90 days are pruned whether read or not; recent ones stay', function () {
    $user = jobUser(UserRole::Employee);
    $row = fn (int $daysAgo, bool $read) => [
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Test', 'notifiable_type' => User::class,
        'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => $read ? now() : null,
        'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
    ];
    DB::table('notifications')->insert([$row(120, true), $row(120, false), $row(10, false)]);

    $this->artisan('hrms:prune-notifications')->assertSuccessful();

    expect(DB::table('notifications')->count())->toBe(1);
});

test('the scheduler registers every spec §7 job', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '')
        ->implode("\n");

    foreach ([
        'hrms:flag-missing-checkouts', 'hrms:check-late-arrivals', 'hrms:check-excess-breaks',
        'hrms:escalate-leaves', 'hrms:escalate-ot', 'hrms:check-document-expiry', 'hrms:check-probation-due',
        'hrms:check-newhire-checkin', 'hrms:remind-review-participants', 'hrms:prune-notifications',
        'hrms:generate-attendance-summary', 'backup:run',
    ] as $command) {
        expect($events)->toContain($command);
    }
});
