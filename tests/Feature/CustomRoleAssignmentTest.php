<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function editorUser(): User
{
    $role = Role::firstOrCreate(['slug' => 'hr_admin'], ['name' => 'HR Admin', 'is_system' => true, 'is_active' => true]);
    $perm = Permission::firstOrCreate(['key' => 'manage_employees'], ['label' => 'Manage Employees', 'module' => 'Employees']);
    $role->permissions()->syncWithoutDetaching([$perm->id]);

    return User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);
}

test('the employee edit role dropdown lists custom roles, not just the fixed six', function () {
    $editor = editorUser();
    $employee = Employee::factory()->create();
    $custom = Role::create(['name' => 'Operations Manager', 'slug' => 'ops-manager', 'is_system' => false, 'is_active' => true]);

    Livewire::actingAs($editor)->test(EmployeeEdit::class, ['employee' => $employee])
        ->assertOk()
        ->assertSee('Operations Manager')
        ->assertViewHas('roles', fn ($roles) => $roles->contains('id', $custom->id));
});

test('assigning a custom role sets role_id directly and a permission-derived legacy bucket', function () {
    $editor = editorUser();
    $employee = Employee::factory()->create();
    $custom = Role::create(['name' => 'Operations Manager', 'slug' => 'ops-manager', 'is_system' => false, 'is_active' => true]);
    $approveLeave = Permission::firstOrCreate(['key' => 'approve_leave'], ['label' => 'Approve Leave', 'module' => 'Leave']);
    $custom->permissions()->attach($approveLeave->id);

    Livewire::actingAs($editor)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('roleId', (string) $custom->id)
        ->call('save')
        ->assertHasNoErrors();

    $user = $employee->fresh()->user;
    expect($user->role_id)->toBe($custom->id);
    expect($user->role)->toBe(UserRole::Manager); // bucketed via approve_leave, not a fixed slug match
    expect($user->hasPermission('approve_leave'))->toBeTrue();
    expect($user->displayRoleName())->toBe('Operations Manager');
});

test('assigning one of the six built-in roles keeps the exact matching legacy bucket', function () {
    $editor = editorUser();
    $employee = Employee::factory()->create();
    $financeRole = Role::firstOrCreate(['slug' => 'finance'], ['name' => 'Finance', 'is_system' => true, 'is_active' => true]);

    Livewire::actingAs($editor)->test(EmployeeEdit::class, ['employee' => $employee])
        ->set('roleId', (string) $financeRole->id)
        ->call('save')
        ->assertHasNoErrors();

    $user = $employee->fresh()->user;
    expect($user->role)->toBe(UserRole::Finance);
    expect($user->role_id)->toBe($financeRole->id);
});

test('Role::legacyBucket maps a custom role with no matching permissions to Employee', function () {
    $custom = Role::create(['name' => 'Office Greeter', 'slug' => 'greeter', 'is_system' => false, 'is_active' => true]);

    expect($custom->legacyBucket())->toBe(UserRole::Employee);
});

test('displayRoleName falls back to the legacy enum label when no DB role is linked', function () {
    $user = User::factory()->create(['role' => UserRole::Manager, 'role_id' => null]);

    expect($user->displayRoleName())->toBe(UserRole::Manager->label());
});
