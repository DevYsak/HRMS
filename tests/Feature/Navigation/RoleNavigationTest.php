<?php

use App\Enums\UserRole;
use App\Models\AttendanceRegularisation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Navigation\DashboardLanding;
use App\Services\Navigation\Sidebar;

/**
 * Navigation is built from what each user can open: every link it shows
 * must open (never a 403), each role gets its own menu, and "Dashboard"
 * lands on the right page — decided by permissions, not role names.
 */
function navUser(string $slug, array $userAttributes = []): User
{
    $department = Department::factory()->create();
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(array_merge(['role' => $role->legacyBucket(), 'role_id' => $role->id], $userAttributes));
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

/** @return array<int, string> */
function navLabels(User $user): array
{
    $nav = app(Sidebar::class);

    return collect($nav->groups($user))
        ->push($nav->settings($user) ?? ['items' => []])
        ->flatMap(fn (array $g) => collect($g['items'])->pluck('label'))
        ->all();
}

dataset('nav roles', ['super_admin', 'hr_admin', 'director', 'department_head', 'manager', 'finance', 'coordinator', 'employee']);

test('every link a role is shown opens without a 403 or an error', function (string $slug) {
    $user = navUser($slug);
    $nav = app(Sidebar::class);

    $items = collect($nav->groups($user))
        ->push($nav->settings($user) ?? ['items' => []])
        ->flatMap(fn (array $g) => $g['items']);

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        $status = $this->actingAs($user)->get($item['url'])->getStatusCode();

        expect($status)->not->toBe(403, "{$slug}: '{$item['label']}' ({$item['route']}) is in the menu but returns 403")
            ->and($status)->toBeLessThan(500, "{$slug}: '{$item['label']}' ({$item['route']}) returns {$status}");
    }
})->with('nav roles');

test('the sidebar renders for every role', function (string $slug) {
    $this->actingAs(navUser($slug))->get(route('help.getting-started'))->assertOk();
})->with('nav roles');

test('employees and coordinators get the self-service menu', function (string $slug) {
    expect(app(Sidebar::class)->isSelfServiceOnly(navUser($slug)))->toBeTrue();
})->with(['employee', 'coordinator']);

test('staff roles get the workspace menu', function (string $slug) {
    expect(app(Sidebar::class)->isSelfServiceOnly(navUser($slug)))->toBeFalse();
})->with(['super_admin', 'hr_admin', 'director', 'department_head', 'manager', 'finance']);

test('a Director gets the Director workspace, not the Finance menu', function () {
    $labels = navLabels(navUser('director'));

    expect($labels)->toContain('Manage Employees')
        ->and($labels)->toContain('Team Leave')
        ->and($labels)->toContain('Finance Approval')
        ->and($labels)->not->toContain('Run Payroll')
        ->and($labels)->not->toContain('Incentives')
        ->and($labels)->not->toContain('Reimbursements');
});

test('a Manager sees team approvals but no people administration or settings', function () {
    $user = navUser('manager');
    $labels = navLabels($user);

    expect($labels)->toContain('Team Leave')
        ->and($labels)->toContain('Team Attendance')
        ->and($labels)->not->toContain('Manage Employees')
        ->and($labels)->not->toContain('All Attendance')
        ->and(app(Sidebar::class)->settings($user))->toBeNull();
});

test('Finance sees payroll work and no team approvals', function () {
    $labels = navLabels(navUser('finance'));

    expect($labels)->toContain('Run Payroll')
        ->and($labels)->toContain('Finance Approval')
        ->and($labels)->not->toContain('Team Leave')
        ->and($labels)->not->toContain('Manage Employees');
});

test('HR sees people, attendance, leave and settings', function () {
    $labels = navLabels(navUser('hr_admin'));

    expect($labels)->toContain('Manage Employees')
        ->and($labels)->toContain('All Attendance')
        ->and($labels)->toContain('Leave Management')
        ->and($labels)->toContain('Roles & Permissions');
});

test('a per-user grant changes the menu without a new role', function () {
    $user = navUser('employee');
    expect(app(Sidebar::class)->isSelfServiceOnly($user))->toBeTrue();

    UserPermissionOverride::create([
        'user_id' => $user->id,
        'permission_id' => Permission::where('key', 'approve_leave')->value('id'),
        'effect' => 'grant',
    ]);

    expect(navLabels($user->fresh()))->toContain('Team Leave');
});

test('company-wide reports are hidden from department-scoped HR', function () {
    $scoped = navUser('hr_admin', ['scope_departments' => [Department::factory()->create()->id]]);

    expect(navLabels($scoped))->not->toContain('Attendance Summary')
        ->and(navLabels($scoped))->not->toContain('Performance Summary')
        ->and(navLabels(navUser('hr_admin')))->toContain('Attendance Summary');
});

test('"Dashboard" lands each role on its own page', function (string $slug, ?string $route, string $view) {
    $user = navUser($slug);
    $landing = app(DashboardLanding::class);

    expect($landing->route($user))->toBe($route)
        ->and($landing->view($user))->toBe($view);

    $response = $this->actingAs($user)->get(route('dashboard'));
    $route === null ? $response->assertOk() : $response->assertRedirect(route($route));
})->with([
    'super admin' => ['super_admin', null, DashboardLanding::COMPANY],
    'hr admin' => ['hr_admin', null, DashboardLanding::HR],
    'director' => ['director', 'dashboard.director', DashboardLanding::SELF_SERVICE],
    'department head' => ['department_head', 'dashboard.department', DashboardLanding::SELF_SERVICE],
    'manager' => ['manager', 'dashboard.manager', DashboardLanding::SELF_SERVICE],
    'employee' => ['employee', null, DashboardLanding::SELF_SERVICE],
]);

test('the landing page is not listed twice in the menu', function () {
    $labels = navLabels(navUser('manager'));

    expect($labels)->toContain('Dashboard')
        ->and($labels)->not->toContain('Team View');
});

test('the Department dashboard shows the headed department and refuses non-heads', function () {
    $head = navUser('department_head');
    $department = Department::where('head_id', $head->id)->first();

    $this->actingAs($head)->get(route('dashboard.department'))
        ->assertOk()
        ->assertSee($department->name);

    $this->actingAs(navUser('manager'))->get(route('dashboard.department'))->assertForbidden();
});

test('the Command Center badge counts only requests in the approver\'s reach', function () {
    $manager = navUser('manager');
    $report = Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'manager_id' => $manager->id, 'status' => 'active']);
    $stranger = Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'manager_id' => null, 'status' => 'active']);

    $date = now()->subDay()->toDateString();

    foreach ([$report, $stranger] as $employee) {
        AttendanceRegularisation::create([
            'employee_id' => $employee->id, 'work_date' => $date,
            'requested_check_in' => "$date 09:00:00", 'requested_check_out' => "$date 18:00:00",
            'reason' => 'Forgot to punch', 'status' => 'pending', 'stage' => 'manager_review',
        ]);
    }

    $hr = navUser('hr_admin');
    $badge = fn (User $u) => collect(app(Sidebar::class)->groups($u))->flatMap(fn ($g) => $g['items'])->firstWhere('route', 'attendance.command-center')['badge'] ?? null;

    expect($badge($manager))->toBe('1')
        ->and($badge($hr))->toBe('2');
});
