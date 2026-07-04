<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeCreate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function ecrCreator(): User
{
    $role = Role::firstOrCreate(['slug' => 'hr_admin'], ['name' => 'HR Admin', 'is_system' => true, 'is_active' => true]);
    $perm = Permission::firstOrCreate(['key' => 'manage_employees'], ['label' => 'Manage Employees', 'module' => 'Employees']);
    $role->permissions()->syncWithoutDetaching([$perm->id]);

    return User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);
}

test('the new-employee role dropdown defaults to Employee and lists custom roles', function () {
    $creator = ecrCreator();
    Role::firstOrCreate(['slug' => 'employee'], ['name' => 'Employee', 'is_system' => true, 'is_active' => true]);
    $custom = Role::create(['name' => 'Operations Manager', 'slug' => 'ops-manager', 'is_system' => false, 'is_active' => true]);

    Livewire::actingAs($creator)->test(EmployeeCreate::class)
        ->assertOk()
        ->assertSee('Operations Manager')
        ->assertViewHas('roles', fn ($roles) => $roles->contains('id', $custom->id))
        ->assertSet('roleId', (string) Role::where('slug', 'employee')->value('id'));
});

test('creating an employee with a custom role sets role_id and a permission-derived legacy bucket', function () {
    $creator = ecrCreator();
    $custom = Role::create(['name' => 'Operations Manager', 'slug' => 'ops-manager', 'is_system' => false, 'is_active' => true]);
    $approveLeave = Permission::firstOrCreate(['key' => 'approve_leave'], ['label' => 'Approve Leave', 'module' => 'Leave']);
    $custom->permissions()->attach($approveLeave->id);

    Livewire::actingAs($creator)->test(EmployeeCreate::class)
        ->set('name', 'New Hire')
        ->set('email', 'new.hire.'.uniqid().'@example.com')
        ->set('roleId', (string) $custom->id)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('name', 'New Hire')->firstOrFail();
    expect($user->role_id)->toBe($custom->id);
    expect($user->role)->toBe(UserRole::Manager); // bucketed via approve_leave
    expect($user->hasPermission('approve_leave'))->toBeTrue();
    expect($user->displayRoleName())->toBe('Operations Manager');
});

test('creating an employee with a built-in role keeps the exact matching legacy bucket', function () {
    $creator = ecrCreator();
    $financeRole = Role::firstOrCreate(['slug' => 'finance'], ['name' => 'Finance', 'is_system' => true, 'is_active' => true]);

    Livewire::actingAs($creator)->test(EmployeeCreate::class)
        ->set('name', 'Finance Hire')
        ->set('email', 'finance.hire.'.uniqid().'@example.com')
        ->set('roleId', (string) $financeRole->id)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('name', 'Finance Hire')->firstOrFail();
    expect($user->role)->toBe(UserRole::Finance);
    expect($user->role_id)->toBe($financeRole->id);
});
