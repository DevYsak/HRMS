<?php

use App\Enums\UserRole;
use App\Livewire\Settings\RoleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
});

test('role list renders for a user who can manage settings', function () {
    $this->actingAs($this->admin)
        ->get(route('settings.roles'))
        ->assertOk()
        ->assertSee('Roles & Permissions')
        ->assertSee('Super Admin')
        ->assertSee('HR Admin');
});

test('a plain employee is forbidden from the role manager', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    $this->actingAs($employee)
        ->get(route('settings.roles'))
        ->assertForbidden();
});

test('a custom role can be created with selected permissions', function () {
    $permissionIds = Permission::query()->whereIn('key', ['view_dashboard', 'view_directory'])->pluck('id')->all();

    Livewire::actingAs($this->admin)
        ->test(RoleManager::class)
        ->call('openCreate')
        ->set('name', 'Team Lead')
        ->set('description', 'Leads a small team.')
        ->set('selectedPermissions', $permissionIds)
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::where('name', 'Team Lead')->first();

    expect($role)->not->toBeNull()
        ->and($role->is_system)->toBeFalse()
        ->and($role->permissions()->pluck('key')->sort()->values()->all())
        ->toEqual(['view_dashboard', 'view_directory']);
});

test('editing a role updates its permissions and is reflected in hasPermission', function () {
    $role = Role::create(['name' => 'Coordinator', 'slug' => 'coordinator', 'is_system' => false, 'is_active' => true]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $viewDashboard = Permission::where('key', 'view_dashboard')->firstOrFail();
    $manageEmployees = Permission::where('key', 'manage_employees')->firstOrFail();

    expect($user->fresh()->hasPermission('manage_employees'))->toBeFalse();

    Livewire::actingAs($this->admin)
        ->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->set('selectedPermissions', [$viewDashboard->id, $manageEmployees->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->hasPermission('manage_employees'))->toBeTrue();
});

test('cloning a role duplicates its permission set as a new custom role', function () {
    $source = Role::where('slug', 'manager')->firstOrFail();
    $sourceKeys = $source->permissions()->pluck('key')->sort()->values()->all();

    Livewire::actingAs($this->admin)
        ->test(RoleManager::class)
        ->call('cloneRole', $source->id);

    $clone = Role::where('name', 'Manager (Copy)')->first();

    expect($clone)->not->toBeNull()
        ->and($clone->is_system)->toBeFalse()
        ->and($clone->permissions()->pluck('key')->sort()->values()->all())->toEqual($sourceKeys);
});

test('deleting a role is blocked while users are assigned', function () {
    $role = Role::create(['name' => 'Coordinator', 'slug' => 'coordinator', 'is_system' => false, 'is_active' => true]);
    User::factory()->create(['role_id' => $role->id]);

    Livewire::actingAs($this->admin)
        ->test(RoleManager::class)
        ->call('confirmDelete', $role->id)
        ->call('deleteRole');

    expect(Role::find($role->id))->not->toBeNull();
});

test('a role with no users assigned can be deleted', function () {
    $role = Role::create(['name' => 'Coordinator', 'slug' => 'coordinator', 'is_system' => false, 'is_active' => true]);

    Livewire::actingAs($this->admin)
        ->test(RoleManager::class)
        ->call('confirmDelete', $role->id)
        ->call('deleteRole');

    expect(Role::find($role->id))->toBeNull();
});

test('system roles cannot be deleted or deactivated', function () {
    $hrAdmin = Role::where('slug', 'hr_admin')->firstOrFail();

    Livewire::actingAs($this->admin)
        ->test(RoleManager::class)
        ->call('toggleActive', $hrAdmin->id);

    expect($hrAdmin->fresh()->is_active)->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(RoleManager::class)
        ->call('confirmDelete', $hrAdmin->id)
        ->call('deleteRole');

    expect(Role::find($hrAdmin->id))->not->toBeNull();
});
