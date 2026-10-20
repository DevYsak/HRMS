<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\TeamAttendance;
use App\Livewire\ManagerDashboard;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Phase 5 policy QA-F-080 — team pages show the page permission's scope
 * (PeopleScope): a Department Head's headed department, a Manager's team.
 * UK Operations is headed by Dana; its people report to Manny, not to her.
 */
function tsUser(string $slug, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
}

function tsStaff(string $name, Department $department, ?User $manager): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => $name, 'role' => UserRole::Employee])->id,
        'department_id' => $department->id, 'manager_id' => $manager?->id, 'status' => 'active', 'joining_date' => '2024-01-08',
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 11:00:00'));
    $this->head = tsUser('department_head', 'Dana Head');
    $this->manager = tsUser('manager', 'Manny Manager');
    $this->ops = Department::factory()->create(['name' => 'UK Operations', 'head_id' => $this->head->id]);
    $marketing = Department::factory()->create(['name' => 'Marketing']);
    Employee::factory()->create(['user_id' => $this->head->id, 'department_id' => $this->ops->id, 'status' => 'active', 'joining_date' => '2024-01-08']);
    Employee::factory()->create(['user_id' => $this->manager->id, 'department_id' => $this->ops->id, 'status' => 'active', 'joining_date' => '2024-01-08']);

    tsStaff('OPSALPHA', $this->ops, $this->manager);
    tsStaff('OPSBETA', $this->ops, null);
    tsStaff('MARKETINGONLY', $marketing, null);
});

test('a Department Head\'s Team Attendance covers the whole headed department, with a department scope badge', function () {
    Livewire::actingAs($this->head)->test(TeamAttendance::class)
        ->assertOk()
        ->assertSee('OPSALPHA')->assertSee('OPSBETA')->assertSee('Manny Manager')
        ->assertDontSee('MARKETINGONLY')
        ->assertSee('Scope: UK Operations')
        ->assertSee('Department scope');
});

test('a Manager\'s Team Attendance stays on their team', function () {
    Livewire::actingAs($this->manager)->test(TeamAttendance::class)
        ->assertOk()
        ->assertSee('OPSALPHA')
        ->assertDontSee('OPSBETA')->assertDontSee('MARKETINGONLY')
        ->assertSee('Scope: My team')->assertDontSee('Department scope');
});

test('Team View follows its own permission\'s scope, never wider', function () {
    Livewire::actingAs($this->head)->test(ManagerDashboard::class)
        ->assertOk()
        ->assertSee('OPSBETA')
        ->assertDontSee('MARKETINGONLY')
        ->assertSee('Department scope');

    Livewire::actingAs($this->manager)->test(ManagerDashboard::class)
        ->assertOk()
        ->assertDontSee('OPSBETA')
        ->assertDontSee('MARKETINGONLY');
});
