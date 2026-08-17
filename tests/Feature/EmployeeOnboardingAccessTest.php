<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\Onboarding\MyOnboarding;
use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Services\EmployeeMenu;
use Livewire\Livewire;

/**
 * Onboarding tasks were created for every new hire, including tasks the
 * employee themselves owned — but every screen that showed them sat behind
 * role:manage-employees. People were assigned work they could not see.
 */
function eoaEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    // EmployeeObserver assigns the onboarding template on creation, so a fresh
    // employee already has tasks. Clear them so each test controls its own set.
    OnboardingTask::where('employee_id', $employee->id)->delete();

    return $employee;
}

function eoaTask(Employee $employee, string $ownerRole, array $attributes = []): OnboardingTask
{
    return OnboardingTask::create($attributes + [
        'employee_id' => $employee->id,
        'phase' => 'onboarding',
        'title' => ucfirst($ownerRole).' task',
        'owner_role' => $ownerRole,
        'is_completed' => false,
        'status' => 'pending',
        'due_date' => now()->addWeek()->toDateString(),
    ]);
}

test('an employee can reach their own onboarding checklist', function () {
    $employee = eoaEmployee();

    $this->withoutVite()->actingAs($employee->user)->get(route('onboarding.my'))->assertOk();
});

test('the checklist separates the employee tasks from the ones handled for them', function () {
    $employee = eoaEmployee();
    eoaTask($employee, 'employee', ['title' => 'Submit your ID proof']);
    eoaTask($employee, 'it', ['title' => 'Issue laptop']);
    eoaTask($employee, 'hr', ['title' => 'File the contract']);

    Livewire::actingAs($employee->user)->test(MyOnboarding::class)
        ->assertOk()
        ->assertViewHas('mine', fn ($m) => $m->count() === 1 && $m->first()->title === 'Submit your ID proof')
        ->assertViewHas('others', fn ($o) => $o->count() === 2)
        ->assertViewHas('total', 3);
});

test('an employee can complete a task they own', function () {
    $employee = eoaEmployee();
    $task = eoaTask($employee, 'employee');

    Livewire::actingAs($employee->user)->test(MyOnboarding::class)
        ->call('toggleComplete', $task->id);

    $fresh = $task->fresh();

    expect($fresh->is_completed)->toBeTrue()
        ->and($fresh->status)->toBe('completed')
        ->and($fresh->completed_by)->toBe($employee->user->id);
});

test('an employee cannot complete a task another team owns', function () {
    // Visible for context, not theirs to tick.
    $employee = eoaEmployee();
    $task = eoaTask($employee, 'it');

    Livewire::actingAs($employee->user)->test(MyOnboarding::class)
        ->call('toggleComplete', $task->id);

    expect($task->fresh()->is_completed)->toBeFalse();
});

test('an employee cannot touch somebody else onboarding task', function () {
    $employee = eoaEmployee();
    $colleague = eoaEmployee();
    $theirTask = eoaTask($colleague, 'employee');

    Livewire::actingAs($employee->user)->test(MyOnboarding::class)
        ->call('toggleComplete', $theirTask->id);

    expect($theirTask->fresh()->is_completed)->toBeFalse();
});

test('the checklist shows an empty state rather than an error when there are no tasks', function () {
    $employee = eoaEmployee();
    OnboardingTask::where('employee_id', $employee->id)->delete();

    Livewire::actingAs($employee->user)->test(MyOnboarding::class)
        ->assertOk()
        ->assertViewHas('total', 0)
        ->assertSee('No onboarding tasks assigned.');
});

test('my onboarding appears in the employee menu', function () {
    $keys = collect(app(EmployeeMenu::class)->visible())->pluck('key');

    expect($keys)->toContain('onboarding');
});

test('the dashboard surfaces outstanding onboarding tasks', function () {
    $employee = eoaEmployee();
    OnboardingTask::where('employee_id', $employee->id)->delete();
    eoaTask($employee, 'employee');
    eoaTask($employee, 'employee');
    eoaTask($employee, 'it');

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        // Only the employee's own open tasks are counted — an IT task is not
        // something they can act on.
        ->assertViewHas('myOnboardingOpen', 2)
        ->assertSee('Finish your onboarding');
});

test('the dashboard surfaces an incomplete profile', function () {
    $employee = eoaEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('profileCompletion', fn ($c) => isset($c['percent']) && is_int($c['percent']));
});
