<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Settings\RoleManager;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeImportService;
use Livewire\Livewire;

/**
 * Phase 1 safety — nobody but a Super Admin can reach Super Admin, change
 * their own role, or hand out permissions above their delegation ceiling;
 * every role and permission change is audited.
 */
function escalationRole(string $slug): Role
{
    return Role::where('slug', $slug)->firstOrFail();
}

function escalationHr(): User
{
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $hr->id, 'status' => 'active']);

    return $hr->fresh();
}

function escalationSuperAdmin(): User
{
    return User::factory()->create(['role' => UserRole::SuperAdmin]);
}

function escalationTarget(UserRole $role = UserRole::Employee): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => $role])->id,
        'status' => 'active',
    ]);
}

// ── Assigning roles ─────────────────────────────────────────────────────────

test('HR cannot make anyone a Super Admin', function () {
    $target = escalationTarget();

    Livewire::actingAs(escalationHr())->test(EmployeeEdit::class, ['employee' => $target])
        ->set('roleId', (string) escalationRole('super_admin')->id)
        ->call('save')
        ->assertHasErrors('roleId');

    expect($target->user->fresh()->role)->toBe(UserRole::Employee);
});

test('HR cannot change their own role', function () {
    $hr = escalationHr();

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $hr->employee])
        ->set('roleId', (string) escalationRole('director')->id)
        ->call('save')
        ->assertHasErrors('roleId');

    expect($hr->fresh()->role)->toBe(UserRole::HrAdmin);
});

test('HR cannot change a Super Admin\'s role', function () {
    $admin = escalationTarget(UserRole::SuperAdmin);

    Livewire::actingAs(escalationHr())->test(EmployeeEdit::class, ['employee' => $admin])
        ->set('roleId', (string) escalationRole('employee')->id)
        ->call('save')
        ->assertHasErrors('roleId');

    expect($admin->user->fresh()->role)->toBe(UserRole::SuperAdmin);
});

test('HR can assign the operational roles inside the delegation ceiling', function () {
    $target = escalationTarget();

    Livewire::actingAs(escalationHr())->test(EmployeeEdit::class, ['employee' => $target])
        ->set('roleId', (string) escalationRole('finance')->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($target->user->fresh()->role)->toBe(UserRole::Finance);
});

test('HR cannot assign a custom role that carries a privileged permission', function () {
    $sneaky = Role::create(['name' => 'Payroll Fixer', 'slug' => 'payroll-fixer', 'is_system' => false, 'is_active' => true]);
    $sneaky->permissions()->attach(Permission::where('key', 'unlock_payroll')->value('id'));
    $target = escalationTarget();

    Livewire::actingAs(escalationHr())->test(EmployeeEdit::class, ['employee' => $target])
        ->set('roleId', (string) $sneaky->id)
        ->call('save')
        ->assertHasErrors('roleId');

    expect($target->user->fresh()->role_id)->not->toBe($sneaky->id);
});

test('the role dropdown only offers roles the editor may grant', function () {
    Livewire::actingAs(escalationHr())->test(EmployeeEdit::class, ['employee' => escalationTarget()])
        ->assertViewHas('roles', fn ($roles) => ! $roles->contains('slug', 'super_admin') && $roles->contains('slug', 'manager'));
});

test('HR cannot create a Super Admin directly', function () {
    $department = Department::factory()->create();

    Livewire::actingAs(escalationHr())->test(EmployeeCreate::class)
        ->set('name', 'Mallory')
        ->set('email', 'mallory@example.com')
        ->set('employee_id', 'EMP-9001')
        ->set('department_id', (string) $department->id)
        ->set('roleId', (string) escalationRole('super_admin')->id)
        ->call('save')
        ->assertHasErrors('roleId');

    expect(User::where('email', 'mallory@example.com')->exists())->toBeFalse();
});

test('a spreadsheet import cannot mint a Super Admin', function () {
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'IMP-1', 'first_name' => 'Imported', 'email' => 'imported.admin@example.com', 'joining_date' => '2026-07-01', 'role' => 'super_admin'],
    ]);
    expect($parsed['rows'][0]['status'])->toBe('new');

    $log = $service->import($parsed, 'skip', escalationHr());

    expect(User::where('email', 'imported.admin@example.com')->exists())->toBeFalse()
        ->and($log->failed)->toBe(1);
});

test('changing a user\'s role writes an EMPLOYEE_ROLE_CHANGED audit event', function () {
    $hr = escalationHr();
    $target = escalationTarget();

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $target])
        ->set('roleId', (string) escalationRole('manager')->id)
        ->call('save');

    $event = AuditLog::where('event', 'EMPLOYEE_ROLE_CHANGED')->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->user_id)->toBe($hr->id)
        ->and($event->category)->toBe('permissions')
        ->and($event->subject_employee_id)->toBe($target->id)
        ->and($event->old_values['role'])->toBe('Employee')
        ->and($event->new_values['role'])->toBe('Manager');
});

// ── Access scope ────────────────────────────────────────────────────────────

test('HR cannot widen their own access scope', function () {
    $department = Department::factory()->create();
    $hr = escalationHr();
    $hr->update(['scope_departments' => [$department->id]]);

    Livewire::actingAs($hr->fresh())->test(EmployeeEdit::class, ['employee' => $hr->employee])
        ->set('scopeDepartments', [])
        ->call('save')
        ->assertHasErrors('scopeDepartments');

    expect($hr->fresh()->scope_departments)->toBe([$department->id]);
});

// ── Role Manager ────────────────────────────────────────────────────────────

test('HR cannot add permissions to the role they hold', function () {
    $hr = escalationHr();
    $hrRole = escalationRole('hr_admin');
    $before = $hrRole->permissionKeys();

    Livewire::actingAs($hr)->test(RoleManager::class)
        ->call('openEdit', $hrRole->id)
        ->assertSet('editingId', null);

    expect($hrRole->fresh()->permissionKeys())->toBe($before);
});

test('HR cannot create a role carrying a privileged permission', function () {
    $privileged = Permission::where('key', 'manage_roles')->value('id');

    Livewire::actingAs(escalationHr())->test(RoleManager::class)
        ->call('openCreate')
        ->set('name', 'Shadow Admin')
        ->set('selectedPermissions', [$privileged])
        ->call('save')
        ->assertHasErrors('selectedPermissions');

    expect(Role::where('name', 'Shadow Admin')->exists())->toBeFalse();
});

test('HR cannot clone or edit the Super Admin role', function () {
    $superAdminRole = escalationRole('super_admin');

    Livewire::actingAs(escalationHr())->test(RoleManager::class)
        ->call('cloneRole', $superAdminRole->id);

    expect(Role::where('name', 'like', 'Super Admin (Copy)%')->exists())->toBeFalse();
});

test('a role manager without manage_roles is refused', function () {
    $director = User::factory()->create(['role' => UserRole::Director]);

    Livewire::actingAs($director)->test(RoleManager::class)->assertForbidden();
});

test('a Super Admin can still build any role, and the permission change is audited', function () {
    $admin = escalationSuperAdmin();
    $role = Role::create(['name' => 'Auditor', 'slug' => 'auditor', 'is_system' => false, 'is_active' => true]);
    $privileged = Permission::where('key', 'manage_roles')->value('id');

    Livewire::actingAs($admin)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->set('selectedPermissions', [$privileged])
        ->call('save')
        ->assertHasNoErrors();

    expect($role->fresh()->permissionKeys())->toBe(['manage_roles']);

    $event = AuditLog::where('event', 'ROLE_PERMISSIONS_CHANGED')->latest('id')->first();
    expect($event)->not->toBeNull()
        ->and($event->user_id)->toBe($admin->id)
        ->and($event->new_values['granted'])->toBe(['manage_roles']);
});
