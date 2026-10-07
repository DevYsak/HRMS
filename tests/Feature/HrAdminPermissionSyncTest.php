<?php

use App\Enums\UserRole;
use App\Livewire\Settings\DataManagement;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The HR Admin role's settings, employee and "View as this employee"
 * permissions, kept in line on every database by the
 * sync_hr_admin_core_permissions migration, with the destructive controls
 * left to the Super Admin.
 */
function hpsUser(UserRole $role = UserRole::Employee, array $employee = []): User
{
    $user = User::factory()->create(['role' => $role, 'email_verified_at' => now()]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', ...$employee]);

    return $user->fresh();
}

function hpsRunSync(): void
{
    (require database_path('migrations/2026_10_06_150527_sync_hr_admin_core_permissions.php'))->up();
}

/** @return array<int, string> */
function hpsHrKeys(): array
{
    return DB::table('role_permission')
        ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
        ->where('role_permission.role_id', Role::where('slug', 'hr_admin')->value('id'))
        ->orderBy('permissions.key')
        ->pluck('permissions.key')
        ->all();
}

// ── Permissions ────────────────────────────────────────────────────────────

test('HR Admin holds the settings, employee and impersonate permissions', function (string $key) {
    expect(hpsUser(UserRole::HrAdmin)->hasPermission($key))->toBeTrue();
})->with(['manage_settings', 'manage_company_settings', 'view_employee', 'view_directory', 'impersonate', 'manage_employees']);

test('HR Admin does not hold the Super Admin-only destructive controls', function (string $key) {
    expect(hpsUser(UserRole::HrAdmin)->hasPermission($key))->toBeFalse()
        ->and(hpsUser(UserRole::SuperAdmin)->hasPermission($key))->toBeTrue();
})->with(['data_purge', 'force_delete_employee']);

test('HR Admin satisfies the settings helpers and gates', function () {
    $hr = hpsUser(UserRole::HrAdmin);

    expect($hr->canManageSettings())->toBeTrue()
        ->and($hr->can('manageFullSettings'))->toBeTrue()
        ->and($hr->can('manage-settings'))->toBeTrue()
        ->and($hr->can('manage_company_settings'))->toBeTrue();
});

// ── Employees ──────────────────────────────────────────────────────────────

test('HR Admin can open an employee profile, Manage Employees and the directory', function () {
    $hr = hpsUser(UserRole::HrAdmin);
    $employee = hpsUser()->employee;

    $this->actingAs($hr)->get(route('employees.profile', $employee))->assertOk();
    $this->actingAs($hr)->get(route('employees.index'))->assertOk();
    $this->actingAs($hr)->get(route('employees.directory'))->assertOk();
});

test('a manager still cannot open an employee profile outside the HR routes', function () {
    $this->actingAs(hpsUser(UserRole::Manager))
        ->get(route('employees.profile', hpsUser()->employee))
        ->assertForbidden();
});

// ── View as this employee ──────────────────────────────────────────────────

test('HR Admin can view as an employee, and start and stop are audited', function () {
    $hr = hpsUser(UserRole::HrAdmin);
    $employee = hpsUser();

    $this->actingAs($hr)->post(route('impersonate.start', $employee))->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($employee->id);

    $this->get(route('impersonate.stop'))->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($hr->id);

    foreach (['IMPERSONATION_STARTED', 'IMPERSONATION_ENDED'] as $event) {
        $log = AuditLog::where('event', $event)->sole();

        expect($log->user_id)->toBe($hr->id)
            ->and($log->auditable_id)->toBe($employee->id)
            ->and($log->auditable_type)->toBe(User::class)
            ->and($log->created_at)->not->toBeNull();
    }
});

test('HR Admin cannot view as a Super Admin', function () {
    $hr = hpsUser(UserRole::HrAdmin);

    $this->actingAs($hr)->post(route('impersonate.start', hpsUser(UserRole::SuperAdmin)))->assertForbidden();
    expect(auth()->id())->toBe($hr->id);
});

test('HR Admin cannot view as an inactive employee, who would be signed straight out', function () {
    $hr = hpsUser(UserRole::HrAdmin);
    $inactive = hpsUser(employee: ['status' => 'inactive']);

    expect($hr->canImpersonate($inactive))->toBeFalse();
    $this->actingAs($hr)->post(route('impersonate.start', $inactive))->assertForbidden();
    expect(auth()->id())->toBe($hr->id);
});

test('roles without the permission cannot view as anyone', function (UserRole $role) {
    $actor = hpsUser($role);

    expect($actor->hasPermission('impersonate'))->toBeFalse();
    $this->actingAs($actor)->post(route('impersonate.start', hpsUser()))->assertForbidden();
})->with([UserRole::Director, UserRole::Manager, UserRole::Finance, UserRole::Employee]);

test('a manager can view as their report once the role is explicitly granted the permission', function () {
    $manager = hpsUser(UserRole::Manager);
    $report = hpsUser(employee: ['manager_id' => $manager->id]);

    $role = Role::where('slug', 'manager')->first();
    $role->permissions()->attach(Permission::where('key', 'impersonate')->value('id'));
    $role->flushPermissionCache();

    $this->actingAs($manager->fresh())->post(route('impersonate.start', $report))->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($report->id);
});

test('the eye icon follows the same authorization', function () {
    hpsUser();

    $this->actingAs(hpsUser(UserRole::HrAdmin))->get(route('employees.index'))->assertSee('View as this employee');
    $this->actingAs(hpsUser(UserRole::Manager))->get(route('employees.index'))->assertDontSee('View as this employee');
});

// ── Settings ───────────────────────────────────────────────────────────────

test('HR Admin can open the HR settings pages', function (string $route) {
    $this->actingAs(hpsUser(UserRole::HrAdmin))->get(route($route))->assertOk();
})->with([
    'settings.general', 'settings.control-panel', 'settings.departments', 'settings.employment-types',
    'settings.work-modes', 'settings.job-titles', 'settings.notifications', 'settings.onboarding-templates',
    'settings.holidays', 'settings.modules', 'settings.menu', 'settings.audit-log',
]);

test('HR Admin cannot use the Super Admin-only destructive controls', function () {
    $hr = hpsUser(UserRole::HrAdmin);
    $archived = hpsUser()->employee;

    $this->actingAs($hr)->get(route('settings.data-management'))->assertForbidden();
    Livewire::actingAs($hr)->test(DataManagement::class)->assertForbidden();

    expect($hr->can('forceDelete', $archived))->toBeFalse()
        ->and(hpsUser(UserRole::SuperAdmin)->can('forceDelete', $archived))->toBeTrue();
});

test('the sidebar shows HR the Settings menu and Manage Employees, without Data Management', function () {
    $this->actingAs(hpsUser(UserRole::HrAdmin))->get(route('dashboard'))
        ->assertSee(route('settings.control-panel'))
        ->assertSee(route('settings.general'))
        ->assertSee(route('employees.index'))
        ->assertDontSee(route('settings.data-management'));

    $this->actingAs(hpsUser(UserRole::Manager))->get(route('dashboard'))
        ->assertDontSee(route('settings.control-panel'));
});

// ── The sync migration ─────────────────────────────────────────────────────

test('the sync restores missing HR grants by key and keeps every other HR permission', function () {
    $hrRole = Role::where('slug', 'hr_admin')->first();
    $synced = ['manage_settings', 'manage_company_settings', 'view_employee', 'view_directory', 'impersonate'];

    // A production-like state: the five grants missing, one permission row
    // missing entirely, and the destructive controls still held.
    $hrRole->permissions()->detach(Permission::whereIn('key', $synced)->pluck('id'));
    Permission::where('key', 'manage_company_settings')->delete();
    $hrRole->permissions()->attach(Permission::whereIn('key', ['data_purge', 'force_delete_employee'])->pluck('id'));
    $hrRole->flushPermissionCache();

    $before = array_diff(hpsHrKeys(), ['data_purge', 'force_delete_employee']);

    hpsRunSync();

    $after = hpsHrKeys();
    $hr = hpsUser(UserRole::HrAdmin);

    expect($after)->toContain(...$synced)
        ->and(array_diff($before, $after))->toBeEmpty()
        ->and($after)->toContain('manage_roles')
        ->and($after)->not->toContain('data_purge')
        ->and($after)->not->toContain('force_delete_employee');

    foreach ($synced as $key) {
        expect($hr->hasPermission($key))->toBeTrue("HR should hold {$key}");
    }
});

test('the sync is idempotent and leaves other roles alone', function () {
    $otherRoles = fn () => DB::table('role_permission')
        ->where('role_id', '!=', Role::where('slug', 'hr_admin')->value('id'))
        ->count();

    hpsRunSync();
    $hrKeys = hpsHrKeys();
    $others = $otherRoles();
    $permissions = Permission::count();

    hpsRunSync();
    hpsRunSync();

    expect(hpsHrKeys())->toBe($hrKeys)
        ->and($otherRoles())->toBe($others)
        ->and(Permission::count())->toBe($permissions);
});
