<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard\PeopleOperations;
use App\Livewire\Performance\KpiManagement;
use App\Livewire\Settings\ApprovalMatrix;
use App\Livewire\Settings\RoleManager;
use App\Livewire\Settings\UserPermissionOverrides;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Ai\AiIntentRouter;
use App\Services\Security\PermissionSections;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function toolsSuperAdmin(): User
{
    return User::factory()->create(['role' => UserRole::SuperAdmin]);
}

function toolsDepartmentHead(Department $department): User
{
    $role = Role::where('slug', 'department_head')->firstOrFail();
    $user = User::factory()->create(['name' => 'Nikita Dalal', 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
    $department->update(['head_id' => $user->id]);

    return $user->fresh();
}

// ── Roles & Permissions ─────────────────────────────────────────────────────

test('the role editor groups permissions into the nine sections and summarises the role before saving', function () {
    $role = Role::where('slug', 'department_head')->firstOrFail();

    Livewire::actingAs(toolsSuperAdmin())->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->assertViewHas('groupedPermissions', fn ($groups) => $groups->keys()->diff(PermissionSections::ORDER)->isEmpty())
        ->assertSeeHtml('data-role-summary')
        ->assertSee('Team View')
        ->assertSee('Default scope')
        ->assertSee('Leave (Own department)')
        ->assertSee('Payroll approval')
        ->assertSee('System settings')
        ->assertSee('Company-wide data');
});

test('a preset is previewed first and only fills the editor — nothing is saved, overrides untouched', function () {
    $role = Role::where('slug', 'manager')->firstOrFail();
    $before = $role->permissions()->pluck('key')->sort()->values()->all();
    $managerUser = User::factory()->create(['role' => UserRole::Manager, 'role_id' => $role->id]);
    UserPermissionOverride::create(['user_id' => $managerUser->id, 'permission_id' => Permission::where('key', 'export_reports')->value('id'), 'effect' => 'grant', 'reason' => 'Monthly pack']);

    $page = Livewire::actingAs(toolsSuperAdmin())->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->call('previewPreset', 'hr_admin')
        ->assertSeeHtml('data-preset-preview')
        ->assertSee('Preview: HR Admin preset');

    expect($role->fresh()->permissions()->pluck('key')->sort()->values()->all())->toBe($before);

    $page->call('applyPreset');
    expect($role->fresh()->permissions()->pluck('key')->sort()->values()->all())->toBe($before)
        ->and(UserPermissionOverride::where('user_id', $managerUser->id)->count())->toBe(1);
});

test('a user with overrides is badged, explained and can be reset to role defaults — audited', function () {
    $role = Role::where('slug', 'manager')->firstOrFail();
    $target = User::factory()->create(['role' => UserRole::Manager, 'role_id' => $role->id]);
    UserPermissionOverride::create(['user_id' => $target->id, 'permission_id' => Permission::where('key', 'export_reports')->value('id'), 'effect' => 'grant', 'reason' => 'Monthly pack']);
    UserPermissionOverride::create(['user_id' => $target->id, 'permission_id' => Permission::where('key', 'approve_overtime')->value('id'), 'effect' => 'revoke', 'reason' => 'Not their team']);
    $admin = toolsSuperAdmin();

    Livewire::actingAs($admin)->test(RoleManager::class)
        ->assertViewHas('overrideCounts', fn ($c) => ($c[$role->id] ?? 0) === 1)
        ->assertSee('Custom Permissions');

    Livewire::actingAs($admin)->test(UserPermissionOverrides::class)
        ->call('selectUser', $target->id)
        ->assertSeeHtml('data-override-explainer')
        ->assertSee('+ Export Reports', false)
        ->call('resetToRoleDefaults')
        ->assertHasErrors('resetReason')
        ->set('resetReason', 'Back to the standard manager set')
        ->call('resetToRoleDefaults')
        ->assertHasNoErrors();

    expect(UserPermissionOverride::where('user_id', $target->id)->count())->toBe(0)
        ->and(AuditLog::where('event', 'USER_PERMISSION_OVERRIDES_RESET')->exists())->toBeTrue();
});

test('nobody resets their own overrides', function () {
    $admin = toolsSuperAdmin();
    UserPermissionOverride::create(['user_id' => $admin->id, 'permission_id' => Permission::where('key', 'export_reports')->value('id'), 'effect' => 'grant', 'reason' => 'Self']);

    Livewire::actingAs($admin)->test(UserPermissionOverrides::class)
        ->call('selectUser', $admin->id)
        ->set('resetReason', 'Trying my own')
        ->call('resetToRoleDefaults');

    expect(UserPermissionOverride::where('user_id', $admin->id)->count())->toBe(1);
});

// ── Approval matrix ─────────────────────────────────────────────────────────

test('the approval matrix shows the current policy and who decides, for admins only', function () {
    Livewire::actingAs(toolsSuperAdmin())->test(ApprovalMatrix::class)
        ->assertSeeHtml('data-approval-matrix="leave"')
        ->assertSeeHtml('data-approval-matrix="encashment"')
        ->assertSee('Final on approval')
        ->assertSee('Payable OT worked out from attendance')
        ->assertSee('Director or HR Admin')
        ->assertSee('Department Head');

    $employee = User::factory()->create(['role' => UserRole::Employee]);
    $this->actingAs($employee)->get(route('settings.approval-matrix'))->assertForbidden();
});

// ── KPI management ──────────────────────────────────────────────────────────

test('KPI management warns when a department in scope has no KPIs for the cycle', function () {
    $this->travelTo(Carbon::parse('2026-10-14 11:00:00'));
    $uk = Department::factory()->create(['name' => 'UK Operations']);
    $nikita = toolsDepartmentHead($uk);
    Employee::factory()->count(2)->create(['department_id' => $uk->id, 'status' => 'active', 'manager_id' => $nikita->id, 'joining_date' => '2024-01-01']);
    $template = PerformanceTemplate::create(['name' => 'Q2', 'code' => 'Q2T', 'created_by' => $nikita->id]);
    PerformanceCycle::create(['template_id' => $template->id, 'name' => 'Q2 Oct–Dec 2026', 'cycle_type' => 'quarterly', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_by' => $nikita->id]);

    Livewire::actingAs($nikita)->test(KpiManagement::class)
        ->assertSee('Scope: UK Operations')
        ->assertSee('Q2 Oct–Dec 2026 KPIs have not yet been configured for UK Operations.')
        ->assertViewHas('lockedDepartment', fn ($d) => $d?->name === 'UK Operations')
        ->assertDontSee('Configure KPIs');
});

// ── HR dashboard ───────────────────────────────────────────────────────────

test('the HR department selector drives every People Operations widget and refuses departments out of reach', function () {
    $this->travelTo(Carbon::parse('2026-10-14 11:00:00'));
    $uk = Department::factory()->create(['name' => 'UK Operations']);
    $it = Department::factory()->create(['name' => 'IT Operations']);
    Employee::factory()->count(3)->create(['department_id' => $uk->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
    Employee::factory()->count(2)->create(['department_id' => $it->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $page = Livewire::actingAs($hr)->test(PeopleOperations::class)
        ->assertSee('All Departments')
        ->assertSee('Department Health')
        ->assertSeeHtml('data-department-health="UK Operations"');

    $page->set('department', (string) $uk->id)
        ->assertViewHas('overview', fn ($o) => count($o['employee_ids']) === 3 && $o['attendance']['total'] === 3)
        ->assertViewHas('scope', fn ($s) => $s['label'] === 'UK Operations');

    $nikita = toolsDepartmentHead($uk);
    Livewire::actingAs($nikita)->test(PeopleOperations::class)
        ->set('department', (string) $it->id)
        ->assertSet('department', '')
        ->assertViewHas('departments', fn ($d) => $d->pluck('name')->all() === ['UK Operations']);
});

test('the AI never reports a department outside the user\'s scope', function () {
    $uk = Department::factory()->create(['name' => 'UK Operations']);
    Department::factory()->create(['name' => 'IT Operations']);
    $nikita = toolsDepartmentHead($uk);
    $this->actingAs($nikita);

    $router = app(AiIntentRouter::class);
    expect($router->answer($nikita, 'Show UK Operations health')['content'])->toContain('UK Operations')
        ->and($router->answer($nikita, 'Show IT Operations health')['content'])->toContain('not in your scope');
});
