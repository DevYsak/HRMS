<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveV2Migration;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveV2MigrationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The production Leave V2 balance migration (leave:v2-migrate): each
 * employee's CURRENT CSL available becomes their Earned Leave opening —
 * never CSL + Sick + Annual, never a fresh 12 — audited, idempotent and
 * reconciled; anything uncertain is STOP. Leave V2 stays off, as on live.
 *
 * Clock: Thu 22 Oct 2026, leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => false]);
    $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    conexusPaidLeave();
    app(ConexusLeavePolicyService::class)->apply();
    $this->csl = LeaveType::where('code', ConexusLeavePolicyService::CSL_CODE)->sole();
    $this->policy = LeavePolicy::where('name', ConexusLeavePolicyService::POLICY_NAME)->sole();

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'email' => 'hr.migration@example.com']);

    $this->sick = LeaveType::create(['name' => 'Sick Leave (old)', 'code' => 'SLO', 'category' => 'sick', 'is_paid' => true, 'color' => '#ef4444', 'availability' => LeaveType::AVAILABILITY_HISTORICAL]);
    $this->annual = LeaveType::create(['name' => 'Annual Leave (old)', 'code' => 'ALO', 'category' => 'annual', 'is_paid' => true, 'color' => '#22c55e', 'availability' => LeaveType::AVAILABILITY_HISTORICAL]);
    $this->compOff = LeaveType::where('category', 'comp_off')->first()
        ?? LeaveType::create(['name' => 'Comp Off', 'code' => 'CO', 'category' => 'comp_off', 'is_paid' => true, 'color' => '#8b5cf6']);
    $this->bereavement = LeaveType::create(['name' => 'Bereavement Leave', 'code' => 'BRV', 'category' => 'bereavement', 'is_paid' => true, 'color' => '#0ea5e9']);
});

/** The Earned Leave type HR configures on the policy: 12 days a year. */
function mgEarned(float $days = 12): LeaveType
{
    $earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELV2', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    test()->policy->update(['earned_leave_type_id' => $earned->id]);
    LeavePolicyRule::create(['leave_policy_id' => test()->policy->id, 'leave_type_id' => $earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => $days, 'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);
    test()->earned = $earned;

    return $earned;
}

/** An employee on the Conexus policy. */
function mgEmployee(string $code): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08',
        'employee_id' => $code, 'manager_id' => null, 'leave_policy_id' => test()->policy->id]);
}

/** Set a type's 2026/27 balance to exactly $days through the ledger (whatever provisioning gave). */
function mgSet(Employee $employee, LeaveType $type, float $days): LeaveBalance
{
    $movements = app(LeaveMovementService::class);
    $balance = $movements->balanceFor($employee->id, $type->id, Carbon::parse('2026-07-01'));
    $movements->ledgerReady($balance);
    $current = mgAvailable($employee, $type);
    $ledger = app(LeaveLedgerService::class);
    if ($days - $current > 0.001) {
        $ledger->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, $days - $current, Carbon::parse('2026-07-01'), 'mg-'.$employee->id.'-'.$type->id.'-'.uniqid());
    } elseif ($current - $days > 0.001) {
        $ledger->debit($balance->fresh(), LeaveLedgerEntry::TYPE_USAGE, $current - $days, Carbon::parse('2026-08-03'), 'mg-'.$employee->id.'-'.$type->id.'-'.uniqid());
    }

    return $ledger->rebuild($balance->fresh());
}

function mgAvailable(Employee $employee, LeaveType $type): float
{
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year_id', test()->year->id)->first();

    return $balance ? (float) app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'] : 0.0;
}

function mgLedgerSum(Employee $employee, LeaveType $type): float
{
    return round((float) LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year_id', test()->year->id)->sum('days'), 2);
}

function mgRow(Employee $employee): array
{
    return app(LeaveV2MigrationService::class)->planFor($employee->fresh(), test()->year, test()->csl);
}

function mgApply(Employee $employee): array
{
    return app(LeaveV2MigrationService::class)->apply(mgRow($employee), test()->year, test()->hr);
}

// ── Amounts ──────────────────────────────────────────────────────────────────

test('CSL available 3 becomes Earned Leave available 3, with entitlement 12 kept apart', function () {
    $employee = mgEmployee('CNX-0101');
    mgSet($employee, $this->csl, 3);
    mgEarned();

    $row = mgRow($employee);
    expect($row)->toMatchArray(['status' => LeaveV2MigrationService::READY, 'csl_available' => 3.0, 'proposed' => 3.0, 'entitlement' => 12.0, 'difference' => -9.0]);

    $done = mgApply($employee);
    $record = LeaveV2Migration::where('employee_id', $employee->id)->sole();
    $entry = LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $this->earned->id)->sole();

    expect($done['status'])->toBe(LeaveV2MigrationService::MIGRATED)
        ->and(mgAvailable($employee, $this->earned))->toEqual(3.0)
        ->and($entry->entry_type)->toBe(LeaveLedgerEntry::TYPE_OPENING)
        ->and((float) $entry->days)->toEqual(3.0)
        ->and($entry->source_type)->toBe('leave_v2_migration')
        ->and((int) $entry->created_by)->toBe($this->hr->id)
        ->and($entry->idempotency_key)->toBe(LeaveV2Migration::keyFor($employee->id, $this->earned->id, $this->year->id))
        ->and((int) $record->source_leave_type_id)->toBe($this->csl->id)
        ->and((float) $record->source_available)->toEqual(3.0)
        ->and((float) $record->policy_entitlement)->toEqual(12.0)
        ->and((int) $record->created_by)->toBe($this->hr->id)
        ->and((int) $record->ledger_entry_id)->toBe($entry->id)
        ->and(AuditLog::where('event', 'LEAVE_V2_MIGRATED')->where('subject_employee_id', $employee->id)->exists())->toBeTrue()
        // CSL is untouched and still readable.
        ->and(mgAvailable($employee, $this->csl))->toEqual(3.0);
});

test('CSL available 8.5 becomes Earned Leave available 8.5', function () {
    $employee = mgEmployee('CNX-0102');
    mgSet($employee, $this->csl, 8.5);
    mgEarned();

    mgApply($employee);

    expect(mgAvailable($employee, $this->earned))->toEqual(8.5)
        ->and(mgLedgerSum($employee, $this->earned))->toEqual(8.5);
});

test('a zero balance migrates to zero with a record and no credit', function () {
    $employee = mgEmployee('CNX-0103');
    mgSet($employee, $this->csl, 0);
    mgEarned();

    expect(mgRow($employee))->toMatchArray(['status' => LeaveV2MigrationService::READY, 'proposed' => 0.0]);
    mgApply($employee);

    expect(LeaveV2Migration::where('employee_id', $employee->id)->sole()->ledger_entry_id)->toBeNull()
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $this->earned->id)->count())->toBe(0)
        ->and(mgAvailable($employee, $this->earned))->toEqual(0.0);
});

test('historical Annual 28 and Sick are shown but never added', function () {
    $employee = mgEmployee('CNX-0104');
    mgSet($employee, $this->csl, 4);
    mgSet($employee, $this->annual, 28);
    mgSet($employee, $this->sick, 5);
    mgEarned();

    $row = mgRow($employee);
    expect($row)->toMatchArray(['annual_historical' => 28.0, 'sick_available' => 5.0, 'proposed' => 4.0]);

    mgApply($employee);
    expect(mgAvailable($employee, $this->earned))->toEqual(4.0)
        ->and(mgAvailable($employee, $this->annual))->toEqual(28.0)
        ->and(mgAvailable($employee, $this->sick))->toEqual(5.0);
});

test('Comp Off and Special Leave are left exactly as they were', function () {
    $employee = mgEmployee('CNX-0105');
    mgSet($employee, $this->csl, 6);
    mgSet($employee, $this->compOff, 2);
    mgSet($employee, $this->bereavement, 10);
    mgEarned();
    $before = LeaveLedgerEntry::whereIn('leave_type_id', [$this->compOff->id, $this->bereavement->id])->where('employee_id', $employee->id)->pluck('id')->all();

    expect(mgRow($employee))->toMatchArray(['comp_off' => 2.0, 'special' => 10.0, 'proposed' => 6.0]);
    mgApply($employee);

    expect(mgAvailable($employee, $this->compOff))->toEqual(2.0)
        ->and(mgAvailable($employee, $this->bereavement))->toEqual(10.0)
        ->and(LeaveLedgerEntry::whereIn('leave_type_id', [$this->compOff->id, $this->bereavement->id])->where('employee_id', $employee->id)->pluck('id')->all())->toBe($before);
});

// ── Requests, reruns and STOP ────────────────────────────────────────────────

test('a pending legacy request on another type stays as it is; one on CSL stops the employee', function () {
    $employee = mgEmployee('CNX-0106');
    mgSet($employee, $this->csl, 5);
    mgEarned();
    $sickRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $this->sick->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
        'days' => 1, 'reason' => 'Doctor', 'status' => 'pending', 'requested_leave_status' => 'paid']);

    expect(mgRow($employee))->toMatchArray(['status' => LeaveV2MigrationService::READY, 'pending_legacy' => 1]);
    mgApply($employee);
    expect($sickRequest->fresh()->only(['status', 'leave_type_id', 'days', 'balance_leave_type_id', 'main_category']))
        ->toBe(['status' => 'pending', 'leave_type_id' => $this->sick->id, 'days' => '1.00', 'balance_leave_type_id' => null, 'main_category' => null]);

    $other = mgEmployee('CNX-0107');
    mgSet($other, $this->csl, 5);
    LeaveRequest::create(['employee_id' => $other->id, 'leave_type_id' => $this->csl->id, 'start_date' => '2026-11-03', 'end_date' => '2026-11-03',
        'days' => 1, 'reason' => 'Errand', 'status' => 'pending', 'requested_leave_status' => 'paid']);
    $row = mgRow($other);
    expect($row['status'])->toBe(LeaveV2MigrationService::STOP)->and($row['reason'])->toContain('pending');
});

test('re-running the migration changes nothing', function () {
    $employee = mgEmployee('CNX-0108');
    mgSet($employee, $this->csl, 3);
    mgEarned();
    mgApply($employee);
    $entries = LeaveLedgerEntry::count();

    $again = mgApply($employee);

    expect($again['status'])->toBe(LeaveV2MigrationService::MIGRATED)
        ->and(LeaveLedgerEntry::count())->toBe($entries)
        ->and(LeaveV2Migration::count())->toBe(1)
        ->and(mgAvailable($employee, $this->earned))->toEqual(3.0);
});

test('uncertain source data stops the employee', function (string $case) {
    $employee = mgEmployee('CNX-0109');
    mgEarned();

    // Creating an employee opens an empty CSL row; each case then makes the source uncertain.
    match ($case) {
        'no CSL balance' => LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $this->csl->id)->get()->each->delete(),
        'negative CSL' => mgSet($employee, $this->csl, -2),
        'not on the ledger' => LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $this->csl->id)->toBase()
            ->update(['ledger_migrated_at' => null, 'allocated_days' => 5]),
        'Earned already credited' => [mgSet($employee, $this->csl, 3), mgSet($employee, $this->earned, 12)],
    };

    $row = mgRow($employee);
    expect($row['status'])->toBe(LeaveV2MigrationService::STOP)
        ->and(app(LeaveV2MigrationService::class)->apply($row, $this->year, $this->hr)['status'])->toBe(LeaveV2MigrationService::STOP)
        ->and(LeaveV2Migration::count())->toBe(0);
})->with(['no CSL balance', 'negative CSL', 'not on the ledger', 'Earned already credited']);

test('without Earned Leave configured on the policy everyone is STOP', function () {
    $employee = mgEmployee('CNX-0110');
    mgSet($employee, $this->csl, 3);

    expect(mgRow($employee))->toMatchArray(['status' => LeaveV2MigrationService::STOP, 'proposed' => null])
        ->and(mgRow($employee)['reason'])->toContain('no Earned Leave type yet');
});

// ── No double entitlement ────────────────────────────────────────────────────

test('provisioning and accrual never grant 12 on top, before or after the migration', function () {
    $employee = mgEmployee('CNX-0111');
    mgSet($employee, $this->csl, 3);
    mgEarned();
    $ensure = fn () => app(EnsureEmployeeLeaveBalancesService::class)->ensureType($employee->fresh(), $this->earned, $this->year);

    // Earned Leave configured, not yet migrated: held.
    expect($ensure()['status'])->toBe(EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT)
        ->and(app(LeaveAccrualService::class)->creditOne($employee->fresh(), $this->earned, Carbon::parse('2026-10-01'), $this->year, 2026, 10))->toBe('skipped')
        ->and(mgAvailable($employee, $this->earned))->toEqual(0.0);

    mgApply($employee);

    // Migrated: no base; the in-advance monthly job never touches Earned Leave
    // (only leave:accrue-earned does, for months the opening does not hold).
    expect($ensure()['status'])->toBe(EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT)
        ->and(app(LeaveAccrualService::class)->creditOne($employee->fresh(), $this->earned, Carbon::parse('2026-10-01'), $this->year, 2026, 10))->toBe('skipped')
        ->and(mgAvailable($employee, $this->earned))->toEqual(3.0);

    // A zero-balance employee is never topped up to 12 either.
    $zero = mgEmployee('CNX-0112');
    mgSet($zero, $this->csl, 0);
    mgApply($zero);
    expect(app(EnsureEmployeeLeaveBalancesService::class)->ensureType($zero->fresh(), $this->earned, $this->year)['status'])->toBe(EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT)
        ->and(mgAvailable($zero, $this->earned))->toEqual(0.0);
});

test('every balance still reconciles with its ledger after the migration', function () {
    $employees = collect([['CNX-0113', 3], ['CNX-0114', 8.5], ['CNX-0115', 0]])->map(function ($e) {
        $employee = mgEmployee($e[0]);
        mgSet($employee, $this->csl, $e[1]);

        return $employee;
    });
    mgEarned();
    $employees->each(fn (Employee $e) => mgApply($e));

    LeaveBalance::whereIn('employee_id', $employees->pluck('id'))->get()->each(function (LeaveBalance $balance) {
        if (! $balance->isLedgerBacked()) {
            return;
        }
        $sum = round((float) LeaveLedgerEntry::where('employee_id', $balance->employee_id)->where('leave_type_id', $balance->leave_type_id)->where('leave_year_id', $balance->leave_year_id)->sum('days'), 2);
        expect((float) app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'])->toEqual($sum);
    });
});

// ── The command ──────────────────────────────────────────────────────────────

test('the preview writes nothing; --apply migrates READY employees and records the actor', function () {
    $employee = mgEmployee('CNX-0116');
    mgSet($employee, $this->csl, 3);
    mgEarned();
    $tables = fn () => [LeaveLedgerEntry::count(), LeaveBalance::count(), LeaveV2Migration::count(), AuditLog::count(), LeaveYear::count()];
    $before = $tables();

    $this->artisan('leave:v2-migrate', ['--preview' => true, '--employee' => ['CNX-0116']])
        ->expectsOutputToContain('PREVIEW — nothing is written')
        ->assertSuccessful();
    expect($tables())->toBe($before);

    $this->artisan('leave:v2-migrate', ['--apply' => true, '--employee' => ['CNX-0116']])->assertFailed();
    expect($tables())->toBe($before);

    $this->artisan('leave:v2-migrate', ['--apply' => true, '--actor' => 'hr.migration@example.com', '--employee' => ['CNX-0116']])
        ->expectsOutputToContain('MIGRATED')
        ->assertSuccessful();

    expect(mgAvailable($employee, $this->earned))->toEqual(3.0)
        ->and((int) LeaveV2Migration::sole()->created_by)->toBe($this->hr->id);
});

test('an HR override replaces the source, with its reason recorded', function () {
    $employee = mgEmployee('CNX-0117');
    mgEarned();
    $path = storage_path('framework/testing/v2-overrides-'.uniqid().'.csv');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "employee_code,earned_available,reason\nCNX-0117,5.5,Register correction approved by HR\n");

    $this->artisan('leave:v2-migrate', ['--apply' => true, '--actor' => 'hr.migration@example.com', '--employee' => ['CNX-0117'], '--overrides' => $path])->assertSuccessful();

    expect(mgAvailable($employee, $this->earned))->toEqual(5.5)
        ->and(LeaveV2Migration::sole()->override_reason)->toBe('Register correction approved by HR');
    @unlink($path);
});
