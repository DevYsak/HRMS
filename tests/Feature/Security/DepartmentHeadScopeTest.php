<?php

use App\Enums\DataScope;
use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\DepartmentDashboard;
use App\Livewire\Overtime\ManageOtRequests;
use App\Livewire\Settings\RoleManager;
use App\Livewire\Settings\UserPermissionOverrides;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Security\ScopeResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Nikita — Department Head for the UK Sales shift/team (Pulse v3.1 §4.1).
 *
 * Her reach comes from the department (and, for the UK-shift narrowing, the
 * shift) — resolved live from the employees table, never from a hand-kept
 * list — so a new UK Sales hire is covered the moment they exist. Everything
 * else is closed by default: other departments, the other Sales shift,
 * payroll, settings, roles and HR-only pages.
 *
 * Clock: Wednesday 14 October 2026, 14:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 14:00:00'));

    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);
    $this->ukShift = ShiftSetting::create(['name' => 'UK Sales', 'start_time' => '13:00:00', 'end_time' => '22:00:00', 'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9]);
    $this->itShift = ShiftSetting::create(['name' => 'IT Day', 'start_time' => '10:30:00', 'end_time' => '19:30:00', 'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9]);

    $this->ukStaff = dhsStaff('UKSALESPERSON', $this->sales, $this->ukShift);
    $this->itStaff = dhsStaff('ITSALESPERSON', $this->sales, $this->itShift);
    $this->mktStaff = dhsStaff('MARKETINGPERSON', $this->marketing, $this->itShift);
});

function dhsStaff(string $name, Department $department, ShiftSetting $shift): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => $name, 'role' => UserRole::Employee])->id,
        'department_id' => $department->id, 'shift_id' => $shift->id, 'status' => 'active',
        'manager_id' => null, 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

/** Nikita: the Department Head role, a Sales employee record on the UK shift, scoped to UK Sales. */
function dhsNikita(Department $sales, ShiftSetting $uk, bool $narrowToShift = true): User
{
    $role = Role::where('slug', 'department_head')->firstOrFail();
    $user = User::factory()->create(['name' => 'Nikita', 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $sales->id, 'shift_id' => $uk->id, 'status' => 'active', 'manager_id' => null]);

    $user->update($narrowToShift
        ? ['scope_departments' => [$sales->id], 'scope_shifts' => [$uk->id]]
        : ['scope_departments' => [$sales->id]]);

    return $user->fresh();
}

function dhsLeave(Employee $employee): LeaveRequest
{
    $type = LeaveType::query()->first() ?? LeaveType::create(['name' => 'Casual / Sick', 'code' => 'CSL', 'is_paid' => true]);

    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20',
        'days' => 1, 'reason' => 'Family event', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ]);
}

function dhsOt(Employee $employee): OtRequest
{
    return OtRequest::create([
        'employee_id' => $employee->id, 'work_date' => '2026-10-13', 'start_time' => '18:00', 'end_time' => '20:00',
        'requested_hours' => 2, 'reason' => 'Release', 'status' => 'pending', 'source' => 'manual',
    ]);
}

// ── Assignment and automatic scope ─────────────────────────────────────────

test('Nikita can be assigned the Department Head role, a department and a shift scope', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);

    expect($nikita->effectiveRole()->slug)->toBe('department_head')
        ->and($nikita->isDepartmentScoped())->toBeTrue()
        ->and($nikita->scope_departments)->toBe([$this->sales->id])
        ->and($nikita->scope_shifts)->toBe([$this->ukShift->id])
        ->and(app(ScopeResolver::class)->scopeFor($nikita, 'view_attendance'))->toBe(DataScope::SelectedDepartments);
});

test('scope follows the employees table — a new UK Sales hire is covered without editing any list', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);
    $resolver = app(ScopeResolver::class);

    $before = $resolver->employeeIds($nikita, 'view_attendance');
    $newHire = dhsStaff('NEWUKHIRE', $this->sales, $this->ukShift);
    $moved = dhsStaff('MOVEDOUT', $this->sales, $this->ukShift);
    $moved->update(['department_id' => $this->marketing->id]);

    $after = $resolver->employeeIds($nikita, 'view_attendance');

    expect($before)->toContain($this->ukStaff->id)
        ->and($before)->not->toContain($newHire->id)
        ->and($after)->toContain($newHire->id)
        ->and($after)->not->toContain($moved->id);
});

test('with only the department set she reaches all of Sales; the shift narrows it to the UK team', function () {
    $wholeSales = dhsNikita($this->sales, $this->ukShift, narrowToShift: false);
    $resolver = app(ScopeResolver::class);

    expect($resolver->covers($wholeSales, 'view_attendance', $this->ukStaff))->toBeTrue()
        ->and($resolver->covers($wholeSales, 'view_attendance', $this->itStaff))->toBeTrue()
        ->and($resolver->covers($wholeSales, 'view_attendance', $this->mktStaff))->toBeFalse();

    $wholeSales->update(['scope_shifts' => [$this->ukShift->id]]);

    expect($resolver->covers($wholeSales->fresh(), 'view_attendance', $this->ukStaff))->toBeTrue()
        ->and($resolver->covers($wholeSales->fresh(), 'view_attendance', $this->itStaff))->toBeFalse();
});

test('every permission she holds reaches the same people (department view / approval / review)', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);
    $guard = app(ApprovalGuard::class);

    foreach (['view_employee', 'view_attendance', 'approve_leave', 'approve_overtime', 'review_performance', 'view_reports'] as $permission) {
        expect(app(ScopeResolver::class)->covers($nikita, $permission, $this->ukStaff))->toBeTrue("{$permission} should reach UK Sales")
            ->and(app(ScopeResolver::class)->covers($nikita, $permission, $this->mktStaff))->toBeFalse("{$permission} must not reach Marketing")
            ->and(app(ScopeResolver::class)->covers($nikita, $permission, $this->itStaff))->toBeFalse("{$permission} must not reach the IT-shift Sales team");
    }

    expect($guard->covers($nikita, $this->ukStaff, 'approve_leave'))->toBeTrue()
        ->and($guard->covers($nikita, $this->mktStaff, 'approve_leave'))->toBeFalse();
});

test('an invalid scope value on her grant fails closed', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift, narrowToShift: false);
    $nikita->update(['scope_departments' => null, 'scope_shifts' => null]);

    expect(DataScope::tryFrom('galaxy'))->toBeNull();
    // A stored override naming an unknown scope resolves to no access.
    DB::table('user_permission_overrides')->insert([
        'user_id' => $nikita->id, 'permission_id' => Permission::where('key', 'view_attendance')->value('id'),
        'effect' => 'grant', 'scope' => 'galaxy', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(ScopeResolver::class)->scopeFor($nikita->fresh(), 'view_attendance'))->toBe(DataScope::None);
});

// ── Pages: list, direct URL, scoping ───────────────────────────────────────

test('the employee list shows UK Sales only', function () {
    $this->actingAs(dhsNikita($this->sales, $this->ukShift))
        ->get(route('employees.index'))->assertOk()
        ->assertSee('UKSALESPERSON')
        ->assertDontSee('ITSALESPERSON')
        ->assertDontSee('MARKETINGPERSON');
});

test('direct URLs outside her authority are refused', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);
    $this->actingAs($nikita);

    foreach ([
        route('employees.create'),
        route('employees.edit', $this->ukStaff),
        route('employees.offboarding', $this->ukStaff),
        route('employees.edit', $this->mktStaff),
        route('employees.profile', $this->mktStaff),
        route('employees.finance-profile', $this->ukStaff),
        route('payroll.overview'), route('payroll.process'), route('payroll.finance-approve'), route('payroll.structures'),
        route('dashboard.finance'), route('dashboard.hr-admin'), route('dashboard.executive'),
        route('settings.roles'), route('settings.control-panel'), route('settings.departments'), route('settings.modules'),
        route('overtime.windows'), route('time-off.leave-policies'),
        route('reports.payroll-register'), route('reports.salary-register'), route('reports.monthly-ot'), route('reports.attendance-summary'),
    ] as $url) {
        $this->get($url)->assertForbidden();
    }
});

test('other people\'s payslips are not reachable', function () {
    $this->actingAs(dhsNikita($this->sales, $this->ukShift));

    $this->get(route('payroll.payslips'))->assertOk();   // her own page only
    $this->get('/payroll/payslips/999999/download')->assertNotFound();
});

test('attendance is scoped to UK Sales, on the board and in the report export', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);
    foreach ([$this->ukStaff, $this->itStaff, $this->mktStaff] as $e) {
        Attendance::create(['employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 13:02:00', 'status' => 'on_time', 'work_mode' => 'office']);
    }

    Livewire::actingAs($nikita)->test(AllAttendance::class)->set('date', '2026-10-14')
        ->assertSee('UKSALESPERSON')
        ->assertDontSee('ITSALESPERSON')
        ->assertDontSee('MARKETINGPERSON');

    $csv = $this->actingAs($nikita)->get(route('reports.attendance-report-csv', ['type' => 'daily', 'from' => '2026-10-14', 'to' => '2026-10-14']))
        ->assertOk()->streamedContent();

    expect($csv)->toContain('UKSALESPERSON')->not->toContain('ITSALESPERSON')->not->toContain('MARKETINGPERSON');
});

test('leave: she sees and decides UK Sales requests only', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);
    $own = dhsLeave($this->ukStaff);
    $other = dhsLeave($this->mktStaff);
    $itLeave = dhsLeave($this->itStaff);

    Livewire::actingAs($nikita)->test(TeamTimeOff::class)
        ->set('filterFrom', '2026-10-01')->set('filterTo', '2026-10-31')
        ->assertSee('UKSALESPERSON')
        ->assertDontSee('MARKETINGPERSON')
        ->assertDontSee('ITSALESPERSON');

    $guard = app(ApprovalGuard::class);
    expect(fn () => $guard->assertCanDecide($nikita, $other->employee))->toThrow(ApprovalNotPermitted::class)
        ->and(fn () => $guard->assertCanDecide($nikita, $itLeave->employee))->toThrow(ApprovalNotPermitted::class)
        ->and($other->fresh()->status)->toBe('pending')
        ->and($itLeave->fresh()->status)->toBe('pending');

    Livewire::actingAs($nikita)->test(TeamTimeOff::class)
        ->call('selectRequest', $own->id)
        ->assertSet('selectedRequestId', $own->id);
});

test('overtime: she sees and decides UK Sales requests only', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);
    $own = dhsOt($this->ukStaff);
    $other = dhsOt($this->mktStaff);
    dhsOt($this->itStaff);

    Livewire::actingAs($nikita)->test(ManageOtRequests::class)
        ->assertSee('UKSALESPERSON')
        ->assertDontSee('MARKETINGPERSON')
        ->assertDontSee('ITSALESPERSON');

    $guard = app(ApprovalGuard::class);
    expect(fn () => $guard->assertCanDecide($nikita, $other->employee))->toThrow(ApprovalNotPermitted::class)
        ->and(fn () => $guard->assertCanDecide($nikita, $own->employee))->not->toThrow(ApprovalNotPermitted::class)
        ->and($other->fresh()->status)->toBe('pending');
});

// ── Dashboard: the numbers use the same scope ──────────────────────────────

test('her dashboard shows UK Sales numbers only — the same people every other page uses', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift);
    $ukLate = dhsStaff('UKLATE', $this->sales, $this->ukShift);

    Attendance::create(['employee_id' => $this->ukStaff->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 13:02:00', 'status' => 'on_time', 'work_mode' => 'office']);
    Attendance::create(['employee_id' => $ukLate->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 13:40:00', 'status' => 'late', 'is_late' => true, 'late_minutes' => 35, 'work_mode' => 'office']);
    Attendance::create(['employee_id' => $this->mktStaff->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 10:30:00', 'status' => 'on_time', 'work_mode' => 'office']);
    Attendance::create(['employee_id' => $this->itStaff->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 10:30:00', 'status' => 'on_time', 'work_mode' => 'office']);
    dhsLeave($this->ukStaff);
    dhsLeave($this->mktStaff);
    dhsOt($this->ukStaff);
    dhsOt($this->mktStaff);
    OvertimeRecord::create(['employee_id' => $this->ukStaff->id, 'work_date' => '2026-10-10', 'total_hours_worked' => 11, 'standard_hours' => 9, 'ot_hours' => 2, 'rate_per_hour' => 100, 'ot_amount' => 200, 'is_paid' => false]);
    OvertimeRecord::create(['employee_id' => $this->mktStaff->id, 'work_date' => '2026-10-10', 'total_hours_worked' => 12, 'standard_hours' => 9, 'ot_hours' => 3, 'rate_per_hour' => 100, 'ot_amount' => 300, 'is_paid' => false]);
    LeaveRequest::create(['employee_id' => $this->ukStaff->id, 'leave_type_id' => LeaveType::first()->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15', 'days' => 1, 'reason' => 'x', 'status' => 'approved', 'requested_leave_status' => 'paid']);
    LeaveRequest::create(['employee_id' => $this->mktStaff->id, 'leave_type_id' => LeaveType::first()->id, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15', 'days' => 1, 'reason' => 'x', 'status' => 'approved', 'requested_leave_status' => 'paid']);

    $template = PerformanceTemplate::create(['name' => 'Quarterly', 'code' => 'Q', 'created_by' => $nikita->id]);
    $cycle = PerformanceCycle::create(['template_id' => $template->id, 'name' => 'Q2', 'cycle_type' => 'quarterly', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_by' => $nikita->id]);
    EmployeeScorecard::create(['employee_id' => $this->ukStaff->id, 'performance_cycle_id' => $cycle->id, 'template_id' => $template->id, 'final_score' => 80]);
    EmployeeScorecard::create(['employee_id' => $this->mktStaff->id, 'performance_cycle_id' => $cycle->id, 'template_id' => $template->id, 'final_score' => 40]);

    Livewire::actingAs($nikita)->test(DepartmentDashboard::class)
        ->assertOk()
        // Present today / late
        ->assertViewHas('presentCount', 2)
        ->assertViewHas('lateCount', 1)
        // Pending approvals (leave + OT) — UK Sales only
        ->assertViewHas('pendingLeaveCount', 1)
        ->assertViewHas('pendingOtCount', 1)
        // Team OT this month — 2 h, ₹200 (Marketing's 3 h / ₹300 excluded)
        ->assertViewHas('teamOtHours', 2.0)
        ->assertViewHas('teamOtAmount', 200.0)
        // Leave this week
        ->assertViewHas('onLeaveThisWeek', fn ($rows) => $rows->count() === 1 && $rows->first()['name'] === 'UKSALESPERSON')
        // KPI / review status
        ->assertViewHas('teamKpis', fn ($rows) => $rows->count() === 1 && (int) $rows->first()->employee_id === $this->ukStaff->id)
        ->assertViewHas('teamAvgKpi', 80.0)
        // Team attendance today and the trend
        ->assertViewHas('teamAttendanceList', fn ($rows) => $rows->pluck('name')->sort()->values()->all() === ['UKLATE', 'UKSALESPERSON'])
        ->assertViewHas('trend', fn ($days) => end($days)['present'] === 2)
        ->assertSee('UKSALESPERSON')
        ->assertDontSee('MARKETINGPERSON')
        ->assertDontSee('ITSALESPERSON');
});

test('the dashboard labels she is promised are on the page', function () {
    $this->actingAs(dhsNikita($this->sales, $this->ukShift))
        ->get(route('dashboard.department'))->assertOk()
        ->assertSee('Present')
        ->assertSee('Pending approvals')
        ->assertSee('Team Attendance')
        ->assertSee('Team Attendance Trend')
        ->assertSee('Needs Attention')
        ->assertSee('Leave This Week')
        ->assertSee('Team OT');
});

// ── Defaults she must not have ─────────────────────────────────────────────

test('she holds no company-wide, payroll or administration permission by default', function () {
    $nikita = dhsNikita($this->sales, $this->ukShift, narrowToShift: false);

    foreach (['manage_employees', 'create_employee', 'delete_employee', 'manage_offboarding', 'view_payroll', 'run_payroll', 'approve_payroll',
        'approve_finance', 'view_finance_profile', 'manage_settings', 'manage_roles', 'impersonate', 'view_audit_log', 'manage_salary_components'] as $permission) {
        expect($nikita->hasPermission($permission))->toBeFalse("Nikita must not hold {$permission}");
    }

    expect(app(ScopeResolver::class)->scopeFor($nikita, 'view_payroll'))->toBe(DataScope::None);
});

test('HR can see and change her scope in Roles & Permissions and per-user overrides', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $role = Role::where('slug', 'department_head')->firstOrFail();

    Livewire::actingAs($hr)->test(RoleManager::class)
        ->call('openEdit', $role->id)
        ->assertOk()
        ->assertSeeHtml('Data scope for');
    Livewire::actingAs($hr)->test(UserPermissionOverrides::class)->assertOk();
});
