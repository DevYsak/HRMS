<?php

use App\Livewire\Employees\ProbationConfirmation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\ProbationEngine;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Probation confirmation follows the employee record's scope: a
 * department-scoped Director can open and extend probation only inside their
 * own department, and the probation service enforces the same rule for every
 * caller. (Found by the role × route audit: the page checked the capability
 * but never whose probation it was.)
 */
function psUser(string $slug, Department $department): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

beforeEach(function () {
    $this->own = Department::factory()->create(['name' => 'Own Dept']);
    $this->other = Department::factory()->create(['name' => 'Other Dept']);
    $this->director = psUser('director', $this->own);
    $this->inside = Employee::factory()->create(['manager_id' => null, 'department_id' => $this->own->id, 'status' => 'probation', 'probation_end_date' => now()->addDays(10)->toDateString()]);
    $this->outside = Employee::factory()->create(['manager_id' => null, 'department_id' => $this->other->id, 'status' => 'probation', 'probation_end_date' => now()->addDays(10)->toDateString()]);
});

test('a department-scoped Director cannot open another department\'s probation page', function () {
    $this->actingAs($this->director)->get(route('employees.probation', $this->outside))->assertForbidden();
    $this->actingAs($this->director)->get(route('employees.probation', $this->inside))->assertOk();
});

test('the probation service refuses to extend outside the actor\'s scope', function () {
    expect(fn () => app(ProbationEngine::class)->extend($this->outside, $this->director, now()->addDays(30)->toDateString(), 'Needs more time'))
        ->toThrow(HttpException::class);

    expect($this->outside->fresh()->probation_end_date->toDateString())->toBe(now()->addDays(10)->toDateString());
});

test('in scope, the Director can still extend from the probation page', function () {
    Livewire::actingAs($this->director)->test(ProbationConfirmation::class, ['employee' => $this->inside])
        ->set('extendEndDate', now()->addDays(30)->toDateString())
        ->set('extendReason', 'Needs more time on the new process')
        ->call('extendProbation')
        ->assertHasNoErrors();

    expect($this->inside->fresh()->probation_end_date->toDateString())->toBe(now()->addDays(30)->toDateString());
});

test('nobody extends their own probation', function () {
    $hr = psUser('hr_admin', $this->own);
    $hr->employee->update(['status' => 'probation']);

    expect(fn () => app(ProbationEngine::class)->extend($hr->employee->fresh(), $hr, now()->addDays(30)->toDateString(), 'Self extension'))
        ->toThrow(Exception::class);
});

test('company-wide HR still extends any employee', function () {
    $hr = psUser('hr_admin', $this->own);

    app(ProbationEngine::class)->extend($this->outside, $hr, now()->addDays(20)->toDateString(), 'Project ramp-up');

    expect($this->outside->fresh()->probation_end_date->toDateString())->toBe(now()->addDays(20)->toDateString());
});
