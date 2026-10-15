<?php

use App\Enums\UserRole;
use App\Livewire\Settings\RoleManager;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Navigation\DashboardAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Phase 1 role policy decisions (15 Oct 2026):
 *  - Department Head: Team View + Department View, department scope only;
 *    Employee 360 in the department; no automatic employee editing.
 *  - Finance: no performance management by default (explicit grants kept).
 *  - Coordinator: Team View (department scope), no Main dashboard, no
 *    approvals implied by the role.
 *  - HR administers the Department Head role.
 */
function rpdUser(string $slug, Department $department, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

function rpdStaff(Department $department, string $name): Employee
{
    $user = User::factory()->create(['name' => $name, 'role' => UserRole::Employee, 'role_id' => Role::where('slug', 'employee')->value('id')]);

    return Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
}

beforeEach(function () {
    $this->ops = Department::factory()->create(['name' => 'UK Operations']);
    $this->sales = Department::factory()->create(['name' => 'Sales Bravo']);
    $this->insider = rpdStaff($this->ops, 'Olive Opsperson');
    $this->outsider = rpdStaff($this->sales, 'Sam Salesoutside');
});

test('a Department Head sees Team View and Department View for their department only', function () {
    $head = rpdUser('department_head', $this->ops, 'Nikita Head');

    expect(app(DashboardAccess::class)->permitted($head))->toBe([DashboardAccess::TEAM, DashboardAccess::DEPARTMENT]);

    foreach (['dashboard.manager', 'dashboard.department'] as $route) {
        $this->actingAs($head)->get(route($route))->assertOk()->assertDontSee('Sam Salesoutside');
    }
    $this->actingAs($head)->get(route('dashboard.department'))->assertSee('UK Operations');
});

test('a Department Head opens Employee 360 in the department but does not edit employees', function () {
    $head = rpdUser('department_head', $this->ops, 'Nikita Head');

    $this->actingAs($head)->get(route('employees.360', $this->insider))->assertOk();
    $this->actingAs($head)->get(route('employees.360', $this->outsider))->assertForbidden();
    $this->actingAs($head)->get(route('employees.edit', $this->insider))->assertForbidden();
    expect($head->hasPermission('manage_employees'))->toBeFalse()
        ->and($head->hasPermission('edit_employee'))->toBeFalse();
});

test('Finance has no performance management by default', function () {
    $finance = rpdUser('finance', $this->ops, 'Fin Person');

    foreach (['performance.team', 'performance.pip.manage', 'performance.promotions.manage', 'performance.kpi-management'] as $route) {
        $this->actingAs($finance)->get(route($route))->assertForbidden();
    }
    // Payroll work is untouched.
    $this->actingAs($finance)->get(route('dashboard.finance'))->assertOk();
    $this->actingAs($finance)->get(route('payroll.overview'))->assertOk();
});

test('a Finance user explicitly granted performance review keeps it', function () {
    $finance = rpdUser('finance', $this->ops, 'Fin Reviewer');
    UserPermissionOverride::create(['user_id' => $finance->id, 'permission_id' => Permission::where('key', 'review_performance')->value('id'), 'effect' => 'grant']);

    $this->actingAs($finance->fresh())->get(route('performance.team'))->assertOk();
});

test('a Coordinator lands on Team View for their department, with no Main dashboard and no approvals', function () {
    $coordinator = rpdUser('coordinator', $this->ops, 'Cora Coordinator');

    expect(app(DashboardAccess::class)->permitted($coordinator))->toBe([DashboardAccess::TEAM])
        ->and(app(DashboardAccess::class)->landing($coordinator))->toBe('dashboard.manager');

    $this->actingAs($coordinator)->get('/')->assertRedirect(route('dashboard.manager'));
    $this->actingAs($coordinator)->get(route('dashboard.manager'))->assertOk()->assertDontSee('Sam Salesoutside');
    $this->actingAs($coordinator)->get(route('dashboard.main'))->assertForbidden();

    foreach (['time-off.team', 'overtime.manage', 'wfh.manage', 'employees.edit'] as $route) {
        $url = $route === 'employees.edit' ? route($route, $this->insider) : route($route);
        $this->actingAs($coordinator)->get($url)->assertForbidden();
    }
    $this->actingAs($coordinator)->get(route('attendance.live'))->assertOk();
});

test('HR administers the Department Head role but never the Super Admin role', function () {
    $hr = rpdUser('hr_admin', $this->ops, 'Hana HR');

    Livewire::actingAs($hr)->test(RoleManager::class)
        ->call('openEdit', Role::where('slug', 'department_head')->value('id'))
        ->assertSet('showModal', true);

    Livewire::actingAs($hr)->test(RoleManager::class)
        ->call('openEdit', Role::where('slug', 'super_admin')->value('id'))
        ->assertSet('showModal', false);
});

test('the policy migration updates the built-in roles and never touches per-user grants', function () {
    $migration = require database_path('migrations/2026_10_15_111021_apply_phase1_role_policy_decisions.php');
    $role = fn (string $slug) => Role::where('slug', $slug)->first();
    $perm = fn (string $key) => Permission::where('key', $key)->value('id');
    $finance = rpdUser('finance', $this->ops, 'Fin Granted');
    UserPermissionOverride::create(['user_id' => $finance->id, 'permission_id' => $perm('manage_pip'), 'effect' => 'grant']);

    // An older database: the previous defaults.
    $migration->down();
    expect($role('coordinator')->permissionKeys())->toContain('view_main_dashboard')
        ->and($role('finance')->permissionKeys())->toContain('review_performance')
        ->and($role('department_head')->permissionKeys())->not->toContain('view_department_dashboard');

    $migration->up();
    $migration->up();   // idempotent

    expect($role('coordinator')->permissionKeys())->toContain('view_team_dashboard')->not->toContain('view_main_dashboard')
        ->and($role('finance')->permissionKeys())->not->toContain('review_performance')->not->toContain('manage_pip')->not->toContain('manage_promotions')
        ->and($role('department_head')->permissionKeys())->toContain('view_department_dashboard')
        ->and(DB::table('user_permission_overrides')->where('user_id', $finance->id)->where('permission_id', $perm('manage_pip'))->exists())->toBeTrue()
        ->and($finance->fresh()->hasPermission('manage_pip'))->toBeTrue();
});
