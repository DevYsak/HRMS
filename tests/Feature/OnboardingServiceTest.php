<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\OnboardingTemplate;
use App\Models\OnboardingTemplateTask;
use App\Models\User;
use App\Notifications\OnboardingCompletedNotification;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function makeOnboardingEmployee(array $overrides = []): Employee
{
    $user = User::factory()->create();

    $employee = Employee::factory()->create(array_merge([
        'user_id' => $user->id,
        'joining_date' => now()->toDateString(),
    ], $overrides));

    // Clear tasks seeded by observer so each test starts with a blank slate.
    $employee->onboardingTasks()->delete();

    return $employee;
}

function makeDefaultTemplate(int $taskCount = 3): OnboardingTemplate
{
    $template = OnboardingTemplate::create([
        'name' => 'Default',
        'is_default' => true,
        'is_active' => true,
    ]);

    for ($i = 1; $i <= $taskCount; $i++) {
        OnboardingTemplateTask::create([
            'template_id' => $template->id,
            'phase' => 'onboarding',
            'title' => "Task {$i}",
            'category' => 'general',
            'sort_order' => $i,
            'due_days' => 7,
        ]);
    }

    return $template;
}

// ── assignTemplate ────────────────────────────────────────────────

test('assignTemplate seeds fallback tasks when no templates exist', function () {
    $employee = makeOnboardingEmployee();

    app(OnboardingService::class)->assignTemplate($employee);

    expect($employee->onboardingTasks()->where('phase', 'onboarding')->count())->toBe(11);
});

test('assignTemplate seeds from default template when one exists', function () {
    makeDefaultTemplate(5);
    $employee = makeOnboardingEmployee();

    app(OnboardingService::class)->assignTemplate($employee);

    expect($employee->onboardingTasks()->where('phase', 'onboarding')->count())->toBe(5);
});

test('assignTemplate does not create duplicate tasks if called twice', function () {
    makeDefaultTemplate(3);
    $employee = makeOnboardingEmployee();
    $service = app(OnboardingService::class);

    $service->assignTemplate($employee);
    $service->assignTemplate($employee);

    expect($employee->onboardingTasks()->count())->toBe(3);
});

test('assignTemplate uses scoped template over default when department matches', function () {
    $dept = Department::factory()->create();

    $defaultTemplate = makeDefaultTemplate(2);

    $scopedTemplate = OnboardingTemplate::create([
        'name' => 'Dept Scoped',
        'department_id' => $dept->id,
        'is_default' => false,
        'is_active' => true,
    ]);
    OnboardingTemplateTask::create([
        'template_id' => $scopedTemplate->id,
        'phase' => 'onboarding',
        'title' => 'Dept Task',
        'category' => 'general',
        'sort_order' => 1,
        'due_days' => 3,
    ]);

    $employee = makeOnboardingEmployee(['department_id' => $dept->id]);

    app(OnboardingService::class)->assignTemplate($employee);

    expect($employee->onboardingTasks()->count())->toBe(1)
        ->and($employee->onboardingTasks()->first()->title)->toBe('Dept Task');
});

test('assignTemplate sets due_date relative to joining_date', function () {
    $joinDate = now()->addDays(5)->toDateString();
    $employee = makeOnboardingEmployee(['joining_date' => $joinDate]);

    $template = OnboardingTemplate::create(['name' => 'T', 'is_default' => true, 'is_active' => true]);
    OnboardingTemplateTask::create([
        'template_id' => $template->id,
        'phase' => 'onboarding',
        'title' => 'Task A',
        'category' => 'general',
        'sort_order' => 1,
        'due_days' => 7,
    ]);

    app(OnboardingService::class)->assignTemplate($employee);

    $task = $employee->onboardingTasks()->first();
    $expectedDue = now()->parse($joinDate)->addDays(7)->toDateString();

    expect($task->due_date->toDateString())->toBe($expectedDue);
});

test('assignTemplate copies auto_trigger from template task', function () {
    $template = OnboardingTemplate::create(['name' => 'T', 'is_default' => true, 'is_active' => true]);
    OnboardingTemplateTask::create([
        'template_id' => $template->id,
        'phase' => 'onboarding',
        'title' => 'KYC Upload',
        'category' => 'hr',
        'sort_order' => 1,
        'due_days' => 3,
        'auto_trigger' => 'kyc_upload',
    ]);

    $employee = makeOnboardingEmployee();
    app(OnboardingService::class)->assignTemplate($employee);

    expect($employee->onboardingTasks()->first()->auto_trigger)->toBe('kyc_upload');
});

// ── autoComplete ──────────────────────────────────────────────────

test('autoComplete marks matching trigger tasks as completed', function () {
    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'KYC Upload',
        'category' => 'hr',
        'sort_order' => 1,
        'auto_trigger' => 'kyc_upload',
        'status' => 'pending',
    ]);
    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Bank Setup',
        'category' => 'finance',
        'sort_order' => 2,
        'auto_trigger' => null,
        'status' => 'pending',
    ]);

    app(OnboardingService::class)->autoComplete($employee, 'kyc_upload', 0);

    $tasks = $employee->onboardingTasks()->get();
    $kyc = $tasks->where('auto_trigger', 'kyc_upload')->first();
    $bank = $tasks->where('auto_trigger', null)->first();

    expect($kyc->is_completed)->toBeTrue()
        ->and($kyc->status)->toBe('completed')
        ->and($bank->is_completed)->toBeFalse();
});

test('autoComplete does not affect tasks with different trigger', function () {
    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Asset Assign',
        'category' => 'it_setup',
        'sort_order' => 1,
        'auto_trigger' => 'asset_assign',
        'status' => 'pending',
    ]);

    app(OnboardingService::class)->autoComplete($employee, 'kyc_upload', 0);

    expect($employee->onboardingTasks()->first()->is_completed)->toBeFalse();
});

test('autoComplete sends completion notification when all tasks are done', function () {
    Notification::fake();

    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Profile',
        'category' => 'hr',
        'sort_order' => 1,
        'auto_trigger' => 'account_create',
        'status' => 'pending',
    ]);

    app(OnboardingService::class)->autoComplete($employee, 'account_create', 0);

    Notification::assertSentTo($employee->user, OnboardingCompletedNotification::class);
});

// ── markOverdue ───────────────────────────────────────────────────

test('markOverdue sets status to overdue for past-due incomplete tasks', function () {
    $employee = makeOnboardingEmployee();

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

    $count = app(OnboardingService::class)->markOverdue();

    expect($count)->toBe(1)
        ->and($employee->onboardingTasks()->first()->status)->toBe('overdue');
});

test('markOverdue does not touch completed tasks', function () {
    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Done Task',
        'category' => 'hr',
        'sort_order' => 1,
        'due_date' => now()->subDays(2)->toDateString(),
        'status' => 'completed',
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    $count = app(OnboardingService::class)->markOverdue();

    expect($count)->toBe(0);
});

test('markOverdue does not touch already-blocked tasks', function () {
    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Blocked Task',
        'category' => 'hr',
        'sort_order' => 1,
        'due_date' => now()->subDays(2)->toDateString(),
        'status' => 'blocked',
        'is_completed' => false,
        'blocked_reason' => 'Waiting on IT',
    ]);

    app(OnboardingService::class)->markOverdue();

    expect($employee->onboardingTasks()->first()->status)->toBe('blocked');
});

test('markOverdue does not touch future tasks', function () {
    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Future Task',
        'category' => 'hr',
        'sort_order' => 1,
        'due_date' => now()->addDays(5)->toDateString(),
        'status' => 'pending',
        'is_completed' => false,
    ]);

    $count = app(OnboardingService::class)->markOverdue();

    expect($count)->toBe(0)
        ->and($employee->onboardingTasks()->first()->status)->toBe('pending');
});

// ── checkAndNotifyCompletion ──────────────────────────────────────

test('checkAndNotifyCompletion sends notification when all tasks complete', function () {
    Notification::fake();

    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Done',
        'category' => 'hr',
        'sort_order' => 1,
        'status' => 'completed',
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    app(OnboardingService::class)->checkAndNotifyCompletion($employee);

    Notification::assertSentTo($employee->user, OnboardingCompletedNotification::class);
});

test('checkAndNotifyCompletion does not notify when tasks remain', function () {
    Notification::fake();

    $employee = makeOnboardingEmployee();

    OnboardingTask::create([
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => 'Pending',
        'category' => 'hr',
        'sort_order' => 1,
        'status' => 'pending',
        'is_completed' => false,
    ]);

    app(OnboardingService::class)->checkAndNotifyCompletion($employee);

    Notification::assertNothingSent();
});

// ── statusBadge ───────────────────────────────────────────────────

test('OnboardingTask statusBadge returns correct data for each status', function (string $status, string $expectedColor) {
    $task = new OnboardingTask(['status' => $status]);
    $badge = $task->statusBadge();

    expect($badge)->toHaveKey('label')
        ->and($badge)->toHaveKey('color')
        ->and($badge['color'])->toBe($expectedColor);
})->with([
    ['pending', 'zinc'],
    ['in_progress', 'blue'],
    ['completed', 'green'],
    ['overdue', 'red'],
    ['blocked', 'orange'],
]);
