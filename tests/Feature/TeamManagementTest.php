<?php

use App\Enums\UserRole;
use App\Livewire\Employees\TeamManagement;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\Employee;
use App\Models\User;
use App\Services\Teams\TeamService;
use Livewire\Livewire;

test('HR can create a team with a lead and members', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $dept = Department::factory()->create();
    $lead = Employee::factory()->create(['department_id' => $dept->id]);
    $m1 = Employee::factory()->create(['department_id' => $dept->id]);
    $m2 = Employee::factory()->create(['department_id' => $dept->id]);

    Livewire::actingAs($hr)->test(TeamManagement::class)
        ->call('newTeam')
        ->set('name', 'Web Team')
        ->set('departmentId', $dept->id)
        ->set('teamLeadId', $lead->id)
        ->set('memberIds', [$m1->id, $m2->id])
        ->call('save')
        ->assertSet('showForm', false)
        ->assertHasNoErrors();

    $team = DepartmentTeam::first();
    expect($team->name)->toBe('Web Team')
        ->and($team->team_lead_id)->toBe($lead->id)
        ->and($team->activeMemberships()->count())->toBe(2);

    expect($m1->fresh()->activeTeam()->id)->toBe($team->id);
});

test('editing a team reconciles membership — unticked members are removed', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $dept = Department::factory()->create();
    $m1 = Employee::factory()->create(['department_id' => $dept->id]);
    $m2 = Employee::factory()->create(['department_id' => $dept->id]);
    $team = DepartmentTeam::create(['department_id' => $dept->id, 'name' => 'Ops', 'status' => 'active']);
    app(TeamService::class)->assignMany([$m1->id, $m2->id], $team);

    Livewire::actingAs($hr)->test(TeamManagement::class)
        ->call('editTeam', $team->id)
        ->assertSet('memberIds', fn ($ids) => count($ids) === 2)
        ->set('memberIds', [$m1->id])   // drop m2
        ->call('save');

    expect($team->activeMemberships()->count())->toBe(1)
        ->and($m2->fresh()->activeTeam())->toBeNull();
});

test('the secondary lead must differ from the primary lead', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $dept = Department::factory()->create();
    $lead = Employee::factory()->create(['department_id' => $dept->id]);

    Livewire::actingAs($hr)->test(TeamManagement::class)
        ->call('newTeam')
        ->set('name', 'Dupe')
        ->set('departmentId', $dept->id)
        ->set('teamLeadId', $lead->id)
        ->set('secondaryLeadId', $lead->id)
        ->call('save')
        ->assertHasErrors('secondaryLeadId');
});

test('a non-HR user cannot open team management', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(TeamManagement::class)
        ->assertForbidden();
});
