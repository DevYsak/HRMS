<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\LeaveService;
use App\Services\WfhService;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function lwrEmployee(array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active']);
}

function lwrWeeklyOffs(array $days): void
{
    AttendanceSetting::query()->delete();
    AttendanceSetting::create([
        'shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => $days,
        'requires_location' => false, 'requires_photo' => false,
    ]);

    // weeklyOffDays() memoises statically for the life of the process, which is
    // right for a request and wrong for a test suite that changes the setting.
    AttendanceSetting::flushWeeklyOffCache();
}

// ── Leave weekly-off ───────────────────────────────────────────────────────

test('leave can start on a Saturday when the company works Saturdays', function () {
    // The defect: LeaveService used Carbon's isWeekend(), which is always
    // Sat+Sun. Under a Sunday-only week that refused leave on a Saturday the
    // company actually works.
    lwrWeeklyOffs([Carbon::SUNDAY]);

    $employee = lwrEmployee();
    $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CLW', 'category' => 'annual', 'allow_paid_request' => true]);
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => 2026, 'allocated_days' => 10, 'used_days' => 0,
    ]);

    // 2026-06-06 is a Saturday.
    $request = app(LeaveService::class)
        ->submitRequest($employee, $type, '2026-06-06', '2026-06-06', 'Working-Saturday leave');

    expect($request)->not->toBeNull()
        ->and($request->start_date->toDateString())->toBe('2026-06-06');
});

test('leave still cannot start on a configured weekly off', function () {
    lwrWeeklyOffs([Carbon::SUNDAY]);

    $employee = lwrEmployee();
    $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CLX', 'category' => 'annual', 'allow_paid_request' => true]);
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => 2026, 'allocated_days' => 10, 'used_days' => 0,
    ]);

    // 2026-06-07 is a Sunday — the configured off.
    expect(fn () => app(LeaveService::class)
        ->submitRequest($employee, $type, '2026-06-07', '2026-06-07', 'Sunday leave'))
        ->toThrow(DomainException::class);
});

test('a company that rests on Friday blocks Friday leave and allows Sunday', function () {
    // The reverse case, so the rule is genuinely configuration-driven rather
    // than a differently-hardcoded weekend.
    lwrWeeklyOffs([Carbon::FRIDAY]);

    $employee = lwrEmployee();
    $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CLF', 'category' => 'annual', 'allow_paid_request' => true]);
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => 2026, 'allocated_days' => 10, 'used_days' => 0,
    ]);

    $service = app(LeaveService::class);

    // 2026-06-05 is a Friday.
    expect(fn () => $service->submitRequest($employee, $type, '2026-06-05', '2026-06-05', 'Friday leave'))
        ->toThrow(DomainException::class);

    // 2026-06-07 is a Sunday — a working day here.
    $request = $service->submitRequest($employee, $type, '2026-06-07', '2026-06-07', 'Sunday leave');

    expect($request->start_date->toDateString())->toBe('2026-06-07');
});

// ── Leave type dropdown ────────────────────────────────────────────────────

test('unauthorized leave is not something an employee can request', function () {
    // A disciplinary classification HR applies, not a request type. The legacy
    // uncoded row was fully requestable and won the dropdown by name.
    $this->seed(LeaveTypeSeeder::class);

    $requestable = LeaveType::where(function ($q) {
        $q->where('allow_paid_request', true)->orWhere('allow_unpaid_request', true);
    })->pluck('name');

    expect($requestable)->not->toContain('Unauthorized Leave');
});

test('the coded unauthorized leave record still exists for HR classification', function () {
    // Not deleted — historical records and HR classification still need it.
    $this->seed(LeaveTypeSeeder::class);

    expect(LeaveType::where('code', 'UL')->exists())->toBeTrue();
});

// ── WFH enforcement ────────────────────────────────────────────────────────

test('an approved request covers its date range', function () {
    $employee = lwrEmployee();
    WfhRequest::create([
        'employee_id' => $employee->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-05',
        'reason' => 'Approved block', 'status' => 'approved',
    ]);

    $service = app(WfhService::class);

    expect($service->isApprovedFor($employee, Carbon::parse('2026-06-03')))->toBeTrue()
        ->and($service->isApprovedFor($employee, Carbon::parse('2026-06-06')))->toBeFalse();
});

test('a pending request does not count as approval', function () {
    $employee = lwrEmployee();
    WfhRequest::create([
        'employee_id' => $employee->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-05',
        'reason' => 'Still pending', 'status' => 'pending',
    ]);

    expect(app(WfhService::class)->isApprovedFor($employee, Carbon::parse('2026-06-03')))->toBeFalse();
});

test('an employee cannot clock in as work-from-home without an approved request', function () {
    lwrWeeklyOffs([Carbon::SUNDAY]);
    $employee = lwrEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('workMode', 'wfh')
        ->call('checkIn');

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('an employee with an approved request can clock in as work-from-home', function () {
    lwrWeeklyOffs([Carbon::SUNDAY]);
    $employee = lwrEmployee();

    WfhRequest::create([
        'employee_id' => $employee->id,
        'start_date' => Carbon::today()->toDateString(),
        'end_date' => Carbon::today()->toDateString(),
        'reason' => 'Approved for today', 'status' => 'approved',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('workMode', 'wfh')
        ->call('checkIn');

    $attendance = Attendance::where('employee_id', $employee->id)->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance->work_mode)->toBe('wfh');
});

test('clocking in from the office is unaffected by the work-from-home rule', function () {
    lwrWeeklyOffs([Carbon::SUNDAY]);
    $employee = lwrEmployee();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('workMode', 'office')
        ->call('checkIn');

    $attendance = Attendance::where('employee_id', $employee->id)->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance->work_mode)->toBe('office');
});
