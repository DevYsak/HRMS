<?php

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Notifications\OnboardingCompletedNotification;
use App\Notifications\OnboardingTaskOverdueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function makeEmployeeWithUser(array $overrides = []): Employee
{
    $user = User::factory()->create(['role' => 'employee']);

    $employee = Employee::factory()->create(array_merge([
        'user_id' => $user->id,
        'joining_date' => now()->toDateString(),
        'status' => 'onboarding',
    ], $overrides));

    // Clear tasks seeded by observer so each test starts with a blank slate.
    $employee->onboardingTasks()->delete();

    return $employee;
}

test('command runs successfully with no tasks', function () {
    $this->artisan('hrms:send-onboarding-reminders')
        ->assertExitCode(0);
});

test('command marks overdue tasks and outputs count', function () {
    $employee = makeEmployeeWithUser();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Old Task',
        'category' => 'hr',
        'sort_order' => 1,
        'due_date' => now()->subDays(3)->toDateString(),
        'status' => 'pending',
        'is_completed' => false,
    ]);

    $this->artisan('hrms:send-onboarding-reminders')
        ->expectsOutputToContain('Marked 1 task(s) as overdue')
        ->assertExitCode(0);

    expect(OnboardingTask::first()->status)->toBe('overdue');
});

test('command sends overdue notifications to hr admins', function () {
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);
    $employee = makeEmployeeWithUser();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'HR Task',
        'category' => 'hr',
        'owner_role' => 'hr',
        'sort_order' => 1,
        'due_date' => now()->subDays(2)->toDateString(),
        'status' => 'overdue',
        'is_completed' => false,
    ]);

    $this->artisan('hrms:send-onboarding-reminders')->assertExitCode(0);

    Notification::assertSentTo($hrAdmin, OnboardingTaskOverdueNotification::class);
});

test('command sends completion notification for fully-completed employee', function () {
    Notification::fake();

    $employee = makeEmployeeWithUser();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Done Task',
        'category' => 'hr',
        'sort_order' => 1,
        'status' => 'completed',
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    $this->artisan('hrms:send-onboarding-reminders')->assertExitCode(0);

    Notification::assertSentTo($employee->user, OnboardingCompletedNotification::class);
});

test('command does not send completion notification when tasks remain', function () {
    Notification::fake();

    $employee = makeEmployeeWithUser();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Pending Task',
        'category' => 'hr',
        'sort_order' => 1,
        'status' => 'pending',
        'is_completed' => false,
    ]);

    $this->artisan('hrms:send-onboarding-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($employee->user, OnboardingCompletedNotification::class);
});

test('command does not touch blocked tasks when marking overdue', function () {
    $employee = makeEmployeeWithUser();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Blocked Task',
        'category' => 'hr',
        'sort_order' => 1,
        'due_date' => now()->subDays(5)->toDateString(),
        'status' => 'blocked',
        'is_completed' => false,
        'blocked_reason' => 'Waiting',
    ]);

    $this->artisan('hrms:send-onboarding-reminders')
        ->expectsOutputToContain('Marked 0 task(s) as overdue')
        ->assertExitCode(0);

    expect(OnboardingTask::first()->status)->toBe('blocked');
});
