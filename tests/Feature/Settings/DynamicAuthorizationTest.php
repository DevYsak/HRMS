<?php

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('database-driven roles back the legacy role enum after migration', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    expect($hrAdmin->fresh()->assignedRole?->slug)->toBe('hr_admin')
        ->and($employee->fresh()->assignedRole?->slug)->toBe('employee');
});

test('an hr admin still passes the manage-employees authorization checks', function () {
    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    expect($hrAdmin->canManageEmployees())->toBeTrue();

    $this->actingAs($hrAdmin)
        ->get(route('employees.create'))
        ->assertOk();
});

test('a plain employee still gets a 403 from manage-employees gated routes', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    expect($employee->canManageEmployees())->toBeFalse();

    $this->actingAs($employee)
        ->get(route('employees.create'))
        ->assertForbidden();
});

test('super admin bypasses all dynamic permission checks regardless of role permissions', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    foreach (Permission::pluck('key') as $key) {
        expect($superAdmin->hasPermission($key))->toBeTrue();
    }
});

test('granting a custom role a permission makes Gate::allows resolve true for it', function () {
    $role = Role::create(['name' => 'Coordinator', 'slug' => 'coordinator', 'is_system' => false, 'is_active' => true]);
    $user = User::factory()->create(['role_id' => $role->id]);

    expect($user->fresh()->hasPermission('manage_kpi_templates'))->toBeFalse();

    $role->permissions()->sync([Permission::where('key', 'manage_kpi_templates')->value('id')]);
    $role->flushPermissionCache();

    $this->actingAs($user->fresh());

    expect(Gate::allows('manage_kpi_templates'))->toBeTrue()
        ->and(Gate::allows('manage_warning_letters'))->toBeFalse();
});
