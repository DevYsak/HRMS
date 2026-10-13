<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\CommandCenter;
use App\Livewire\DepartmentDashboard;
use App\Livewire\Departments\DepartmentOverview;
use App\Livewire\Employees\OrgChart;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Ai\AiDataTools;
use App\Services\Ai\AiIntentRouter;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\TodayStatusService;
use App\Services\Dashboards\PeopleOperationsService;
use App\Services\Performance\PerformanceStatusService;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * One connected system: for the same user, date and scope, every module
 * resolves the same people and the same counts.
 *
 * Nikita Dalal — Department Head of UK Operations (11 working employees),
 * default Department scope, no per-user narrowing. IT Operations (3) is
 * outside her reach. Clock: Wednesday 14 October 2026, 11:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 11:00:00'));

    $this->shift = ShiftSetting::create(['name' => 'UK Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 9, 'ot_threshold_hours' => 9]);
    $this->uk = Department::factory()->create(['name' => 'UK Operations']);
    $this->it = Department::factory()->create(['name' => 'IT Operations']);

    $role = Role::where('slug', 'department_head')->firstOrFail();
    $this->nikita = User::factory()->create(['name' => 'Nikita Dalal', 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $this->nikita->id, 'department_id' => $this->uk->id, 'shift_id' => $this->shift->id, 'status' => 'active', 'manager_id' => null, 'joining_date' => '2023-01-02']);
    $this->uk->update(['head_id' => $this->nikita->id]);

    $this->ukPeople = collect(range(1, 11))->map(fn (int $i) => hcEmployee('UKPERSON'.$i, $this->uk, $this->shift, $this->nikita->id));
    $this->itPeople = collect(range(1, 3))->map(fn (int $i) => hcEmployee('ITPERSON'.$i, $this->it, $this->shift, null));

    // Dashboard Access: Department View is granted explicitly (not a Department Head default).
    UserPermissionOverride::create(['user_id' => $this->nikita->id, 'permission_id' => Permission::where('key', 'view_department_dashboard')->value('id'), 'effect' => 'grant']);
    $this->nikita = $this->nikita->fresh();
});

function hcEmployee(string $name, Department $department, ShiftSetting $shift, ?int $managerUserId): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => $name, 'role' => UserRole::Employee])->id,
        'department_id' => $department->id, 'shift_id' => $shift->id, 'status' => 'active',
        'manager_id' => $managerUserId, 'joining_date' => '2024-01-08',
    ]);
}

function hcLeaveType(): LeaveType
{
    return LeaveType::firstOrCreate(['code' => 'HCL'], ['name' => 'HC Leave', 'is_paid' => true, 'color' => '#1DB77A', 'category' => 'annual', 'allow_paid_request' => true, 'allow_unpaid_request' => true]);
}

function hcLeave(Employee $employee, string $status, string $from, ?string $to = null): LeaveRequest
{
    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => hcLeaveType()->id,
        'start_date' => $from, 'end_date' => $to ?? $from, 'days' => 1, 'reason' => 'Connectivity fixture',
        'status' => $status, 'requested_leave_status' => 'unpaid',
    ]);
}

/** 6 in (one late), 2 on approved leave, 3 not in yet — at 11:00 on a working Wednesday. */
function hcToday($test): void
{
    foreach ($test->ukPeople->take(6) as $i => $employee) {
        Attendance::create([
            'employee_id' => $employee->id, 'date' => '2026-10-14',
            'check_in' => $i === 0 ? '2026-10-14 09:40:00' : '2026-10-14 08:55:00',
            'status' => $i === 0 ? 'late' : 'on_time', 'is_late' => $i === 0, 'late_minutes' => $i === 0 ? 30 : 0, 'work_mode' => 'office',
        ]);
    }
    hcLeave($test->ukPeople[6], 'approved', '2026-10-14');
    hcLeave($test->ukPeople[7], 'approved', '2026-10-13', '2026-10-15');
    Attendance::create(['employee_id' => $test->itPeople[0]->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 08:50:00', 'status' => 'on_time', 'work_mode' => 'office']);
}

// ── 1. One people scope ─────────────────────────────────────────────────────

test('Nikita reaches exactly her 11 UK Operations people in every permission she holds', function () {
    $people = app(PeopleScope::class);
    $uk = $this->ukPeople->pluck('id')->sort()->values()->all();

    foreach (['approve_leave', 'view_attendance', 'approve_overtime', 'approve_wfh', 'review_performance', 'view_employee'] as $permission) {
        expect(collect($people->workingEmployeeIds($this->nikita, $permission))->sort()->values()->all())->toBe($uk, $permission);
    }

    expect($people->departmentOptions($this->nikita, 'view_attendance')->pluck('name')->all())->toBe(['UK Operations'])
        ->and($people->summary($this->nikita, 'view_attendance'))->toMatchArray(['kind' => 'department', 'label' => 'UK Operations', 'count' => 11]);
});

test('a department filter never offers or honours a department outside the reach', function () {
    $people = app(PeopleScope::class);

    expect($people->sanitiseDepartment($this->nikita, 'view_attendance', $this->it->id))->toBeNull()
        ->and($people->sanitiseDepartment($this->nikita, 'view_attendance', $this->uk->id))->toBe($this->uk->id);

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    expect($people->departmentOptions($hr, 'manage_employees')->pluck('name')->all())->toContain('UK Operations', 'IT Operations');
});

// ── 2. Same attendance numbers everywhere ───────────────────────────────────

test('Team View, Command Center, the people overview, the AI and the report agree on today', function () {
    hcToday($this);
    $ids = app(PeopleScope::class)->workingEmployeeIds($this->nikita, 'view_attendance');
    $summary = app(TodayStatusService::class)->summary($ids);

    expect($summary)->toMatchArray(['present' => 6, 'late' => 1, 'on_leave' => 2, 'no_checkin' => 3, 'absent' => 0, 'expected' => 9]);

    Livewire::actingAs($this->nikita)->test(DepartmentDashboard::class)
        ->assertViewHas('presentCount', 6)
        ->assertViewHas('lateCount', 1)
        ->assertViewHas('absentCount', 3);

    Livewire::actingAs($this->nikita)->test(CommandCenter::class)
        ->assertViewHas('health', fn ($h) => $h['present_today'] === 6 && $h['late_today'] === 1 && $h['absent_today'] === 0
            && $h['no_checkin'] === 3 && $h['on_leave'] === 2 && $h['active'] === 11);

    expect(app(PeopleOperationsService::class)->overview($this->nikita, 'view_attendance')['attendance']['present'])->toBe(6);

    $this->actingAs($this->nikita);
    $answer = app(AiIntentRouter::class)->answer($this->nikita, 'How many people are present today?');
    expect($answer['content'])->toContain('6 present')->toContain('UK Operations');

    $csv = $this->get(route('reports.attendance-report-csv', ['type' => 'daily', 'from' => '2026-10-14', 'to' => '2026-10-14']))->streamedContent();
    expect(substr_count($csv, 'UKPERSON'))->toBe(6)->and($csv)->not->toContain('ITPERSON');
});

test('on a weekly off nobody is absent anywhere (screenshot regression)', function () {
    $this->travelTo(Carbon::parse('2026-10-18 11:00:00')); // Sunday

    $summary = app(TodayStatusService::class)->summary(app(PeopleScope::class)->workingEmployeeIds($this->nikita, 'view_attendance'));
    expect($summary)->toMatchArray(['present' => 0, 'late' => 0, 'absent' => 0, 'no_checkin' => 0, 'weekly_off' => 11, 'day_off' => true]);

    Livewire::actingAs($this->nikita)->test(DepartmentDashboard::class)
        ->assertViewHas('absentCount', 0)
        ->assertViewHas('lateCount', 0)
        ->assertSee('Weekly Off');

    Livewire::actingAs($this->nikita)->test(CommandCenter::class)
        ->assertViewHas('health', fn ($h) => $h['absent_today'] === 0 && $h['weekly_off'] === 11 && $h['day_off'] === true)
        ->assertSee('Weekly Off');
});

// ── 3. Same approval queue everywhere; buttons only where she can act ──────

test('leave and OT pending counts match across Team Leave, Team View, Command Center, the badge and the AI', function () {
    hcLeave($this->ukPeople[0], 'pending', '2026-10-20');
    hcLeave($this->ukPeople[1], 'pending_hr', '2026-10-21');
    hcLeave($this->itPeople[0], 'pending', '2026-10-20');
    hcLeave($this->nikita->employee, 'pending', '2026-10-22'); // her own: never in her queue
    OtRequest::factory()->create(['employee_id' => $this->ukPeople[2]->id, 'status' => 'pending']);
    OtRequest::factory()->create(['employee_id' => $this->itPeople[1]->id, 'status' => 'pending']);

    $counts = app(ApprovalQueueService::class)->counts($this->nikita);
    expect($counts['leave'])->toBe(2)->and($counts['overtime'])->toBe(1);

    Livewire::actingAs($this->nikita)->test(TeamTimeOff::class)->assertViewHas('pendingRequests', fn ($rows) => $rows->count() === 2);
    Livewire::actingAs($this->nikita)->test(DepartmentDashboard::class)->assertViewHas('pendingLeaveCount', 2)->assertViewHas('pendingOtCount', 1);
    Livewire::actingAs($this->nikita)->test(CommandCenter::class)->assertViewHas('counts', fn ($c) => $c['leave'] === 2 && $c['overtime'] === 1);
    expect(app(AiDataTools::class)->approvalQueue($this->nikita))->toMatchArray(['leave' => 2, 'overtime' => 1]);
});

test('Team Leave offers Approve only on requests she can decide, and the server agrees', function () {
    $mine = hcLeave($this->ukPeople[0], 'pending', '2026-10-20');
    $other = hcLeave($this->itPeople[0], 'pending', '2026-10-20');

    $page = Livewire::actingAs($this->nikita)->test(TeamTimeOff::class)
        ->assertViewHas('decidable', fn ($d) => $d[$mine->id] === true)
        ->call('selectRequest', $mine->id)
        ->assertViewHas('canDecideSelected', true)
        ->assertSee('Approve')
        ->assertSeeHtml('data-approval-path')
        ->assertSee('Nikita Dalal — Department Head');

    $page->call('approve')->assertHasNoErrors();
    expect($mine->fresh()->status)->toBe('approved');

    // Out of her reach: refused by the server (a 403), never offered by the page.
    Livewire::actingAs($this->nikita)->test(TeamTimeOff::class)
        ->assertViewHas('decidable', fn ($d) => ! isset($d[$other->id]))
        ->call('selectRequest', $other->id)
        ->assertForbidden();
});

test('a department-scoped approver not recorded as head still decides exactly what she is shown', function () {
    $this->uk->update(['head_id' => null]);
    $request = hcLeave($this->ukPeople[3], 'pending', '2026-10-20');

    Livewire::actingAs($this->nikita->fresh())->test(TeamTimeOff::class)
        ->assertViewHas('decidable', fn ($d) => ($d[$request->id] ?? false) === true)
        ->call('selectRequest', $request->id)
        ->call('approve')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('approved');
});

// ── 4. Pages titled and exported by the real scope ─────────────────────────

test('All Leave is titled by her department, not company-wide, and has no export without the permission', function () {
    Livewire::actingAs($this->nikita)->test(AllTimeOff::class)
        ->assertSee('Employee Leave — UK Operations')
        ->assertDontSee('Employee Leave Master')
        ->assertViewHas('canExport', false)
        ->call('export')
        ->assertForbidden();
});

test('the Department Overview opens only for a department in reach and shows the same numbers', function () {
    hcToday($this);
    $template = PerformanceTemplate::create(['name' => 'Q2 KPIs', 'code' => 'Q2K', 'created_by' => $this->nikita->id]);
    $cycle = PerformanceCycle::create(['template_id' => $template->id, 'name' => 'Q2 Oct–Dec 2026', 'cycle_type' => 'quarterly', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_by' => $this->nikita->id]);
    foreach ($this->ukPeople->take(4) as $employee) {
        PerformanceReview::create(['performance_cycle_id' => $cycle->id, 'template_id' => $template->id, 'employee_id' => $employee->id, 'type' => 'self', 'status' => 'submitted', 'self_submitted_at' => now()]);
    }

    $reviews = app(PerformanceStatusService::class)->summary($this->ukPeople->pluck('id'));
    expect($reviews)->toMatchArray(['manager_review_pending' => 4, 'not_started' => 7, 'pending' => 4]);

    Livewire::actingAs($this->nikita)->test(DepartmentOverview::class, ['department' => $this->uk])
        ->assertSee('UK Operations')
        ->assertSee('Department Head: Nikita Dalal')
        ->assertViewHas('today', fn ($t) => $t['present'] === 6)
        ->assertViewHas('reviews', fn ($r) => $r['pending'] === 4);

    Livewire::actingAs($this->nikita)->test(DepartmentDashboard::class)->assertViewHas('reviewsPending', 4);

    $this->actingAs($this->nikita);
    expect(app(AiIntentRouter::class)->answer($this->nikita, 'How many reviews are pending?')['content'])->toContain('4');

    $this->actingAs($this->nikita)->get(route('departments.show', $this->it))->assertForbidden();
    $this->actingAs($this->nikita)->get(route('departments.show', $this->uk))->assertOk();
});

test('the org chart shows her department and only opens profiles she may see', function () {
    $page = Livewire::actingAs($this->nikita)->test(OrgChart::class)
        ->assertSee('UKPERSON1')
        ->assertDontSee('ITPERSON1')
        ->call('openProfile', $this->ukPeople[0]->id)
        ->assertSeeHtml('data-org-profile')
        ->assertSee('Attendance today');

    Livewire::actingAs($this->nikita)->test(OrgChart::class)
        ->call('openProfile', $this->itPeople[0]->id)
        ->assertForbidden();
});

// ── 5. HR: one people-operations view, by department ───────────────────────

test('HR sees Department Health for every department and data-quality problems without changing data', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->itPeople[0]->update(['manager_id' => null, 'job_title_id' => null]);

    $rows = app(PeopleOperationsService::class)->departmentHealth($hr, 'manage_employees');
    expect($rows->pluck('department')->all())->toContain('UK Operations', 'IT Operations')
        ->and($rows->firstWhere('department', 'UK Operations'))->toMatchArray(['head' => 'Nikita Dalal', 'employees' => 12]);

    $problems = app(PeopleOperationsService::class)->connectionProblems();
    expect(collect($problems)->where('subject', 'IT Operations')->pluck('problem')->all())->toContain('No department head set');
});

// ── 6. Impersonation shows whose view it is and uses their scope ───────────

test('viewing as Nikita says so and resolves her scope, not the admin\'s', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($this->nikita)
        ->withSession([AuditLog::IMPERSONATOR_SESSION_KEY => $admin->id])
        ->get(route('dashboard.department'))
        ->assertOk()
        ->assertSee('Viewing as Nikita Dalal')
        ->assertSee('Scope: UK Operations')
        ->assertDontSee('ITPERSON1');
});
