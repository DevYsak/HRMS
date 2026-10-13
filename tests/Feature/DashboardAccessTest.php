<?php

use App\Enums\DataScope;
use App\Enums\UserRole;
use App\Livewire\Settings\RoleManager;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Navigation\DashboardAccess;
use App\Services\Navigation\Sidebar;
use App\Services\Security\PermissionAdministration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Dashboard Access: Roles & Permissions decides which dashboards a user sees
 * in the sidebar, may open by URL, and lands on — never the role's name.
 *
 * Nikita Dalal — Department Head of UK Operations.
 */
function daUser(string $slug, ?Department $department = null, string $name = 'User'): User
{
    $department ??= Department::factory()->create();
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id, 'password' => bcrypt('Secret#2026')]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

/** Written straight to the table, so a corrupt stored scope can be tested too. */
function daOverride(User $user, string $key, string $effect = 'grant', ?string $scope = null): void
{
    DB::table('user_permission_overrides')->insert([
        'user_id' => $user->id, 'permission_id' => Permission::where('key', $key)->value('id'),
        'effect' => $effect, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Cache::forget(UserPermissionOverride::cacheKey($user->id));
}

/** @return array<int, string> the dashboard entries in the user's sidebar */
function daMenu(User $user): array
{
    $labels = collect(app(Sidebar::class)->groups($user))->flatMap(fn (array $g) => collect($g['items'])->pluck('label'))->all();

    return array_values(array_intersect($labels, ['Main Dashboard', 'Team View', 'Department View', 'Executive View', 'Finance View']));
}

beforeEach(function () {
    $this->uk = Department::factory()->create(['name' => 'UK Operations']);
    $this->nikita = daUser('department_head', $this->uk, 'Nikita Dalal');
});

test('the five Dashboard Access permissions exist, and the two views are scoped', function () {
    $permissions = Permission::whereIn('key', ['view_main_dashboard', 'view_team_dashboard', 'view_department_dashboard', 'view_executive_dashboard', 'view_finance_dashboard'])
        ->get()->keyBy('key');

    expect($permissions)->toHaveCount(5)
        ->and($permissions['view_team_dashboard']->is_scoped)->toBeTruthy()
        ->and($permissions['view_department_dashboard']->is_scoped)->toBeTruthy()
        ->and($permissions['view_main_dashboard']->is_scoped)->toBeFalsy()
        ->and(Permission::where('key', 'view_dashboard')->exists())->toBeFalse();
});

test('each role gets its dashboards by default', function (string $slug, array $menu) {
    expect(daMenu(daUser($slug)))->toBe($menu);
})->with([
    'department head' => ['department_head', ['Team View']],
    'manager' => ['manager', ['Team View']],
    'finance' => ['finance', ['Finance View']],
    'employee' => ['employee', ['Main Dashboard']],
    'hr admin' => ['hr_admin', ['Main Dashboard', 'Team View', 'Executive View', 'Finance View']],
    // D1: department-scoped, so no company-wide Executive View.
    'director' => ['director', ['Team View', 'Department View']],
]);

test('Nikita sees only Team View and every other dashboard URL is closed', function () {
    expect(daMenu($this->nikita))->toBe(['Team View']);

    $this->actingAs($this->nikita)->get(route('dashboard.manager'))->assertOk();
    foreach (['dashboard.main', 'dashboard.department', 'dashboard.executive', 'dashboard.director', 'dashboard.finance'] as $route) {
        $this->actingAs($this->nikita)->get(route($route))->assertForbidden();
    }
});

test('Department View shows for Nikita only once it is granted', function () {
    daOverride($this->nikita, 'view_department_dashboard');
    $nikita = $this->nikita->fresh();

    expect(daMenu($nikita))->toBe(['Team View', 'Department View']);
    $this->actingAs($nikita)->get(route('dashboard.department'))->assertOk()->assertSee('UK Operations');
    // Department before Team in the landing order.
    expect(app(DashboardAccess::class)->landing($nikita))->toBe('dashboard.department');
});

test('Team View permission alone opens Team View', function () {
    $employee = daUser('employee');
    $this->actingAs($employee)->get(route('dashboard.manager'))->assertForbidden();

    daOverride($employee, 'view_team_dashboard', scope: DataScope::Team->value);
    $employee = $employee->fresh();

    expect(daMenu($employee))->toContain('Team View');
    $this->actingAs($employee)->get(route('dashboard.manager'))->assertOk();
});

test('a user override removes a dashboard the role grants', function () {
    $manager = daUser('manager');
    daOverride($manager, 'view_team_dashboard', 'revoke');
    $manager = $manager->fresh();

    expect(daMenu($manager))->not->toContain('Team View');
    $this->actingAs($manager)->get(route('dashboard.manager'))->assertForbidden();
});

test('an unknown or too-narrow scope keeps the view closed', function () {
    $employee = daUser('employee');
    daOverride($employee, 'view_team_dashboard', scope: 'galaxy');
    $employee = $employee->fresh();
    expect(app(DashboardAccess::class)->allows($employee, DashboardAccess::TEAM))->toBeFalse();
    $this->actingAs($employee)->get(route('dashboard.manager'))->assertForbidden();

    // Department View at team level is not a department view.
    $manager = daUser('manager');
    daOverride($manager, 'view_department_dashboard', scope: DataScope::Team->value);
    expect(app(DashboardAccess::class)->allows($manager->fresh(), DashboardAccess::DEPARTMENT))->toBeFalse();

    // …and the editors refuse to save it.
    $refusal = app(PermissionAdministration::class)->refusalForScope(
        User::where('role', UserRole::SuperAdmin)->first() ?? daUser('super_admin'),
        Permission::where('key', 'view_department_dashboard')->first(),
        DataScope::Team,
    );
    expect($refusal)->toContain('department');
});

test('"/" and the login land on the first permitted dashboard', function (string $slug, ?string $route) {
    $user = daUser($slug);

    $this->post('/login', ['email' => $user->email, 'password' => 'Secret#2026'])->assertRedirect('/');
    $response = $this->get('/');
    $route === null ? $response->assertOk() : $response->assertRedirect(route($route));
})->with([
    'department head → Team View' => ['department_head', 'dashboard.manager'],
    'finance → Finance View' => ['finance', 'dashboard.finance'],
    'hr admin → Main Dashboard (HR overview)' => ['hr_admin', null],
    'employee → Main Dashboard (self-service)' => ['employee', null],
]);

test('a role with no dashboard lands on a safe page instead of a 403', function () {
    $role = Role::create(['name' => 'Auditor', 'slug' => 'auditor', 'is_system' => false, 'is_active' => true]);
    $user = User::factory()->create(['role' => UserRole::Employee, 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    expect(daMenu($user))->toBe([]);
    $this->actingAs($user)->get('/')->assertRedirect(route('attendance.my'));
});

test('impersonation uses the target user\'s dashboards', function () {
    $admin = daUser('super_admin');
    expect(daMenu($admin))->toContain('Main Dashboard');

    $this->actingAs($admin)->post(route('impersonate.start', $this->nikita))->assertRedirect();

    $this->get(route('dashboard.manager'))->assertOk();
    $this->get(route('dashboard.main'))->assertForbidden();
    $this->get(route('dashboard.finance'))->assertForbidden();
    $this->get('/')->assertRedirect(route('dashboard.manager'));
});

test('the role editor shows what the role will see, and offers only valid scopes', function () {
    $admin = daUser('super_admin');
    $role = Role::where('slug', 'department_head')->first();

    Livewire::actingAs($admin)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->assertSeeHtml('data-dashboard-access')
        ->assertSeeHtml('data-dashboard-visible>✓ Team View')
        ->assertSee('Dashboard Access')
        ->assertViewHas('roleSummary', fn (array $s) => $s['dashboards']['visible'] === ['Team View']
            && in_array('Main Dashboard', $s['dashboards']['hidden'], true)
            && in_array('Department View', $s['dashboards']['hidden'], true)
            && $s['dashboards']['lands_on'] === 'Team View');

    expect(DashboardAccess::summarise(['view_department_dashboard'], ['view_department_dashboard' => DataScope::Team])['visible'])->toBe([]);
});
