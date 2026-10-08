<?php

use App\Livewire\Help\EmployeeGuide;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Help\RoleGuides;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Help & Guide role journeys: each reader gets only the journeys and sections
 * whose pages they can open, with menu paths from their own sidebar, and no
 * link that leads to a 403 / 404. Route references are checked so a removed or
 * renamed page breaks a test instead of the guide.
 */
function rgUser(string $slug): User
{
    $department = Department::factory()->create();
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

test('every route the guide refers to exists', function () {
    $missing = collect(app(RoleGuides::class)->referencedRoutes())->reject(fn (string $r) => Route::has($r))->values()->all();

    expect($missing)->toBe([], 'Guide refers to routes that no longer exist: '.implode(', ', $missing));
});

test('each role gets exactly the journeys it can use', function (string $slug, array $has, array $hasNot) {
    $journeys = array_keys(app(RoleGuides::class)->journeys(rgUser($slug)));

    foreach ($has as $id) {
        expect($journeys)->toContain($id);
    }
    foreach ($hasNot as $id) {
        expect($journeys)->not->toContain($id);
    }
})->with([
    'employee' => ['employee', ['employee'], ['manager', 'department-head', 'hr-admin', 'finance', 'super-admin']],
    'manager' => ['manager', ['employee', 'manager'], ['department-head', 'hr-admin', 'finance', 'super-admin']],
    'department head' => ['department_head', ['employee', 'department-head'], ['hr-admin', 'finance', 'super-admin']],
    'finance' => ['finance', ['employee', 'finance'], ['manager', 'hr-admin', 'super-admin']],
    'hr admin' => ['hr_admin', ['employee', 'hr-admin'], ['super-admin']],
    'super admin' => ['super_admin', ['hr-admin', 'finance', 'super-admin'], []],
]);

test('the guide opens on the reader\'s main role journey', function (string $slug, string $journey) {
    Livewire::actingAs(rgUser($slug))->test(EmployeeGuide::class)->assertSet('journey', $journey);
})->with([
    ['employee', 'employee'],
    ['manager', 'manager'],
    ['department_head', 'department-head'],
    ['finance', 'finance'],
    ['hr_admin', 'hr-admin'],
    ['super_admin', 'super-admin'],
]);

test('asking for another role\'s journey never shows it', function () {
    Livewire::actingAs(rgUser('employee'))
        ->withQueryParams(['journey' => 'hr-admin'])
        ->test(EmployeeGuide::class)
        ->assertSet('journey', 'employee')
        ->assertDontSee('HR Admin Guide')
        ->assertDontSee('Manage Employees');
});

test('menu paths come from the reader\'s own sidebar and are always resolved', function (string $slug) {
    $user = rgUser($slug);
    // What a step would say if a {menu:route} were not in the reader's sidebar.
    $fallbacks = collect(app(RoleGuides::class)->referencedRoutes())
        ->reject(fn (string $r) => str_starts_with($r, 'dashboard'))
        ->map(fn (string $r) => 'the '.str_replace(['.', '-'], ' ', $r).' page');

    // The Employee journey is written prose (EmployeeGuide) with no menu placeholders.
    foreach (collect(app(RoleGuides::class)->journeys($user))->except('employee') as $journey) {
        $text = json_encode($journey['sections'], JSON_UNESCAPED_UNICODE);

        expect($text)->not->toContain('{menu:', "{$slug}/{$journey['id']}: unresolved menu placeholder");

        foreach ($fallbacks as $fallback) {
            expect($text)->not->toContain($fallback, "{$slug}/{$journey['id']}: a step names a page outside the reader's menu");
        }
    }
})->with(['manager', 'department_head', 'finance', 'hr_admin', 'super_admin']);

test('a manager\'s steps use the manager\'s real menu labels', function () {
    $sections = collect(app(RoleGuides::class)->journeys(rgUser('manager'))['manager']['sections'])->keyBy('id');

    expect($sections['mgr-leave']['steps'][0])->toBe('Open Approvals → Team Leave.')
        ->and($sections['mgr-team-attendance']['steps'][0])->toBe('Open Approvals → Team Attendance.');
});

test('every link in every journey opens for that reader', function (string $slug) {
    $user = rgUser($slug);

    foreach (app(RoleGuides::class)->journeys($user) as $journey) {
        foreach ($journey['sections'] as $section) {
            foreach ($section['links'] as $link) {
                $status = $this->actingAs($user)->get($link['url'])->getStatusCode();

                expect($status)->toBeLessThan(400, "{$slug}/{$journey['id']}/{$section['id']}: '{$link['label']}' returns {$status}");
            }
        }
    }
})->with(['manager', 'department_head', 'finance', 'hr_admin', 'super_admin']);

test('regularisation is explained as an HR decision to managers, and as a task to HR', function () {
    $manager = collect(app(RoleGuides::class)->journeys(rgUser('manager'))['manager']['sections'])->pluck('id');
    $hr = collect(app(RoleGuides::class)->journeys(rgUser('hr_admin'))['hr-admin']['sections'])->pluck('id');

    expect($manager)->toContain('mgr-regularisation')
        ->and($hr)->toContain('hr-regularisation');
});

test('the page renders each journey a reader has', function (string $slug) {
    $user = rgUser($slug);

    foreach (array_keys(app(RoleGuides::class)->journeys($user)) as $id) {
        Livewire::actingAs($user)->test(EmployeeGuide::class)
            ->set('journey', $id)
            ->assertOk()
            ->assertSet('journey', $id);
    }
})->with(['manager', 'department_head', 'finance', 'hr_admin', 'super_admin']);
