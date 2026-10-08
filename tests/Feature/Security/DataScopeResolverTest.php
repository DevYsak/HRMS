<?php

use App\Enums\DataScope;
use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\DepartmentTeamMember;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Security\PermissionScopes;
use App\Services\Security\ScopeResolver;

/**
 * Action + data scope: who reaches whose records for each permission.
 *
 * World: departments Ops and Sales. The actor works in Ops and has one
 * direct report in Sales; Ops and Sales each hold one more employee.
 */
function dsWorld(): array
{
    $ops = Department::factory()->create(['name' => 'Ops']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $finance = Department::factory()->create(['name' => 'Finance']);

    return compact('ops', 'sales', 'finance');
}

function dsEmployee(Department $department, array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'department_id' => $department->id,
        'status' => 'active',
        'manager_id' => null,
    ], $attributes));
}

function dsActor(UserRole|Role $role, Department $department, array $userAttributes = []): User
{
    $user = User::factory()->create(array_merge(
        $role instanceof Role ? ['role' => $role->legacyBucket(), 'role_id' => $role->id] : ['role' => $role],
        $userAttributes,
    ));
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function dsScope(Role $role, string $key, DataScope $scope, array $departmentIds = []): void
{
    $role->permissions()->syncWithoutDetaching([
        Permission::where('key', $key)->value('id') => ['scope' => $scope->value, 'department_ids' => json_encode($departmentIds)],
    ]);
    $role->flushPermissionCache();
}

function dsOverride(User $user, string $key, string $effect, ?DataScope $scope = null, array $departmentIds = []): UserPermissionOverride
{
    return UserPermissionOverride::create([
        'user_id' => $user->id,
        'permission_id' => Permission::where('key', $key)->value('id'),
        'effect' => $effect,
        'scope' => $scope,
        'department_ids' => $departmentIds,
    ]);
}

function dsRole(string $slug): Role
{
    return Role::where('slug', $slug)->firstOrFail();
}

function dsIds(User $user, string $permission): ?array
{
    $ids = app(ScopeResolver::class)->employeeIds($user, $permission);

    return $ids === null ? null : collect($ids)->sort()->values()->all();
}

function dsSorted(Employee ...$employees): array
{
    return collect($employees)->pluck('id')->sort()->values()->all();
}

// ─── Catalogue ──────────────────────────────────────────────────────────

test('the new keys exist, scoped keys are flagged, and Finance gets summary access', function () {
    foreach (['export_attendance', 'view_overtime', 'manage_overtime', 'manage_notifications'] as $key) {
        expect(Permission::where('key', $key)->exists())->toBeTrue();
    }

    expect(Permission::where('key', 'approve_leave')->value('is_scoped'))->toBeTrue()
        ->and(Permission::where('key', 'manage_settings')->value('is_scoped'))->toBeFalse()
        ->and(Permission::where('is_scoped', true)->count())->toBe(count(PermissionScopes::SCOPED));

    $finance = dsRole('finance');
    expect($finance->hasPermission('view_attendance'))->toBeTrue()
        ->and($finance->hasPermission('view_leave_management'))->toBeTrue()
        ->and($finance->hasPermission('approve_leave'))->toBeFalse()
        ->and($finance->hasPermission('manage_attendance'))->toBeFalse()
        ->and($finance->hasPermission('manage_documents'))->toBeFalse();

    expect(dsRole('super_admin')->hasPermission('manage_notifications'))->toBeTrue();
});

// ─── Role defaults (no scope chosen yet = today's behaviour) ────────────

test('role defaults resolve to the pre-scope reach', function (string $slug, string $permission, DataScope $expected) {
    $world = dsWorld();
    $user = dsActor(UserRole::from($slug), $world['ops']);

    expect(app(ScopeResolver::class)->scopeFor($user, $permission))->toBe($expected);
})->with([
    'super admin: anything' => ['super_admin', 'approve_leave', DataScope::All],
    'hr admin: leave approval' => ['hr_admin', 'approve_leave', DataScope::All],
    'hr admin: attendance' => ['hr_admin', 'view_attendance', DataScope::All],
    'director: employees' => ['director', 'view_employee', DataScope::All],
    'finance: payroll' => ['finance', 'view_payroll', DataScope::All],
    'finance: attendance summary' => ['finance', 'view_attendance', DataScope::All],
    'finance: leave summary' => ['finance', 'view_leave_management', DataScope::All],
    'finance: performance stays team' => ['finance', 'review_performance', DataScope::Team],
    'finance: no leave approval' => ['finance', 'approve_leave', DataScope::None],
    'manager: leave approval' => ['manager', 'approve_leave', DataScope::Team],
    'manager: attendance' => ['manager', 'view_attendance', DataScope::Team],
    'manager: no payroll' => ['manager', 'view_payroll', DataScope::None],
    'coordinator: attendance' => ['coordinator', 'view_attendance', DataScope::Team],
    'employee: own performance' => ['employee', 'view_performance', DataScope::Own],
    'employee: no attendance admin' => ['employee', 'view_attendance', DataScope::None],
]);

test('a permission that does not touch employee data is unrestricted when held', function () {
    $world = dsWorld();
    $hr = dsActor(UserRole::HrAdmin, $world['ops']);

    expect(app(ScopeResolver::class)->scopeFor($hr, 'manage_settings'))->toBe(DataScope::All);
});

test('a custom role reaches the company only when it manages employees', function () {
    $world = dsWorld();
    $approver = Role::create(['name' => 'Shift Lead', 'slug' => 'shift-lead', 'is_system' => false, 'is_active' => true]);
    $approver->permissions()->sync(Permission::whereIn('key', ['approve_leave'])->pluck('id'));
    $people = Role::create(['name' => 'People Ops', 'slug' => 'people-ops', 'is_system' => false, 'is_active' => true]);
    $people->permissions()->sync(Permission::whereIn('key', ['manage_employees', 'approve_leave'])->pluck('id'));

    $resolver = app(ScopeResolver::class);

    expect($resolver->scopeFor(dsActor($approver, $world['ops']), 'approve_leave'))->toBe(DataScope::Team)
        ->and($resolver->scopeFor(dsActor($people, $world['ops']), 'approve_leave'))->toBe(DataScope::All);
});

// ─── Each scope level ───────────────────────────────────────────────────

test('each scope reaches exactly its employees', function (DataScope $scope, Closure $expected) {
    $world = dsWorld();
    $actor = dsActor(UserRole::Manager, $world['ops']);
    $report = dsEmployee($world['sales'], ['manager_id' => $actor->id]);
    $opsPeer = dsEmployee($world['ops']);
    $salesPeer = dsEmployee($world['sales']);
    $financePeer = dsEmployee($world['finance']);
    $self = $actor->employee;

    dsScope(dsRole('manager'), 'approve_leave', $scope, [$world['finance']->id]);

    expect(dsIds($actor->fresh(), 'approve_leave'))
        ->toBe($expected(compact('self', 'report', 'opsPeer', 'salesPeer', 'financePeer')));
})->with([
    'none' => [DataScope::None, fn () => []],
    'own' => [DataScope::Own, fn ($e) => dsSorted($e['self'])],
    'team' => [DataScope::Team, fn ($e) => dsSorted($e['self'], $e['report'])],
    'department' => [DataScope::Department, fn ($e) => dsSorted($e['self'], $e['report'], $e['opsPeer'])],
    'selected departments' => [DataScope::SelectedDepartments, fn ($e) => dsSorted($e['self'], $e['report'], $e['financePeer'])],
    'all' => [DataScope::All, fn () => null],
]);

test('department scope includes every department the user heads', function () {
    $world = dsWorld();
    $actor = dsActor(UserRole::Manager, $world['ops']);
    $world['sales']->update(['head_id' => $actor->id]);
    $salesPeer = dsEmployee($world['sales']);
    $financePeer = dsEmployee($world['finance']);

    dsScope(dsRole('manager'), 'view_attendance', DataScope::Department);
    $resolver = app(ScopeResolver::class);

    expect($resolver->covers($actor, 'view_attendance', $salesPeer))->toBeTrue()
        ->and($resolver->covers($actor, 'view_attendance', $financePeer))->toBeFalse()
        ->and(collect($resolver->departmentIds($actor, 'view_attendance'))->sort()->values()->all())
        ->toBe(collect([$world['ops']->id, $world['sales']->id])->sort()->values()->all());
});

test('team scope covers members of teams the user leads', function () {
    $world = dsWorld();
    $lead = dsActor(UserRole::Manager, $world['ops']);
    $member = dsEmployee($world['sales']);
    $outsider = dsEmployee($world['sales']);
    $team = DepartmentTeam::create(['department_id' => $world['ops']->id, 'name' => 'Night', 'team_lead_id' => $lead->employee->id, 'status' => 'active']);
    DepartmentTeamMember::create(['department_team_id' => $team->id, 'employee_id' => $member->id, 'is_active' => true]);

    $resolver = app(ScopeResolver::class);

    expect($resolver->covers($lead, 'approve_leave', $member))->toBeTrue()
        ->and($resolver->covers($lead, 'approve_leave', $outsider))->toBeFalse();
});

test('scopes are per permission on the same role', function () {
    $world = dsWorld();
    $actor = dsActor(UserRole::Manager, $world['ops']);
    $opsPeer = dsEmployee($world['ops']);

    dsScope(dsRole('manager'), 'view_attendance', DataScope::Department);
    $resolver = app(ScopeResolver::class);

    expect($resolver->covers($actor, 'view_attendance', $opsPeer))->toBeTrue()
        ->and($resolver->covers($actor, 'approve_leave', $opsPeer))->toBeFalse();
});

test('constrain filters a query to the reach, and to nothing for no access', function () {
    $world = dsWorld();
    $actor = dsActor(UserRole::Manager, $world['ops']);
    $report = dsEmployee($world['sales'], ['manager_id' => $actor->id]);
    dsEmployee($world['sales']);
    $resolver = app(ScopeResolver::class);

    $visible = $resolver->constrain(Employee::query(), $actor, 'approve_leave', 'id')->pluck('id')->sort()->values()->all();
    expect($visible)->toBe(dsSorted($actor->employee, $report));

    expect($resolver->constrain(Employee::query(), $actor, 'view_payroll', 'id')->count())->toBe(0);

    $hr = dsActor(UserRole::HrAdmin, $world['ops']);
    expect($resolver->constrain(Employee::query(), $hr, 'approve_leave', 'id')->count())->toBe(Employee::count());
});

// ─── Per-user overrides ─────────────────────────────────────────────────

test('a user override grants a permission the role lacks, at its own scope', function () {
    $world = dsWorld();
    $employee = dsActor(UserRole::Employee, $world['ops']);
    $salesPeer = dsEmployee($world['sales']);
    $opsPeer = dsEmployee($world['ops']);

    dsOverride($employee, 'view_attendance', UserPermissionOverride::GRANT, DataScope::SelectedDepartments, [$world['sales']->id]);
    $employee = $employee->fresh();
    $resolver = app(ScopeResolver::class);

    expect($employee->hasPermission('view_attendance'))->toBeTrue()
        ->and($resolver->resolve($employee, 'view_attendance')->source)->toBe('user_override')
        ->and($resolver->covers($employee, 'view_attendance', $salesPeer))->toBeTrue()
        ->and($resolver->covers($employee, 'view_attendance', $opsPeer))->toBeFalse()
        ->and($employee->effectivePermissionKeys())->toContain('view_attendance');
});

test('a user override narrows a role grant without touching the role', function () {
    $world = dsWorld();
    $hr = dsActor(UserRole::HrAdmin, $world['ops']);
    $colleague = dsActor(UserRole::HrAdmin, $world['ops']);

    dsOverride($hr, 'approve_leave', UserPermissionOverride::GRANT, DataScope::Department);
    $resolver = app(ScopeResolver::class);

    expect($resolver->scopeFor($hr->fresh(), 'approve_leave'))->toBe(DataScope::Department)
        ->and($resolver->scopeFor($colleague, 'approve_leave'))->toBe(DataScope::All);
});

test('a revoke override removes the permission and every reach it gave', function () {
    $world = dsWorld();
    $manager = dsActor(UserRole::Manager, $world['ops']);
    $report = dsEmployee($world['sales'], ['manager_id' => $manager->id]);

    dsOverride($manager, 'approve_leave', UserPermissionOverride::REVOKE);
    $manager = $manager->fresh();
    $guard = app(ApprovalGuard::class);

    expect($manager->hasPermission('approve_leave'))->toBeFalse()
        ->and($manager->effectivePermissionKeys())->not->toContain('approve_leave')
        ->and(app(ScopeResolver::class)->scopeFor($manager, 'approve_leave'))->toBe(DataScope::None)
        ->and($guard->covers($manager, $report, 'approve_leave'))->toBeFalse()
        ->and($guard->accessibleEmployeeIds($manager, 'approve_leave'))->toBe([])
        // Other permissions are untouched.
        ->and($guard->covers($manager, $report, 'approve_overtime'))->toBeTrue();

    expect(fn () => $guard->assertCanDecide($manager, $report, 'approve_leave'))->toThrow(ApprovalNotPermitted::class);
});

test('saving or deleting an override takes effect immediately', function () {
    $world = dsWorld();
    $employee = dsActor(UserRole::Employee, $world['ops']);

    expect($employee->hasPermission('export_attendance'))->toBeFalse();

    $override = dsOverride($employee, 'export_attendance', UserPermissionOverride::GRANT, DataScope::Own);
    expect($employee->fresh()->hasPermission('export_attendance'))->toBeTrue();

    $override->delete();
    expect($employee->fresh()->hasPermission('export_attendance'))->toBeFalse();
});

test('Super Admin is never narrowed by an override', function () {
    $world = dsWorld();
    $super = dsActor(UserRole::SuperAdmin, $world['ops']);
    dsOverride($super, 'approve_leave', UserPermissionOverride::REVOKE);

    expect($super->fresh()->hasPermission('approve_leave'))->toBeTrue()
        ->and(app(ScopeResolver::class)->scopeFor($super->fresh(), 'approve_leave'))->toBe(DataScope::All);
});

// ─── Legacy per-user department / shift narrowing ───────────────────────

test('HR department scoping caps a company-wide grant to those departments', function () {
    $world = dsWorld();
    $hr = dsActor(UserRole::HrAdmin, $world['ops'], ['scope_departments' => [$world['sales']->id]]);
    $salesPeer = dsEmployee($world['sales']);
    $financePeer = dsEmployee($world['finance']);
    $resolver = app(ScopeResolver::class);

    expect($resolver->scopeFor($hr, 'approve_leave'))->toBe(DataScope::SelectedDepartments)
        ->and($resolver->covers($hr, 'approve_leave', $salesPeer))->toBeTrue()
        ->and($resolver->covers($hr, 'approve_leave', $financePeer))->toBeFalse();
});

test('department scoping never widens a team-scoped manager', function () {
    $world = dsWorld();
    $manager = dsActor(UserRole::Manager, $world['ops'], ['scope_departments' => [$world['sales']->id]]);
    $salesPeer = dsEmployee($world['sales']);

    expect(app(ScopeResolver::class)->scopeFor($manager, 'approve_leave'))->toBe(DataScope::Team)
        ->and(app(ScopeResolver::class)->covers($manager, 'approve_leave', $salesPeer))->toBeFalse();
});

test('department and shift scoping intersect, as before', function () {
    $world = dsWorld();
    $day = ShiftSetting::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 5]);
    $night = ShiftSetting::create(['name' => 'Night', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 5]);
    $hr = dsActor(UserRole::HrAdmin, $world['ops'], ['scope_departments' => [$world['sales']->id], 'scope_shifts' => [$night->id]]);
    $salesNight = dsEmployee($world['sales'], ['shift_id' => $night->id]);
    $salesDay = dsEmployee($world['sales'], ['shift_id' => $day->id]);
    $resolver = app(ScopeResolver::class);

    expect($resolver->covers($hr, 'approve_leave', $salesNight))->toBeTrue()
        ->and($resolver->covers($hr, 'approve_leave', $salesDay))->toBeFalse();
});

// ─── Deactivated custom roles ───────────────────────────────────────────

test('a deactivated custom role grants only employee self-service', function () {
    $world = dsWorld();
    $role = Role::create(['name' => 'Shift Lead', 'slug' => 'shift-lead', 'is_system' => false, 'is_active' => true]);
    $role->permissions()->sync(Permission::whereIn('key', ['approve_leave', 'apply_leave', 'view_leave'])->pluck('id'));
    $user = dsActor($role, $world['ops']);

    expect($user->hasPermission('approve_leave'))->toBeTrue();

    $role->update(['is_active' => false]);
    $user = $user->fresh();

    expect($user->hasPermission('approve_leave'))->toBeFalse()
        ->and($user->hasPermission('apply_leave'))->toBeTrue()
        ->and($user->hasPermission('view_payslips'))->toBeTrue();
});

// ─── ApprovalGuard integration ──────────────────────────────────────────

test('ApprovalGuard with a permission matches the legacy reach on default roles', function () {
    $world = dsWorld();
    $manager = dsActor(UserRole::Manager, $world['ops']);
    $hr = dsActor(UserRole::HrAdmin, $world['ops']);
    $report = dsEmployee($world['sales'], ['manager_id' => $manager->id]);
    $stranger = dsEmployee($world['finance']);
    $guard = app(ApprovalGuard::class);

    foreach ([$manager, $hr] as $user) {
        foreach ([$report, $stranger] as $employee) {
            expect($guard->covers($user, $employee, 'approve_leave'))->toBe($guard->covers($user, $employee));
        }
    }

    expect($guard->accessibleEmployeeIds($manager, 'approve_leave'))->toBe($guard->accessibleEmployeeIds($manager))
        ->and($guard->accessibleEmployeeIds($hr, 'approve_leave'))->toBeNull();
});

test('ApprovalGuard applies a configured scope to holders of the permission', function () {
    $world = dsWorld();
    $manager = dsActor(UserRole::Manager, $world['ops']);
    $opsPeer = dsEmployee($world['ops']);
    $guard = app(ApprovalGuard::class);

    expect($guard->covers($manager, $opsPeer, 'approve_leave'))->toBeFalse();

    dsScope(dsRole('manager'), 'approve_leave', DataScope::Department);

    expect($guard->covers($manager->fresh(), $opsPeer, 'approve_leave'))->toBeTrue()
        ->and($guard->accessibleEmployeeIds($manager->fresh(), 'approve_leave'))->not->toContain($manager->employee->id);
});

test('a team lead without the permission keeps their reporting-line reach', function () {
    $world = dsWorld();
    $lead = dsActor(UserRole::Employee, $world['ops']);
    $report = dsEmployee($world['ops'], ['manager_id' => $lead->id]);

    expect($lead->hasPermission('approve_leave'))->toBeFalse()
        ->and(app(ApprovalGuard::class)->covers($lead, $report, 'approve_leave'))->toBeTrue();
});

test('nobody decides their own record, whatever the scope', function () {
    $world = dsWorld();
    $hr = dsActor(UserRole::HrAdmin, $world['ops']);

    expect(fn () => app(ApprovalGuard::class)->assertCanDecide($hr, $hr->employee, 'approve_leave'))
        ->toThrow(ApprovalNotPermitted::class);
});
