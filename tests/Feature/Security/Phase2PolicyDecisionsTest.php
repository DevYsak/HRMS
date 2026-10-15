<?php

use App\Livewire\DepartmentDashboard;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\DataQuality\DataQualityAuditor;
use App\Services\Navigation\DashboardAccess;
use App\Services\ProbationEngine;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 2 policy decisions (15 Oct 2026):
 *  1. Super Admin keeps company-wide capabilities; Department View needs a
 *     department context.
 *  2. Top-level (no reporting manager expected): Super Admin, Director,
 *     HR Admin, Department Head / head of function. Others: REVIEW REQUIRED.
 *  3. The reporting manager is the canonical first approver (DepartmentTeamTest).
 *  QA-F-014: probation runs on explicit permissions, not role names.
 */
function p2dUser(string $slug, ?Department $department = null, array $employee = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => ($department ?? Department::factory()->create())->id, 'status' => 'active', 'manager_id' => null, ...$employee]);

    return $user->fresh();
}

beforeEach(function () {
    $this->ops = Department::factory()->create(['name' => 'P2D Operations']);
    $this->sales = Department::factory()->create(['name' => 'P2D Sales']);
    $this->opsPerson = p2dUser('employee', $this->ops)->employee;
    $this->salesPerson = p2dUser('employee', $this->sales)->employee;
});

test('Super Admin opens Department View only with a chosen department, never company totals', function () {
    $admin = p2dUser('super_admin');

    $this->actingAs($admin)->get(route('dashboard.department'))
        ->assertOk()->assertSee('data-department-picker', false)->assertSee('Choose a department')
        ->assertDontSee('Present Today');

    $this->actingAs($admin)->get(route('dashboard.department', ['department' => $this->ops->id]))
        ->assertOk()->assertSee('P2D Operations')->assertSee('Present Today');

    // Super Admin still lands on the Main dashboard and keeps every permission.
    expect(app(DashboardAccess::class)->landing($admin))->not->toBe('dashboard.department')
        ->and($admin->hasPermission('view_department_dashboard'))->toBeTrue();
});

test('a Department Head keeps their own Department View with no picker', function () {
    $head = p2dUser('department_head', $this->ops);
    $this->ops->update(['head_id' => $head->id]);

    $this->actingAs($head)->get(route('dashboard.department'))
        ->assertOk()->assertDontSee('data-department-picker', false)->assertSee('P2D Operations')->assertSee('Present Today');
    Livewire::actingAs($head)->test(DepartmentDashboard::class)->set('department', (string) $this->sales->id)
        ->assertDontSee('P2D Sales');
});

test('Super Admin, Director, HR Admin and Department Heads are top-level; others are not', function () {
    $top = ['super_admin', 'director', 'hr_admin', 'department_head'];
    foreach ($top as $slug) {
        expect(p2dUser($slug)->employee->isTopOfHierarchy())->toBeTrue("{$slug} should be top-level");
    }
    foreach (['finance', 'manager', 'coordinator', 'employee'] as $slug) {
        expect(p2dUser($slug)->employee->isTopOfHierarchy())->toBeFalse("{$slug} should not be top-level");
    }

    // Heading a department makes a head of function top-level, whatever the role.
    $managerHead = p2dUser('manager', $this->sales);
    $this->sales->update(['head_id' => $managerHead->id]);
    expect($managerHead->employee->fresh()->isTopOfHierarchy())->toBeTrue();
});

test('the HR completion queue and the data-quality line agree on who needs a manager', function () {
    $hr = p2dUser('hr_admin', $this->ops)->employee;
    $finance = p2dUser('finance', $this->ops)->employee;

    $queue = Employee::incompleteHrProfile()->pluck('id');
    expect(array_keys($hr->missingHrFields()))->not->toContain('manager_id')
        ->and(array_keys($finance->missingHrFields()))->toContain('manager_id')
        ->and($queue)->toContain($finance->id);

    $auditor = app(DataQualityAuditor::class);
    expect($auditor->managerLine($hr))->toBe('VALID')
        ->and($auditor->managerLine($finance))->toBe('REVIEW REQUIRED')
        ->and($auditor->issues()->where('check', 'no_manager')->pluck('employeeId'))->toContain($finance->id)->not->toContain($hr->id);
});

test('probation follows explicit permissions, not role names', function () {
    $manager = p2dUser('manager', $this->ops);
    $this->opsPerson->update(['manager_id' => $manager->id, 'status' => 'probation', 'probation_end_date' => now()->addMonth()]);
    $engine = app(ProbationEngine::class);

    // Built-in roles keep exactly their previous abilities.
    $has = fn (string $slug, string $key) => in_array($key, Role::where('slug', $slug)->first()->permissionKeys(), true);
    foreach (['manager', 'director', 'department_head', 'super_admin'] as $slug) {
        expect($has($slug, 'confirm_probation'))->toBeTrue("{$slug} confirms");
    }
    foreach (['hr_admin', 'super_admin'] as $slug) {
        expect($has($slug, 'hr_approve_probation'))->toBeTrue("{$slug} HR-approves");
    }
    foreach (['finance', 'coordinator', 'employee'] as $slug) {
        expect($has($slug, 'confirm_probation') || $has($slug, 'hr_approve_probation') || $has($slug, 'view_probation'))->toBeFalse("{$slug} has no probation access");
    }

    // Revoking the permission stops a Manager, whatever their role name says.
    UserPermissionOverride::create(['user_id' => $manager->id, 'permission_id' => Permission::where('key', 'confirm_probation')->value('id'), 'effect' => 'revoke']);
    expect(fn () => $engine->managerConfirm($this->opsPerson->fresh(), $manager->fresh()))
        ->toThrow(HttpException::class);

    // Granting it (with a Team scope) lets an Employee-role line manager confirm their report.
    $lineLead = p2dUser('employee', $this->ops);
    $this->salesPerson->update(['manager_id' => $lineLead->id, 'status' => 'probation', 'probation_end_date' => now()->addMonth()]);
    UserPermissionOverride::create(['user_id' => $lineLead->id, 'permission_id' => Permission::where('key', 'confirm_probation')->value('id'), 'effect' => 'grant', 'scope' => 'team']);
    $engine->managerConfirm($this->salesPerson->fresh(), $lineLead->fresh());
    expect($this->salesPerson->fresh()->probation_confirmed_by)->toBe($lineLead->id);

    // Step 2 needs hr_approve_probation: the line lead cannot, HR can.
    expect(fn () => $engine->hrApprove($this->salesPerson->fresh(), $lineLead->fresh()))
        ->toThrow(HttpException::class);
    $engine->hrApprove($this->salesPerson->fresh(), p2dUser('hr_admin'));
    expect($this->salesPerson->fresh()->probation_hr_approved_at)->not->toBeNull();
});

test('the probation page opens for view_probation holders in scope only', function () {
    $manager = p2dUser('manager', $this->ops);
    $this->opsPerson->update(['manager_id' => $manager->id]);

    $this->actingAs($manager)->get(route('employees.probation', $this->opsPerson))->assertOk()->assertSee('Confirm');
    $this->actingAs($manager)->get(route('employees.probation', $this->salesPerson))->assertForbidden();
    $this->actingAs(p2dUser('finance'))->get(route('employees.probation', $this->opsPerson))->assertForbidden();
});
