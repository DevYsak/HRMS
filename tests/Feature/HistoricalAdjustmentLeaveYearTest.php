<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\HistoricalAdjustmentYearMapper;
use App\Services\Leave\LeaveLedgerBackfillService;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * A historical-balance adjustment belongs to the leave year it states — by
 * leave_year_id, never by the date HR happened to enter it.
 *
 * Fixed clock: 15 August 2026, inside leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-15 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2024-07-01', 'ends_on' => '2025-06-30'], ['label' => '2024/25']);
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
});

function hayYear(string $label): LeaveYear
{
    return LeaveYear::where('label', $label)->firstOrFail();
}

function hayType(): LeaveType
{
    return LeaveType::create([
        'name' => 'Casual Leave', 'code' => 'CL'.random_int(1000, 9999), 'category' => 'other',
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#10b981',
    ]);
}

function hayEmployee(): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
    ]);
}

function hayHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

/** A plain legacy balance with nothing else recorded against it. */
function hayBalance(Employee $employee, LeaveType $type, string $label, float $allocated): LeaveBalance
{
    $year = hayYear($label);

    return LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => $year->legacyYear(), 'leave_year_id' => $year->id,
        'allocated_days' => $allocated, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0,
    ]);
}

/** A historical adjustment as it was written before leave_year_id existed. */
function hayLegacyHistoricalAdjustment(Employee $employee, LeaveType $type, ?array $auditValues): LeaveBalanceAdjustment
{
    $adjustment = LeaveBalanceAdjustment::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_year_id' => null,
        'action' => 'credit', 'source' => 'historical', 'days' => 8, 'previous_balance' => 0, 'new_balance' => 8,
        'reason' => 'Closing balance from the old system', 'adjusted_by' => hayHr()->id,
        // Entered today, i.e. inside 2026/27 — the date says nothing about the year stated.
        'adjusted_at' => now(),
    ]);

    if ($auditValues !== null) {
        AuditLog::create([
            'action' => 'leave.historical_balance_set',
            'auditable_type' => LeaveBalanceAdjustment::class,
            'auditable_id' => $adjustment->id,
            'subject_employee_id' => $employee->id,
            'new_values' => $auditValues,
        ]);
    }

    return $adjustment;
}

function hayClassify(LeaveBalance $balance): array
{
    return app(LeaveLedgerBackfillService::class)->classify($balance->fresh());
}

test('a new historical statement records the leave year it is about', function () {
    $employee = hayEmployee();
    $type = hayType();

    $adjustment = app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, hayYear('2025/26'), 20, 12, 0, 'Closing balance from the old system', null, hayHr(),
    );

    expect($adjustment->fresh()->leave_year_id)->toBe(hayYear('2025/26')->id);
});

test('an older historical adjustment gets its year from its audit entry', function () {
    $employee = hayEmployee();
    $type = hayType();
    $byId = hayLegacyHistoricalAdjustment($employee, $type, ['leave_year_id' => hayYear('2025/26')->id]);
    $byLabel = hayLegacyHistoricalAdjustment($employee, $type, ['leave_year_label' => '2024/25']);

    $result = app(HistoricalAdjustmentYearMapper::class)->run();

    expect($result)->toBe(['mapped' => 2, 'unmapped' => 0])
        ->and($byId->fresh()->leave_year_id)->toBe(hayYear('2025/26')->id)
        ->and($byLabel->fresh()->leave_year_id)->toBe(hayYear('2024/25')->id);

    // Re-running changes nothing.
    expect(app(HistoricalAdjustmentYearMapper::class)->run())->toBe(['mapped' => 0, 'unmapped' => 0]);
});

test('an adjustment whose audit entry names no known year is left unmapped, not guessed', function () {
    $employee = hayEmployee();
    $type = hayType();
    $noAudit = hayLegacyHistoricalAdjustment($employee, $type, null);
    $unknownYear = hayLegacyHistoricalAdjustment($employee, $type, ['leave_year_id' => 999999, 'leave_year' => 2019]);

    $result = app(HistoricalAdjustmentYearMapper::class)->run();

    expect($result)->toBe(['mapped' => 0, 'unmapped' => 2])
        ->and($noAudit->fresh()->leave_year_id)->toBeNull()
        ->and($unknownYear->fresh()->leave_year_id)->toBeNull();
});

test('a past year is classified only by the historical adjustments of that same year', function () {
    $employee = hayEmployee();
    $type = hayType();
    $stated = hayBalance($employee, $type, '2024/25', 8);
    $clean = hayBalance($employee, $type, '2025/26', 20);
    LeaveBalanceAdjustment::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_year_id' => hayYear('2024/25')->id,
        'action' => 'credit', 'source' => 'historical', 'days' => 8, 'previous_balance' => 0, 'new_balance' => 8,
        'reason' => 'Closing balance', 'adjusted_by' => hayHr()->id,
        // Entered during 2025/26 — irrelevant: its year is 2024/25.
        'adjusted_at' => Carbon::parse('2025-09-10'),
    ]);

    expect(hayClassify($stated)['classification'])->toBe('NEEDS_HR_REVIEW')
        ->and(hayClassify($clean)['classification'])->toBe('SAFE');
});

test('a historical adjustment entered this year but about a past year does not taint the current year', function () {
    $employee = hayEmployee();
    $type = hayType();
    $current = hayBalance($employee, $type, '2026/27', 20);
    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, hayYear('2025/26'), 20, 12, 0, 'Closing balance from the old system', null, hayHr(),
    );

    $row = hayClassify($current);

    expect($row['classification'])->toBe('SAFE')
        ->and($row['reason'])->not->toContain('historical');
});

test('an unmappable historical adjustment sends every balance of that employee and type to HR review', function () {
    $employee = hayEmployee();
    $type = hayType();
    $past = hayBalance($employee, $type, '2025/26', 20);
    $current = hayBalance($employee, $type, '2026/27', 20);
    $otherType = hayBalance($employee, hayType(), '2026/27', 20);
    hayLegacyHistoricalAdjustment($employee, $type, null);

    expect(hayClassify($past)['classification'])->toBe('NEEDS_HR_REVIEW')
        ->and(hayClassify($current)['classification'])->toBe('NEEDS_HR_REVIEW')
        ->and(hayClassify($current)['reason'])->toContain('could not be matched to a leave year')
        // A different leave type is not affected.
        ->and(hayClassify($otherType)['classification'])->toBe('SAFE');
});

test('a historically stated year is preserved as an opening balance, never as invented base entitlement', function () {
    $employee = hayEmployee();
    $type = hayType();
    app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, hayYear('2025/26'), 20, null, null, 'Closing balance only', null, hayHr(),
    );
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2025)->firstOrFail();

    expect(hayClassify($balance)['classification'])->toBe('NEEDS_HR_REVIEW');

    app(LeaveLedgerBackfillService::class)->apply(['employee_id' => $employee->id], hayHr(), includeReview: true);
    $after = $balance->fresh();

    expect($after->ledger_status)->toBe(LeaveBalance::LEDGER_NEEDS_HR_REVIEW)
        ->and((float) $after->base_days)->toBe(0.0)
        ->and((float) $after->opening_days)->toBe(20.0)
        ->and((float) $after->allocated_days)->toBe(20.0);
});
