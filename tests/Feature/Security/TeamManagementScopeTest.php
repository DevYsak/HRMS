<?php

use App\Livewire\Employees\TeamManagement;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * Teams follow the manage-employees scope (Phase 1 leakage audit): a
 * department-scoped Director runs only their own department's teams and
 * people; HR (company-wide) runs every team.
 */
function tmsUser(string $slug, Department $department, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function tmsStaff(Department $department, string $name): Employee
{
    $user = User::factory()->create(['name' => $name, 'role_id' => Role::where('slug', 'employee')->value('id')]);

    return Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
}

beforeEach(function () {
    $this->own = Department::factory()->create(['name' => 'Own Ops']);
    $this->other = Department::factory()->create(['name' => 'Other Bravo']);
    $this->director = tmsUser('director', $this->own, 'Dina Director');
    $this->insider = tmsStaff($this->own, 'Ivy Insider');
    $this->outsider = tmsStaff($this->other, 'Otto Outsider');
    $this->ownTeam = DepartmentTeam::create(['department_id' => $this->own->id, 'name' => 'Own Squad', 'status' => 'active']);
    $this->otherTeam = DepartmentTeam::create(['department_id' => $this->other->id, 'name' => 'Bravo Squad', 'status' => 'active']);
});

test('a department-scoped Director sees only their department\'s teams and people', function () {
    Livewire::actingAs($this->director)->test(TeamManagement::class)
        ->assertSee('Own Squad')
        ->assertDontSee('Bravo Squad')
        ->assertDontSee('Otto Outsider')
        ->assertDontSee('Other Bravo');
});

test('the Director cannot open, create in, or staff from another department', function () {
    Livewire::actingAs($this->director)->test(TeamManagement::class)
        ->call('editTeam', $this->otherTeam->id)
        ->assertForbidden();

    Livewire::actingAs($this->director)->test(TeamManagement::class)
        ->set('name', 'Sneaky')->set('departmentId', $this->other->id)->set('status', 'active')
        ->call('save')
        ->assertForbidden();

    Livewire::actingAs($this->director)->test(TeamManagement::class)
        ->set('name', 'Mixed')->set('departmentId', $this->own->id)->set('status', 'active')
        ->set('memberIds', [$this->insider->id, $this->outsider->id])
        ->call('save')
        ->assertForbidden();

    expect(DepartmentTeam::whereIn('name', ['Sneaky', 'Mixed'])->exists())->toBeFalse();
});

test('in scope, the Director still builds a team', function () {
    Livewire::actingAs($this->director)->test(TeamManagement::class)
        ->set('name', 'Night Shift')->set('departmentId', $this->own->id)->set('status', 'active')
        ->set('memberIds', [$this->insider->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(DepartmentTeam::where('name', 'Night Shift')->first()?->activeMemberships()->pluck('employee_id')->all())->toBe([$this->insider->id]);
});

test('company-wide HR runs every team', function () {
    $hr = tmsUser('hr_admin', $this->own, 'Hana HR');

    Livewire::actingAs($hr)->test(TeamManagement::class)
        ->assertSee('Own Squad')
        ->assertSee('Bravo Squad')
        ->call('editTeam', $this->otherTeam->id)
        ->assertSet('editingId', $this->otherTeam->id);
});
