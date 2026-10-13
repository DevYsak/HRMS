<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Employees\Directory;
use App\Livewire\Employees\Employee360;
use App\Livewire\Employees\EmployeeIndex;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\WarningLetter;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\TodayStatusService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\OvertimeService;
use App\Services\People\Employee360Access;
use App\Services\People\Employee360Service;
use App\Services\Performance\PerformanceStatusService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/**
 * Employee 360°: one scoped page per employee whose numbers are the numbers
 * of the modules they come from.
 *
 * Nikita Dalal heads UK Operations (Department scope); Mo Manager manages two
 * of its people (Team scope); IT Operations is outside both. Clock: Wednesday
 * 14 October 2026, 11:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 11:00:00'));

    $this->shift = ShiftSetting::create(['name' => 'UK Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 9, 'ot_threshold_hours' => 9]);
    $this->uk = Department::factory()->create(['name' => 'UK Operations']);
    $this->it = Department::factory()->create(['name' => 'IT Operations']);

    $this->nikita = e360User('Nikita Dalal', 'department_head', $this->uk, $this->shift);
    $this->uk->update(['head_id' => $this->nikita->id]);
    $this->manager = e360User('Mo Manager', 'manager', $this->uk, $this->shift, $this->nikita->id);
    $this->hr = e360User('Hana HR', 'hr_admin', $this->uk, $this->shift);

    $this->ari = e360Employee('Ari Alpha', $this->uk, $this->shift, $this->manager->id);
    $this->bea = e360Employee('Bea Beta', $this->uk, $this->shift, $this->manager->id);
    $this->cal = e360Employee('Cal Gamma', $this->uk, $this->shift, $this->nikita->id);
    $this->ivy = e360Employee('Ivy IT', $this->it, $this->shift, null);

    $this->nikita = $this->nikita->fresh();
    $this->manager = $this->manager->fresh();
});

function e360User(string $name, string $roleSlug, Department $department, ShiftSetting $shift, ?int $managerUserId = null): User
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'shift_id' => $shift->id, 'status' => 'active', 'manager_id' => $managerUserId, 'joining_date' => '2023-01-02']);

    return $user;
}

function e360Employee(string $name, Department $department, ShiftSetting $shift, ?int $managerUserId): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => $name, 'role' => UserRole::Employee])->id,
        'department_id' => $department->id, 'shift_id' => $shift->id, 'status' => 'active',
        'manager_id' => $managerUserId, 'joining_date' => '2024-01-08', 'employee_id' => 'EMP-'.strtoupper(substr($name, 0, 3)),
    ]);
}

function e360LeaveType(): LeaveType
{
    return LeaveType::firstOrCreate(['code' => 'E36'], ['name' => 'Casual Sick Leave', 'is_paid' => true, 'color' => '#1DB77A', 'category' => 'annual', 'allow_paid_request' => true, 'allow_unpaid_request' => true]);
}

function e360Leave(Employee $employee, string $status, string $from, ?string $to = null, float $days = 1): LeaveRequest
{
    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => e360LeaveType()->id,
        'start_date' => $from, 'end_date' => $to ?? $from, 'days' => $days, 'reason' => 'Employee 360 fixture',
        'status' => $status, 'requested_leave_status' => 'unpaid',
    ]);
}

/** October so far for Ari: 5 working days attended (one late), one absent day, and in now. */
function e360AriAttendance(Employee $employee): void
{
    foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
        $late = $date === '2026-10-06';
        Attendance::create([
            'employee_id' => $employee->id, 'date' => $date,
            'check_in' => $date.($late ? ' 09:45:00' : ' 08:58:00'), 'check_out' => $date.' 18:05:00',
            'status' => $late ? 'late' : 'on_time', 'is_late' => $late, 'late_minutes' => $late ? 35 : 0, 'work_mode' => 'office',
        ]);
    }
    Attendance::create(['employee_id' => $employee->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 08:59:00', 'status' => 'on_time', 'is_late' => false, 'work_mode' => 'office']);
}

function e360Range(): array
{
    return [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')];
}

// ── Access ────────────────────────────────────────────────────────────────

test('the permission is seeded, scoped and granted to the people roles only', function () {
    foreach (['hr_admin', 'director', 'department_head', 'manager'] as $slug) {
        expect(Role::where('slug', $slug)->first()->hasPermission('view_employee_360'))->toBeTrue("{$slug} should hold it");
    }
    foreach (['employee', 'coordinator', 'finance'] as $slug) {
        expect(Role::where('slug', $slug)->first()->hasPermission('view_employee_360'))->toBeFalse("{$slug} should not hold it");
    }
    expect(Permission::where('key', 'view_employee_360')->value('is_scoped'))->toBeTruthy();
});

test('HR opens any employee in the company', function () {
    $this->actingAs($this->hr)->get(route('employees.360', $this->ivy))
        ->assertOk()
        ->assertSee('Ivy IT')
        ->assertSee('All employees · Company scope');
});

test('a Department Head opens her own department and is blocked from another', function () {
    $this->actingAs($this->nikita)->get(route('employees.360', $this->cal))
        ->assertOk()
        ->assertSee('UK Operations · Department scope');

    $this->actingAs($this->nikita)->get(route('employees.360', $this->ivy))->assertForbidden();
    $this->actingAs($this->nikita)->get(route('employees.360.report', $this->ivy))->assertForbidden();
});

test('a Manager is limited to their team', function () {
    $this->actingAs($this->manager)->get(route('employees.360', $this->ari))->assertOk()->assertSee('Team scope');
    $this->actingAs($this->manager)->get(route('employees.360', $this->cal))->assertForbidden();
    $this->actingAs($this->manager)->get(route('employees.360', $this->ivy))->assertForbidden();
});

test('the direct URL is protected for employees and while switching employees', function () {
    $this->actingAs($this->ari->user)->get(route('employees.360', $this->bea))->assertForbidden();
    $this->actingAs($this->ari->user)->get(route('employees.360', $this->ari))->assertForbidden();

    // Switching from the selector cannot reach outside the scope either.
    Livewire::actingAs($this->nikita)->test(Employee360::class, ['employee' => $this->cal])
        ->call('selectEmployee', $this->ivy->id)
        ->assertForbidden();
});

test('an explicit grant gives an employee their own page only', function () {
    $this->ari->user->permissionOverrides()->create([
        'permission_id' => Permission::where('key', 'view_employee_360')->value('id'),
        'effect' => UserPermissionOverride::GRANT, 'scope' => 'own',
    ]);
    $user = $this->ari->user->fresh();

    $this->actingAs($user)->get(route('employees.360', $this->ari))->assertOk();
    $this->actingAs($user)->get(route('employees.360', $this->bea))->assertForbidden();
});

test('the employee selector and department filter only offer people in scope', function () {
    Livewire::actingAs($this->nikita)->test(Employee360::class, ['employee' => $this->cal])
        ->assertViewHas('employeeOptions', function (array $options) {
            return isset($options[$this->ari->id], $options[$this->bea->id], $options[$this->cal->id])
                && ! isset($options[$this->ivy->id]);
        })
        ->assertViewHas('departmentOptions', fn ($departments) => $departments->pluck('id')->all() === [$this->uk->id])
        // A department outside the scope is dropped, not applied.
        ->set('department', $this->it->id)
        ->assertSet('department', null);

    Livewire::actingAs($this->manager)->test(Employee360::class, ['employee' => $this->ari])
        ->assertViewHas('employeeOptions', fn (array $options) => isset($options[$this->ari->id], $options[$this->bea->id]) && ! isset($options[$this->cal->id]));
});

test('the selector opens the chosen employee in place, keeping the filters', function () {
    Livewire::actingAs($this->nikita)->test(Employee360::class, ['employee' => $this->cal])
        ->set('from', '2026-09-01')->set('to', '2026-09-30')
        ->call('selectEmployee', $this->ari->id)
        ->assertRedirect(route('employees.360', ['employee' => $this->ari->id, 'from' => '2026-09-01', 'to' => '2026-09-30']));
});

test('the date range defaults to the current month and rejects bad input', function () {
    Livewire::actingAs($this->hr)->test(Employee360::class, ['employee' => $this->ari])
        ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-31')
        ->set('from', '2026-02-31')->assertSet('from', '2026-10-01')
        ->set('from', '2026-10-20')->set('to', '2026-10-05')
        ->assertSet('from', '2026-10-05')->assertSet('to', '2026-10-20');

    [$from, $to] = Employee360Service::range('2020-01-01', '2026-10-31');
    expect($from->diffInDays($to))->toBeLessThanOrEqual(Employee360Service::MAX_RANGE_DAYS);
});

// ── Same numbers as the modules ───────────────────────────────────────────

test('attendance numbers match My Attendance for the same month', function () {
    e360AriAttendance($this->ari);

    $mine = Livewire::actingAs($this->ari->user)->test(AttendanceTracker::class)->get('stats');
    [$from, $to] = e360Range();
    $page = app(Employee360Service::class)->build($this->hr, $this->ari, $from, $to)['attendance'];

    expect($page['summary']['present'])->toBe($mine['present'])
        ->and($page['summary']['late'])->toBe($mine['late'])
        ->and($page['summary']['absent'])->toBe($mine['absent'])
        ->and($page['summary']['scheduled'])->toBe($mine['scheduled'])
        ->and($mine['present'])->toBe(6)
        ->and($mine['late'])->toBe(1);

    // Per-day statuses add up to the same counts.
    $days = collect($page['days'])->countBy('status');
    expect(($days['present'] ?? 0) + ($days['late'] ?? 0))->toBe($page['summary']['present'])
        ->and($days['absent'] ?? 0)->toBe($page['summary']['absent']);

    Livewire::actingAs($this->hr)->test(Employee360::class, ['employee' => $this->ari])
        ->assertSeeHtml('data-kpi="present"')
        ->assertSee('Present days');
});

test('the live strip reads the same status as Command Center', function () {
    e360AriAttendance($this->ari);
    $row = app(TodayStatusService::class)->summary([$this->ari->id])['rows'][$this->ari->id];

    expect($row['state'])->toBe('working');

    Livewire::actingAs($this->hr)->test(Employee360::class, ['employee' => $this->ari])
        ->assertSeeHtml('data-live-status="working"')
        ->assertSee('At work');
});

test('the attendance status filter narrows the day log', function () {
    e360AriAttendance($this->ari);

    Livewire::actingAs($this->hr)->test(Employee360::class, ['employee' => $this->ari])
        ->set('attendanceStatus', 'late')
        ->assertViewHas('attendance', fn ($a) => $a['day_log_total'] === 1 && $a['day_log'][0]['date'] === '2026-10-06')
        ->set('attendanceStatus', 'nonsense')
        ->assertSet('attendanceStatus', '');
});

test('leave counts match the leave module', function () {
    e360Leave($this->ari, 'approved', '2026-10-08', '2026-10-09', 2);
    e360Leave($this->ari, 'pending', '2026-10-20');
    e360Leave($this->ari, 'rejected', '2026-10-12');

    $overview = app(EmployeeLeaveOverviewService::class)->for($this->ari);
    [$from, $to] = e360Range();
    $leave = app(Employee360Service::class)->build($this->hr, $this->ari, $from, $to)['leave'];

    expect($leave['pending_requests'])->toBe($overview['pending_requests'])->toBe(1)
        ->and($leave['taken_this_year'])->toBe((float) $overview['taken_this_year'])
        ->and($leave['available'])->toBe((float) $overview['available_to_request'])
        ->and($leave['range'])->toMatchArray(['approved_days' => 2.0, 'approved' => 1, 'rejected' => 1]);

    Livewire::actingAs($this->hr)->test(Employee360::class, ['employee' => $this->ari])
        ->call('setLeaveTab', 'pending')
        ->assertViewHas('leave', fn ($l) => count($l['requests']) === 1 && $l['requests'][0]['status'] === 'pending')
        ->call('setLeaveTab', 'rejected')
        ->assertViewHas('leave', fn ($l) => count($l['requests']) === 1 && $l['requests'][0]['status'] === 'rejected');
});

test('leave can be approved or rejected inline only by someone who may decide it', function () {
    $pending = e360Leave($this->ari, 'pending', '2026-10-22');
    $other = e360Leave($this->bea, 'pending', '2026-10-23');

    // Mo manages Ari: he may decide.
    Livewire::actingAs($this->manager)->test(Employee360::class, ['employee' => $this->ari])
        ->call('setLeaveTab', 'pending')
        ->assertSeeHtml('data-approve-leave="'.$pending->id.'"')
        ->call('approveLeave', $pending->id)
        ->assertHasNoErrors();
    expect($pending->fresh()->status)->toBe('approved');

    // A request of another employee cannot be decided through this page.
    expect(fn () => Livewire::actingAs($this->manager)->test(Employee360::class, ['employee' => $this->ari])
        ->call('approveLeave', $other->id))
        ->toThrow(ModelNotFoundException::class);
    expect($other->fresh()->status)->toBe('pending');

    // Rejection needs a reason.
    $again = e360Leave($this->bea, 'pending', '2026-10-26');
    Livewire::actingAs($this->manager)->test(Employee360::class, ['employee' => $this->bea])
        ->call('startReject', $again->id)
        ->set('rejectReason', 'no')
        ->call('confirmReject')
        ->assertHasErrors('rejectReason')
        ->set('rejectReason', 'Team coverage is too thin that day.')
        ->call('confirmReject')
        ->assertHasNoErrors();
    expect($again->fresh()->status)->toBe('rejected');

    // HR can see the request but Ari himself never decides his own.
    $own = e360Leave($this->ari, 'pending', '2026-10-28');
    expect(app(ApprovalQueueService::class)->canDecide($this->ari->user, $own))->toBeFalse();
});

test('overtime counts match the overtime module and never use requested hours as worked', function () {
    $approved = OtRequest::factory()->create(['employee_id' => $this->ari->id, 'status' => 'approved', 'work_date' => '2026-10-07', 'requested_hours' => 3]);
    OvertimeRecord::create(['employee_id' => $this->ari->id, 'ot_request_id' => $approved->id, 'work_date' => '2026-10-07', 'total_hours_worked' => 10.5, 'standard_hours' => 9, 'ot_hours' => 1.5, 'rate_per_hour' => 100, 'ot_amount' => 150, 'is_paid' => false]);
    OtRequest::factory()->create(['employee_id' => $this->ari->id, 'status' => 'pending', 'work_date' => '2026-10-13', 'requested_hours' => 2]);

    $module = app(OvertimeService::class)->summaryFor($this->ari, Carbon::parse('2026-10-14'));
    [$from, $to] = e360Range();
    $page = app(Employee360Service::class)->build($this->hr, $this->ari, $from, $to)['overtime'];

    expect($page['worked_hours'])->toBe($module['approved_hours_month'])->toBe(1.5)
        ->and($page['pending'])->toBe($module['pending'])
        ->and($page['approved'])->toBe($module['approved'])
        ->and($page['requested_hours_approved'])->toBe(3.0);
});

test('payable overtime needs payroll access', function () {
    [$from, $to] = e360Range();

    expect(app(Employee360Service::class)->build($this->manager, $this->ari, $from, $to)['overtime']['payable'] ?? null)->toBeNull()
        ->and(app(Employee360Service::class)->build($this->hr, $this->ari, $from, $to)['overtime'])->not->toBeNull();
});

test('performance status matches the performance module', function () {
    $template = PerformanceTemplate::create(['name' => 'Q2 KPIs', 'code' => 'Q2K', 'created_by' => $this->nikita->id]);
    $cycle = PerformanceCycle::create(['template_id' => $template->id, 'name' => 'Q2 Oct–Dec 2026', 'cycle_type' => 'quarterly', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_by' => $this->nikita->id]);
    PerformanceReview::create(['performance_cycle_id' => $cycle->id, 'template_id' => $template->id, 'employee_id' => $this->ari->id, 'type' => 'self', 'status' => 'submitted', 'self_submitted_at' => now()]);

    $module = app(PerformanceStatusService::class)->summary([$this->ari->id, $this->bea->id]);
    [$from, $to] = e360Range();
    $ari = app(Employee360Service::class)->build($this->nikita, $this->ari, $from, $to)['performance'];
    $bea = app(Employee360Service::class)->build($this->nikita, $this->bea, $from, $to)['performance'];

    expect($ari['state'])->toBe($module['by_employee'][$this->ari->id])->toBe(PerformanceStatusService::MANAGER_PENDING)
        ->and($ari['label'])->toBe('Manager Review Pending')
        ->and($ari['self'])->toBe('Submitted')
        ->and($bea['state'])->toBe($module['by_employee'][$this->bea->id])->toBe(PerformanceStatusService::NOT_STARTED)
        ->and($ari['score'])->toBeNull();
});

// ── Permissions inside the page ───────────────────────────────────────────

test('the warnings section follows the conduct permission', function () {
    WarningLetter::create(['employee_id' => $this->ari->id, 'issued_by' => $this->hr->id, 'warning_type' => 'verbal', 'reason' => 'Repeated late arrivals', 'issue_date' => '2026-10-09', 'status' => 'issued']);

    expect($this->hr->hasPermission('manage_warning_letters'))->toBeTrue()
        ->and($this->manager->hasPermission('manage_warning_letters'))->toBeFalse();

    Livewire::actingAs($this->hr)->test(Employee360::class, ['employee' => $this->ari])
        ->assertSeeHtml('data-360-section="warnings"')
        ->assertSee('Repeated late arrivals');

    Livewire::actingAs($this->manager)->test(Employee360::class, ['employee' => $this->ari])
        ->assertDontSeeHtml('data-360-section="warnings"')
        ->assertDontSee('Repeated late arrivals');
});

test('activity shows only entries from sections the viewer may see', function () {
    $leave = e360Leave($this->ari, 'approved', '2026-10-08');
    AuditLog::record($leave, 'approved', null, ['status' => 'approved'], subjectEmployeeId: $this->ari->id, context: ['event' => 'LEAVE_APPROVED']);
    $warning = WarningLetter::create(['employee_id' => $this->ari->id, 'issued_by' => $this->hr->id, 'warning_type' => 'verbal', 'reason' => 'Late', 'issue_date' => '2026-10-09', 'status' => 'issued']);
    AuditLog::record($warning, 'created', null, ['status' => 'issued'], subjectEmployeeId: $this->ari->id, context: ['event' => 'WARNING_ISSUED']);

    [$from, $to] = e360Range();
    $forManager = collect(app(Employee360Service::class)->build($this->manager, $this->ari, $from, $to)['activity']['entries'])->pluck('action');
    $forHr = collect(app(Employee360Service::class)->build($this->hr, $this->ari, $from, $to)['activity']['entries'])->pluck('action');

    expect($forManager)->toContain('Leave approved')->not->toContain('Warning issued')
        ->and($forHr)->toContain('Leave approved')->toContain('Warning issued');
});

// ── Export ────────────────────────────────────────────────────────────────

test('the employee report downloads within scope, is audited, and leaves out hidden sections', function () {
    WarningLetter::create(['employee_id' => $this->ari->id, 'issued_by' => $this->hr->id, 'warning_type' => 'verbal', 'reason' => 'Confidential conduct note', 'issue_date' => '2026-10-09', 'status' => 'issued']);

    $captured = [];
    View::composer('pdf.employee-360', function ($view) use (&$captured) {
        $captured[] = $view->getData();
    });

    $this->actingAs($this->manager)->get(route('employees.360.report', ['employee' => $this->ari, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($captured[0]['warnings'])->toBeNull()
        ->and($captured[0]['overtime']['payable'] ?? null)->toBeNull()
        ->and($captured[0]['attendance'])->not->toBeNull();

    $this->actingAs($this->hr)->get(route('employees.360.report', $this->ari))->assertOk();
    expect($captured[1]['warnings']['active'])->toBe(1);

    expect(AuditLog::where('event', 'EMPLOYEE_360_EXPORTED')->where('subject_employee_id', $this->ari->id)->count())->toBe(2);

    $this->actingAs($this->manager)->get(route('employees.360.report', $this->cal))->assertForbidden();
    $this->actingAs($this->ari->user)->get(route('employees.360.report', $this->ari))->assertForbidden();
});

// ── Entry points ──────────────────────────────────────────────────────────

test('entry points appear only where the page would open', function () {
    $access = app(Employee360Access::class);
    $can = $access->checker($this->nikita);
    expect($can($this->cal->id))->toBeTrue()->and($can($this->ivy->id))->toBeFalse();

    Livewire::actingAs($this->hr)->test(EmployeeIndex::class)
        ->assertSeeHtml('data-employee-360-link="'.$this->ivy->id.'"');

    Livewire::actingAs($this->nikita)->test(Directory::class)
        ->assertSeeHtml('data-employee-360-link="'.$this->cal->id.'"')
        ->assertDontSeeHtml('data-employee-360-link="'.$this->ivy->id.'"');

    Livewire::actingAs($this->ari->user)->test(Directory::class)
        ->assertDontSeeHtml('data-employee-360-link=');

    $this->actingAs($this->hr)->get(route('employees.edit', $this->ari))
        ->assertOk()
        ->assertSee(route('employees.360', $this->ari), false);
});

test('dates and times on the page follow the viewer\'s preferences', function () {
    e360AriAttendance($this->ari);
    $this->hr->forceFill(['time_format' => 'H:i'])->save();

    Livewire::actingAs($this->hr->fresh())->test(Employee360::class, ['employee' => $this->ari])
        ->assertSee('08:58')
        ->assertDontSee('8:58 AM');
});
