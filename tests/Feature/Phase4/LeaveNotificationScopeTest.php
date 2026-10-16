<?php

use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Services\LeaveService;
use App\Services\Teams\TeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 4 · Notifications: a new leave request reaches the HR who cover
 * the employee, not every HR admin (a department-scoped HR admin saw other
 * departments' names and got a link that refused them), and everyone
 * notified gets a link they can open — a team lead kept informed without
 * approve_leave lands on their dashboard, not a 403.
 */
function lnsUser(string $slug, Department $department, string $name, array $user = [], array $employee = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $u = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id, ...$user]);
    Employee::factory()->create(['user_id' => $u->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null, ...$employee]);

    return $u->fresh();
}

beforeEach(function () {
    Notification::fake();
    $this->sales = Department::factory()->create(['name' => 'LNS Sales']);
    $this->ops = Department::factory()->create(['name' => 'LNS Ops']);
    $this->manager = lnsUser('manager', $this->sales, 'Mia Manager');
    $this->hrAll = lnsUser('hr_admin', $this->sales, 'Hana HR');
    $this->hrOps = lnsUser('hr_admin', $this->ops, 'Otto OpsHR', ['scope_departments' => [$this->ops->id]]);
    $this->lead = lnsUser('employee', $this->sales, 'Lee Lead');
    $this->member = lnsUser('employee', $this->sales, 'Sam Member', [], ['manager_id' => $this->manager->id])->employee;

    $team = DepartmentTeam::create(['department_id' => $this->sales->id, 'name' => 'Sales Team', 'team_lead_id' => $this->lead->employee->id, 'status' => 'active']);
    app(TeamService::class)->assign($this->member, $team);

    $day = Carbon::today()->addWeek()->nextWeekday()->toDateString();
    app(LeaveService::class)->submitRequest($this->member->fresh(), LeaveType::create(['name' => 'Casual', 'is_paid' => false, 'allow_unpaid_request' => true]),
        $day, $day, 'Personal work', requestedLeaveStatus: 'unpaid');
    $this->request = LeaveRequest::where('employee_id', $this->member->id)->firstOrFail();
});

test('only the HR who cover the employee are notified', function () {
    Notification::assertSentTo($this->hrAll, LeaveRequestNotification::class);
    Notification::assertNotSentTo($this->hrOps, LeaveRequestNotification::class);
});

test('each recipient gets a link they can open', function () {
    $link = fn (User $user) => (new LeaveRequestNotification($this->request->fresh(['employee.user', 'leaveType'])))->toArray($user)['url'];

    expect($link($this->manager))->toContain(route('time-off.team'))->toContain('selectedRequestId='.$this->request->id)
        ->and($link($this->hrAll))->toContain(route('time-off.team'));

    // The team lead is still told (v4 Part 3.2), but cannot decide: no 403 link.
    Notification::assertSentTo($this->lead, LeaveRequestNotification::class);
    expect($link($this->lead))->toBe(route('dashboard'));
});
