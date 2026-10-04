<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceMonthlySummary;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OnboardingTask;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Notifications\NewHireCheckInNotification;
use App\Notifications\OnboardingCompletedNotification;
use App\Notifications\ProbationDueNotification;
use App\Services\Attendance\HolidayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Follow-ups from the review of the scheduler changes: sent-once facts live
 * on the employee, and no clean-up may cancel a deduction a payslip holds.
 */
function sfUser(UserRole $role, array $employee = []): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(array_merge(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null], $employee));

    return $user->fresh();
}

beforeEach(fn () => Notification::fake());

test('an onboarding completion notice is not re-sent once its notification rows are gone', function () {
    sfUser(UserRole::HrAdmin);
    $employee = sfUser(UserRole::Employee, ['status' => 'onboarding']);
    $employee->employee->onboardingTasks()->delete();
    OnboardingTask::create([
        'employee_id' => $employee->employee->id, 'title' => 'Sign contract', 'phase' => 'onboarding',
        'is_completed' => true, 'status' => 'completed', 'completed_at' => now(),
    ]);

    $this->artisan('hrms:send-onboarding-reminders')->assertSuccessful();
    // No notification rows exist at all (faked) — as after the 90-day prune.
    $this->artisan('hrms:send-onboarding-reminders')->assertSuccessful();

    Notification::assertSentToTimes($employee, OnboardingCompletedNotification::class, 1);
    expect($employee->employee->fresh()->onboarding_completed_notified_at)->not->toBeNull();
});

test('a wrongly flagged absence already inside a payroll awaiting Finance is listed, not cancelled', function () {
    $type = LeaveType::create(['name' => 'Unauthorized Absence', 'code' => 'UNA', 'category' => 'unauthorized', 'is_paid' => false]);
    $employee = sfUser(UserRole::Employee, ['joining_date' => '2024-01-08', 'salary_cycle' => 'cycle_a']);
    PublicHoliday::factory()->create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'is_active' => true,
        'country' => app(HolidayResolver::class)->resolveCountry($employee->employee)]);
    $leave = LeaveRequest::create([
        'employee_id' => $employee->employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-08-31', 'end_date' => '2026-08-31',
        'days' => 1, 'reason' => 'Auto-flagged: absent without approved leave or regularisation.', 'status' => 'approved',
        'requested_leave_status' => 'unpaid', 'approved_leave_status' => 'unpaid',
    ]);
    $payroll = Payroll::create(['month' => 'August', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'pending_finance', 'processed_by' => $employee->id]);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $employee->employee->id, 'gross_salary' => 1, 'total_deductions' => 0, 'net_salary' => 1, 'status' => 'draft']);
    $this->travelTo(Carbon::parse('2026-10-04'));

    $this->artisan('hrms:review-auto-absences', ['--apply' => true])
        ->expectsOutputToContain('decide with Finance')
        ->assertSuccessful();

    expect($leave->fresh()->status)->toBe('approved');
});

test('--month regenerates the month asked for, even on the 31st', function () {
    $this->travelTo(Carbon::parse('2026-10-31 10:00'));
    $employee = sfUser(UserRole::Employee);
    Attendance::create(['employee_id' => $employee->employee->id, 'date' => '2026-09-10', 'check_in' => '2026-09-10 10:30:00', 'check_out' => '2026-09-10 19:30:00', 'status' => 'on_time']);

    $this->artisan('hrms:generate-attendance-summary', ['--month' => '2026-09'])->assertSuccessful();

    expect(AttendanceMonthlySummary::where('employee_id', $employee->employee->id)->pluck('month')->all())->toBe(['2026-09']);
});

test('the overdue probation digest says overdue, not "due soon"', function () {
    $hr = sfUser(UserRole::HrAdmin);
    sfUser(UserRole::Employee, ['status' => 'probation', 'probation_end_date' => now()->subDays(5)->toDateString()]);

    $this->artisan('hrms:check-probation-expiry')->assertSuccessful();

    Notification::assertSentTo($hr, ProbationDueNotification::class,
        fn (ProbationDueNotification $n) => $n->toArray($hr)['title'] === 'Probation Reviews Overdue');
});

test('a missed new-hire check-in is caught up, and a re-run sends nothing twice', function () {
    $manager = sfUser(UserRole::Manager);
    // Joined 32 days ago: the run on day 30 was missed.
    sfUser(UserRole::Employee, ['manager_id' => $manager->id, 'joining_date' => now()->subDays(32)->toDateString()]);

    $this->artisan('hrms:check-newhire-checkin')->assertSuccessful();
    $this->artisan('hrms:check-newhire-checkin')->assertSuccessful();

    Notification::assertSentToTimes($manager, NewHireCheckInNotification::class, 1);
});
