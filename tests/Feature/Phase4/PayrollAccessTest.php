<?php

use App\Livewire\DepartmentDashboard;
use App\Livewire\ExecutiveDashboard;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollApprovalStep;
use App\Models\Payslip;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Payroll\PayrollAccess;
use App\Services\People\Employee360Access;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;

/**
 * Phase 4 payroll access policy: permission = what action, scope = whose
 * payroll — never inferred from role names; unknown scope fails closed.
 */
function paUser(string $slug, Department $department, string $name = 'Someone'): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function paOverride(User $user, string $key, string $effect = 'grant', ?string $scope = null): User
{
    DB::table('user_permission_overrides')->insert(['user_id' => $user->id, 'permission_id' => Permission::where('key', $key)->value('id'),
        'effect' => $effect, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
    Cache::forget(UserPermissionOverride::cacheKey($user->id));
    Once::flush();   // permissions are memoised per request; a test is one long request

    return $user->fresh();
}

function paSlip(Employee $employee, Payroll $payroll, float $net): Payslip
{
    return Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $employee->id, 'gross_salary' => $net, 'total_deductions' => 0, 'net_salary' => $net, 'status' => 'paid']);
}

beforeEach(function () {
    $this->sales = Department::factory()->create(['name' => 'PA Sales']);
    $this->ops = Department::factory()->create(['name' => 'PA Ops']);
    $this->payroll = Payroll::create(['month' => 'September', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized', 'finance_approved_at' => now()]);
    $this->salesPerson = paUser('employee', $this->sales, 'Sam Sales');
    $this->opsPerson = paUser('employee', $this->ops, 'Olly Ops');
    $this->salesSlip = paSlip($this->salesPerson->employee, $this->payroll, 41111);
    $this->opsSlip = paSlip($this->opsPerson->employee, $this->payroll, 97777);
});

test('payroll pages open for company-wide payroll roles only; Directors, heads, managers and employees are refused', function (string $slug, bool $opens) {
    $user = paUser($slug, $this->sales);

    foreach (['payroll.overview', 'payroll.process', 'payroll.components', 'reports.payroll-register'] as $route) {
        $response = $this->actingAs($user)->get(route($route));
        $opens ? $response->assertSuccessful() : $response->assertForbidden();
    }
})->with([
    'super admin' => ['super_admin', true], 'hr admin' => ['hr_admin', true], 'finance' => ['finance', true],
    'director' => ['director', false], 'department head' => ['department_head', false], 'manager' => ['manager', false], 'employee' => ['employee', false],
]);

test('the bank-transfer file needs export_bank_transfer; salary components need manage_salary_components', function () {
    $finance = paUser('finance', $this->sales);
    $hr = paUser('hr_admin', $this->sales);

    $this->actingAs($finance)->get(route('reports.bank-transfer'))->assertSuccessful();
    $this->actingAs($hr)->get(route('reports.bank-transfer'))->assertForbidden();

    $hr = paOverride($hr, 'manage_salary_components', 'revoke');
    $this->actingAs($hr)->get(route('payroll.components'))->assertForbidden();
    $this->actingAs($hr)->get(route('payroll.overview'))->assertSuccessful();
});

test('a department-scoped payroll grant reaches its department only, and never company-wide payroll', function () {
    $director = paOverride(paUser('director', $this->sales, 'Dina Director'), 'view_payroll', 'grant', 'department');
    $access = app(PayrollAccess::class);

    expect($access->covers($director, $this->salesPerson->employee))->toBeTrue()
        ->and($access->covers($director, $this->opsPerson->employee))->toBeFalse()
        ->and($access->isCompanyWide($director))->toBeFalse();

    $this->actingAs($director)->get(route('payroll.payslips.download', $this->opsSlip))->assertForbidden();
    $this->actingAs($director)->get(route('payroll.overview'))->assertForbidden();
});

test('an employee downloads only their own payslip', function () {
    $this->actingAs($this->salesPerson)->get(route('payroll.payslips.download', $this->salesSlip))->assertSuccessful();
    $this->actingAs($this->salesPerson)->get(route('payroll.payslips.download', $this->opsSlip))->assertForbidden();
    $this->actingAs($this->salesPerson)->get(route('payroll.payslips.print-combined', ['ids' => [$this->opsSlip->id]]))->assertNotFound();
});

test('Directors see no employee pay by default; an explicit summary grant shows only their department total', function () {
    $director = paUser('director', $this->sales, 'Dina Director');
    $this->sales->update(['head_id' => $director->id]);
    expect($director->hasPermission('view_payroll'))->toBeFalse()
        ->and(app(Employee360Access::class)->sections($director, $this->salesPerson->employee)['payable_overtime'])->toBeFalse();

    Livewire::actingAs($director)->test(DepartmentDashboard::class)->assertOk()->assertDontSee('Department Payroll Cost');

    $director = paOverride($director, 'view_department_payroll_summary', 'grant', 'department');
    Livewire::actingAs($director)->test(DepartmentDashboard::class)
        ->assertSee('Department Payroll Cost')->assertSee('₹41,111')->assertDontSee('97,777');
});

test('company payroll cost on the Executive view needs company-wide payroll access', function () {
    $hr = paUser('hr_admin', $this->sales);
    Livewire::actingAs($hr)->test(ExecutiveDashboard::class)->assertSee('Payroll Cost');

    $scopedHr = paOverride(paUser('hr_admin', $this->sales), 'view_payroll', 'grant', 'department');
    Livewire::actingAs($scopedHr)->test(ExecutiveDashboard::class)->assertDontSee('Payroll Cost')->assertDontSee('Payroll Analytics');
});

test('payroll approval steps are decided by permission with company-wide reach, never by role name', function () {
    $step = new PayrollApprovalStep(['approver_type' => 'finance']);
    $finance = paUser('finance', $this->sales);
    expect($step->isEligible($finance))->toBeTrue();

    // A Finance user whose approve_finance is revoked can no longer sign off.
    expect($step->isEligible(paOverride($finance, 'approve_finance', 'revoke')))->toBeFalse();

    // A custom role granted approve_finance with company-wide payroll can.
    $custom = Role::create(['name' => 'Payroll Controller', 'slug' => 'payroll_controller', 'is_system' => false]);
    $controller = User::factory()->create(['role' => 'employee', 'role_id' => $custom->id]);
    $controller = paOverride(paOverride($controller, 'approve_finance'), 'view_payroll', 'grant', 'all');
    expect($step->isEligible($controller))->toBeTrue();

    // An "HR review" step: HR Admin holds approve_payroll by default; a Director does not.
    $hrStep = new PayrollApprovalStep(['approver_type' => 'hr_admin']);
    expect($hrStep->isEligible(paUser('hr_admin', $this->sales)))->toBeTrue()
        ->and($hrStep->isEligible(paUser('director', $this->sales)))->toBeFalse();
});
