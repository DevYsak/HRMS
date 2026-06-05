<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePaymentAuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function auditEmployee(): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

function auditLeaveType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Audit Test Leave',
        'code' => 'ATL'.rand(100, 999),
        'is_paid' => true,
        'color' => '#3B82F6',
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
    ]);
}

function approvedRequest(Employee $employee, LeaveType $type, string $status = 'paid'): LeaveRequest
{
    return LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
        'is_half_day' => false,
        'days' => 1,
        'reason' => 'Audit test',
        'requested_leave_status' => $status,
        'approved_leave_status' => $status,
        'status' => 'approved',
    ]);
}

// ─── HR Override: basic ───────────────────────────────────────────────────────

it('overrides paid to unpaid and writes audit log', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => 5,
        'used_days' => 1,
        'carried_forward_days' => 0,
        'encashed_days' => 0,
        'comp_off_credits' => 0,
    ]);

    $request = approvedRequest($employee, $type, 'paid');

    app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'unpaid', 'Testing unpaid override for audit.');

    $request->refresh();
    expect($request->approved_leave_status)->toBe('unpaid')
        ->and($request->payment_status_changed_by)->toBe($hr->id)
        ->and($request->hr_remark)->toBe('Testing unpaid override for audit.');

    $log = LeavePaymentAuditLog::where('leave_request_id', $request->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('paid')
        ->and($log->to_status)->toBe('unpaid')
        ->and($log->changed_by)->toBe($hr->id)
        ->and($log->stage)->toBe('post_approval');
});

it('overrides unpaid to paid and deducts balance', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $balance = LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => 5,
        'used_days' => 0,
        'carried_forward_days' => 0,
        'encashed_days' => 0,
        'comp_off_credits' => 0,
    ]);

    $request = approvedRequest($employee, $type, 'unpaid');

    app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'paid', 'Converting to paid — employee confirmed.');

    $balance->refresh();
    expect($balance->used_days)->toBe('1.00'); // 1 day deducted
});

it('reverses balance deduction when overriding paid to unpaid', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $balance = LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => 5,
        'used_days' => 1, // already deducted (from approval)
        'carried_forward_days' => 0,
        'encashed_days' => 0,
        'comp_off_credits' => 0,
    ]);

    $request = approvedRequest($employee, $type, 'paid');

    app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'unpaid', 'Marking as unpaid — employee on LOP.');

    $balance->refresh();
    expect($balance->used_days)->toBe('0.00'); // deduction reversed
});

it('throws when remark is empty on override', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $request = approvedRequest($employee, $type, 'paid');

    expect(fn () => app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'unpaid', ''))
        ->toThrow(DomainException::class, 'HR remark is mandatory');
});

it('throws when trying to override to the same status', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $request = approvedRequest($employee, $type, 'paid');

    expect(fn () => app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'paid', 'No actual change.'))
        ->toThrow(DomainException::class, 'already set to paid');
});

it('throws when leave type does not allow hr override', function () {
    $employee = auditEmployee();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    $type = LeaveType::create([
        'name' => 'No Override Type',
        'code' => 'NOT'.rand(100, 999),
        'is_paid' => true,
        'color' => '#EF4444',
        'category' => 'annual',
        'allow_hr_override' => false,
        'allow_paid_request' => true,
        'allow_unpaid_request' => false,
        'hr_remark_required' => false,
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
    ]);

    $request = approvedRequest($employee, $type, 'paid');

    expect(fn () => app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'unpaid', 'Should fail.'))
        ->toThrow(DomainException::class, 'does not allow HR payment status override');
});

// ─── Audit log immutability ───────────────────────────────────────────────────

it('audit log records multiple payment changes in sequence', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $hr = User::factory()->create(['role' => 'hr_admin']);

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => 10,
        'used_days' => 1,
        'carried_forward_days' => 0,
        'encashed_days' => 0,
        'comp_off_credits' => 0,
    ]);

    $request = approvedRequest($employee, $type, 'paid');

    app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'unpaid', 'First change to unpaid.');

    $request->refresh();
    app(LeaveService::class)->hrOverridePaymentStatus($request, $hr, 'paid', 'Second change back to paid.');

    $logs = LeavePaymentAuditLog::where('leave_request_id', $request->id)->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->from_status)->toBe('paid')
        ->and($logs[0]->to_status)->toBe('unpaid')
        ->and($logs[1]->from_status)->toBe('unpaid')
        ->and($logs[1]->to_status)->toBe('paid');
});

// ─── Monthly accrual ──────────────────────────────────────────────────────────

it('accrues leave days for eligible employees and logs each credit', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $type->update(['is_monthly_accrual' => true, 'accrual_days_per_month' => 1.75]);

    $count = app(LeaveService::class)->accrueMonthly(2026, 10);

    expect($count)->toBeGreaterThan(0);

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->allocated_days)->toBe(1.75);
});

it('skips accrual when already run for that month', function () {
    $employee = auditEmployee();
    $type = auditLeaveType();
    $type->update(['is_monthly_accrual' => true, 'accrual_days_per_month' => 1.75]);

    app(LeaveService::class)->accrueMonthly(2026, 11);
    app(LeaveService::class)->accrueMonthly(2026, 11); // run twice

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', 2026)
        ->first();

    // Should only credit once — not double
    expect((float) $balance->allocated_days)->toBe(1.75);
});
