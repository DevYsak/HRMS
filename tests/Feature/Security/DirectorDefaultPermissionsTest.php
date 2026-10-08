<?php

use App\Enums\UserRole;
use App\Livewire\Onboarding\OffboardingManager;
use App\Livewire\Settings\ApprovalPolicySettings;
use App\Livewire\TimeOff\FinanceEncashments;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\PayrollApprovalPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * D1 (8 Oct 2026): a Director works inside the department(s) they head. They
 * do not create, delete or offboard employees, or sign off payroll, unless
 * an admin grants it in Roles & Permissions.
 */
function d1Director(): array
{
    $director = User::factory()->create(['role' => UserRole::Director]);
    $department = Department::factory()->create(['head_id' => $director->id]);
    // No manager: the factory picks a random user, who could be the Director.
    $inside = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
    $outside = Employee::factory()->create(['department_id' => Department::factory()->create()->id, 'status' => 'active', 'manager_id' => null]);

    return [$director, $inside, $outside];
}

function d1Grant(string $roleSlug, string $key): void
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $role->permissions()->syncWithoutDetaching([Permission::where('key', $key)->value('id')]);
    Cache::forget("role_{$role->id}_permission_keys");
}

function d1LeaveType(): LeaveType
{
    return LeaveType::create([
        'name' => 'D1 Encashable', 'code' => 'D1E'.rand(1000, 9999), 'is_paid' => true,
        'color' => '#000', 'category' => 'annual', 'allow_paid_request' => true, 'allow_unpaid_request' => true,
        'allow_hr_override' => false, 'hr_remark_required' => false, 'allow_carry_forward' => false,
        'carry_forward_limit' => 0, 'allow_encashment' => true, 'is_sandwich_applicable' => false,
        'allow_half_day' => true, 'is_monthly_accrual' => false, 'accrual_days_per_month' => 0,
        'gender_restriction' => 'none', 'probation_restricted' => false, 'notice_period_restricted' => false,
        'max_consecutive_days' => null, 'attachment_required' => false,
    ]);
}

test('the Director role no longer holds create, delete, offboarding or payroll sign-off by default', function () {
    $director = Role::where('slug', 'director')->firstOrFail();

    foreach (['create_employee', 'delete_employee', 'manage_offboarding', 'approve_finance', 'approve_payroll'] as $key) {
        expect($director->hasPermission($key))->toBeFalse("Director should not hold {$key}");
    }

    foreach (['manage_employees', 'view_employee', 'approve_leave', 'manage_onboarding', 'view_payroll'] as $key) {
        expect($director->hasPermission($key))->toBeTrue("Director should keep {$key}");
    }
});

test('a Director sees and edits their department, but cannot create, delete or offboard', function () {
    [$director, $inside, $outside] = d1Director();

    expect($director->can('view', $inside))->toBeTrue()
        ->and($director->can('update', $inside))->toBeTrue()
        ->and($director->can('update', $outside))->toBeFalse()
        ->and($director->can('create', Employee::class))->toBeFalse()
        ->and($director->can('delete', $inside))->toBeFalse();

    $this->actingAs($director)->get(route('employees.create'))->assertForbidden();
    $this->actingAs($director)->get(route('employees.import'))->assertForbidden();
    $this->actingAs($director)->get(route('employees.offboarding', $inside->id))->assertForbidden();
    Livewire::actingAs($director)->test(OffboardingManager::class)->assertForbidden();
});

test('a Director cannot sign off payroll or open the Finance dashboard', function () {
    [$director] = d1Director();

    $this->actingAs($director)->get(route('payroll.finance-approve'))->assertForbidden();
    $this->actingAs($director)->get(route('dashboard.finance'))->assertForbidden();
});

test('HR Admin keeps create, delete and offboarding', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['status' => 'active']);

    expect($hr->can('create', Employee::class))->toBeTrue()
        ->and($hr->can('delete', $employee))->toBeTrue()
        ->and($hr->hasPermission('manage_offboarding'))->toBeTrue();

    Livewire::actingAs($hr)->test(OffboardingManager::class)->assertOk();
});

test('a Director still approves encashments (D3), only for their own department', function () {
    [$director, $inside, $outside] = d1Director();
    $type = d1LeaveType();
    $mine = LeaveEncashment::create(['employee_id' => $inside->id, 'leave_type_id' => $type->id, 'requested_days' => 1, 'status' => 'pending']);
    $theirs = LeaveEncashment::create(['employee_id' => $outside->id, 'leave_type_id' => $type->id, 'requested_days' => 1, 'status' => 'pending']);

    $this->actingAs($director)->get(route('time-off.encashments'))->assertOk();

    $page = Livewire::actingAs($director)->test(FinanceEncashments::class);
    $ids = $page->viewData('encashments')->pluck('id')->all();

    expect($ids)->toContain($mine->id)->not->toContain($theirs->id)
        ->and($page->viewData('kpi')['pending'])->toBe(1);

    $page->call('openReview', $mine->id, 'approve')->assertSet('reviewingId', $mine->id);

    Livewire::actingAs($director)->test(FinanceEncashments::class)
        ->call('openReview', $theirs->id, 'approve')
        ->assertForbidden();
});

test('a Director payroll step needs Finance Approval granted first', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    PayrollApprovalPolicy::create(['level' => 1, 'label' => 'Finance', 'approver_type' => 'finance', 'is_active' => true]);

    Livewire::actingAs($hr)->test(ApprovalPolicySettings::class)
        ->call('openCreate')
        ->set('label', 'Director Review')
        ->set('approver_type', 'director')
        ->call('save')
        ->assertHasErrors('approver_type');

    expect(PayrollApprovalPolicy::where('approver_type', 'director')->exists())->toBeFalse();

    d1Grant('director', 'approve_finance');

    Livewire::actingAs($hr)->test(ApprovalPolicySettings::class)
        ->call('openCreate')
        ->set('label', 'Director Review')
        ->set('approver_type', 'director')
        ->call('save')
        ->assertHasNoErrors();

    expect(PayrollApprovalPolicy::where('approver_type', 'director')->exists())->toBeTrue();
});

test('the migration narrows the Director and keeps every other employee manager whole', function () {
    $custom = Role::create(['name' => 'People Ops', 'slug' => 'people-ops', 'is_system' => false, 'is_active' => true]);
    $custom->permissions()->attach(Permission::where('key', 'manage_employees')->value('id'));
    foreach (['create_employee', 'delete_employee', 'manage_offboarding', 'approve_finance', 'approve_payroll'] as $key) {
        d1Grant('director', $key);
    }

    $migration = require database_path('migrations/2026_10_08_160853_narrow_director_default_permissions.php');
    $migration->up();
    $migration->up();   // idempotent

    $director = Role::where('slug', 'director')->first();
    $custom->refresh();

    foreach (['create_employee', 'delete_employee', 'manage_offboarding', 'approve_finance', 'approve_payroll'] as $key) {
        expect($director->hasPermission($key))->toBeFalse("Director should lose {$key}");
    }
    foreach (['create_employee', 'delete_employee', 'manage_onboarding', 'manage_offboarding'] as $key) {
        expect($custom->hasPermission($key))->toBeTrue("People Ops should keep {$key}");
    }
    expect($director->hasPermission('manage_employees'))->toBeTrue();
});

test('the migration leaves Finance Approval on the Director while an active Director step exists', function () {
    d1Grant('director', 'approve_finance');
    PayrollApprovalPolicy::create(['level' => 1, 'label' => 'Director', 'approver_type' => 'director', 'is_active' => true]);

    (require database_path('migrations/2026_10_08_160853_narrow_director_default_permissions.php'))->up();

    expect(Role::where('slug', 'director')->first()->hasPermission('approve_finance'))->toBeTrue();
});
