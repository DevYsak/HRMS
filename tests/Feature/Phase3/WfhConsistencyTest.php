<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Employees\DataQuality;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Attendance\WfhConsistencyService;
use App\Services\AttendanceReportBuilder;
use App\Services\DataQuality\DataQualityAuditor;
use App\Services\EmployeeDashboardService;
use App\Services\People\Employee360Service;
use App\Services\WfhService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * P3-07 (policy 16 Oct): the approved WFH request is the plan, attendance
 * work_mode is the record; they are compared, never merged. Wed 18 Nov 2026:
 *  - Wendy: approved WFH 2–3 Nov, recorded WFH both days      → 2 valid
 *  - Oscar: approved WFH 4 Nov, recorded Office               → 1 mismatch
 *  - Uma:   no approval, recorded WFH 5 and 6 Nov (a PENDING
 *           request covers 6 Nov — pending authorises nothing) → 2 unapproved
 *  - Hal:   approved half-day WFH 9 Nov, recorded Hybrid       → 1 valid
 *  - Ned:   approved WFH 10 Nov, no attendance that day        → nothing to compare
 */
function wcUser(string $slug, Department $department, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

function wcDay(Employee $employee, string $date, string $mode): void
{
    Attendance::create([
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => Carbon::parse("{$date} 09:00"), 'check_out' => Carbon::parse("{$date} 18:00"),
        'status' => 'on_time', 'is_late' => false, 'work_mode' => $mode,
    ]);
}

function wcRequest(Employee $employee, string $from, string $to, string $status, bool $halfDay = false): WfhRequest
{
    return WfhRequest::create([
        'employee_id' => $employee->id, 'start_date' => $from, 'end_date' => $to,
        'is_half_day' => $halfDay, 'half_day_period' => $halfDay ? 'first_half' : null,
        'reason' => 'Scenario', 'status' => $status,
    ]);
}

/** Every attendance row and WFH request, to prove nothing was rewritten. */
function wcSnapshot(): array
{
    return [
        DB::table('attendances')->orderBy('id')->get(['id', 'employee_id', 'date', 'work_mode', 'check_in', 'check_out', 'status'])->toArray(),
        DB::table('wfh_requests')->orderBy('id')->get(['id', 'status', 'start_date', 'end_date', 'reviewer_id'])->toArray(),
    ];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-18 10:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [Carbon::SUNDAY], 'requires_location' => false, 'requires_photo' => false]);
    AttendanceSetting::flushWeeklyOffCache();

    $this->dept = Department::factory()->create(['name' => 'WC Remote']);
    $this->hr = wcUser('hr_admin', $this->dept, 'Hana HR');
    $this->wendy = wcUser('employee', $this->dept, 'Wendy Valid')->employee;
    $this->oscar = wcUser('employee', $this->dept, 'Oscar Office')->employee;
    $this->uma = wcUser('employee', $this->dept, 'Uma Unapproved')->employee;
    $this->hal = wcUser('employee', $this->dept, 'Hal Halfday')->employee;
    $this->ned = wcUser('employee', $this->dept, 'Ned Nopunch')->employee;

    wcRequest($this->wendy, '2026-11-02', '2026-11-03', 'approved');
    wcDay($this->wendy, '2026-11-02', 'wfh');
    wcDay($this->wendy, '2026-11-03', 'wfh');
    wcRequest($this->oscar, '2026-11-04', '2026-11-04', 'approved');
    wcDay($this->oscar, '2026-11-04', 'office');
    wcDay($this->uma, '2026-11-05', 'wfh');
    $this->umaPending = wcRequest($this->uma, '2026-11-06', '2026-11-06', 'pending');
    wcDay($this->uma, '2026-11-06', 'wfh');
    wcRequest($this->hal, '2026-11-09', '2026-11-09', 'approved', halfDay: true);
    wcDay($this->hal, '2026-11-09', 'hybrid');
    wcRequest($this->ned, '2026-11-10', '2026-11-10', 'approved');

    $this->ids = [$this->wendy->id, $this->oscar->id, $this->uma->id, $this->hal->id, $this->ned->id];
});

test('each attended day is classified by comparing the approved plan with the recorded mode', function () {
    $service = app(WfhConsistencyService::class);
    $days = $service->days($this->ids, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-17'));
    $by = $days->keyBy(fn ($d) => $d['employee_id'].'|'.$d['date']);

    expect($by[$this->wendy->id.'|2026-11-02']['status'])->toBe(WfhConsistencyService::VALID)
        ->and($by[$this->oscar->id.'|2026-11-04']['status'])->toBe(WfhConsistencyService::APPROVED_NOT_WFH)
        ->and($by[$this->oscar->id.'|2026-11-04']['recorded_mode'])->toBe('office')
        ->and($by[$this->uma->id.'|2026-11-05']['status'])->toBe(WfhConsistencyService::UNAPPROVED_WFH)
        ->and($by[$this->uma->id.'|2026-11-05']['pending_request_id'])->toBeNull()
        ->and($by[$this->uma->id.'|2026-11-06']['status'])->toBe(WfhConsistencyService::UNAPPROVED_WFH)
        ->and($by[$this->uma->id.'|2026-11-06']['pending_request_id'])->toBe($this->umaPending->id)
        ->and($by[$this->hal->id.'|2026-11-09']['status'])->toBe(WfhConsistencyService::VALID)
        ->and($by->has($this->ned->id.'|2026-11-10'))->toBeFalse();

    expect($service->summary($days))->toBe(['valid' => 3, 'approved_not_wfh' => 1, 'unapproved_wfh' => 2, 'flagged' => 3, 'employees_flagged' => 2]);

    // The window narrows it: only 5–6 Nov.
    $narrow = $service->summary($service->days($this->ids, Carbon::parse('2026-11-05'), Carbon::parse('2026-11-06')));
    expect([$narrow['unapproved_wfh'], $narrow['valid']])->toBe([2, 0]);
});

test('Data Quality, Employee 360 and the attendance report show the same mismatches', function () {
    $from = Carbon::parse('2026-11-01');
    $to = Carbon::parse('2026-11-17');

    // Data Quality: one REVIEW REQUIRED issue per employee per kind, never a code bug.
    $issues = app(DataQualityAuditor::class)->issues()->whereIn('check', ['wfh_unapproved', 'wfh_approved_not_used']);
    expect($issues->where('check', 'wfh_unapproved')->pluck('employeeId')->all())->toBe([$this->uma->id])
        ->and($issues->where('check', 'wfh_approved_not_used')->pluck('employeeId')->all())->toBe([$this->oscar->id])
        ->and($issues->every(fn ($i) => $i->nature() === 'data'))->toBeTrue()
        ->and($issues->firstWhere('check', 'wfh_unapproved')->message)->toContain('2 days')->toContain('pending does not authorise');
    $wfhRows = collect(app(DataQualityAuditor::class)->wfhConsistency());
    expect($wfhRows->where('status', '!=', WfhConsistencyService::VALID)->count())->toBe(3);

    Livewire::actingAs($this->hr)->test(DataQuality::class, ['tab' => 'wfh'])
        ->assertOk()->assertSee('Uma Unapproved')->assertSee('Oscar Office')->assertSee('WFH without an approved request')
        ->assertDontSee('Wendy Valid');

    // Employee 360 (attendance section) over the same window.
    $uma360 = app(Employee360Service::class)->build($this->hr, $this->uma, $from, $to)['attendance']['wfh'];
    $oscar360 = app(Employee360Service::class)->build($this->hr, $this->oscar, $from, $to)['attendance']['wfh'];
    $wendy360 = app(Employee360Service::class)->build($this->hr, $this->wendy, $from, $to)['attendance']['wfh'];
    expect($uma360['counts']['unapproved_wfh'])->toBe(2)
        ->and($oscar360['counts']['approved_not_wfh'])->toBe(1)
        ->and([$wendy360['counts']['valid'], $wendy360['counts']['flagged']])->toBe([2, 0]);
    $this->actingAs($this->hr)->get(route('employees.360', ['employee' => $this->uma->id, 'from' => '2026-11-01', 'to' => '2026-11-17']))
        ->assertOk()->assertSee('data-wfh-consistency', false)->assertSee('WFH without an approved request');

    // Attendance Reports → WFH Consistency (preview + CSV).
    $report = app(AttendanceReportBuilder::class)->build('wfh_consistency', ['from' => '2026-11-01', 'to' => '2026-11-17', 'employee_ids' => $this->ids]);
    expect(collect($report['summary'])->pluck('value', 'label')->all())
        ->toMatchArray(['Valid' => 3, 'Approved WFH, recorded another mode' => 1, 'WFH without an approved request' => 2])
        ->and($report['rows'])->toHaveCount(6);
    $csv = $this->actingAs($this->hr)->get(route('reports.attendance-report-csv', ['type' => 'wfh_consistency', 'from' => '2026-11-01', 'to' => '2026-11-17']))->assertOk()->streamedContent();
    expect(substr_count($csv, 'WFH without an approved request'))->toBe(2)
        ->and(substr_count($csv, 'Approved WFH, recorded another mode'))->toBe(1);
});

test('the checks, the command and the screens rewrite no attendance or request', function () {
    $before = wcSnapshot();

    app(WfhConsistencyService::class)->days();
    app(DataQualityAuditor::class)->issues();
    $this->artisan('hrms:data-quality', ['--json' => true])->assertSuccessful();
    app(AttendanceReportBuilder::class)->build('wfh_consistency', ['from' => '2026-11-01', 'to' => '2026-11-17']);
    app(Employee360Service::class)->build($this->hr, $this->uma, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-17'));
    Livewire::actingAs($this->hr)->test(DataQuality::class, ['tab' => 'wfh'])->assertOk();

    expect(wcSnapshot())->toEqual($before);
});

test('approving WFH creates no attendance, and a punch never approves a request', function () {
    $employee = wcUser('employee', $this->dept, 'Pia Pending')->employee;
    $request = wcRequest($employee, '2026-11-18', '2026-11-20', 'pending');

    // Rule 4 + 6: a pending request authorises nothing — WFH is refused, and
    // an office punch leaves the request pending.
    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('workMode', 'office')
        ->assertSet('wfhApprovedToday', false)
        ->set('workMode', 'wfh')->call('checkIn');
    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
    expect(app(EmployeeDashboardService::class)->build($employee->user)['today']['default_mode'])->toBe('office');

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)->set('workMode', 'office')->call('checkIn');
    expect(Attendance::where('employee_id', $employee->id)->value('work_mode'))->toBe('office')
        ->and($request->fresh()->status)->toBe('pending');

    // Rule 5: approval writes the request only.
    $other = wcUser('employee', $this->dept, 'Ari Approved')->employee;
    $otherRequest = wcRequest($other, '2026-11-19', '2026-11-20', 'pending');
    app(WfhService::class)->approve($otherRequest, $this->hr->id);
    expect($otherRequest->fresh()->status)->toBe('approved')
        ->and(Attendance::where('employee_id', $other->id)->count())->toBe(0);
});

test('an approved request preselects WFH, but the punch records what the employee chose', function () {
    $employee = wcUser('employee', $this->dept, 'Remy Remote')->employee;
    wcRequest($employee, '2026-11-18', '2026-11-18', 'approved');

    expect(app(EmployeeDashboardService::class)->build($employee->user)['today']['default_mode'])->toBe('wfh');

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('wfhApprovedToday', true)
        ->assertSet('workMode', 'wfh')
        ->assertSee('Approved work from home today')
        ->set('workMode', 'office')->call('checkIn');

    // The actual event is authoritative: recorded as office, then flagged for review.
    expect(Attendance::where('employee_id', $employee->id)->value('work_mode'))->toBe('office');
    $today = app(WfhConsistencyService::class)->days([$employee->id], Carbon::today(), Carbon::today());
    expect($today->first()['status'])->toBe(WfhConsistencyService::APPROVED_NOT_WFH);
});
