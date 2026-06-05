<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Support\Str;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function encashEmployee(): Employee
{
    $user = User::factory()->create(['role' => 'employee']);

    return Employee::factory()->create(['user_id' => $user->id]);
}

function encashableType(array $overrides = []): LeaveType
{
    return LeaveType::create(array_merge([
        'name' => 'Earned Leave '.Str::random(4),
        'code' => 'EL'.Str::random(3),
        'category' => 'annual',
        'is_paid' => true,
        'color' => '#1DB77A',
        'allow_carry_forward' => true,
        'carry_forward_limit' => 30,
        'allow_encashment' => true,
        'max_encashable_days' => null,
        'encashment_rate_multiplier' => 1.00,
        'allow_current_year_encashment' => false,
    ], $overrides));
}

function makeBalance(Employee $employee, LeaveType $type, array $overrides = []): LeaveBalance
{
    return LeaveBalance::create(array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => 18,
        'used_days' => 5,
        'carried_forward_days' => 8,
        'encashed_days' => 0,
    ], $overrides));
}

// ─── Tests ────────────────────────────────────────────────────────────────────

test('employee can encash carried-forward days', function () {
    $employee = encashEmployee();
    $type = encashableType();
    makeBalance($employee, $type, ['carried_forward_days' => 10, 'encashed_days' => 0]);

    $service = app(LeaveService::class);
    $encashment = $service->requestEncashment($employee, $type, 5, now()->format('Y-m'));

    expect($encashment->status)->toBe('pending');
    expect($encashment->requested_days)->toBe(5.0);
    expect($encashment->source_leave_year)->toBe(now()->year - 1);
});

test('encashment throws when carry-forward balance is insufficient', function () {
    $employee = encashEmployee();
    $type = encashableType();
    makeBalance($employee, $type, ['carried_forward_days' => 3, 'encashed_days' => 0]);

    $service = app(LeaveService::class);
    expect(fn () => $service->requestEncashment($employee, $type, 5, now()->format('Y-m')))
        ->toThrow(DomainException::class);
});

test('encashment cap is enforced per year', function () {
    $employee = encashEmployee();
    $type = encashableType(['max_encashable_days' => 10]);
    makeBalance($employee, $type, ['carried_forward_days' => 20, 'encashed_days' => 0]);

    LeaveEncashment::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'requested_days' => 8,
        'status' => 'pending',
        'payout_month' => now()->format('Y-m'),
        'source_leave_year' => now()->year - 1,
    ]);

    $service = app(LeaveService::class);
    expect(fn () => $service->requestEncashment($employee, $type, 5, now()->format('Y-m')))
        ->toThrow(DomainException::class, 'cap');
});

test('current-year encashment allowed when enabled on leave type', function () {
    $employee = encashEmployee();
    $type = encashableType(['allow_current_year_encashment' => true, 'carried_forward_days' => 0]);
    makeBalance($employee, $type, ['carried_forward_days' => 0, 'allocated_days' => 15, 'used_days' => 5]);

    $service = app(LeaveService::class);
    $encashment = $service->requestEncashment($employee, $type, 5, now()->format('Y-m'));

    expect($encashment->status)->toBe('pending');
    expect($encashment->source_leave_year)->toBe(now()->year);
});

test('approveEncashment transitions to pending_finance', function () {
    $employee = encashEmployee();
    $type = encashableType();
    makeBalance($employee, $type, ['carried_forward_days' => 10]);

    $hrUser = User::factory()->create(['role' => 'hr_admin']);
    $encashment = LeaveEncashment::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'requested_days' => 5,
        'status' => 'pending',
        'payout_month' => now()->format('Y-m'),
        'source_leave_year' => now()->year - 1,
    ]);

    app(LeaveService::class)->approveEncashment($hrUser, $encashment, 'Looks good');

    expect($encashment->fresh()->status)->toBe('pending_finance');
    expect($encashment->fresh()->reviewer_id)->toBe($hrUser->id);
});

test('financeApproveEncashment transitions to approved and commits balance', function () {
    $employee = encashEmployee();
    $type = encashableType();
    $balance = makeBalance($employee, $type, ['carried_forward_days' => 10, 'encashed_days' => 0]);

    $financeUser = User::factory()->create(['role' => 'finance']);
    $encashment = LeaveEncashment::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'requested_days' => 5,
        'status' => 'pending_finance',
        'payout_month' => now()->format('Y-m'),
        'source_leave_year' => now()->year - 1,
    ]);

    app(LeaveService::class)->financeApproveEncashment($financeUser, $encashment, 'Approved');

    expect($encashment->fresh()->status)->toBe('approved');
    expect($encashment->fresh()->finance_reviewer_id)->toBe($financeUser->id);
    expect((float) $balance->fresh()->encashed_days)->toBe(5.0);
});

test('rejectEncashment from pending sets rejected and does not touch balance', function () {
    $employee = encashEmployee();
    $type = encashableType();
    $balance = makeBalance($employee, $type, ['carried_forward_days' => 10, 'encashed_days' => 0]);

    $hrUser = User::factory()->create(['role' => 'hr_admin']);
    $encashment = LeaveEncashment::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'requested_days' => 5,
        'status' => 'pending',
        'payout_month' => now()->format('Y-m'),
        'source_leave_year' => now()->year - 1,
    ]);

    app(LeaveService::class)->rejectEncashment($hrUser, $encashment, 'Not eligible');

    expect($encashment->fresh()->status)->toBe('rejected');
    expect((float) $balance->fresh()->encashed_days)->toBe(0.0);
});

test('leave type without encashment flag throws on request', function () {
    $employee = encashEmployee();
    $type = encashableType(['allow_encashment' => false]);
    makeBalance($employee, $type);

    expect(fn () => app(LeaveService::class)->requestEncashment($employee, $type, 3, now()->format('Y-m')))
        ->toThrow(DomainException::class, 'not eligible');
});
