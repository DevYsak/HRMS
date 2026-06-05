<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveBalanceService;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function adjEmployee(): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

function hrUser(): User
{
    return User::factory()->create(['role' => 'hr_admin']);
}

function adjLeaveType(array $overrides = []): LeaveType
{
    return LeaveType::create(array_merge([
        'name' => 'Adj Test Leave '.rand(100, 999),
        'code' => 'ATJ'.rand(100, 999),
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
        'annual_allocation_days' => 12,
        'is_system_controlled' => false,
    ], $overrides));
}

function seedBalance(Employee $employee, LeaveType $type, float $allocated, float $used = 0): LeaveBalance
{
    return LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => $allocated,
        'used_days' => $used,
        'carried_forward_days' => 0,
        'encashed_days' => 0,
        'comp_off_credits' => 0,
    ]);
}

// ─── Credit tests ──────────────────────────────────────────────────────────────

it('credits days to a new balance record and writes an audit log', function () {
    $employee = adjEmployee();
    $type = adjLeaveType();
    $hr = hrUser();

    app(LeaveBalanceService::class)->adjust(
        $employee, $type, 'credit', 5, 'Annual grant', 'Year start credit', $hr,
    );

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', now()->year)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->allocated_days)->toBe(5.0);

    $log = LeaveBalanceAdjustment::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('credit')
        ->and((float) $log->days)->toBe(5.0)
        ->and((float) $log->previous_balance)->toBe(0.0)
        ->and((float) $log->new_balance)->toBe(5.0)
        ->and($log->reason)->toBe('Annual grant')
        ->and($log->adjusted_by)->toBe($hr->id);
});

it('credits days to an existing balance without overwriting used_days', function () {
    $employee = adjEmployee();
    $type = adjLeaveType();
    $hr = hrUser();

    seedBalance($employee, $type, 10, 3); // 10 allocated, 3 used

    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'Bonus credit', '', $hr);

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->first();

    expect((float) $balance->allocated_days)->toBe(12.0)  // 10 + 2
        ->and((float) $balance->used_days)->toBe(3.0);    // unchanged
});

// ─── Debit tests ───────────────────────────────────────────────────────────────

it('debits days from an existing balance and writes an audit log', function () {
    $employee = adjEmployee();
    $type = adjLeaveType();
    $hr = hrUser();

    seedBalance($employee, $type, 12, 0);

    app(LeaveBalanceService::class)->adjust($employee, $type, 'debit', 4, 'Correction', 'HR correction', $hr);

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->first();

    expect((float) $balance->allocated_days)->toBe(8.0);

    $log = LeaveBalanceAdjustment::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->first();

    expect($log->action)->toBe('debit')
        ->and((float) $log->previous_balance)->toBe(12.0)
        ->and((float) $log->new_balance)->toBe(8.0);
});

it('throws when debiting more than available balance', function () {
    $employee = adjEmployee();
    $type = adjLeaveType();
    $hr = hrUser();

    seedBalance($employee, $type, 5, 3); // 5 allocated, 3 used → 2 available

    expect(fn () => app(LeaveBalanceService::class)->adjust(
        $employee, $type, 'debit', 3, 'Over-debit attempt', '', $hr,
    ))->toThrow(DomainException::class, 'Cannot debit 3');
});

// ─── System-controlled block ───────────────────────────────────────────────────

it('blocks adjustment on system-controlled leave types', function () {
    $employee = adjEmployee();
    $systemType = adjLeaveType(['is_system_controlled' => true]);
    $hr = hrUser();

    expect(fn () => app(LeaveBalanceService::class)->adjust(
        $employee, $systemType, 'credit', 1, 'Should fail', '', $hr,
    ))->toThrow(DomainException::class, 'system-controlled');
});

// ─── Audit immutability ────────────────────────────────────────────────────────

it('multiple adjustments each produce a separate immutable audit log entry', function () {
    $employee = adjEmployee();
    $type = adjLeaveType();
    $hr = hrUser();

    seedBalance($employee, $type, 20, 0);

    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 3, 'Q1 grant', '', $hr);
    app(LeaveBalanceService::class)->adjust($employee, $type, 'debit', 1, 'Correction', '', $hr);
    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 5, 'Q2 grant', '', $hr);

    $logs = LeaveBalanceAdjustment::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->orderBy('id')
        ->get();

    expect($logs)->toHaveCount(3)
        ->and($logs[0]->action)->toBe('credit')
        ->and($logs[1]->action)->toBe('debit')
        ->and($logs[2]->action)->toBe('credit');

    // Final balance: 20 + 3 - 1 + 5 = 27
    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->first();
    expect((float) $balance->allocated_days)->toBe(27.0);
});

// ─── Initialize for employee ──────────────────────────────────────────────────

it('initializes leave balances from annual_allocation_days on new employee', function () {
    $employee = adjEmployee();

    // Create types with allocation
    $cl = adjLeaveType(['annual_allocation_days' => 12]);
    $sl = adjLeaveType(['annual_allocation_days' => 6]);
    $unlimitedType = adjLeaveType(['annual_allocation_days' => null]); // no auto-init

    app(LeaveBalanceService::class)->initializeForEmployee($employee, now()->year);

    $clBalance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $cl->id)->first();

    $slBalance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $sl->id)->first();

    $noBalance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $unlimitedType->id)->first();

    expect($clBalance)->not->toBeNull()
        ->and((float) $clBalance->allocated_days)->toBe(12.0);

    expect($slBalance)->not->toBeNull()
        ->and((float) $slBalance->allocated_days)->toBe(6.0);

    expect($noBalance)->toBeNull(); // not initialized — no annual_allocation_days
});

it('initializeForEmployee is idempotent and does not reset existing balances', function () {
    $employee = adjEmployee();
    $type = adjLeaveType(['annual_allocation_days' => 12]);

    // Pre-existing balance with used days
    seedBalance($employee, $type, 12, 4);

    // Run twice
    app(LeaveBalanceService::class)->initializeForEmployee($employee, now()->year);
    app(LeaveBalanceService::class)->initializeForEmployee($employee, now()->year);

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->first();

    // Should not reset used_days
    expect((float) $balance->used_days)->toBe(4.0);
});

// ─── getBalanceSummary ────────────────────────────────────────────────────────

it('getBalanceSummary includes all active leave types with correct available calculation', function () {
    $employee = adjEmployee();
    $type = adjLeaveType(['annual_allocation_days' => 10]);

    seedBalance($employee, $type, 10, 3);

    $summary = app(LeaveBalanceService::class)->getBalanceSummary($employee, now()->year);

    $row = $summary->firstWhere('leave_type_id', $type->id);

    expect($row)->not->toBeNull()
        ->and($row->allocated)->toBe(10.0)
        ->and($row->used)->toBe(3.0)
        ->and($row->available)->toBe(7.0);
});
