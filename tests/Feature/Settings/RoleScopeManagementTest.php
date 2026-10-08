<?php

use App\Enums\DataScope;
use App\Enums\UserRole;
use App\Livewire\Settings\RoleManager;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Security\ScopeResolver;
use Livewire\Livewire;

/**
 * Roles & Permissions: a data scope per granted permission, set from the role
 * editor, validated, delegation-capped and audited — and D1: a Director is
 * department-scoped unless granted more.
 */
function rsmUser(UserRole $role, ?Department $department = null, array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['role' => $role], $attributes));
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => ($department ?? Department::factory()->create())->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function rsmPermission(string $key): Permission
{
    return Permission::where('key', $key)->firstOrFail();
}

function rsmCustomRole(array $keys = ['approve_leave', 'view_attendance']): Role
{
    $role = Role::create(['name' => 'Shift Lead '.uniqid(), 'slug' => 'shift-lead-'.uniqid(), 'is_system' => false, 'is_active' => true]);
    $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));

    return $role;
}

test('a scope set in the role editor is saved on the grant and audited', function () {
    $admin = rsmUser(UserRole::SuperAdmin);
    $role = rsmCustomRole();
    $ops = Department::factory()->create();
    $permission = rsmPermission('view_attendance');

    Livewire::actingAs($admin)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->set("scopes.{$permission->id}.scope", DataScope::SelectedDepartments->value)
        ->set("scopes.{$permission->id}.departments", [(string) $ops->id])
        ->call('save')
        ->assertHasNoErrors();

    $grant = $role->permissions()->where('permissions.id', $permission->id)->first()->pivot;
    expect($grant->scope)->toBe('selected_departments')
        ->and(json_decode($grant->department_ids, true))->toBe([$ops->id])
        ->and(AuditLog::where('event', 'ROLE_PERMISSION_SCOPES_CHANGED')->where('auditable_id', $role->id)->exists())->toBeTrue();

    $member = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    expect(app(ScopeResolver::class)->resolve($member, 'view_attendance')->departmentIds)->toBe([$ops->id]);
});

test('the editor shows the inherited scope and reopens with the saved one', function () {
    $admin = rsmUser(UserRole::SuperAdmin);
    $role = Role::where('slug', 'manager')->firstOrFail();
    $permission = rsmPermission('approve_leave');

    Livewire::actingAs($admin)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->assertSee('Inherit — Team (reporting line)')
        ->set("scopes.{$permission->id}.scope", DataScope::Department->value)
        ->call('save')
        ->assertHasNoErrors()
        ->call('openEdit', $role->id)
        ->assertSet("scopes.{$permission->id}.scope", 'department');
});

test('invalid combinations are refused under the permission that caused them', function (string $key, string $scope, array $departments, string $field, string $message) {
    $admin = rsmUser(UserRole::SuperAdmin);
    $role = rsmCustomRole(['approve_leave', 'view_attendance', 'manage_settings']);
    $permission = rsmPermission($key);

    Livewire::actingAs($admin)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->set("scopes.{$permission->id}.scope", $scope)
        ->set("scopes.{$permission->id}.departments", $departments)
        ->call('save')
        ->assertHasErrors("scopes.{$permission->id}.{$field}")
        ->assertSee($message);

    expect($role->permissions()->where('permissions.id', $permission->id)->first()->pivot->scope)->toBeNull();
})->with([
    'selected departments with none chosen' => ['view_attendance', 'selected_departments', [], 'departments', 'at least one department'],
    '"no access" on a granted permission' => ['approve_leave', 'none', [], 'scope', 'contradictory'],
]);

test('a scope on a permission without employee data is ignored, not stored', function () {
    $admin = rsmUser(UserRole::SuperAdmin);
    $role = rsmCustomRole(['manage_settings', 'approve_leave']);
    $permission = rsmPermission('manage_settings');

    Livewire::actingAs($admin)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->set("scopes.{$permission->id}.scope", 'all')
        ->call('save')
        ->assertHasNoErrors();

    expect($role->permissions()->where('permissions.id', $permission->id)->first()->pivot->scope)->toBeNull();
});

test('nobody grants a role more reach than they have themselves', function () {
    $ops = Department::factory()->create();
    $scopedHr = rsmUser(UserRole::HrAdmin, $ops, ['scope_departments' => [$ops->id]]);
    $role = rsmCustomRole();
    $permission = rsmPermission('approve_leave');

    Livewire::actingAs($scopedHr)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->set("scopes.{$permission->id}.scope", DataScope::All->value)
        ->call('save')
        ->assertHasErrors("scopes.{$permission->id}.scope");

    expect($role->permissions()->where('permissions.id', $permission->id)->first()->pivot->scope)->toBeNull();
});

test('a cloned role keeps every grant\'s scope', function () {
    $admin = rsmUser(UserRole::SuperAdmin);
    $role = rsmCustomRole();
    $permission = rsmPermission('approve_leave');
    $role->permissions()->updateExistingPivot($permission->id, ['scope' => 'department']);

    Livewire::actingAs($admin)->test(RoleManager::class)->call('cloneRole', $role->id);

    $clone = Role::where('name', $role->name.' (Copy)')->firstOrFail();
    expect($clone->permissions()->where('permissions.id', $permission->id)->first()->pivot->scope)->toBe('department');
});

test('Roles & Permissions has a user overrides tab', function () {
    Livewire::actingAs(rsmUser(UserRole::SuperAdmin))->test(RoleManager::class)
        ->set('tab', 'overrides')
        ->assertSeeLivewire('settings.user-permission-overrides');
});

test('an unrecognised stored scope fails closed to no access', function () {
    $role = rsmCustomRole();
    $role->permissions()->updateExistingPivot(rsmPermission('approve_leave')->id, ['scope' => 'everyone-please']);
    $role->flushPermissionCache();
    $member = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);

    expect(app(ScopeResolver::class)->scopeFor($member, 'approve_leave'))->toBe(DataScope::None)
        ->and(app(ScopeResolver::class)->employeeIds($member, 'approve_leave'))->toBe([]);
});

// ── D1: Director is department-scoped by default ───────────────────────────

test('D1: a default Director reaches their own department only', function () {
    $ops = Department::factory()->create();
    $sales = Department::factory()->create();
    $director = rsmUser(UserRole::Director, $ops);
    $opsPeer = Employee::factory()->create(['user_id' => User::factory()->create()->id, 'department_id' => $ops->id, 'status' => 'active', 'manager_id' => null]);
    $salesPeer = Employee::factory()->create(['user_id' => User::factory()->create()->id, 'department_id' => $sales->id, 'status' => 'active', 'manager_id' => null]);
    $guard = app(ApprovalGuard::class);

    expect($director->isCompanyWideApprover())->toBeFalse()
        ->and($guard->covers($director, $opsPeer))->toBeTrue()
        ->and($guard->covers($director, $salesPeer))->toBeFalse()
        ->and($guard->covers($director, $salesPeer, 'approve_leave'))->toBeFalse()
        ->and($guard->accessibleEmployeeIds($director))->not->toBeNull();

    $this->actingAs($director)->get(route('dashboard'))->assertRedirect(route('dashboard.department'));
    $this->actingAs($director)->get(route('dashboard.director'))->assertForbidden();
    $this->actingAs($director)->get(route('dashboard.executive'))->assertForbidden();
});

test('D1: company-wide reach is a deliberate grant, not a default', function () {
    $director = rsmUser(UserRole::Director);
    $role = Role::where('slug', 'director')->firstOrFail();

    $role->permissions()->updateExistingPivot(rsmPermission('manage_employees')->id, ['scope' => 'all']);
    $role->flushPermissionCache();

    expect($director->fresh()->isCompanyWideApprover())->toBeTrue();
    $this->actingAs($director->fresh())->get(route('dashboard.director'))->assertOk();
});

test('D1: HR stays company-wide by default', function () {
    $hr = rsmUser(UserRole::HrAdmin);

    expect($hr->isCompanyWideApprover())->toBeTrue()
        ->and(app(ApprovalGuard::class)->accessibleEmployeeIds($hr))->toBeNull();
});
