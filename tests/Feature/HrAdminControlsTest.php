<?php

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\ControlPanel;
use App\Livewire\Settings\DataManagement;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\RoleDelegationGuard;
use Livewire\Livewire;

/**
 * HR controls that used to be hard-wired to the Super Admin are permissions:
 * the HR Admin role holds manage_ai_settings and impersonate, while the
 * destructive data_purge and force_delete_employee stay with the Super Admin.
 * The admin menus follow whatever Roles & Permissions grants.
 */
function hrcHr(): User
{
    $user = User::factory()->create(['role' => UserRole::HrAdmin, 'email_verified_at' => now()]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user->fresh();
}

function hrcStaff(UserRole $role = UserRole::Employee): User
{
    $user = User::factory()->create(['role' => $role, 'email_verified_at' => now()]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user->fresh();
}

/** A custom role holding exactly the given permission keys. */
function hrcCustomRole(array $keys): User
{
    $role = Role::create(['name' => 'Settings Clerk', 'slug' => 'settings-clerk', 'is_system' => false, 'is_active' => true]);
    $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
    $role->flushPermissionCache();

    $user = User::factory()->create(['role' => UserRole::Employee, 'role_id' => $role->id, 'email_verified_at' => now()]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user->fresh();
}

test('HR Admin holds the support controls by default; a manager holds none', function () {
    $hr = hrcHr();
    $manager = hrcStaff(UserRole::Manager);

    foreach (['manage_ai_settings', 'impersonate'] as $key) {
        expect($hr->hasPermission($key))->toBeTrue("HR should hold {$key}")
            ->and($manager->hasPermission($key))->toBeFalse("a manager should not hold {$key}");
    }

    foreach (['data_purge', 'force_delete_employee'] as $key) {
        expect($hr->hasPermission($key))->toBeFalse("HR should not hold {$key}")
            ->and($manager->hasPermission($key))->toBeFalse("a manager should not hold {$key}");
    }
});

test('only a Super Admin can hand the new controls to another role', function () {
    $keys = app(RoleDelegationGuard::class)->delegableKeys(hrcHr());

    expect($keys)->not->toContain('data_purge')
        ->not->toContain('impersonate')
        ->not->toContain('force_delete_employee')
        ->not->toContain('manage_ai_settings');
});

// ── Login as employee ──────────────────────────────────────────────────────

test('HR can log in as an employee and return', function () {
    $hr = hrcHr();
    $employee = hrcStaff();

    $this->actingAs($hr)->post(route('impersonate.start', $employee))->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($employee->id)
        ->and(session('impersonator_id'))->toBe($hr->id);

    $this->get(route('impersonate.stop'))->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($hr->id);
});

test('HR can never log in as a Super Admin', function () {
    $hr = hrcHr();
    $admin = hrcStaff(UserRole::SuperAdmin);

    $this->actingAs($hr)->post(route('impersonate.start', $admin))->assertForbidden();
    expect(auth()->id())->toBe($hr->id);
});

test('a manager cannot log in as anyone', function () {
    $this->actingAs(hrcStaff(UserRole::Manager))->post(route('impersonate.start', hrcStaff()))->assertForbidden();
});

// ── Data Management ────────────────────────────────────────────────────────

test('a purge of one employee is recorded in the audit trail', function () {
    $admin = hrcStaff(UserRole::SuperAdmin);
    $target = hrcStaff();
    $employeeId = $target->employee->id;

    Livewire::actingAs($admin)->test(DataManagement::class)->call('deleteEmployee', $employeeId);

    expect(Employee::withTrashed()->find($employeeId))->toBeNull()
        ->and(AuditLog::where('event', 'EMPLOYEE_PURGED')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('a bulk domain purge is recorded in the audit trail', function () {
    $admin = hrcStaff(UserRole::SuperAdmin);

    Livewire::actingAs($admin)->test(DataManagement::class)->call('purge', 'notifications');

    expect(AuditLog::where('event', 'DATA_PURGED')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('HR cannot open Data Management', function () {
    Livewire::actingAs(hrcHr())->test(DataManagement::class)->assertForbidden();
});

test('a role granted Data Management still cannot purge a Super Admin', function () {
    $admin = hrcStaff(UserRole::SuperAdmin);

    Livewire::actingAs(hrcCustomRole(['manage_settings', 'data_purge']))->test(DataManagement::class)
        ->call('deleteEmployee', $admin->employee->id);

    expect(Employee::find($admin->employee->id))->not->toBeNull();
});

test('a role with Manage Settings but not Data Management cannot open it', function () {
    Livewire::actingAs(hrcCustomRole(['manage_settings']))->test(DataManagement::class)->assertForbidden();
});

// ── Dynamic menus ──────────────────────────────────────────────────────────

test('the Control Panel shows HR the AI Assistant card but not Data Management', function () {
    Livewire::actingAs(hrcHr())->test(ControlPanel::class)
        ->assertSee('AI Assistant')
        ->assertDontSee('Data Management')
        ->assertSee('Holidays')
        ->assertSee('Roles &amp; Permissions', escape: false);
});

test('the Control Panel hides cards whose permission the role lacks', function () {
    Livewire::actingAs(hrcCustomRole(['manage_settings']))->test(ControlPanel::class)
        ->assertSee('Departments')
        ->assertDontSee('AI Assistant')
        ->assertDontSee('Data Management')
        ->assertDontSee('Roles &amp; Permissions', escape: false);
});

test('the full settings menu follows the Manage Settings permission', function () {
    expect(hrcCustomRole(['manage_settings'])->can('manageFullSettings'))->toBeTrue()
        ->and(hrcStaff(UserRole::Manager)->can('manageFullSettings'))->toBeFalse();
});

// ── Pages HR was linked to but locked out of ───────────────────────────────

test('HR can open Leave Encashments; a manager cannot', function () {
    $this->actingAs(hrcHr())->get(route('time-off.encashments'))->assertOk();
    $this->actingAs(hrcStaff(UserRole::Manager))->get(route('time-off.encashments'))->assertForbidden();
});

test('HR sees the Executive View link', function () {
    $this->actingAs(hrcHr())->get(route('dashboard'))->assertSee('Executive View');
});

test('HR sees the probation co-approval once the manager has confirmed', function () {
    $employee = Employee::factory()->create([
        'status' => EmployeeStatus::Probation,
        'probation_confirmed_at' => now(),
        'probation_confirmed_by' => hrcStaff(UserRole::Manager)->id,
    ]);

    $this->actingAs(hrcHr())->get(route('employees.probation', $employee))
        ->assertOk()
        ->assertSee('Stage 2 — HR Co-Approval');
});
