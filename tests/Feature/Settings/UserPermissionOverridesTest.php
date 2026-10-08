<?php

use App\Enums\DataScope;
use App\Enums\UserRole;
use App\Livewire\Settings\UserPermissionOverrides;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Security\PermissionAdministration;
use App\Services\Security\ScopeResolver;
use Livewire\Livewire;

/**
 * Per-user overrides from Roles & Permissions: grant, narrow or revoke one
 * permission for one person, within the actor's own delegation and reach,
 * audited, with the effective result shown.
 */
function upoUser(UserRole $role, ?Department $department = null): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create([
        'user_id' => $user->id,
        'department_id' => ($department ?? Department::factory()->create())->id,
        'status' => 'active',
        'manager_id' => null,
    ]);

    return $user->fresh();
}

function upoPermission(string $key): Permission
{
    return Permission::where('key', $key)->firstOrFail();
}

test('HR grants a permission to one person at a chosen scope, audited', function () {
    $hr = upoUser(UserRole::HrAdmin);
    $sales = Department::factory()->create();
    $target = upoUser(UserRole::Employee);

    Livewire::actingAs($hr)
        ->test(UserPermissionOverrides::class)
        ->call('selectUser', $target->id)
        ->set('permissionId', (string) upoPermission('view_attendance')->id)
        ->set('effect', 'grant')
        ->set('scope', DataScope::SelectedDepartments->value)
        ->set('departmentIds', [(string) $sales->id])
        ->set('reason', 'Covering Sales attendance while the lead is away')
        ->call('save')
        ->assertHasNoErrors();

    $override = UserPermissionOverride::where('user_id', $target->id)->firstOrFail();
    $resolved = app(ScopeResolver::class)->resolve($target->fresh(), 'view_attendance');

    expect($override->effect)->toBe('grant')
        ->and($resolved->scope)->toBe(DataScope::SelectedDepartments)
        ->and($resolved->departmentIds)->toBe([$sales->id])
        ->and(AuditLog::where('auditable_type', User::class)->where('auditable_id', $target->id)->exists())->toBeTrue();
});

test('the effective view shows source and reach', function () {
    $hr = upoUser(UserRole::HrAdmin);
    $target = upoUser(UserRole::Manager);
    UserPermissionOverride::create(['user_id' => $target->id, 'permission_id' => upoPermission('approve_leave')->id, 'effect' => 'revoke', 'reason' => 'Under review']);

    $rows = collect(app(PermissionAdministration::class)->effectivePermissions($target->fresh()))->keyBy('key');

    expect($rows['approve_leave']['held'])->toBeFalse()
        ->and($rows['approve_leave']['source'])->toBe('Revoked for this user')
        ->and($rows['view_attendance']['source'])->toBe('From role')
        ->and($rows['view_attendance']['scope'])->toBe(DataScope::Team->label());

    Livewire::actingAs($hr)
        ->test(UserPermissionOverrides::class)
        ->call('selectUser', $target->id)
        ->assertSee('Revoked for this user')
        ->assertSee('Team (reporting line)');
});

test('impossible or conflicting combinations are refused with a field message', function (string $key, string $effect, ?DataScope $scope, array $departments, string $field, string $message) {
    $hr = upoUser(UserRole::HrAdmin);
    $target = upoUser(UserRole::Manager);

    Livewire::actingAs($hr)
        ->test(UserPermissionOverrides::class)
        ->call('selectUser', $target->id)
        ->set('permissionId', (string) upoPermission($key)->id)
        ->set('effect', $effect)
        ->set('scope', $scope?->value ?? '')
        ->set('departmentIds', $departments)
        ->set('reason', 'Testing a combination')
        ->call('save')
        ->assertHasErrors($field)
        ->assertSee($message);

    expect(UserPermissionOverride::where('user_id', $target->id)->exists())->toBeFalse();
})->with([
    'scope on a permission without data' => ['manage_settings', 'grant', DataScope::All, [], 'scope', 'has no data scope'],
    'selected departments with none chosen' => ['view_overtime', 'grant', DataScope::SelectedDepartments, [], 'departmentIds', 'at least one department'],
    'grant identical to the role' => ['approve_leave', 'grant', null, [], 'permissionId', 'already grants'],
    'revoke what the role lacks' => ['view_payroll', 'revoke', null, [], 'permissionId', 'Nothing to revoke'],
]);

test('nobody changes their own permissions', function () {
    $hr = upoUser(UserRole::HrAdmin);

    Livewire::actingAs($hr)
        ->test(UserPermissionOverrides::class)
        ->call('selectUser', $hr->id)
        ->set('permissionId', (string) upoPermission('data_purge')->id)
        ->set('reason', 'Self-grant attempt')
        ->call('save')
        ->assertHasErrors('permissionId');

    expect($hr->fresh()->hasPermission('data_purge'))->toBeFalse();
});

test('HR cannot grant a privileged permission or override a Super Admin', function () {
    $hr = upoUser(UserRole::HrAdmin);
    $employee = upoUser(UserRole::Employee);
    $super = upoUser(UserRole::SuperAdmin);
    $admin = app(PermissionAdministration::class);

    expect($admin->refusalForOverride($hr, $employee, upoPermission('data_purge'), 'grant', null))->toContain('outside what you can delegate')
        ->and($admin->refusalForOverride($hr, $employee, upoPermission('manage_roles'), 'grant', null))->toContain('outside what you can delegate')
        ->and($admin->refusalForOverride($hr, $super, upoPermission('approve_leave'), 'revoke', null))->toContain('Super Admin');
});

test('a scoped actor cannot hand out more reach than they have', function () {
    $ops = Department::factory()->create();
    $sales = Department::factory()->create();
    $scopedHr = upoUser(UserRole::HrAdmin, $ops);
    $scopedHr->update(['scope_departments' => [$ops->id]]);
    $target = upoUser(UserRole::Employee, $ops);
    $admin = app(PermissionAdministration::class);
    $permission = upoPermission('view_attendance');

    expect($admin->refusalForOverride($scopedHr->fresh(), $target, $permission, 'grant', DataScope::All))->toContain('only as far as your own reach')
        ->and($admin->refusalForOverride($scopedHr->fresh(), $target, $permission, 'grant', DataScope::SelectedDepartments, [$sales->id]))->toContain('inside your own')
        ->and($admin->refusalForOverride($scopedHr->fresh(), $target, $permission, 'grant', DataScope::SelectedDepartments, [$ops->id]))->toBeNull();
});

test('removing an override restores the role and is audited', function () {
    $hr = upoUser(UserRole::HrAdmin);
    $target = upoUser(UserRole::Manager);
    $override = UserPermissionOverride::create(['user_id' => $target->id, 'permission_id' => upoPermission('approve_leave')->id, 'effect' => 'revoke', 'reason' => 'Temporary']);

    expect($target->fresh()->hasPermission('approve_leave'))->toBeFalse();

    Livewire::actingAs($hr)
        ->test(UserPermissionOverrides::class)
        ->call('selectUser', $target->id)
        ->call('remove', $override->id);

    expect($target->fresh()->hasPermission('approve_leave'))->toBeTrue()
        ->and(AuditLog::where('event', 'USER_PERMISSION_OVERRIDE_REMOVED')->exists())->toBeTrue();
});

test('people without role management cannot open overrides', function () {
    Livewire::actingAs(upoUser(UserRole::Manager))->test(UserPermissionOverrides::class)->assertForbidden();
});
