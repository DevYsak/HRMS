<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveCreditConsumption;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveLedgerBackfillService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveYearLifecycleService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 2A — the leave balance ledger foundation.
 *
 * Fixed clock: 15 August 2026, inside leave year 2026/27 (1 Jul – 30 Jun).
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-15 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
});

function llYear(string $label): LeaveYear
{
    return LeaveYear::where('label', $label)->firstOrFail();
}

function llType(array $attributes = []): LeaveType
{
    return LeaveType::create(array_merge([
        'name' => 'Casual Leave', 'code' => 'CL'.random_int(1000, 9999), 'category' => 'other',
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#10b981',
    ], $attributes));
}

function llEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        // Settled staff: accrual starts from the joining month.
        'joining_date' => '2024-01-10',
    ]);
}

function llHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

/** A clean ledger-backed balance: a legacy row with no history migrates SAFE as base entitlement. */
function llBalance(Employee $employee, LeaveType $type, string $year = '2026/27', float $allocated = 20): LeaveBalance
{
    $leaveYear = llYear($year);
    $balance = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => $leaveYear->legacyYear(), 'leave_year_id' => $leaveYear->id,
        'allocated_days' => $allocated, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0,
    ]);

    expect(app(LeaveLedgerBackfillService::class)->migrateIfSafe($balance))->toBeTrue();

    return $balance->fresh();
}

function llLedger(): LeaveLedgerService
{
    return app(LeaveLedgerService::class);
}

function llSummary(LeaveBalance $balance): array
{
    return app(LeaveBalanceCalculator::class)->summary($balance->fresh());
}

function llRequest(Employee $employee, LeaveType $type, string $date, float $days = 1, string $status = 'pending'): LeaveRequest
{
    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $date, 'end_date' => $date, 'days' => $days,
        'reason' => 'Personal', 'requested_leave_status' => 'paid', 'status' => $status,
    ]);
}

function llDecide(LeaveRequest $request, string $status, User $reviewer): LeaveRequest
{
    return app(LeaveService::class)->reviewRequest($request, [
        'leave_type_id' => $request->leave_type_id,
        'start_date' => $request->start_date->toDateString(),
        'end_date' => $request->end_date->toDateString(),
        'reason' => $request->reason,
        'is_half_day' => false,
    ], $status, $reviewer->id, $status === 'rejected' ? 'Not this time' : null);
}

// ── Posting ──────────────────────────────────────────────────────────────────

test('posting the same movement twice is blocked by its idempotency key', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 0);

    $first = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 2, today(), 'test:grant:1');
    $second = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 2, today(), 'test:grant:1');
    llLedger()->rebuild($balance);

    expect($second->id)->toBe($first->id)
        ->and(LeaveLedgerEntry::where('idempotency_key', 'test:grant:1')->count())->toBe(1)
        ->and((float) $balance->fresh()->add_on_days)->toBe(2.0);
});

test('an entry is reversed by a new opposite entry, and cannot be reversed twice', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 0);
    $grant = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 2, today(), 'test:grant:rev');

    $reversal = llLedger()->reverse($grant, 'Granted in error');
    llLedger()->rebuild($balance);

    expect($reversal->reverses_entry_id)->toBe($grant->id)
        ->and((float) $reversal->days)->toBe(-2.0)
        ->and((float) $balance->fresh()->add_on_days)->toBe(0.0)
        ->and(LeaveLedgerEntry::count())->toBe(LeaveLedgerEntry::where('id', '<=', $reversal->id)->count());

    expect(fn () => llLedger()->reverse($grant, 'Again'))->toThrow(DomainException::class, 'already been reversed');
});

test('ledger entries and consumptions cannot be edited or deleted through the application', function () {
    $balance = llBalance(llEmployee(), llType());
    $entry = LeaveLedgerEntry::where('employee_id', $balance->employee_id)->firstOrFail();

    expect(fn () => $entry->update(['days' => 99]))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'cannot be deleted');

    $debit = llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 1, today(), 'test:use:imm');
    $consumption = LeaveCreditConsumption::where('debit_entry_id', $debit->id)->firstOrFail();

    expect(fn () => $consumption->delete())->toThrow(LogicException::class);
});

test('a ledger-backed balance cannot have its figures written directly', function () {
    $balance = llBalance(llEmployee(), llType());

    expect(fn () => $balance->update(['allocated_days' => 99]))->toThrow(LogicException::class, 'ledger-backed');
});

// ── Consumption ──────────────────────────────────────────────────────────────

test('leave consumes carry forward before base entitlement, and only the unused carry forward expires', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 20);
    $cf = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 3, Carbon::parse('2026-07-01'), 'test:cf:1', ['expires_on' => '2026-09-30']);

    llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 2, Carbon::parse('2026-08-12'), 'test:use:aug');

    expect(llLedger()->remaining($cf))->toBe(1.0);

    llLedger()->expireCredit($cf, Carbon::parse('2026-09-30'));
    $after = llLedger()->rebuild($balance);

    expect((float) $after->expired_days)->toBe(1.0)
        ->and((float) $after->base_days)->toBe(20.0)
        ->and((float) $after->used_days)->toBe(2.0)
        ->and(llSummary($after)['approved_available'])->toBe(20.0);
});

test('add-on leave is consumed before base entitlement', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 20);
    $addOn = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 2, today(), 'test:addon:1', ['expires_on' => '2026-12-31']);

    llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 1, today(), 'test:use:1');

    expect(llLedger()->remaining($addOn))->toBe(1.0);
});

test('the earliest-expiring lot is consumed first, carry forward ahead of add-on', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 0);
    $novAddOn = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 1, today(), 'test:addon:nov', ['expires_on' => '2026-11-30']);
    $octAddOn = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 1, today(), 'test:addon:oct', ['expires_on' => '2026-10-31']);
    $cf = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 1, Carbon::parse('2026-07-01'), 'test:cf:x', ['expires_on' => '2026-12-31']);

    llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 2, today(), 'test:use:2');

    expect(llLedger()->remaining($cf))->toBe(0.0)          // carry forward first, despite its later expiry
        ->and(llLedger()->remaining($octAddOn))->toBe(0.0) // then the earliest-expiring add-on
        ->and(llLedger()->remaining($novAddOn))->toBe(1.0);
});

test('reversing usage returns the days to the exact lots they came from', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 20);
    $cf = llLedger()->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 3, Carbon::parse('2026-07-01'), 'test:cf:r', ['expires_on' => '2026-09-30']);
    $usage = llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 2, today(), 'test:use:r');

    expect(llLedger()->remaining($cf))->toBe(1.0);

    llLedger()->reverse($usage, 'Cancelled');

    expect(llLedger()->remaining($cf))->toBe(3.0);
});

test('usage beyond every lot is recorded unbacked and the real balance goes negative, unfloored', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 2);

    $debit = llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 3, today(), 'test:over');
    $after = llLedger()->rebuild($balance);

    expect((float) LeaveCreditConsumption::where('debit_entry_id', $debit->id)->whereNull('credit_entry_id')->sum('days'))->toBe(1.0)
        ->and(llSummary($after)['approved_available'])->toBe(-1.0)
        ->and($after->realAvailable())->toBe(-1.0)
        ->and($after->available())->toBe(0.0); // legacy display value only
});

test('the summary row always equals the sum of the ledger', function () {
    $balance = llBalance(llEmployee(), llType(), allocated: 20);
    llLedger()->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 3, Carbon::parse('2026-07-01'), 'test:s:cf');
    llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, 1.5, Carbon::parse('2026-08-01'), 'test:s:acc');
    llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 2, today(), 'test:s:addon');
    llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 4, today(), 'test:s:use');
    llLedger()->debit($balance, LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT, 0.5, today(), 'test:s:debit');
    $after = llLedger()->rebuild($balance);

    $ledgerNet = (float) LeaveLedgerEntry::where('employee_id', $balance->employee_id)
        ->where('leave_type_id', $balance->leave_type_id)->where('leave_year_id', $balance->leave_year_id)->sum('days');

    expect(llSummary($after)['approved_available'])->toBe(round($ledgerNet, 2))
        ->and($after->realAvailable())->toBe(22.0)
        ->and((float) $after->base_days)->toBe(20.0)
        ->and((float) $after->carried_forward_days)->toBe(3.0)
        ->and((float) $after->accrued_days)->toBe(1.5)
        ->and((float) $after->add_on_days)->toBe(2.0)
        ->and((float) $after->adjustment_debit_days)->toBe(0.5)
        ->and((float) $after->used_days)->toBe(4.0);
});

// ── Pending, approval, rejection, cancellation ───────────────────────────────

test('a pending request reduces available-to-request only', function () {
    $employee = llEmployee();
    $type = llType();
    $balance = llBalance($employee, $type, allocated: 12);
    llRequest($employee, $type, '2026-09-09', 3);

    $summary = llSummary($balance);

    expect($summary['approved_available'])->toBe(12.0)
        ->and($summary['pending'])->toBe(3.0)
        ->and($summary['available_to_request'])->toBe(9.0)
        ->and((float) $balance->fresh()->used_days)->toBe(0.0);
});

test('approval turns the reservation into usage; rejection just releases it', function () {
    $employee = llEmployee();
    $type = llType();
    $balance = llBalance($employee, $type, allocated: 12);
    $approved = llRequest($employee, $type, '2026-09-09', 1);
    $rejected = llRequest($employee, $type, '2026-09-16', 1);
    $hr = llHr();

    llDecide($approved, 'approved', $hr);
    llDecide($rejected, 'rejected', $hr);

    $summary = llSummary($balance);

    expect($summary['used'])->toBe(1.0)
        ->and($summary['pending'])->toBe(0.0)
        ->and($summary['approved_available'])->toBe(11.0)
        ->and($summary['available_to_request'])->toBe(11.0)
        ->and(LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $approved->id)->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->count())->toBe(1)
        ->and(LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $rejected->id)->exists())->toBeFalse();
});

test('a new request cannot be accepted against days other pending requests already reserve', function () {
    $employee = llEmployee();
    $type = llType();
    llBalance($employee, $type, allocated: 2);
    llRequest($employee, $type, '2026-09-09', 2);

    expect(fn () => app(LeaveService::class)->submitRequest($employee, $type, '2026-09-16', '2026-09-16', 'Another day'))
        ->toThrow(DomainException::class, 'Insufficient balance');
});

test('cancelling leave reverses usage in the leave year it was taken, not today\'s', function () {
    $this->travelTo(Carbon::parse('2026-06-25 10:00:00'));
    $employee = llEmployee();
    $type = llType();
    $june = llBalance($employee, $type, '2025/26', 10);
    $july = llBalance($employee, $type, '2026/27', 10);
    $request = llDecide(llRequest($employee, $type, '2026-06-30', 1), 'approved', llHr());

    expect((float) $june->fresh()->used_days)->toBe(1.0);

    // Cancelled after the year turned.
    $this->travelTo(Carbon::parse('2026-07-03 10:00:00'));
    app(LeaveService::class)->cancelRequest($request->fresh());

    expect((float) $june->fresh()->used_days)->toBe(0.0)
        ->and((float) $july->fresh()->used_days)->toBe(0.0)
        ->and((float) $july->fresh()->allocated_days)->toBe(10.0);
});

test('30 June belongs to 2025/26 and 1 July to 2026/27', function () {
    $resolver = app(LeaveYearResolver::class);

    expect($resolver->forDate(Carbon::parse('2026-06-30'))->label)->toBe('2025/26')
        ->and($resolver->forDate(Carbon::parse('2026-07-01'))->label)->toBe('2026/27')
        ->and($resolver->legacyYearFor(Carbon::parse('2026-06-30')))->toBe(2025)
        ->and($resolver->legacyYearFor(Carbon::parse('2026-07-01')))->toBe(2026);
});

test('leave on 30 June and on 1 July land in different leave years', function () {
    $this->travelTo(Carbon::parse('2026-06-20 10:00:00'));
    $employee = llEmployee();
    $type = llType();
    $old = llBalance($employee, $type, '2025/26', 5);
    $new = llBalance($employee, $type, '2026/27', 5);
    $hr = llHr();

    llDecide(llRequest($employee, $type, '2026-06-30', 1), 'approved', $hr);
    llDecide(llRequest($employee, $type, '2026-07-01', 1), 'approved', $hr);

    expect((float) $old->fresh()->used_days)->toBe(1.0)
        ->and((float) $new->fresh()->used_days)->toBe(1.0);
});

// ── Accrual ──────────────────────────────────────────────────────────────────

test('monthly accrual only adds; it never resets base, carry forward or usage', function () {
    $employee = llEmployee();
    $type = llType(['is_monthly_accrual' => true, 'accrual_days_per_month' => 1.5]);
    $balance = llBalance($employee, $type, allocated: 10);
    llLedger()->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 2, Carbon::parse('2026-07-01'), 'test:acc:cf');
    llLedger()->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 3, Carbon::parse('2026-07-20'), 'test:acc:use');
    llLedger()->rebuild($balance);

    app(LeaveService::class)->accrueMonthly(2026, 8);
    $after = $balance->fresh();

    expect((float) $after->base_days)->toBe(10.0)
        ->and((float) $after->carried_forward_days)->toBe(2.0)
        ->and((float) $after->used_days)->toBe(3.0)
        ->and((float) $after->accrued_days)->toBe(1.5)
        ->and($after->realAvailable())->toBe(10.5);
});

test('running the accrual twice for the same month credits it once', function () {
    $employee = llEmployee();
    $type = llType(['is_monthly_accrual' => true, 'accrual_days_per_month' => 1.5]);
    $balance = llBalance($employee, $type, allocated: 0);

    app(LeaveService::class)->accrueMonthly(2026, 8);
    app(LeaveService::class)->accrueMonthly(2026, 8);

    expect((float) $balance->fresh()->accrued_days)->toBe(1.5)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)->count())->toBe(1);
});

test('accrual on a legacy (un-migrated) balance adds without zeroing anything', function () {
    $employee = llEmployee();
    $type = llType(['is_monthly_accrual' => true, 'accrual_days_per_month' => 1.5]);
    $year = llYear('2026/27');
    // A manual adjustment makes the row ambiguous, so it stays legacy.
    $balance = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => $year->id,
        'allocated_days' => 12, 'used_days' => 4, 'carried_forward_days' => 2, 'encashed_days' => 0,
    ]);
    LeaveBalanceAdjustment::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'action' => 'credit', 'source' => 'manual',
        'days' => 1, 'previous_balance' => 11, 'new_balance' => 12, 'reason' => 'Old HR credit',
        'adjusted_by' => llHr()->id, 'adjusted_at' => now(),
    ]);

    app(LeaveService::class)->accrueMonthly(2026, 8);
    $after = $balance->fresh();

    expect($after->isLedgerBacked())->toBeFalse()
        ->and((float) $after->allocated_days)->toBe(13.5)
        ->and((float) $after->used_days)->toBe(4.0)
        ->and((float) $after->carried_forward_days)->toBe(2.0);
});

// ── HR adjustments ───────────────────────────────────────────────────────────

test('HR add-on leave is its own expiring bucket, audited with before and after', function () {
    $employee = llEmployee();
    $type = llType();
    $balance = llBalance($employee, $type, allocated: 12);
    $hr = llHr();
    $this->actingAs($hr);

    app(LeaveBalanceService::class)->adjust($employee, $type, 'credit', 2, 'Management approved', '', $hr, 2026,
        category: LeaveBalanceService::CATEGORY_ADD_ON, addOnType: 'management_grant', expiresOn: Carbon::parse('2026-12-31'));

    $after = $balance->fresh();
    $entry = LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_ADD_ON)->where('employee_id', $employee->id)->firstOrFail();
    $log = AuditLog::where('event', 'LEAVE_ADD_ON_GRANTED')->latest('id')->firstOrFail();

    expect((float) $after->add_on_days)->toBe(2.0)
        ->and((float) $after->base_days)->toBe(12.0)
        ->and($entry->expires_on->toDateString())->toBe('2026-12-31')
        ->and($log->user_id)->toBe($hr->id)
        ->and((float) $log->old_values['available'])->toBe(12.0)
        ->and((float) $log->new_values['available'])->toBe(14.0);
});

test('set correct balance posts the difference instead of overwriting', function () {
    $employee = llEmployee();
    $type = llType();
    $balance = llBalance($employee, $type, allocated: 11);

    $adjustment = app(LeaveBalanceService::class)->setCorrectBalance($employee, $type, 8, 'Payroll reconciliation', '', llHr(), 2026);

    expect($adjustment->action)->toBe('debit')
        ->and((float) $adjustment->days)->toBe(3.0)
        ->and($adjustment->category)->toBe(LeaveBalanceService::CATEGORY_CORRECTION)
        ->and((float) $balance->fresh()->adjustment_debit_days)->toBe(3.0)
        ->and($balance->fresh()->realAvailable())->toBe(8.0);
});

// ── Backfill ─────────────────────────────────────────────────────────────────

test('a balance whose history fully accounts for it migrates SAFE into proper buckets', function () {
    $employee = llEmployee();
    $type = llType();
    $year = llYear('2026/27');
    $request = llRequest($employee, $type, '2026-08-05', 2, 'approved');
    $balance = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => $year->id,
        'allocated_days' => 23, 'used_days' => 2, 'carried_forward_days' => 3, 'encashed_days' => 0,
    ]);

    $row = app(LeaveLedgerBackfillService::class)->classify($balance);
    expect($row['classification'])->toBe('SAFE');

    app(LeaveLedgerBackfillService::class)->apply(['employee_id' => $employee->id], llHr());
    $after = $balance->fresh();

    expect($after->isLedgerBacked())->toBeTrue()
        ->and($after->ledger_status)->toBe(LeaveBalance::LEDGER_SAFE)
        ->and((float) $after->base_days)->toBe(20.0)
        ->and((float) $after->carried_forward_days)->toBe(3.0)
        ->and((float) $after->used_days)->toBe(2.0)
        ->and((float) $after->allocated_days)->toBe(23.0)
        ->and(LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->exists())->toBeTrue();
});

test('an ambiguous balance is preserved as an opening balance flagged for HR, never decomposed', function () {
    $employee = llEmployee();
    $type = llType();
    $year = llYear('2026/27');
    $balance = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => $year->id,
        'allocated_days' => 25, 'used_days' => 0, 'carried_forward_days' => 3, 'encashed_days' => 0,
    ]);
    LeaveBalanceAdjustment::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'action' => 'credit', 'source' => 'manual',
        'days' => 2, 'previous_balance' => 23, 'new_balance' => 25, 'reason' => 'Unknown purpose',
        'adjusted_by' => llHr()->id, 'adjusted_at' => now(),
    ]);
    $backfill = app(LeaveLedgerBackfillService::class);

    expect($backfill->classify($balance)['classification'])->toBe('NEEDS_HR_REVIEW')
        ->and($backfill->migrateIfSafe($balance))->toBeFalse();

    // Not migrated unless asked.
    $backfill->apply(['employee_id' => $employee->id], llHr());
    expect($balance->fresh()->isLedgerBacked())->toBeFalse();

    $backfill->apply(['employee_id' => $employee->id], llHr(), includeReview: true);
    $after = $balance->fresh();

    expect($after->ledger_status)->toBe(LeaveBalance::LEDGER_NEEDS_HR_REVIEW)
        ->and((float) $after->opening_days)->toBe(22.0)
        ->and((float) $after->carried_forward_days)->toBe(3.0)
        ->and((float) $after->base_days)->toBe(0.0)
        ->and((float) $after->allocated_days)->toBe(25.0)
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_OPENING)->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('a balance that cannot be represented honestly is BLOCKED and never migrated', function () {
    $employee = llEmployee();
    $type = llType();
    $year = llYear('2026/27');
    $balance = LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => $year->id,
        'allocated_days' => 2, 'used_days' => 0, 'carried_forward_days' => 5, 'encashed_days' => 0,
    ]);

    $result = app(LeaveLedgerBackfillService::class)->apply(['employee_id' => $employee->id], llHr(), includeReview: true);

    expect(app(LeaveLedgerBackfillService::class)->classify($balance)['classification'])->toBe('BLOCKED')
        ->and($result['blocked'])->toBe(1)
        ->and($balance->fresh()->isLedgerBacked())->toBeFalse();
});

test('the backfill preview changes nothing', function () {
    $employee = llEmployee();
    $type = llType();
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'leave_year_id' => llYear('2026/27')->id,
        'allocated_days' => 20, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0,
    ]);

    $this->artisan('leave:ledger-backfill', ['--employee' => $employee->id])->assertSuccessful();

    // Only the legacy row is in question; hiring may have provisioned other
    // types straight onto the ledger.
    expect(LeaveLedgerEntry::where('leave_type_id', $type->id)->count())->toBe(0)
        ->and(LeaveBalance::where('leave_type_id', $type->id)->whereNotNull('ledger_migrated_at')->count())->toBe(0);
});

// ── Leave-year lifecycle ─────────────────────────────────────────────────────

test('a closed leave year refuses ordinary postings', function () {
    $balance = llBalance(llEmployee(), llType(), '2025/26', 5);
    llYear('2025/26')->forceFill(['is_closed' => true])->save();

    expect(fn () => llLedger()->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, 1, Carbon::parse('2026-06-01'), 'test:closed'))
        ->toThrow(DomainException::class, 'is closed');
});

test('a leave year cannot close while it has unmigrated or review-pending balances', function () {
    $employee = llEmployee();
    $type = llType();
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2025, 'leave_year_id' => llYear('2025/26')->id,
        'allocated_days' => 10, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0,
    ]);

    $blockers = app(LeaveYearLifecycleService::class)->blockersToClose(llYear('2025/26'));

    expect(implode(' ', $blockers))->toContain('not on the leave ledger')
        ->and(fn () => app(LeaveYearLifecycleService::class)->close(llYear('2025/26'), llHr(), 'Year end'))->toThrow(DomainException::class);
});
