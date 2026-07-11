<?php

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Teams\ApprovalRoutingService;
use App\Services\Teams\TeamService;
use Illuminate\Support\Carbon;

/**
 * v4 Part 3 — department teams, one-active-team enforcement, and the
 * Team Lead → secondary → department head → HR approval routing chain.
 */
function makeTeam(?Department $dept = null, ?Employee $lead = null, ?Employee $secondary = null): DepartmentTeam
{
    $dept ??= Department::factory()->create();

    return DepartmentTeam::create([
        'department_id' => $dept->id,
        'name' => 'Web Team',
        'team_lead_id' => $lead?->id,
        'secondary_lead_id' => $secondary?->id,
        'status' => 'active',
    ]);
}

test('assigning an employee to a team closes any prior active membership', function () {
    $employee = Employee::factory()->create();
    $teamA = makeTeam();
    $teamB = makeTeam();
    $service = app(TeamService::class);

    $service->assign($employee, $teamA);
    $service->assign($employee, $teamB);

    // Exactly one active membership, and it's the newer team; history preserved.
    expect($employee->teamMemberships()->where('is_active', true)->count())->toBe(1)
        ->and($employee->fresh()->activeTeam()->id)->toBe($teamB->id)
        ->and($employee->teamMemberships()->count())->toBe(2);           // old row kept, closed

    $old = $employee->teamMemberships()->where('department_team_id', $teamA->id)->first();
    expect($old->is_active)->toBeFalse()->and($old->left_at)->not->toBeNull();
});

test('re-assigning to the same team is a no-op', function () {
    $employee = Employee::factory()->create();
    $team = makeTeam();
    $service = app(TeamService::class);

    $service->assign($employee, $team);
    $service->assign($employee, $team);

    expect($employee->teamMemberships()->count())->toBe(1);
});

test('approval routes to the team lead first', function () {
    $dept = Department::factory()->create();
    $leadEmp = Employee::factory()->create(['department_id' => $dept->id, 'user_id' => User::factory()->create(['name' => 'LEAD'])->id]);
    $team = makeTeam($dept, $leadEmp);

    $member = Employee::factory()->create(['department_id' => $dept->id]);
    app(TeamService::class)->assign($member, $team);

    $approver = app(ApprovalRoutingService::class)->resolveApprover($member->fresh());

    expect($approver->name)->toBe('LEAD');
});

test('when the team lead is on leave that day, routing falls to the secondary lead', function () {
    $dept = Department::factory()->create();
    $leadEmp = Employee::factory()->create(['department_id' => $dept->id, 'user_id' => User::factory()->create(['name' => 'LEAD'])->id]);
    $secondEmp = Employee::factory()->create(['department_id' => $dept->id, 'user_id' => User::factory()->create(['name' => 'SECOND'])->id]);
    $team = makeTeam($dept, $leadEmp, $secondEmp);

    $member = Employee::factory()->create(['department_id' => $dept->id]);
    app(TeamService::class)->assign($member, $team);

    // The primary lead is on approved leave covering today.
    LeaveRequest::create([
        'employee_id' => $leadEmp->id,
        'leave_type_id' => LeaveType::create(['name' => 'Casual'])->id,
        'start_date' => today()->subDay(), 'end_date' => today()->addDay(), 'days' => 3,
        'status' => 'approved', 'reason' => 'Vacation',
    ]);

    $chain = app(ApprovalRoutingService::class)->getApproverChain($member->fresh(), Carbon::today());

    expect($chain->first()->name)->toBe('SECOND');
});

test('when the requester is the team lead, routing goes to the department head', function () {
    $head = User::factory()->create(['name' => 'DEPT HEAD']);
    $dept = Department::factory()->create(['head_id' => $head->id]);
    $leadEmp = Employee::factory()->create(['department_id' => $dept->id]);
    makeTeam($dept, $leadEmp);
    app(TeamService::class)->assign($leadEmp, makeTeam($dept, $leadEmp));

    // Re-fetch: the lead's own request skips themselves → department head.
    $approver = app(ApprovalRoutingService::class)->resolveApprover($leadEmp->fresh());

    expect($approver->name)->toBe('DEPT HEAD');
});

test('with no team, routing falls through to an in-scope HR admin', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'HR ONE']);
    $employee = Employee::factory()->create();

    $approver = app(ApprovalRoutingService::class)->resolveApprover($employee);

    expect($approver->name)->toBe('HR ONE');
});
