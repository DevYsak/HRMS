<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveRolloverRecord;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Notifications\LeaveExpiringNotification;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveExpiryService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveReconciliationService;
use App\Services\Leave\LeaveRolloverService;
use App\Services\Leave\LeaveYearLifecycleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 2C — carry forward, expiry, the 1 July rollover and reconciliation.
 *
 * Clock: 1 July 2026, the first day of leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-07-01 02:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    User::factory()->create(['role' => UserRole::SuperAdmin]);
});

function lroYear(string $label): LeaveYear
{
    return LeaveYear::where('label', $label)->firstOrFail();
}

function lroPolicy(): LeavePolicy
{
    return LeavePolicy::create([
        'name' => 'Rollover '.random_int(100, 999), 'statutory_weeks' => 5.6, 'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional', 'irregular_accrual_rate' => 0.1207, 'is_default' => false, 'is_active' => true,
    ]);
}

/** A carry-forward type with a 20-day policy rule; carry capped at 5, expiring 30 Sep. */
function lroType(LeavePolicy $policy, string $mode = 'automatic', array $rule = []): LeaveType
{
    $type = LeaveType::create([
        'name' => 'Privilege Leave', 'code' => 'P'.random_int(10000, 99999), 'category' => 'other',
        'is_paid' => true, 'allow_paid_request' => true, 'allow_carry_forward' => true, 'carry_forward_mode' => $mode,
    ]);

    LeavePolicyRule::create(array_merge([
        'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
        'entitlement_method' => 'fixed_days', 'fixed_days' => 20, 'accrual_method' => 'annual_upfront',
        'carry_forward_enabled' => true, 'carry_forward_max_days' => 5, 'carry_forward_expiry_date' => '09-30',
    ], $rule));

    return $type;
}

function lroHire(LeavePolicy $policy, bool $provision = true): Employee
{
    $make = fn () => Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'leave_policy_id' => $policy->id, 'joining_date' => '2023-03-01',
        'working_pattern' => 'regular', 'working_days_per_week' => 5,
    ]);

    return $provision ? $make() : Employee::withoutEvents($make);
}

/** The closing year: base 20, $used days taken. */
function lroClosingYear(Employee $employee, LeaveType $type, float $used): LeaveBalance
{
    app(EnsureEmployeeLeaveBalancesService::class)->ensureType($employee, $type, lroYear('2025/26'));
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2025)->firstOrFail();

    if ($used > 0) {
        app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_USAGE, $used, Carbon::parse('2026-03-02'), 'test:used:'.$balance->id);
        app(LeaveLedgerService::class)->rebuild($balance);
    }

    return $balance->fresh();
}

function lroRow(Employee $employee, LeaveType $type): array
{
    return app(LeaveRolloverService::class)->preview(lroYear('2025/26'))
        ->first(fn ($r) => $r['employee_id'] === $employee->id && $r['leave_type_id'] === $type->id);
}

function lroNew(Employee $employee, LeaveType $type): LeaveBalance
{
    return LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
}

// ── Carry forward and the new year ───────────────────────────────────────────

test('closing 7 with a 5-day cap carries 5, expires 2, and the new year opens at base 20 + carry 5', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy);
    $closing = lroClosingYear($employee, $type, 13);

    $row = lroRow($employee, $type);
    expect($row['status'])->toBe('SAFE')
        ->and($row['closing'])->toBe(7.0)
        ->and($row['carry'])->toBe(5.0)
        ->and($row['expire'])->toBe(2.0)
        ->and($row['new_base'])->toBe(20.0)
        ->and($row['new_opening'])->toBe(25.0)
        ->and($row['expires_on'])->toBe('2026-09-30');

    app(LeaveRolloverService::class)->process(lroYear('2025/26'), lroYear('2026/27'), null);

    $old = $closing->fresh();
    $new = lroNew($employee, $type);

    expect((float) $old->expired_days)->toBe(2.0)
        ->and(app(LeaveBalanceCalculator::class)->summary($old)['approved_available'])->toBe(5.0)
        // Two separate buckets — carry forward never stands in for the base.
        ->and((float) $new->base_days)->toBe(20.0)
        ->and((float) $new->carried_forward_days)->toBe(5.0)
        ->and((float) $new->allocated_days)->toBe(25.0)
        ->and(LeaveLedgerEntry::where('leave_year_id', lroYear('2026/27')->id)->where('employee_id', $employee->id)
            ->where('entry_type', 'carry_forward')->first()->expires_on->toDateString())->toBe('2026-09-30')
        ->and(AuditLog::where('event', 'LEAVE_ROLLOVER_PROCESSED')->where('subject_employee_id', $employee->id)->exists())->toBeTrue();
});

test('a new-year row created by carry forward alone still gets its base entitlement', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    // Not provisioned on hire: the new year's row will first exist through carry forward.
    $employee = lroHire($policy, provision: false);
    lroClosingYear($employee, $type, 10);

    app(LeaveRolloverService::class)->process(lroYear('2025/26'), lroYear('2026/27'), null);
    $new = lroNew($employee, $type);

    expect((float) $new->carried_forward_days)->toBe(5.0)
        ->and((float) $new->base_days)->toBe(20.0)
        ->and((float) $new->allocated_days)->toBe(25.0);
});

test('re-running the rollover changes nothing', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy);
    lroClosingYear($employee, $type, 13);
    $service = app(LeaveRolloverService::class);

    $service->process(lroYear('2025/26'), lroYear('2026/27'), null);
    $second = $service->process(lroYear('2025/26'), lroYear('2026/27'), null);

    expect($second['processed'])->toBe(0)
        ->and($second['already'])->toBeGreaterThanOrEqual(1)
        ->and(LeaveCarryForwardTransaction::where('employee_id', $employee->id)->count())->toBe(1)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('entry_type', 'base_entitlement')->where('leave_year_id', lroYear('2026/27')->id)->count())->toBe(1)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('entry_type', 'expiry')->count())->toBe(1)
        ->and((float) lroNew($employee, $type)->allocated_days)->toBe(25.0)
        ->and(lroRow($employee, $type)['status'])->toBe('PROCESSED');
});

test('carry forward that is an HR decision goes to review and is processed only when HR states the amount', function () {
    $policy = lroPolicy();
    $type = lroType($policy, mode: 'hr_approval', rule: ['carry_forward_enabled' => null]);
    $type->update(['allow_carry_forward' => true]);
    $employee = lroHire($policy);
    $closing = lroClosingYear($employee, $type, 13);

    expect(lroRow($employee, $type)['status'])->toBe('NEEDS_HR_REVIEW');

    $result = app(LeaveRolloverService::class)->process(lroYear('2025/26'), lroYear('2026/27'), null);
    expect($result['needs_review'])->toBe(1)
        ->and(LeaveCarryForwardTransaction::where('employee_id', $employee->id)->exists())->toBeFalse();

    app(LeaveRolloverService::class)->resolveReview($closing, 3, User::factory()->create(['role' => UserRole::HrAdmin]), 'Agreed with line manager');

    expect((float) lroNew($employee, $type)->carried_forward_days)->toBe(3.0)
        ->and((float) $closing->fresh()->expired_days)->toBe(4.0)
        ->and(LeaveRolloverRecord::where('employee_id', $employee->id)->value('status'))->toBe('processed');
});

test('pending requests in the closing year hold the row for HR review', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy);
    lroClosingYear($employee, $type, 13);
    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-06-29', 'end_date' => '2026-06-29',
        'days' => 1, 'reason' => 'x', 'requested_leave_status' => 'paid', 'status' => 'pending',
    ]);

    expect(lroRow($employee, $type)['status'])->toBe('NEEDS_HR_REVIEW')
        ->and(lroRow($employee, $type)['reason'])->toContain('await a decision');
});

test('a leave year cannot close until its rollover is complete', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy);
    lroClosingYear($employee, $type, 13);
    $lifecycle = app(LeaveYearLifecycleService::class);

    expect(implode(' ', $lifecycle->blockersToClose(lroYear('2025/26'))))->toContain('rollover');

    app(LeaveRolloverService::class)->process(lroYear('2025/26'), lroYear('2026/27'), null);

    expect(implode(' ', $lifecycle->blockersToClose(lroYear('2025/26'))))->not->toContain('rollover');
});

test('the rollover command previews without writing and applies with --apply', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy);
    lroClosingYear($employee, $type, 13);

    $this->artisan('leave:rollover', ['--from' => '2025/26'])->assertSuccessful();
    expect(LeaveRolloverRecord::count())->toBe(0);

    $this->artisan('leave:rollover', ['--from' => '2025/26', '--apply' => true])->assertSuccessful();
    expect((float) lroNew($employee, $type)->allocated_days)->toBe(25.0);
});

// ── Expiry ───────────────────────────────────────────────────────────────────

test('only the unused part of carry forward expires, on its expiry date, once', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy);
    lroClosingYear($employee, $type, 13);
    app(LeaveRolloverService::class)->process(lroYear('2025/26'), lroYear('2026/27'), null);
    $new = lroNew($employee, $type);

    // 3 days taken in August come out of the carry forward first.
    app(LeaveLedgerService::class)->debit($new, LeaveLedgerEntry::TYPE_USAGE, 3, Carbon::parse('2026-08-10'), 'test:aug:'.$new->id);
    app(LeaveLedgerService::class)->rebuild($new);

    $this->travelTo(Carbon::parse('2026-10-01 00:30:00'));
    $first = app(LeaveExpiryService::class)->expireDue();
    $second = app(LeaveExpiryService::class)->expireDue();
    $after = $new->fresh();

    expect($first['expired_days'])->toBe(2.0)
        ->and($second['expired_lots'])->toBe(0)
        ->and((float) $after->expired_days)->toBe(2.0)
        ->and((float) $after->base_days)->toBe(20.0)
        ->and(app(LeaveBalanceCalculator::class)->summary($after)['approved_available'])->toBe(20.0)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', 'expiry')
            ->where('leave_year_id', lroYear('2026/27')->id)->first()->effective_date->toDateString())->toBe('2026-09-30');
});

test('employees are told 30 and 7 days before carry forward expires, once per window', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy);
    lroClosingYear($employee, $type, 13);
    app(LeaveRolloverService::class)->process(lroYear('2025/26'), lroYear('2026/27'), null);
    Notification::fake();

    $expiry = app(LeaveExpiryService::class);
    expect($expiry->notifyUpcoming(Carbon::parse('2026-08-31')))->toBe(1)
        ->and($expiry->notifyUpcoming(Carbon::parse('2026-09-23')))->toBe(1)
        ->and($expiry->notifyUpcoming(Carbon::parse('2026-09-10')))->toBe(0);

    Notification::assertSentTo($employee->user, LeaveExpiringNotification::class,
        fn ($n) => $n->days === 5.0 && $n->expiresOn === '2026-09-30');
});

// ── Reconciliation ───────────────────────────────────────────────────────────

test('the 2026/27 reconciliation dry run classifies without writing, and fixes only safe rows', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $year = lroYear('2026/27');

    // Missing balance (imported without provisioning).
    $missing = lroHire($policy, provision: false);
    // Carry-only new-year row.
    $carryOnly = lroHire($policy, provision: false);
    $row = LeaveBalance::create(['employee_id' => $carryOnly->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => $year->id,
        'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0]);
    $row->forceFill(['ledger_status' => 'safe', 'ledger_migrated_at' => now()])->save();
    app(LeaveLedgerService::class)->credit($row, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 4, $year->starts_on, 'test:cf:'.$row->id);
    app(LeaveLedgerService::class)->rebuild($row);
    // Base that disagrees with the policy.
    $mismatch = lroHire($policy, provision: false);
    LeaveBalance::create(['employee_id' => $mismatch->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => $year->id,
        'allocated_days' => 15, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0]);

    $before = LeaveLedgerEntry::count();
    $scan = app(LeaveReconciliationService::class)->scan($year, ['leave_type_id' => $type->id]);
    $for = fn (Employee $e) => $scan->firstWhere('employee_id', $e->id);

    expect(LeaveLedgerEntry::count())->toBe($before)
        ->and($for($missing)['classification'])->toBe('SAFE_AUTO_FIX')
        ->and($for($missing)['missing'])->toBeTrue()
        ->and($for($carryOnly)['classification'])->toBe('SAFE_AUTO_FIX')
        ->and($for($carryOnly)['carry_only'])->toBeTrue()
        ->and($for($mismatch)['classification'])->toBe('NEEDS_HR_REVIEW')
        ->and($for($mismatch)['expected_base'])->toBe(20.0);

    $result = app(LeaveReconciliationService::class)->reconcile($year, User::factory()->create(['role' => UserRole::HrAdmin]));

    expect($result['fixed'])->toBeGreaterThanOrEqual(2)
        ->and((float) lroNew($missing, $type)->base_days)->toBe(20.0)
        ->and((float) lroNew($carryOnly, $type)->base_days)->toBe(20.0)
        ->and((float) lroNew($carryOnly, $type)->carried_forward_days)->toBe(4.0)
        // The disputed base is left exactly as it was.
        ->and((float) lroNew($mismatch, $type)->allocated_days)->toBe(15.0);
});

test('duplicate balance rows are BLOCKED, never auto-fixed', function () {
    $policy = lroPolicy();
    $type = lroType($policy);
    $employee = lroHire($policy, provision: false);
    $year = lroYear('2026/27');
    LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => $year->id, 'allocated_days' => 20]);
    LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2027, 'leave_year_id' => $year->id, 'allocated_days' => 20]);

    $row = app(LeaveReconciliationService::class)->scan($year, ['employee_id' => $employee->id, 'leave_type_id' => $type->id])->first();

    expect($row['classification'])->toBe('BLOCKED')
        ->and($row['duplicate'])->toBeTrue();
});
