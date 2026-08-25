<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeEmployee(): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(['user_id' => $user->id]);
}

function maternityType(): LeaveType
{
    return LeaveType::firstOrCreate(
        ['category' => 'maternity'],
        [
            'name' => 'Maternity Leave',
            'is_paid' => false,
            'color' => '#ec4899',
            'allow_carry_forward' => false,
            'carry_forward_limit' => 0,
            'allow_encashment' => false,
        ],
    );
}

function approvedMdl(Employee $employee, string $start, string $end, bool $halfDay = false): LeaveRequest
{
    return LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => maternityType()->id,
        'start_date' => $start,
        'end_date' => $end,
        'is_half_day' => $halfDay,
        'days' => $halfDay ? 0.5 : (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1),
        'reason' => 'Maternity',
        'status' => 'approved',
    ]);
}

function pendingMdl(Employee $employee, string $start, string $end, bool $halfDay = false): LeaveRequest
{
    return LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => maternityType()->id,
        'start_date' => $start,
        'end_date' => $end,
        'is_half_day' => $halfDay,
        'days' => $halfDay ? 0.5 : (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1),
        'reason' => 'Maternity',
        'status' => 'pending',
    ]);
}

// ─── Approved-leave conflicts ──────────────────────────────────────────────────

it('blocks a new maternity full-day when an approved maternity full-day overlaps', function () {
    $employee = makeEmployee();
    approvedMdl($employee, '2026-06-01', '2026-06-30');

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, maternityType(), '2026-06-15', '2026-06-15', 'test',
    ))->toThrow(DomainException::class, 'approved leave');
});

it('blocks a new maternity half-day when an approved maternity full-day overlaps', function () {
    $employee = makeEmployee();
    approvedMdl($employee, '2026-06-01', '2026-06-30');

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, maternityType(), '2026-06-15', '2026-06-15', 'test',
        isHalfDay: true, halfDayPeriod: 'first_half', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'approved leave');
});

it('blocks a new maternity full-day when an approved maternity half-day is on the same date', function () {
    $employee = makeEmployee();
    approvedMdl($employee, '2026-07-10', '2026-07-10', halfDay: true);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, maternityType(), '2026-07-10', '2026-07-10', 'test', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'approved leave');
});

it('blocks a new maternity half-day when an approved maternity half-day is on the same date', function () {
    $employee = makeEmployee();
    approvedMdl($employee, '2026-07-10', '2026-07-10', halfDay: true);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, maternityType(), '2026-07-10', '2026-07-10', 'test',
        isHalfDay: true, halfDayPeriod: 'second_half', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'approved leave');
});

// ─── Pending-leave conflicts ───────────────────────────────────────────────────

it('blocks a new maternity request when a pending maternity already exists on overlapping dates', function () {
    $employee = makeEmployee();
    pendingMdl($employee, '2026-08-01', '2026-08-31');

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, maternityType(), '2026-08-19', '2026-08-19', 'test', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'pending leave');
});

it('blocks a new maternity half-day when a pending maternity half-day is on the same date', function () {
    $employee = makeEmployee();
    pendingMdl($employee, '2026-08-10', '2026-08-10', halfDay: true);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, maternityType(), '2026-08-10', '2026-08-10', 'test',
        isHalfDay: true, halfDayPeriod: 'first_half', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'pending leave');
});

// ─── Non-overlapping is allowed ───────────────────────────────────────────────

it('allows maternity on dates outside the approved maternity window', function () {
    $employee = makeEmployee();
    approvedMdl($employee, '2026-06-01', '2026-06-30');

    $request = app(LeaveService::class)->submitRequest(
        $employee, maternityType(), '2026-07-01', '2026-07-01', 'post-maternity follow-up', requestedLeaveStatus: 'unpaid',
    );

    expect($request)->toBeInstanceOf(LeaveRequest::class)
        ->and($request->status)->toBe('pending');
});

// ─── Cross-type overlap is also blocked ───────────────────────────────────────

it('blocks any leave type when approved maternity covers those dates', function () {
    $employee = makeEmployee();
    approvedMdl($employee, '2026-06-01', '2026-06-30');

    $sickLeave = LeaveType::firstOrCreate(
        ['category' => 'sick'],
        ['name' => 'Sick Leave', 'is_paid' => false, 'color' => '#ef4444',
            'allow_carry_forward' => false, 'carry_forward_limit' => 0, 'allow_encashment' => false,
            'allow_paid_request' => false, 'allow_unpaid_request' => true],
    );

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $sickLeave, '2026-06-10', '2026-06-10', 'sick', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'approved leave');
});
