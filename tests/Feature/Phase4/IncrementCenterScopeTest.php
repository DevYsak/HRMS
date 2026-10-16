<?php

use App\Livewire\Performance\IncrementCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\IncrementCycle;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\Increments\IncrementService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Phase 4 RBAC: the Increment Center follows the manage_employees reach.
 * A Director (department scope) sees and adjusts only their department's
 * proposals — never another department's pay or the company payroll pool —
 * and cannot run company-wide cycle actions.
 */
function icUser(string $slug, Department $department, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function icSalary(Employee $employee, float $gross): void
{
    $component = SalaryComponent::firstOrCreate(['code' => 'BASIC-IC'],
        ['name' => 'Basic', 'type' => 'earning', 'component_type' => 'earning', 'calculation_type' => 'fixed', 'is_active' => true]);
    EmployeeSalary::create(['employee_id' => $employee->id, 'salary_component_id' => $component->id, 'amount' => $gross, 'effective_from' => '2025-07-01']);
}

beforeEach(function () {
    Notification::fake();
    $this->sales = Department::factory()->create(['name' => 'IC Sales']);
    $this->ops = Department::factory()->create(['name' => 'IC Ops']);
    $this->hr = icUser('hr_admin', $this->sales, 'Hana HR');
    $this->director = icUser('director', $this->sales, 'Dina Director');
    $this->own = icUser('employee', $this->sales, 'Sam Sales')->employee;
    $this->other = icUser('employee', $this->ops, 'Olly Ops')->employee;
    icSalary($this->own, 41111);
    icSalary($this->other, 97777);

    $this->cycle = app(IncrementService::class)->openCycle('2026-27', '2026-07-01', 10, $this->hr);
    app(IncrementService::class)->generateProposals($this->cycle, $this->hr);
    $this->otherProposal = $this->cycle->proposals()->where('employee_id', $this->other->id)->firstOrFail();
    $this->ownProposal = $this->cycle->proposals()->where('employee_id', $this->own->id)->firstOrFail();
});

test('HR sees every proposal and the payroll pool', function () {
    Livewire::actingAs($this->hr)->test(IncrementCenter::class)
        ->assertOk()->assertSee('Olly Ops')->assertSee('97,777')->assertSee('pool')->assertViewHas('companyWide', true);
});

test('a department-scoped Director sees only their department, never the pool', function () {
    Livewire::actingAs($this->director)->test(IncrementCenter::class)
        ->assertOk()
        ->assertSee('Sam Sales')->assertSee('41,111')
        ->assertDontSee('Olly Ops')->assertDontSee('97,777')
        ->assertDontSee('pool ₹')->assertSee('Showing proposals for your departments only')
        ->assertViewHas('companyWide', false)->assertViewHas('canApprove', false);
});

test('direct calls outside the reach or on the whole cycle are refused', function () {
    $page = fn () => Livewire::actingAs($this->director)->test(IncrementCenter::class);

    $page()->call('saveProposal', $this->otherProposal->id)->assertForbidden();
    $page()->call('openOverride', $this->otherProposal->id)->assertForbidden();
    $page()->set('overrideProposalId', $this->otherProposal->id)->set('overrideBand', 'A')->set('overrideReason', 'Trying to change another department')
        ->call('applyOverride')->assertForbidden();
    $page()->call('newCycleForm')->assertForbidden();
    $page()->call('generate')->assertForbidden();
    $page()->call('submitCycle')->assertForbidden();
    $page()->call('approveCycle')->assertForbidden();
    $page()->call('applyCycle')->assertForbidden();

    expect($this->otherProposal->fresh()->band)->toBe($this->otherProposal->band)
        ->and(IncrementCycle::count())->toBe(1);

    // Their own department's proposal is still theirs to adjust.
    $page()->call('openOverride', $this->ownProposal->id)->assertOk()->assertSet('overrideProposalId', $this->ownProposal->id);
});
