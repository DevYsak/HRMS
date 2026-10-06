<?php

use App\Enums\UserRole;
use App\Livewire\Help\GettingStarted;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\EmployeeDashboardService;
use App\Services\EmployeeMenu;
use App\Services\Help\GettingStarted as GettingStartedContent;
use Livewire\Livewire;

/**
 * The new-employee tutorial (/help/getting-started): first sign-in, password,
 * profile and leave, with live "done" ticks and links only to pages the
 * reader can open. Reached after the first password is set, from the user
 * menu, the Employee Guide and — for new joiners — the dashboard.
 */
function tutorialEmployee(array $employee = [], array $user = []): User
{
    $account = User::factory()->create($user + ['role' => UserRole::Employee]);
    Employee::factory()->create($employee + ['user_id' => $account->id, 'status' => 'active']);

    return $account->fresh();
}

function tutorialStep(User $user, string $id): array
{
    return collect(app(GettingStartedContent::class)->steps($user))->firstWhere('id', $id);
}

test('guests are sent to the login page', function () {
    $this->get(route('help.getting-started'))->assertRedirect(route('login'));
});

test('an employee opens the tutorial covering login, profile and leave', function () {
    $this->withoutVite()->actingAs(tutorialEmployee())
        ->get(route('help.getting-started'))
        ->assertOk()
        ->assertSee('Getting started with')
        ->assertSee('Open your welcome email')
        ->assertSee('Choose your own password')
        ->assertSee('If you forget your password')
        ->assertSee('Check and update your profile')
        ->assertSee('Apply for leave')
        ->assertSee('Track, answer and cancel leave requests')
        ->assertSee('Regularisation requests go straight to HR');
});

test('the password rules shown match the environment and history limit', function () {
    config(['security.password_history_limit' => 5]);
    $points = implode(' ', tutorialStep(tutorialEmployee(), 'set-password')['points']);

    expect($points)->toContain('last 5 passwords')
        ->and($points)->toContain(app()->isProduction() ? 'at least 12 characters' : 'at least 8 characters');
});

test('the password step is ticked once the temporary password is replaced', function () {
    $user = tutorialEmployee();

    $user->forceFill(['must_change_password' => true])->save();
    expect(tutorialStep($user->fresh(), 'set-password')['done'])->toBeFalse();

    $user->forceFill(['must_change_password' => false])->save();
    expect(tutorialStep($user->fresh(), 'set-password')['done'])->toBeTrue();
});

test('the leave step is ticked once the employee has applied', function () {
    $user = tutorialEmployee();
    expect(tutorialStep($user, 'apply-leave')['done'])->toBeFalse();

    $type = LeaveType::create(['name' => 'Tutorial Leave', 'code' => 'TUT', 'category' => 'annual', 'allow_paid_request' => true]);
    LeaveRequest::create([
        'employee_id' => $user->employee->id, 'leave_type_id' => $type->id,
        'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->toDateString(),
        'days' => 1, 'reason' => 'Family visit', 'status' => 'pending',
    ]);

    expect(tutorialStep($user, 'apply-leave')['done'])->toBeTrue();
});

test('the profile step lists the fields by who may change them', function () {
    $step = tutorialStep(tutorialEmployee(), 'profile');

    expect($step['tiers'])->toHaveKeys(['editable', 'approval', 'locked'])
        ->and($step['links'][0]['url'])->toBe(route('profile.me'));
});

test('the tutorial renders the page with a done counter', function () {
    Livewire::actingAs(tutorialEmployee())->test(GettingStarted::class)
        ->assertViewHas('trackedCount', fn (int $n) => $n >= 3)
        ->assertSee('steps done');
});

// ── Entry points ───────────────────────────────────────────────────────────

test('the user menu offers the tutorial', function () {
    $this->withoutVite()->actingAs(tutorialEmployee())
        ->get(route('help.employee-guide'))
        ->assertSee(route('help.getting-started'), escape: false);
});

test('the dashboard offers the tutorial to a new joiner only', function () {
    $service = app(EmployeeDashboardService::class);

    $new = tutorialEmployee(['joining_date' => now()->subDays(3)->toDateString()]);
    $alerts = collect($service->build($new)['alerts'])->pluck('title');
    expect($alerts)->toContain('New here? Start the tutorial');

    $settled = tutorialEmployee(['joining_date' => now()->subYear()->toDateString()]);
    $alerts = collect($service->build($settled)['alerts'])->pluck('title');
    expect($alerts)->not->toContain('New here? Start the tutorial');
});

test('the profile prompt and the sidebar both open My Profile', function () {
    $user = tutorialEmployee();

    $profileAlert = collect(app(EmployeeDashboardService::class)->build($user)['alerts'])->firstWhere('title', 'Complete your profile');
    $menuItem = collect(app(EmployeeMenu::class)->visible())->firstWhere('key', 'profile');

    if ($profileAlert !== null) {
        expect($profileAlert['url'])->toBe(route('profile.me'));
    }
    expect($menuItem['route'])->toBe('profile.me');
});
