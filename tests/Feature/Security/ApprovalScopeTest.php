<?php

use App\Enums\ExpenseStatus;
use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Livewire\ApprovalCenter;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\DepartmentTeamMember;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Approvals\ApprovalGuard;
use App\Services\ExpenseClaimService;
use App\Services\LeaveService;
use App\Services\OvertimeService;
use App\Services\ReimbursementService;
use App\Services\WfhService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Phase 1 safety — approvals fail closed: a manager reaches only their
 * reporting line, HR reaches the company (or their configured scope), and
 * nobody decides their own request.
 */
function scopeEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'manager_id' => null,
    ], $attributes));
}

function scopeUserWithEmployee(UserRole $role, array $employee = []): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(array_merge(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null], $employee));

    return $user->fresh();
}

function scopeLeave(Employee $employee, string $status = 'pending'): LeaveRequest
{
    $type = LeaveType::create([
        'name' => 'Casual Leave', 'code' => 'CL'.random_int(1000, 9999), 'category' => 'annual',
        'is_paid' => true, 'color' => '#10b981', 'allow_paid_request' => true, 'allow_unpaid_request' => false,
    ]);
    // A weekday, so an approval has a working day to take (the date used to
    // land on a weekend depending on when the suite ran).
    $day = now()->addDays(10);
    while ($day->isWeekend()) {
        $day = $day->addDay();
    }

    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $day, 'end_date' => $day, 'days' => 1, 'reason' => 'Family event',
        'requested_leave_status' => 'paid', 'status' => $status,
    ]);
}

function scopeReviewForm(LeaveRequest $request): array
{
    return [
        'leave_type_id' => $request->leave_type_id,
        'start_date' => $request->start_date->format('Y-m-d'),
        'end_date' => $request->end_date->format('Y-m-d'),
        'reason' => $request->reason,
        'is_half_day' => false,
    ];
}

beforeEach(function () {
    Notification::fake();
});

// ── Reach ────────────────────────────────────────────────────────────────────

test('an unscoped manager is not company-wide and reaches only direct reports', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $report = scopeEmployee(['manager_id' => $manager->id]);
    $stranger = scopeEmployee();

    $guard = app(ApprovalGuard::class);

    expect($guard->isCompanyWide($manager))->toBeFalse()
        ->and($manager->coversEmployee($report))->toBeTrue()
        ->and($manager->coversEmployee($stranger))->toBeFalse()
        ->and($manager->accessibleEmployeeIds())->toBe([$report->id]);
});

test('a team lead reaches their active team and a department head their department', function () {
    $department = Department::factory()->create();
    $lead = scopeUserWithEmployee(UserRole::Manager, ['department_id' => $department->id]);
    $member = scopeEmployee(['department_id' => $department->id]);
    $team = DepartmentTeam::create(['department_id' => $department->id, 'name' => 'Ops', 'team_lead_id' => $lead->employee->id, 'status' => 'active']);
    DepartmentTeamMember::create(['department_team_id' => $team->id, 'employee_id' => $member->id, 'is_active' => true]);

    $otherDepartment = Department::factory()->create();
    $head = User::factory()->create(['role' => UserRole::Manager]);
    $otherDepartment->update(['head_id' => $head->id]);
    $deptMember = scopeEmployee(['department_id' => $otherDepartment->id]);

    expect($lead->coversEmployee($member))->toBeTrue()
        ->and($lead->coversEmployee($deptMember))->toBeFalse()
        ->and($head->coversEmployee($deptMember))->toBeTrue()
        ->and($head->coversEmployee($member))->toBeFalse();
});

test('an unscoped HR admin is company-wide; a department-scoped HR is not', function () {
    $deptA = Department::factory()->create();
    $deptB = Department::factory()->create();
    $inA = scopeEmployee(['department_id' => $deptA->id]);
    $inB = scopeEmployee(['department_id' => $deptB->id]);

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $scopedHr = User::factory()->create(['role' => UserRole::HrAdmin, 'scope_departments' => [$deptA->id]]);

    expect($hr->isCompanyWideApprover())->toBeTrue()
        ->and($hr->accessibleEmployeeIds())->toBeNull()
        ->and($scopedHr->isCompanyWideApprover())->toBeFalse()
        ->and($scopedHr->coversEmployee($inA))->toBeTrue()
        ->and($scopedHr->coversEmployee($inB))->toBeFalse();
});

// ── Leave ────────────────────────────────────────────────────────────────────

test('a manager cannot decide leave for an employee outside their reporting line', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = scopeLeave(scopeEmployee());

    expect(fn () => app(LeaveService::class)->reviewRequest($request, scopeReviewForm($request), 'approved', $manager->id))
        ->toThrow(ApprovalNotPermitted::class, 'outside your approval scope');

    expect($request->fresh()->status)->toBe('pending');
});

test('a manager can still decide leave for their own report', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = scopeLeave(scopeEmployee(['manager_id' => $manager->id]));
    // Unpaid: this is about reach, not balance (a final approval now debits).
    $request->update(['requested_leave_status' => 'unpaid']);

    app(LeaveService::class)->reviewRequest($request, scopeReviewForm($request), 'approved', $manager->id);

    // D2: the reporting approver's decision is final.
    expect($request->fresh()->status)->toBe('approved');
});

test('nobody approves their own leave — not even an HR admin', function () {
    $hr = scopeUserWithEmployee(UserRole::HrAdmin);
    $request = scopeLeave($hr->employee);

    expect(fn () => app(LeaveService::class)->reviewRequest($request, scopeReviewForm($request), 'approved', $hr->id))
        ->toThrow(ApprovalNotPermitted::class, 'your own request');

    expect($request->fresh()->status)->toBe('pending');
});

test('a decided leave request can be corrected by HR but not re-opened by a manager', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = scopeLeave(scopeEmployee(['manager_id' => $manager->id]), 'rejected');

    expect(fn () => app(LeaveService::class)->reviewRequest($request, scopeReviewForm($request), 'approved', $manager->id))
        ->toThrow(DomainException::class, 'already been decided');

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    app(LeaveService::class)->reviewRequest($request->fresh(), scopeReviewForm($request), 'cancelled', $hr->id);

    expect($request->fresh()->status)->toBe('cancelled');
});

test('Team Time Off will not open a request outside the manager\'s team', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = scopeLeave(scopeEmployee());

    Livewire::actingAs($manager)->test(TeamTimeOff::class)
        ->call('selectRequest', $request->id)
        ->assertForbidden();
});

test('All Leave lists only requests inside the manager\'s reach', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $mine = scopeLeave(scopeEmployee(['manager_id' => $manager->id]));
    $theirs = scopeLeave(scopeEmployee());

    $component = Livewire::actingAs($manager)->test(AllTimeOff::class)
        ->set('dateFrom', '')
        ->set('dateTo', '');

    $ids = $component->viewData('requests')->pluck('id')->all();

    expect($ids)->toContain($mine->id)->not->toContain($theirs->id);
});

test('the Approval Center hides out-of-scope and own requests and refuses to act on them', function () {
    $manager = scopeUserWithEmployee(UserRole::Manager);
    $mine = scopeLeave(scopeEmployee(['manager_id' => $manager->id]));
    $stranger = scopeLeave(scopeEmployee());
    $own = scopeLeave($manager->employee);

    $component = Livewire::actingAs($manager)->test(ApprovalCenter::class)->set('filter', 'all');
    $ids = collect($component->viewData('rows'))->where('type', 'leave')->pluck('id')->all();

    expect($ids)->toContain($mine->id)
        ->not->toContain($stranger->id)
        ->not->toContain($own->id);

    $component->call('approve', 'leave', $stranger->id);
    expect($stranger->fresh()->status)->toBe('pending');
});

// ── Overtime, WFH, expenses ──────────────────────────────────────────────────

test('overtime cannot be self-approved or approved out of scope', function () {
    $manager = scopeUserWithEmployee(UserRole::Manager);
    $date = today()->subDay()->toDateString();
    $make = fn (Employee $e) => OtRequest::create([
        'employee_id' => $e->id, 'work_date' => $date,
        'start_time' => "$date 19:00:00", 'end_time' => "$date 21:00:00",
        'requested_hours' => 2, 'reason' => 'Release', 'status' => 'pending',
    ]);

    expect(fn () => app(OvertimeService::class)->approve($make($manager->employee), $manager->id))
        ->toThrow(ApprovalNotPermitted::class, 'your own request')
        ->and(fn () => app(OvertimeService::class)->approve($make(scopeEmployee()), $manager->id))
        ->toThrow(ApprovalNotPermitted::class, 'outside your approval scope');
});

test('work-from-home cannot be approved out of scope', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = WfhRequest::create([
        'employee_id' => scopeEmployee()->id, 'start_date' => now()->addDay(), 'end_date' => now()->addDay(),
        'reason' => 'Plumber visit', 'status' => 'pending',
    ]);

    expect(fn () => app(WfhService::class)->approve($request, $manager->id))
        ->toThrow(ApprovalNotPermitted::class);

    expect($request->fresh()->status)->toBe('pending');
});

test('an HR admin cannot approve their own expense claim', function () {
    $hr = scopeUserWithEmployee(UserRole::HrAdmin);
    $claim = ExpenseClaim::create([
        'employee_id' => $hr->employee->id, 'title' => 'Taxi', 'category' => 'travel',
        'amount' => 450, 'expense_date' => today(), 'status' => ExpenseStatus::Pending,
    ]);

    expect(fn () => app(ExpenseClaimService::class)->approve($claim, $hr->id, app(ReimbursementService::class)))
        ->toThrow(ApprovalNotPermitted::class);

    expect($claim->fresh()->status)->toBe(ExpenseStatus::Pending);
});

// ── Audit of refusals ────────────────────────────────────────────────────────

test('a refused approval attempt is recorded as a security event', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $employee = scopeEmployee();
    $request = scopeLeave($employee);

    try {
        app(LeaveService::class)->reviewRequest($request, scopeReviewForm($request), 'approved', $manager->id);
    } catch (ApprovalNotPermitted) {
        // expected
    }

    $event = AuditLog::where('event', 'APPROVAL_DENIED')->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->category)->toBe('security')
        ->and($event->subject_employee_id)->toBe($employee->id)
        ->and($event->new_values['actor_user_id'])->toBe($manager->id);
});
