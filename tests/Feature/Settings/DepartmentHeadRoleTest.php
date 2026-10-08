<?php

use App\Enums\DataScope;
use App\Enums\UserRole;
use App\Livewire\Settings\DepartmentManager;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Security\RoleDelegationGuard;
use App\Services\Security\ScopeResolver;
use Livewire\Livewire;

/**
 * Department Head: a system role whose permissions reach the departments the
 * holder works in or heads (Settings → Departments → Head).
 */
function dhEmployee(Department $department, array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'department_id' => $department->id,
        'status' => 'active',
        'manager_id' => null,
    ], $attributes));
}

function dhHead(Department $ownDepartment): User
{
    $role = Role::where('slug', 'department_head')->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $ownDepartment->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

test('the Department Head system role exists with manager-level approvals and no HR powers', function () {
    $role = Role::where('slug', 'department_head')->first();

    expect($role)->not->toBeNull()
        ->and($role->is_system)->toBeTrue()
        ->and($role->legacyBucket())->toBe(UserRole::Manager)
        ->and($role->hasPermission('approve_leave'))->toBeTrue()
        ->and($role->hasPermission('view_reports'))->toBeTrue()
        ->and($role->hasPermission('manage_notifications'))->toBeTrue()
        ->and($role->hasPermission('manage_employees'))->toBeFalse()
        ->and($role->hasPermission('view_payroll'))->toBeFalse()
        ->and($role->hasPermission('manage_settings'))->toBeFalse();
});

test('a Department Head reaches their own and headed departments only', function () {
    $ops = Department::factory()->create();
    $sales = Department::factory()->create();
    $finance = Department::factory()->create();
    $head = dhHead($ops);
    $sales->update(['head_id' => $head->id]);

    $opsPeer = dhEmployee($ops);
    $salesPeer = dhEmployee($sales);
    $financePeer = dhEmployee($finance);
    $resolver = app(ScopeResolver::class);
    $guard = app(ApprovalGuard::class);

    foreach (['view_attendance', 'approve_leave', 'review_performance', 'view_reports', 'manage_notifications'] as $permission) {
        expect($resolver->scopeFor($head, $permission))->toBe(DataScope::Department)
            ->and($resolver->covers($head, $permission, $opsPeer))->toBeTrue()
            ->and($resolver->covers($head, $permission, $salesPeer))->toBeTrue()
            ->and($resolver->covers($head, $permission, $financePeer))->toBeFalse();
    }

    expect($guard->covers($head, $salesPeer, 'approve_leave'))->toBeTrue()
        ->and($guard->covers($head, $financePeer, 'approve_leave'))->toBeFalse()
        ->and($resolver->scopeFor($head, 'view_payroll'))->toBe(DataScope::None);
});

test('HR can narrow a Department Head to chosen departments per user', function () {
    $ops = Department::factory()->create();
    $sales = Department::factory()->create();
    $head = dhHead($ops);
    $head->update(['scope_departments' => [$sales->id]]);
    $opsPeer = dhEmployee($ops);
    $salesPeer = dhEmployee($sales);

    $resolver = app(ScopeResolver::class);

    expect($resolver->scopeFor($head->fresh(), 'approve_leave'))->toBe(DataScope::SelectedDepartments)
        ->and($resolver->covers($head->fresh(), 'approve_leave', $salesPeer))->toBeTrue()
        ->and($resolver->covers($head->fresh(), 'approve_leave', $opsPeer))->toBeFalse();
});

test('an HR Admin may assign the Department Head role', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $target = User::factory()->create(['role' => UserRole::Employee]);

    expect(app(RoleDelegationGuard::class)->refusalToAssign($hr, Role::where('slug', 'department_head')->first(), $target))->toBeNull();
});

test('HR sets and clears a department head from Settings → Departments, audited', function () {
    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $department = Department::factory()->create(['name' => 'Operations']);
    $head = dhEmployee($department)->user;

    Livewire::actingAs($admin)
        ->test(DepartmentManager::class)
        ->call('openEdit', $department->id)
        ->assertSet('head_id', '')
        ->set('head_id', (string) $head->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($department->fresh()->head_id)->toBe($head->id)
        ->and(AuditLog::where('auditable_type', Department::class)->where('auditable_id', $department->id)->exists())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(DepartmentManager::class)
        ->call('openEdit', $department->id)
        ->assertSet('head_id', (string) $head->id)
        ->assertSee($head->name)
        ->set('head_id', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($department->fresh()->head_id)->toBeNull();
});

test('the head must be an existing account', function () {
    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $department = Department::factory()->create();

    Livewire::actingAs($admin)
        ->test(DepartmentManager::class)
        ->call('openEdit', $department->id)
        ->set('head_id', '999999')
        ->call('save')
        ->assertHasErrors(['head_id']);

    expect($department->fresh()->head_id)->toBeNull();
});

test('employees cannot open Settings → Departments', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(DepartmentManager::class)->assertForbidden();
});
