<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\AttendanceReports;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\DepartmentTeamMember;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Department / team / employee filters on the HR attendance views, always
 * inside the viewer's reach — and the pickers never list people outside it.
 */
function afEmployee(Department $department, ?User $manager = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'department_id' => $department->id,
        'manager_id' => $manager?->id, 'joining_date' => '2024-01-08',
    ]);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => '2026-10-13', 'check_in' => '2026-10-13 09:00:00',
        'check_out' => '2026-10-13 18:00:00', 'status' => 'on_time', 'work_mode' => 'office',
    ]);

    return $employee;
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-14 10:00:00')));

test('HR narrows All Attendance by department, team and employee', function () {
    $sales = Department::factory()->create(['name' => 'Sales']);
    $ops = Department::factory()->create(['name' => 'Ops']);
    $a = afEmployee($sales);
    $b = afEmployee($sales);
    $c = afEmployee($ops);
    $team = DepartmentTeam::create(['department_id' => $sales->id, 'name' => 'Inside Sales', 'status' => 'active']);
    DepartmentTeamMember::create(['department_team_id' => $team->id, 'employee_id' => $a->id, 'is_active' => true, 'joined_at' => '2025-01-01']);

    $ids = fn ($component) => $component->viewData('attendances')->pluck('employee_id')->unique()->sort()->values()->all();
    $component = Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))->test(AllAttendance::class)
        ->set('dateFrom', '2026-10-12')->set('dateTo', '2026-10-14');

    expect($ids($component->set('filterDepartment', (string) $sales->id)))->toBe([$a->id, $b->id])
        ->and($ids($component->set('filterTeam', (string) $team->id)))->toBe([$a->id])
        ->and($ids($component->set('filterTeam', '')->set('filterDepartment', '')->set('filterEmployee', (string) $c->id)))->toBe([$c->id]);
});

test('a manager\'s pickers list only their own team', function () {
    $dept = Department::factory()->create();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $mine = afEmployee($dept, $manager);
    $other = afEmployee($dept);

    $all = Livewire::actingAs($manager)->test(AllAttendance::class)->viewData('filterEmployees')->pluck('id');
    $reports = Livewire::actingAs($manager)->test(AttendanceReports::class)->viewData('employees')->pluck('id');

    expect($all)->toContain($mine->id)->not->toContain($other->id)
        ->and($reports)->toContain($mine->id)->not->toContain($other->id);
});

test('a filter cannot reach an employee outside the viewer\'s scope', function () {
    $dept = Department::factory()->create();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    afEmployee($dept, $manager);
    $other = afEmployee($dept);

    $rows = Livewire::actingAs($manager)->test(AllAttendance::class)
        ->set('dateFrom', '2026-10-12')->set('dateTo', '2026-10-14')
        ->set('filterEmployee', (string) $other->id)
        ->viewData('attendances');

    expect($rows->total())->toBe(0);
});
