<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLeaveOverride;
use App\Models\LeaveAccrualLog;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\EmployeeLeaveOverrideService;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 2B — policy-driven automatic provisioning and accrual.
 *
 * Fixed clock: 15 October 2026, inside leave year 2026/27 (1 Jul – 30 Jun).
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
});

function lppYear(string $label = '2026/27'): LeaveYear
{
    return LeaveYear::where('label', $label)->firstOrFail();
}

function lppPolicy(string $name = 'Office Staff'): LeavePolicy
{
    return LeavePolicy::create([
        'name' => $name.' '.random_int(100, 999), 'statutory_weeks' => 5.6, 'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional', 'irregular_accrual_rate' => 0.1207, 'is_default' => false, 'is_active' => true,
    ]);
}

function lppType(string $name = 'Casual Leave'): LeaveType
{
    return LeaveType::create([
        'name' => $name, 'code' => 'T'.random_int(10000, 99999), 'category' => 'other',
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true, 'color' => '#10b981',
    ]);
}

function lppRule(LeavePolicy $policy, LeaveType $type, array $attributes = []): LeavePolicyRule
{
    return LeavePolicyRule::create(array_merge([
        'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
        'entitlement_method' => 'fixed_days', 'fixed_days' => 12, 'accrual_method' => 'annual_upfront',
    ], $attributes));
}

function lppHire(LeavePolicy $policy, array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'leave_policy_id' => $policy->id,
        'joining_date' => '2024-01-10',
        'working_pattern' => 'regular',
        'working_days_per_week' => 5,
    ], $attributes));
}

function lppBalance(Employee $employee, LeaveType $type, string $year = '2026/27'): ?LeaveBalance
{
    return LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
        ->where('year', lppYear($year)->legacyYear())->first();
}

function lppBaseEntries(Employee $employee, LeaveType $type): int
{
    return LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
        ->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->count();
}

function lppEnsure(): EnsureEmployeeLeaveBalancesService
{
    return app(EnsureEmployeeLeaveBalancesService::class);
}

function lppHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

// ── Automatic provisioning ───────────────────────────────────────────────────

test('a new employee is given their policy entitlement as base, automatically', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 12]);

    $employee = lppHire($policy);
    $balance = lppBalance($employee, $type);

    expect($balance)->not->toBeNull()
        ->and($balance->isLedgerBacked())->toBeTrue()
        ->and((float) $balance->base_days)->toBe(12.0)
        ->and((float) $balance->allocated_days)->toBe(12.0)
        ->and(AuditLog::where('action', 'leave.entitlement_provisioned')->where('subject_employee_id', $employee->id)
            ->where('auditable_id', $balance->id)->exists())->toBeTrue();
});

test('provisioning again never duplicates the entitlement', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type);
    $employee = lppHire($policy);

    $rows = lppEnsure()->ensure($employee->fresh());
    lppEnsure()->ensure($employee->fresh());

    expect($rows->firstWhere('leave_type_id', $type->id)['status'])->toBe(EnsureEmployeeLeaveBalancesService::ALREADY)
        ->and(lppBaseEntries($employee, $type))->toBe(1)
        ->and(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->count())->toBe(1);
});

test('a mid-year joiner is pro-rated by whole months, joining month counted by the 15th', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 12]);

    // 10 Oct: October counts → Oct–Jun = 9 months → 9 days.
    $early = lppHire($policy, ['joining_date' => '2026-10-10']);
    // 20 Oct: October does not count → Nov–Jun = 8 months → 8 days.
    $late = lppHire($policy, ['joining_date' => '2026-10-20']);

    expect((float) lppBalance($early, $type)->base_days)->toBe(9.0)
        ->and((float) lppBalance($late, $type)->base_days)->toBe(8.0);
});

test('the joining-month rule "full" counts the joining month whatever the day', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 12, 'joining_month_rule' => 'full']);

    expect((float) lppBalance(lppHire($policy, ['joining_date' => '2026-10-28']), $type)->base_days)->toBe(9.0);
});

test('annual leave keeps coming from the UK weeks engine', function () {
    $policy = lppPolicy();
    $annual = LeaveType::where('code', 'AL')->first() ?? LeaveType::create([
        'name' => 'Annual Leave', 'code' => 'AL', 'category' => 'annual', 'is_paid' => true, 'allow_paid_request' => true,
    ]);

    $fullYear = lppHire($policy);
    $partTime = lppHire($policy, ['working_days_per_week' => 3, 'working_days' => ['Monday', 'Tuesday', 'Wednesday']]);

    // 5.6 weeks: 28 days for five days a week, 16.8 for three.
    expect((float) lppBalance($fullYear, $annual)->base_days)->toBe(28.0)
        ->and((float) lppBalance($partTime, $annual)->base_days)->toBe(16.8);
});

test('statuses: probation and notice period hold leave but policy restrictions apply when applying', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 10, 'probation_restricted' => true, 'notice_period_restricted' => true]);

    $probation = lppHire($policy, ['status' => 'probation']);
    $notice = lppHire($policy, ['status' => 'notice_period']);

    expect((float) lppBalance($probation, $type)->base_days)->toBe(10.0)
        ->and((float) lppBalance($notice, $type)->base_days)->toBe(10.0);

    expect(fn () => app(LeaveService::class)->submitRequest($probation->fresh(), $type, '2026-10-20', '2026-10-20', 'Errand'))
        ->toThrow(DomainException::class, 'not available during probation');
    expect(fn () => app(LeaveService::class)->submitRequest($notice->fresh(), $type, '2026-10-20', '2026-10-20', 'Errand'))
        ->toThrow(DomainException::class, 'not available during notice period');
});

test('a policy rule overrides the leave type for request limits', function () {
    $policy = lppPolicy();
    $type = lppType();
    $type->update(['max_consecutive_days' => 10]);
    lppRule($policy, $type, ['fixed_days' => 12, 'max_consecutive_days' => 1]);
    $employee = lppHire($policy);

    expect(fn () => app(LeaveService::class)->submitRequest($employee->fresh(), $type, '2026-10-20', '2026-10-21', 'Trip'))
        ->toThrow(DomainException::class, 'maximum of 1 consecutive');
});

test('terminated employees are not provisioned; becoming eligible provisions them', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type);

    $employee = lppHire($policy, ['status' => 'inactive']);
    expect(lppBalance($employee, $type))->toBeNull();

    $employee->update(['status' => 'active']);

    expect((float) lppBalance($employee, $type)->base_days)->toBe(12.0);
});

test('assigning a different policy recalculates the base through the ledger', function () {
    $standard = lppPolicy('Standard');
    $senior = lppPolicy('Senior');
    $type = lppType();
    lppRule($standard, $type, ['fixed_days' => 12]);
    lppRule($senior, $type, ['fixed_days' => 18]);
    $employee = lppHire($standard);

    $employee->update(['leave_policy_id' => $senior->id]);
    $balance = lppBalance($employee, $type);

    expect((float) $balance->base_days)->toBe(18.0)
        // Old base reversed, new one posted: history kept, nothing edited.
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->whereNotNull('reverses_entry_id')->count())->toBe(1)
        ->and(AuditLog::where('action', 'leave.entitlement_recalculated')->where('subject_employee_id', $employee->id)->exists())->toBeTrue();
});

// ── Employee override ────────────────────────────────────────────────────────

test('an employee override adds to the policy entitlement without cloning the policy', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 20]);
    $employee = lppHire($policy);
    $hr = lppHr();

    $override = app(EmployeeLeaveOverrideService::class)->create($employee, $type, 'add', 5, 'Retention agreement', $hr, lppYear());

    expect((float) lppBalance($employee, $type)->base_days)->toBe(25.0)
        ->and(AuditLog::where('event', 'LEAVE_OVERRIDE_CREATED')->first()?->reason)->toBe('Retention agreement');

    app(EmployeeLeaveOverrideService::class)->revoke($override, $hr, 'Agreement ended');

    expect((float) lppBalance($employee, $type)->base_days)->toBe(20.0)
        ->and($override->fresh()->revoked_at)->not->toBeNull()
        ->and(EmployeeLeaveOverride::count())->toBe(1);
});

test('a "set" override replaces the entitlement, and needs a reason', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 20]);
    $employee = lppHire($policy);

    expect(fn () => app(EmployeeLeaveOverrideService::class)->create($employee, $type, 'set', 15, ' ', lppHr(), lppYear()))
        ->toThrow(DomainException::class);

    app(EmployeeLeaveOverrideService::class)->create($employee, $type, 'set', 15, 'Contracted 15 days', lppHr(), lppYear());

    expect((float) lppBalance($employee, $type)->base_days)->toBe(15.0);
});

// ── New-year carry-only rows (section 17) ────────────────────────────────────

test('a balance that only holds carry forward still receives its base entitlement', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 20]);
    $employee = lppHire($policy, ['status' => 'inactive']);

    // The new year's row was created by carry forward alone.
    $balance = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => lppYear()->id,
        'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0,
    ]);
    $balance->forceFill(['ledger_status' => 'safe', 'ledger_migrated_at' => now()])->save();
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 5, lppYear()->starts_on, 'test:cf:'.$balance->id);
    app(LeaveLedgerService::class)->rebuild($balance);

    $employee->update(['status' => 'active']);
    $after = lppBalance($employee, $type);

    expect((float) $after->base_days)->toBe(20.0)
        ->and((float) $after->carried_forward_days)->toBe(5.0)
        ->and((float) $after->allocated_days)->toBe(25.0);
});

test('a balance holding an undecomposed opening balance is sent to HR review, not topped up', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 20]);
    $employee = lppHire($policy, ['status' => 'inactive']);
    $balance = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => lppYear()->id,
        'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0,
    ]);
    $balance->forceFill(['ledger_status' => 'needs_hr_review', 'ledger_migrated_at' => now()])->save();
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_OPENING, 22, lppYear()->starts_on, 'test:opening:'.$balance->id);

    $employee->forceFill(['status' => 'active'])->saveQuietly();
    $row = lppEnsure()->ensureType($employee->fresh(), $type, lppYear());

    expect($row['status'])->toBe(EnsureEmployeeLeaveBalancesService::NEEDS_REVIEW)
        ->and(lppBaseEntries($employee, $type))->toBe(0);
});

// ── Detection and bulk ───────────────────────────────────────────────────────

test('missing balances are detected, previewed without writing, and provisioned on apply', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 12]);

    // Rows bypassing the observer: a record imported without provisioning.
    $employee = Employee::withoutEvents(fn () => lppHire($policy));

    expect(lppEnsure()->missing(lppYear(), [$employee])->where('leave_type_id', $type->id)->first()['status'])
        ->toBe(EnsureEmployeeLeaveBalancesService::PROVISIONED);

    $this->artisan('leave:ensure-balances', ['--employee' => $employee->id])->assertSuccessful();
    expect(lppBalance($employee, $type))->toBeNull();

    $this->artisan('leave:ensure-balances', ['--employee' => $employee->id, '--apply' => true])->assertSuccessful();
    expect((float) lppBalance($employee, $type)->base_days)->toBe(12.0);
});

test('bulk provisioning previews first and reports the expected changes', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 12]);
    $missing = Employee::withoutEvents(fn () => collect([lppHire($policy), lppHire($policy)]));
    $done = lppHire($policy);

    $preview = lppEnsure()->bulk($missing->push($done), lppYear(), lppHr(), dryRun: true);

    expect($preview['summary']['selected'])->toBe(3)
        ->and($preview['rows']->where('leave_type_id', $type->id)->where('status', 'provisioned')->count())->toBe(2)
        ->and($preview['rows']->where('leave_type_id', $type->id)->where('status', 'already_provisioned')->count())->toBe(1)
        ->and(LeaveLedgerEntry::where('leave_type_id', $type->id)->count())->toBe(1);

    lppEnsure()->bulk($missing, lppYear(), lppHr(), dryRun: false);
    lppEnsure()->bulk($missing, lppYear(), lppHr(), dryRun: false);

    expect(LeaveLedgerEntry::where('leave_type_id', $type->id)->where('entry_type', 'base_entitlement')->count())->toBe(3);
});

// ── Accrual ──────────────────────────────────────────────────────────────────

test('monthly accrual adds only its own credit and never wipes other buckets', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['fixed_days' => 10]);
    $employee = lppHire($policy);
    $balance = lppBalance($employee, $type);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 2, lppYear()->starts_on, 'test:cf:'.$balance->id);
    app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 3, Carbon::parse('2026-08-03'), 'test:use:'.$balance->id);
    app(LeaveLedgerService::class)->rebuild($balance);

    // Switch the type to monthly accrual of 1.5 under this policy.
    LeavePolicyRule::where('leave_type_id', $type->id)->update(['accrual_method' => 'monthly', 'accrual_amount' => 1.5]);

    app(LeaveAccrualService::class)->run(2026, 10);
    $after = $balance->fresh();

    expect((float) $after->base_days)->toBe(10.0)
        ->and((float) $after->carried_forward_days)->toBe(2.0)
        ->and((float) $after->used_days)->toBe(3.0)
        ->and((float) $after->accrued_days)->toBe(1.5);
});

test('running the same month twice accrues once', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['accrual_method' => 'monthly', 'accrual_amount' => 1.5, 'fixed_days' => null]);
    $employee = lppHire($policy);

    app(LeaveService::class)->accrueMonthly(2026, 10);
    app(LeaveService::class)->accrueMonthly(2026, 10);

    expect((float) lppBalance($employee, $type)->accrued_days)->toBe(1.5)
        ->and(LeaveAccrualLog::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->count())->toBe(1);
});

test('accrual respects joining date, the joining-month rule, on-leave status and the cap', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['accrual_method' => 'monthly', 'accrual_amount' => 2, 'fixed_days' => null, 'max_accumulation' => 3]);

    $lateJoiner = lppHire($policy, ['joining_date' => '2026-10-20']);
    $onLeave = lppHire($policy, ['status' => 'on-leave']);

    $accrual = app(LeaveAccrualService::class);
    $accrual->run(2026, 9);   // before the late joiner joined
    $accrual->run(2026, 10);  // the late joiner's joining month (after the 15th)
    $accrual->run(2026, 11);

    expect((float) lppBalance($lateJoiner, $type)->accrued_days)->toBe(2.0)
        // 2 + 1 (capped at 3) + 0.
        ->and((float) lppBalance($onLeave, $type)->accrued_days)->toBe(3.0);
});

test('quarterly accrual credits only in the first month of each leave-year quarter', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['accrual_method' => 'quarterly', 'accrual_amount' => 3, 'fixed_days' => null]);
    $employee = lppHire($policy);

    foreach ([[2026, 7], [2026, 8], [2026, 9], [2026, 10]] as [$y, $m]) {
        app(LeaveAccrualService::class)->run($y, $m);
    }

    expect((float) lppBalance($employee, $type)->accrued_days)->toBe(6.0);
});

test('accrual lands in the leave year of the month: June credits 2025/26, July credits 2026/27', function () {
    $policy = lppPolicy();
    $type = lppType();
    lppRule($policy, $type, ['accrual_method' => 'monthly', 'accrual_amount' => 1, 'fixed_days' => null]);
    $employee = lppHire($policy);

    app(LeaveAccrualService::class)->run(2026, 6);
    app(LeaveAccrualService::class)->run(2026, 7);

    expect((float) lppBalance($employee, $type, '2025/26')->accrued_days)->toBe(1.0)
        ->and((float) lppBalance($employee, $type, '2026/27')->accrued_days)->toBe(1.0);
});
