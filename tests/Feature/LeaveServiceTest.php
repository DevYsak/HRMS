<?php

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function leaveEmployee(?array $attrs = []): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(array_merge(['user_id' => $user->id], $attrs));
}

function paidLeaveType(array $overrides = []): LeaveType
{
    return LeaveType::create(array_merge([
        'name' => 'Test Paid Leave',
        'code' => 'TPL'.rand(100, 999),
        'is_paid' => true,
        'color' => '#1DB77A',
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_unpaid_request' => true,
        'allow_hr_override' => true,
        'hr_remark_required' => true,
        'allow_carry_forward' => false,
        'carry_forward_limit' => 0,
        'allow_encashment' => false,
        'is_sandwich_applicable' => false,
        'allow_half_day' => true,
        'is_monthly_accrual' => false,
        'accrual_days_per_month' => 0,
        'gender_restriction' => 'none',
        'probation_restricted' => false,
        'notice_period_restricted' => false,
        'max_consecutive_days' => null,
        'attachment_required' => false,
    ], $overrides));
}

function giveBalance(Employee $employee, LeaveType $type, float $days): LeaveBalance
{
    return LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => $days,
        'used_days' => 0,
        'carried_forward_days' => 0,
        'encashed_days' => 0,
        'comp_off_credits' => 0,
    ]);
}

// ─── Sandwich policy ──────────────────────────────────────────────────────────

it('applies the sandwich rule only when the leave meets the configured minimum days', function () {
    $svc = app(LeaveService::class);
    $fri = Carbon::parse('2026-01-02'); // Friday
    $mon = Carbon::parse('2026-01-05'); // Monday — 4 calendar days incl. the weekend

    // Threshold 0 = legacy "always sandwich when enabled" → counts all 4 days.
    expect($svc->calculateLeaveDays($fri, $mon, true, 0))->toBe(4.0)
        // Span (4) meets a 3-day threshold → sandwich applies → 4 days.
        ->and($svc->calculateLeaveDays($fri, $mon, true, 3))->toBe(4.0)
        // Span (4) is below a 5-day threshold → no sandwich → weekdays only (Fri+Mon).
        ->and($svc->calculateLeaveDays($fri, $mon, true, 5))->toBe(2.0)
        // Sandwich disabled → weekdays only regardless of threshold.
        ->and($svc->calculateLeaveDays($fri, $mon, false, 0))->toBe(2.0);
});

// ─── Paid/Unpaid policy ───────────────────────────────────────────────────────

it('allows paid request when allow_paid_request is true', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['allow_paid_request' => true]);
    giveBalance($employee, $type, 5);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-01', '2026-09-01', 'test', requestedLeaveStatus: 'paid',
    );

    expect($request->requested_leave_status)->toBe('paid');
});

it('blocks paid request when allow_paid_request is false', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['allow_paid_request' => false, 'allow_unpaid_request' => true]);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-02', '2026-09-02', 'test', requestedLeaveStatus: 'paid',
    ))->toThrow(DomainException::class, 'can only be requested as unpaid leave');
});

it('blocks unpaid request when allow_unpaid_request is false', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['allow_paid_request' => true, 'allow_unpaid_request' => false]);
    giveBalance($employee, $type, 5);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-03', '2026-09-03', 'test', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'can only be requested as paid leave');
});

it('allows unpaid request bypassing balance check', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['allow_unpaid_request' => true]);
    // No balance given — should still work for unpaid

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-04', '2026-09-04', 'test', requestedLeaveStatus: 'unpaid',
    );

    expect($request->requested_leave_status)->toBe('unpaid')
        ->and($request->status)->toBe('pending');
});

// ─── Balance enforcement ──────────────────────────────────────────────────────

it('deducts balance when paid request is approved', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType();
    giveBalance($employee, $type, 5);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-09', '2026-09-09', 'test', requestedLeaveStatus: 'paid',
    );

    // Simulate HR approval
    $request->update([
        'status' => 'approved',
        'approved_leave_status' => 'paid',
    ]);
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->first();
    // Balance should still be untouched at submit time (deduction happens on approval)
    expect($balance->used_days)->toBe('0.00');
});

it('throws insufficient balance when paid request exceeds available days', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType();
    giveBalance($employee, $type, 1); // only 1 day

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-07', '2026-09-09', 'test', requestedLeaveStatus: 'paid', // 3 days (Mon–Wed)
    ))->toThrow(DomainException::class, 'Insufficient balance');
});

// ─── Gender restriction ───────────────────────────────────────────────────────

it('blocks female-only leave for male employee', function () {
    $employee = leaveEmployee(['gender' => 'male']);
    $type = paidLeaveType(['gender_restriction' => 'female']);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-10', '2026-09-10', 'test', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'not available for your gender');
});

it('allows female-only leave for female employee', function () {
    $employee = leaveEmployee(['gender' => 'female']);
    $type = paidLeaveType(['gender_restriction' => 'female', 'allow_unpaid_request' => true]);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-11', '2026-09-11', 'test', requestedLeaveStatus: 'unpaid',
    );

    expect($request)->toBeInstanceOf(LeaveRequest::class);
});

// ─── Probation restriction ────────────────────────────────────────────────────

it('blocks leave for probation employee when probation_restricted is true', function () {
    $employee = leaveEmployee(['status' => EmployeeStatus::Probation]);
    $type = paidLeaveType(['probation_restricted' => true, 'allow_unpaid_request' => true]);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-12', '2026-09-12', 'test', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'not available during probation');
});

it('allows leave for active employee when probation_restricted is true', function () {
    $employee = leaveEmployee(['status' => EmployeeStatus::Active]);
    $type = paidLeaveType(['probation_restricted' => true, 'allow_unpaid_request' => true]);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-14', '2026-09-14', 'test', requestedLeaveStatus: 'unpaid',
    );

    expect($request)->toBeInstanceOf(LeaveRequest::class);
});

// ─── Max consecutive days ─────────────────────────────────────────────────────

it('blocks leave exceeding max consecutive days', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['max_consecutive_days' => 3, 'allow_unpaid_request' => true]);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-14', '2026-09-18', 'test', requestedLeaveStatus: 'unpaid', // 5 working days (Mon–Fri)
    ))->toThrow(DomainException::class, 'maximum of 3 consecutive day');
});

it('allows leave within max consecutive days', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['max_consecutive_days' => 3, 'allow_unpaid_request' => true]);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-15', '2026-09-17', 'test', requestedLeaveStatus: 'unpaid', // 3 days
    );

    expect($request)->toBeInstanceOf(LeaveRequest::class);
});

// ─── Half-day period ──────────────────────────────────────────────────────────

it('requires half_day_period when is_half_day is true', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['allow_half_day' => true, 'allow_unpaid_request' => true]);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-20', '2026-09-20', 'test',
        isHalfDay: true, halfDayPeriod: null, requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'first half or second half');
});

it('stores half_day_period on the request when provided', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['allow_half_day' => true, 'allow_unpaid_request' => true]);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-21', '2026-09-21', 'test',
        isHalfDay: true, halfDayPeriod: 'first_half', requestedLeaveStatus: 'unpaid',
    );

    expect($request->is_half_day)->toBeTrue()
        ->and($request->half_day_period)->toBe('first_half')
        ->and((float) $request->days)->toBe(0.5);
});

it('blocks half-day when leave type does not allow it', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['allow_half_day' => false, 'allow_unpaid_request' => true]);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-22', '2026-09-22', 'test',
        isHalfDay: true, halfDayPeriod: 'first_half', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'does not allow half-day');
});

// ─── Attachment required ──────────────────────────────────────────────────────

it('blocks request when attachment is required but missing', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['attachment_required' => true, 'allow_unpaid_request' => true]);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-25', '2026-09-25', 'test', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'attachment is required');
});

it('allows request when attachment is required and provided', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType(['attachment_required' => true, 'allow_unpaid_request' => true]);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-24', '2026-09-24', 'test',
        requestedLeaveStatus: 'unpaid', attachmentPath: 'leave-attachments/test.pdf',
    );

    expect($request->attachment_path)->toBe('leave-attachments/test.pdf');
});

// ─── Sandwich policy ──────────────────────────────────────────────────────────

it('counts weekends when sandwich policy is enabled', function () {
    $service = app(LeaveService::class);

    // Mon to Fri (5 weekdays) with sandwich = 5 calendar days (Mon-Fri, no weekend in range)
    $days = $service->calculateLeaveDays(
        Carbon::parse('2026-09-07'), // Monday
        Carbon::parse('2026-09-11'), // Friday
        true,
    );

    expect($days)->toBe(5.0);

    // Mon to Mon (8 calendar days including weekend) with sandwich
    $days = $service->calculateLeaveDays(
        Carbon::parse('2026-09-07'), // Monday
        Carbon::parse('2026-09-14'), // Next Monday
        true,
    );

    expect($days)->toBe(8.0);
});

it('skips weekends when sandwich policy is disabled', function () {
    $service = app(LeaveService::class);

    // Mon to next Mon = 6 weekdays (skip Sat + Sun)
    $days = $service->calculateLeaveDays(
        Carbon::parse('2026-09-07'), // Monday
        Carbon::parse('2026-09-14'), // Next Monday
        false,
    );

    expect($days)->toBe(6.0); // Mon, Tue, Wed, Thu, Fri, Mon = 6
});

// ─── Holiday blocking (Phase 2) ────────────────────────────────────────────────

it('blocks a leave request that overlaps a company holiday', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType();
    PublicHoliday::factory()->create([
        'name' => 'Independence Day', 'date' => '2026-08-19', 'country' => 'IN', 'is_active' => true,
        'office_id' => null, 'department_id' => null,
    ]);

    expect(fn () => app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-08-18', '2026-08-20', 'trip', requestedLeaveStatus: 'unpaid',
    ))->toThrow(DomainException::class, 'already a company holiday');
});

it('allows leave that does not overlap any holiday', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType();
    PublicHoliday::factory()->create(['date' => '2026-08-15', 'country' => 'IN', 'is_active' => true]);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-08-18', '2026-08-19', 'ok', requestedLeaveStatus: 'unpaid',
    );

    expect($request->status)->toBe('pending');
});

it('does not block when the holiday belongs to a different branch', function () {
    $office = Office::factory()->create();
    $otherOffice = Office::factory()->create();
    $employee = leaveEmployee(['office_id' => $otherOffice->id]);
    $type = paidLeaveType();
    PublicHoliday::factory()->create([
        'name' => 'Branch Day', 'date' => '2026-08-19', 'country' => 'IN', 'is_active' => true,
        'office_id' => $office->id, // only $office, not the employee's branch
    ]);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-08-18', '2026-08-20', 'ok', requestedLeaveStatus: 'unpaid',
    );

    expect($request->status)->toBe('pending');
});

it('ignores archived holidays when blocking leave', function () {
    $employee = leaveEmployee();
    $type = paidLeaveType();
    PublicHoliday::factory()->archived()->create(['date' => '2026-08-19', 'country' => 'IN']);

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-08-19', '2026-08-19', 'ok', requestedLeaveStatus: 'unpaid',
    );

    expect($request->status)->toBe('pending');
});
