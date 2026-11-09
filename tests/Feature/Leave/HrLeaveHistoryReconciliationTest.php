<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveAccrualTransition;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\EarnedLeaveAccrualService;
use App\Services\Leave\EarnedLeaveBreakdown;
use App\Services\Leave\EarnedOpeningSourceService;
use App\Services\Leave\LeaveAccrualTransitionService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveHrHistoryReconciliationService as History;
use App\Services\Leave\LeaveIntegrityService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use App\Services\Leave\LeaveV2MigrationService;
use App\Services\LeaveBalanceService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * leave:reconcile-hr-history — READ ONLY. The HR register is the snapshot to
 * 30 Sep 2026 (carry forward + current-year earned − used); everything the
 * portal did after it is kept, counted once, from the CSL ledger and the
 * Earned Leave pool alike. The migrated opening is never the baseline.
 *
 * Clock: Thu 29 Oct 2026. Leave V2 migration ran on 20 Oct.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    $this->policy = LeavePolicy::create(['name' => 'Conexus Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => true, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELH', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12,
        'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1, 'is_active' => true]);
    $this->csl = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true, 'color' => '#f97316']);

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);

    // As leave:conexus-reconcile posted the register on CSL.
    $this->yogesh = hhEmployee('CNS021', 'yogesh.sakpal@conexus-ns.com', 'Yogesh');
    hhRegisterOnCsl($this->yogesh, credit: 2, carry: 7, used: 5);
    $this->pratish = hhEmployee('CNS042', 'Pratish.kavade@conexus-ns.com', 'Pratish');
    hhRegisterOnCsl($this->pratish, credit: 2, carry: 8, used: 5.5);

    $this->register = hhRegister([
        ['name' => 'Yogesh', 'emails' => ['yogesh.sakpal@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 5, 'encashed' => 0, 'available' => 4],
        ['name' => 'Pratish', 'emails' => ['Pratish.kavade@conexus-ns.com'], 'credit' => 2, 'carry' => 8, 'used' => 5.5, 'encashed' => 0, 'available' => 4.5],
    ]);
});

afterEach(function () {
    @unlink($this->register);
});

function hhEmployee(string $code, string $email, string $name): Employee
{
    return Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee, 'email' => $email, 'name' => $name])->id,
        'status' => 'active', 'joining_date' => '2020-12-16', 'employee_id' => $code, 'manager_id' => null, 'department_id' => Department::factory()->create()->id]);
}

function hhBalance(Employee $employee, LeaveType $type): LeaveBalance
{
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $type->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);

    return $balance->fresh();
}

function hhRegisterOnCsl(Employee $employee, float $credit, float $carry, float $used): void
{
    $balance = hhBalance($employee, test()->csl);
    $ledger = app(LeaveLedgerService::class);
    $opts = ['source_type' => LeaveRegisterReconciliationService::SOURCE_TYPE];
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_BASE, $credit, Carbon::parse('2026-07-01'), "hh-base-{$employee->id}", $opts);
    if ($carry > 0) {
        $ledger->credit($balance->fresh(), LeaveLedgerEntry::TYPE_CARRY_FORWARD, $carry, Carbon::parse('2026-07-01'), "hh-carry-{$employee->id}", $opts);
    }
    if ($used > 0) {
        $ledger->debit($balance->fresh(), LeaveLedgerEntry::TYPE_USAGE, $used, Carbon::parse('2026-09-30'), "hh-used-{$employee->id}", $opts);
    }
    $ledger->rebuild($balance->fresh());
}

/** A portal day taken (approved) — its usage on the ledger, linked to a real request. */
function hhTaken(Employee $employee, LeaveType $type, float $days, string $date): LeaveLedgerEntry
{
    $request = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'balance_leave_type_id' => $type->id,
        'start_date' => $date, 'end_date' => $date, 'days' => $days, 'reason' => 'Portal leave', 'status' => 'approved', 'requested_leave_status' => 'paid']);
    $balance = hhBalance($employee, $type);
    $entry = app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_USAGE, $days, Carbon::parse($date), "hh-taken-{$request->id}",
        ['source_type' => 'leave_request', 'source_id' => $request->id]);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    return $entry;
}

function hhMigrate(Employee $employee): void
{
    $migration = app(LeaveV2MigrationService::class);
    $row = $migration->planFor($employee->fresh(), test()->year, test()->csl);
    expect($row['status'])->toBe(LeaveV2MigrationService::READY);
    $migration->apply($row, test()->year, test()->hr);
}

/** A register file like database/data/conexus_csl_register_2026_27.php, with checksums. */
function hhRegister(array $rows): string
{
    $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);
    $path = tempnam(sys_get_temp_dir(), 'hhreg').'.php';
    file_put_contents($path, '<?php return '.var_export(['leave_year' => '2026/27', 'source' => 'Test HR register to 30 Sep 2026',
        'checksums' => ['credit' => $sum('credit'), 'carry' => $sum('carry'), 'used' => $sum('used'), 'available' => $sum('available'), 'rows' => count($rows)],
        'employees' => $rows], true).';');

    return $path;
}

function hhPlan(?array $codes = null): Collection
{
    $registers = app(LeaveRegisterReconciliationService::class);
    $register = $registers->loadRegister(test()->register);

    return app(History::class)->plan($register, $registers->yearFor($register['leave_year']), Carbon::parse('2026-09-30'), $codes ?? [])->keyBy('code');
}

/** The October story: Yogesh takes 1 day on CSL before the migration; Pratish 1.5 days on Earned Leave after it. */
function hhOctober(): void
{
    test()->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    hhTaken(test()->yogesh, test()->csl, 1, '2026-10-14');
    test()->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    hhMigrate(test()->yogesh);
    hhMigrate(test()->pratish);
    test()->travelTo(Carbon::parse('2026-10-26 10:00:00'));
    hhTaken(test()->pratish, test()->earned, 1.5, '2026-10-26');
    test()->travelTo(Carbon::parse('2026-10-29 10:00:00'));
}

test('Yogesh: HR 7 + 2 − 5 = 4, then 1 day in October = 3, counted once although it sits inside the migrated opening', function () {
    hhOctober();

    expect(hhPlan()['CNS021'])->toMatchArray([
        'hr_carry_forward' => 7.0, 'hr_current_year' => 2.0, 'hr_used' => 5.0, 'hr_baseline' => 4.0,
        'used_after' => 1.0, 'adjustments_after' => 0.0, 'accrual_after' => 0.0, 'pending' => 0.0,
        'expected' => 3.0, 'existing' => 3.0, 'difference' => 0.0, 'status' => History::MATCH,
    ])->and(hhPlan()['CNS021']['existing_lines'])->toMatchArray(['opening' => 3.0, 'carry_forward' => 0.0]);
});

test('Pratish: HR 8 + 2 − 5.5 = 4.5, then 1.5 days on Earned Leave after the migration = 3', function () {
    hhOctober();

    expect(hhPlan()['CNS042'])->toMatchArray([
        'hr_carry_forward' => 8.0, 'hr_current_year' => 2.0, 'hr_used' => 5.5, 'hr_baseline' => 4.5,
        'used_after' => 1.5, 'expected' => 3.0, 'existing' => 3.0, 'difference' => 0.0, 'status' => History::MATCH,
    ]);
});

test('the preview writes nothing — not a ledger entry, balance, leave year or audit row', function () {
    hhOctober();
    $counts = fn () => [LeaveLedgerEntry::count(), LeaveBalance::count(), LeaveYear::count(), AuditLog::count(), LeaveRequest::count(),
        LeaveBalance::orderBy('id')->get()->map->only(['allocated_days', 'used_days', 'opening_days'])->all()];
    $before = $counts();

    $this->artisan('leave:reconcile-hr-history', ['--register' => $this->register])
        ->expectsOutputToContain('PREVIEW — nothing is written')->expectsOutputToContain('MATCH')->assertSuccessful();
    $this->artisan('leave:reconcile-hr-history', ['--register' => $this->register, '--employee' => ['CNS021']])
        ->expectsOutputToContain('Yogesh')->doesntExpectOutputToContain('Pratish')->assertSuccessful();

    expect($counts())->toBe($before);
});

test('portal activity after the cutoff is kept: an HR adjustment counts, a cancelled day nets to nothing', function () {
    hhOctober();
    app(LeaveBalanceService::class)->adjust($this->yogesh, $this->earned, 'credit', 0.5, 'Worked a holiday', '', $this->hr, 2026,
        effectiveDate: Carbon::parse('2026-10-29'));
    $cancelled = hhTaken($this->yogesh, $this->earned, 1, '2026-10-30');
    app(LeaveLedgerService::class)->reverse($cancelled, 'Request cancelled');
    app(LeaveLedgerService::class)->rebuild(hhBalance($this->yogesh, $this->earned));

    expect(hhPlan()['CNS021'])->toMatchArray(['used_after' => 1.0, 'adjustments_after' => 0.5, 'expected' => 3.5, 'existing' => 3.5, 'status' => History::MATCH]);
});

test('historical usage is never counted twice: a portal day inside the HR period is the register\'s, not the portal\'s', function () {
    // An August day recorded in the portal — already inside HR's "used 5".
    $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));
    hhTaken($this->yogesh, $this->csl, 1, '2026-08-12');
    hhRegisterReplace($this->yogesh, used: 4); // the register's aggregate now stands for the other 4
    hhOctober();

    expect(hhPlan()['CNS021'])->toMatchArray(['used_after' => 1.0, 'expected' => 3.0, 'existing' => 3.0, 'status' => History::MATCH]);
});

/** Restate the register aggregate usage on CSL (a reversal + a new figure), as a re-run of the register would. */
function hhRegisterReplace(Employee $employee, float $used): void
{
    $ledger = app(LeaveLedgerService::class);
    $ledger->reverse(LeaveLedgerEntry::where('idempotency_key', "hh-used-{$employee->id}")->sole(), 'Register restated');
    $ledger->debit(hhBalance($employee, test()->csl), LeaveLedgerEntry::TYPE_USAGE, $used, Carbon::parse('2026-09-30'), "hh-used2-{$employee->id}",
        ['source_type' => LeaveRegisterReconciliationService::SOURCE_TYPE]);
    $ledger->rebuild(hhBalance($employee, test()->csl));
}

test('pending requests are shown on their own and never reduce the expected or existing figure', function () {
    hhOctober();
    LeaveRequest::create(['employee_id' => $this->pratish->id, 'leave_type_id' => $this->earned->id, 'balance_leave_type_id' => $this->earned->id,
        'start_date' => '2026-11-03', 'end_date' => '2026-11-04', 'days' => 2, 'reason' => 'Trip', 'status' => 'pending', 'requested_leave_status' => 'paid']);

    expect(hhPlan()['CNS042'])->toMatchArray(['pending' => 2.0, 'expected' => 3.0, 'existing' => 3.0, 'status' => History::MATCH]);
});

test('carry forward and current-year earned stay separate; a wrong balance is a DIFFERENCE, an unlisted employee MISSING HR HISTORY', function () {
    hhOctober();
    // Sunita: the register says 7 carried + 2 earned; the portal holds 8 (migrated from a CSL that disagreed).
    $sunita = hhEmployee('CNS030', 'qa@businessenergyservices.co.uk', 'Sunita');
    $balance = hhBalance($sunita, $this->earned);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_OPENING, 8, Carbon::parse('2026-07-01'), 'hh-sunita-opening', ['source_type' => 'leave_v2_migration']);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
    @unlink($this->register);
    $this->register = hhRegister([
        ['name' => 'Yogesh', 'emails' => ['yogesh.sakpal@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 5, 'encashed' => 0, 'available' => 4],
        ['name' => 'Sunita', 'emails' => ['qa@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 7, 'used' => 0, 'encashed' => 0, 'available' => 9],
    ]);

    $rows = hhPlan();
    expect($rows['CNS030'])->toMatchArray(['hr_carry_forward' => 7.0, 'hr_current_year' => 2.0, 'expected' => 9.0, 'existing' => 8.0, 'difference' => -1.0, 'status' => History::DIFFERENCE])
        ->and($rows['CNS042'])->toMatchArray(['status' => History::MISSING, 'expected' => null, 'used_after' => 1.5]);
});

test('a start date after the months HR\'s credit covers is called out: September is earned by neither', function () {
    hhOctober();
    app(LeaveAccrualTransitionService::class)->set($this->yogesh, '2026-10-01', 'Canary start', $this->hr);

    expect(hhPlan()['CNS021'])->toMatchArray(['status' => History::MATCH, 'expected' => 3.0])
        ->and(hhPlan()['CNS021']['reason'])->toContain('September 2026 is earned by neither');
});

// ── --apply: restate ONE employee to the HR breakdown, total unchanged ───────

/** The September fix as production does it: Monthly Accrual Starts From 1 Sep, then the normal accrual run credits September. */
function hhSeptemberFix(Employee ...$employees): void
{
    foreach ($employees as $employee) {
        app(LeaveAccrualTransitionService::class)->set($employee, '2026-09-01', 'HR credit covers Jul–Aug', test()->hr);
        $accrual = app(EarnedLeaveAccrualService::class);
        $row = $accrual->planFor($employee->fresh(), test()->year, Carbon::today());
        expect($row['due_months'])->toBe(['2026-09']);
        $accrual->apply($row);
    }
}

function hhBreakdown(Employee $employee): array
{
    return app(EarnedLeaveBreakdown::class)->for($employee->fresh(), test()->year);
}

function hhApply(string $code)
{
    return test()->artisan('leave:reconcile-hr-history', ['--register' => test()->register, '--employee' => [$code], '--apply' => true, '--actor' => test()->hr->email]);
}

/** The four register staff in the brief, migrated on 20 Oct, with their October activity and the September fix. */
function hhFourStaff(): void
{
    test()->sunita = hhEmployee('CNS030', 'qa@businessenergyservices.co.uk', 'Sunita');
    hhRegisterOnCsl(test()->sunita, credit: 2, carry: 7, used: 0.5);
    test()->gayatri = hhEmployee('CNS001', 'carol@businessenergyservices.co.uk', 'Gayatri Chagan Navlakhe');
    hhRegisterOnCsl(test()->gayatri, credit: 2, carry: 2.5, used: 2);
    @unlink(test()->register);
    test()->register = hhRegister([
        ['name' => 'Yogesh', 'emails' => ['yogesh.sakpal@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 5, 'encashed' => 0, 'available' => 4],
        ['name' => 'Pratish', 'emails' => ['Pratish.kavade@conexus-ns.com'], 'credit' => 2, 'carry' => 8, 'used' => 5.5, 'encashed' => 0, 'available' => 4.5],
        ['name' => 'Sunita', 'emails' => ['qa@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 7, 'used' => 0.5, 'encashed' => 0, 'available' => 8.5],
        ['name' => 'Gayatri Chagan Navlakhe', 'emails' => ['carol@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 2.5, 'used' => 2, 'encashed' => 0, 'available' => 2.5],
    ]);

    hhOctober();
    test()->travelTo(Carbon::parse('2026-10-20 11:00:00'));
    hhMigrate(test()->sunita);
    hhMigrate(test()->gayatri);
    test()->travelTo(Carbon::parse('2026-10-29 10:00:00'));
    hhSeptemberFix(test()->yogesh, test()->pratish, test()->gayatri);
    // Gayatri after the cutoff: an HR credit, and a day taken then cancelled.
    app(LeaveBalanceService::class)->adjust(test()->gayatri, test()->earned, 'credit', 0.5, 'Worked a holiday', '', test()->hr, 2026, effectiveDate: Carbon::parse('2026-10-29'));
    $cancelled = hhTaken(test()->gayatri, test()->earned, 1, '2026-10-30');
    app(LeaveLedgerService::class)->reverse($cancelled, 'Request cancelled');
    app(LeaveLedgerService::class)->rebuild(hhBalance(test()->gayatri, test()->earned));
}

test('Yogesh --apply: 7 carried, 2 HR-earned + September 1, 5 used to the cutoff + 1 in October — available stays 4, the opening is gone', function () {
    hhFourStaff();
    $september = LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)->sole();
    $start = LeaveAccrualTransition::where('employee_id', $this->yogesh->id)->sole()->only(['starts_on', 'set_by', 'reason']);
    $request = LeaveRequest::where('employee_id', $this->yogesh->id)->sole();
    $requestBefore = $request->getAttributes();

    expect(hhBreakdown($this->yogesh))->toMatchArray(['opening' => 3.0, 'carry_forward' => 0.0, 'current_year_accrued' => 1.0, 'total_available' => 4.0])
        ->and(hhPlan()['CNS021'])->toMatchArray(['expected' => 4.0, 'existing' => 4.0, 'accrual_after' => 1.0, 'used_after' => 1.0, 'status' => History::MATCH, 'apply_blocker' => null]);

    hhApply('CNS021')->expectsOutputToContain('Migrated Opening')->expectsOutputToContain('RECONCILED')->assertSuccessful();

    expect(hhBreakdown($this->yogesh))->toMatchArray([
        'carry_forward' => 7.0, 'current_year_accrued' => 3.0, 'opening' => 0.0, 'hr_adjustment' => 0.0,
        'used' => 6.0, 'historical_used' => 5.0, 'portal_used' => 1.0, 'total_available' => 4.0, 'consistent' => true, 'monthly_credits' => 1,
    ])
        // September is its own monthly accrual, untouched; the start date and the request are untouched.
        ->and(LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)->sole()->id)->toBe($september->id)
        ->and(LeaveAccrualTransition::where('employee_id', $this->yogesh->id)->sole()->only(['starts_on', 'set_by', 'reason']))->toEqual($start)
        ->and($request->fresh()->getAttributes())->toEqual($requestBefore)
        // The October day now lives on Earned Leave, so cancelling it returns it there.
        ->and(app(LeaveLedgerService::class)->activeEntryFor('leave_request', $request->id, LeaveLedgerEntry::TYPE_USAGE)->leave_type_id)->toBe($this->earned->id)
        ->and(AuditLog::where('event', 'LEAVE_HR_HISTORY_RECONCILED')->where('subject_employee_id', $this->yogesh->id)->where('user_id', $this->hr->id)->count())->toBe(1)
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
    $this->artisan('leave:verify', ['--year' => '2026/27'])->assertSuccessful();

    // October's monthly accrual still follows on 1 November — the restatement does not stop it.
    $this->travelTo(Carbon::parse('2026-11-01 00:20:00'));
    expect(app(EarnedLeaveAccrualService::class)->planFor($this->yogesh->fresh(), $this->year, Carbon::today()))
        ->toMatchArray(['status' => EarnedLeaveAccrualService::READY, 'due_months' => ['2026-10']]);
});

test('Pratish, Sunita and Gayatri keep their totals: 4, 8.5 and 4 (2.5 baseline + September + HR 0.5; a cancelled day nets nothing)', function () {
    hhFourStaff();

    foreach ([['CNS042', $this->pratish, 4.0, 8.0, 3.0, 5.5, 1.5], ['CNS030', $this->sunita, 8.5, 7.0, 2.0, 0.5, 0.0], ['CNS001', $this->gayatri, 4.0, 2.5, 3.0, 2.0, 0.0]] as [$code, $employee, $total, $carry, $current, $historical, $portal]) {
        expect(hhBreakdown($employee)['total_available'])->toBe($total, $code);
        hhApply($code)->expectsOutputToContain('RECONCILED')->assertSuccessful();
        expect(hhBreakdown($employee))->toMatchArray(['carry_forward' => $carry, 'current_year_accrued' => $current, 'opening' => 0.0,
            'historical_used' => $historical, 'portal_used' => $portal, 'total_available' => $total, 'consistent' => true]);
    }
    expect(hhBreakdown($this->gayatri)['hr_adjustment'])->toBe(0.5)
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
    $this->artisan('leave:verify', ['--year' => '2026/27'])->assertSuccessful();
});

test('apply is idempotent: the second run is NO CHANGE and posts nothing', function () {
    hhFourStaff();
    hhApply('CNS021')->assertSuccessful();
    $entries = LeaveLedgerEntry::count();
    $audits = AuditLog::where('event', 'LEAVE_HR_HISTORY_RECONCILED')->count();

    hhApply('CNS021')->expectsOutputToContain('NO CHANGE')->assertSuccessful();
    $this->artisan('leave:reconcile-hr-history', ['--register' => $this->register, '--employee' => ['CNS021']])->expectsOutputToContain('NO CHANGE')->assertSuccessful();

    expect(LeaveLedgerEntry::count())->toBe($entries)
        ->and(AuditLog::where('event', 'LEAVE_HR_HISTORY_RECONCILED')->count())->toBe($audits)
        ->and(hhBreakdown($this->yogesh)['total_available'])->toBe(4.0);
});

test('apply is refused in bulk, without an HR actor, and for a DIFFERENCE or NEEDS REVIEW row — and then writes nothing', function () {
    hhFourStaff();
    $register = $this->register;

    $this->artisan('leave:reconcile-hr-history', ['--register' => $register, '--apply' => true, '--actor' => $this->hr->email])->assertFailed();
    $this->artisan('leave:reconcile-hr-history', ['--register' => $register, '--employee' => ['CNS021', 'CNS042'], '--apply' => true, '--actor' => $this->hr->email])->assertFailed();
    $this->artisan('leave:reconcile-hr-history', ['--register' => $register, '--employee' => ['CNS021'], '--apply' => true])->assertFailed();

    // NEEDS REVIEW: an Earned Leave movement dated inside the HR period (it may already be in the register).
    $balance = hhBalance($this->sunita, $this->earned);
    app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_EXPIRY, 1, Carbon::parse('2026-09-15'), 'hh-odd-expiry');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
    $before = [LeaveLedgerEntry::count(), AuditLog::count()];
    expect(hhPlan()['CNS030']['status'])->toBe(History::REVIEW);
    hhApply('CNS030')->expectsOutputToContain('Refused')->assertFailed();

    // NEEDS REVIEW: an encashment in the register.
    $this->register = hhRegister([['name' => 'Yogesh', 'emails' => ['yogesh.sakpal@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 4, 'encashed' => 1, 'available' => 4]]);
    expect(hhPlan()['CNS021']['status'])->toBe(History::REVIEW);
    hhApply('CNS021')->expectsOutputToContain('Refused')->assertFailed();
    @unlink($this->register);

    // DIFFERENCE: a register that disagrees with the portal.
    $this->register = hhRegister([['name' => 'Pratish', 'emails' => ['Pratish.kavade@conexus-ns.com'], 'credit' => 2, 'carry' => 9, 'used' => 5.5, 'encashed' => 0, 'available' => 5.5]]);
    expect(hhPlan()['CNS042']['status'])->toBe(History::DIFFERENCE);
    hhApply('CNS042')->expectsOutputToContain('Refused')->assertFailed();
    @unlink($register);

    expect([LeaveLedgerEntry::count(), AuditLog::count()])->toBe($before);
});

test('an employee outside the register is never restated', function () {
    hhFourStaff();
    $outsider = hhEmployee('CNS099', 'new.joiner@conexus-ns.com', 'New Joiner');
    expect(hhPlan(['CNS099'])['CNS099']['status'])->toBe(History::MISSING);
    $before = LeaveLedgerEntry::where('employee_id', $outsider->id)->pluck('id')->all();

    hhApply('CNS099')->expectsOutputToContain('Refused')->assertFailed();
    expect(LeaveLedgerEntry::where('employee_id', $outsider->id)->pluck('id')->all())->toBe($before);
});

// ── Production ledger shapes: September CSL accrual, HR corrections, register normalisation, reclassified openings ──

/** The CSL September accrual leave:conexus-csl-accrual posts on 1 Oct, effective 30 Sep. */
function hhCslAccrual(Employee $employee, string $month = '2026-09'): void
{
    $balance = hhBalance($employee, test()->csl);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, 1, Carbon::parse($month.'-01')->endOfMonth()->startOfDay(),
        "conexus-csl-accrual:{$employee->id}:".test()->year->id.":{$month}", ['source_type' => 'conexus_csl_accrual', 'source_id' => $employee->id,
            'reason' => 'CSL earned for September 2026', 'meta' => ['month' => $month]]);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
}

/** An HR add / deduct / correct on CSL through the real adjustment screen's service (adjustment record + adjustment:{id} entry). */
function hhCslAdjust(Employee $employee, string $action, float $days, string $reason, string $on): void
{
    test()->travelTo(Carbon::parse($on.' 10:00:00'));
    app(LeaveBalanceService::class)->adjust($employee, test()->csl, $action, $days, $reason, '', test()->hr, 2026,
        category: LeaveBalanceService::CATEGORY_CORRECTION, effectiveDate: Carbon::parse($on));
}

function hhRegisterOf(array $names): string
{
    $all = [
        'Yogesh' => ['name' => 'Yogesh', 'emails' => ['yogesh.sakpal@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 5, 'encashed' => 0, 'available' => 4],
        'Pratish' => ['name' => 'Pratish', 'emails' => ['Pratish.kavade@conexus-ns.com'], 'credit' => 2, 'carry' => 8, 'used' => 5.5, 'encashed' => 0, 'available' => 4.5],
        'Sunita' => ['name' => 'Sunita', 'emails' => ['qa@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 7, 'used' => 0.5, 'encashed' => 0, 'available' => 8.5],
        'Gayatri' => ['name' => 'Gayatri Chagan Navlakhe', 'emails' => ['carol@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 2.5, 'used' => 2, 'encashed' => 0, 'available' => 2.5],
        'Nikita' => ['name' => 'Nikita Dalal', 'emails' => ['nikita@conexus-ns.com', 'Nikita.Dalal@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 2, 'encashed' => 0, 'available' => 1],
        'Shradha' => ['name' => 'Shradha', 'emails' => ['shradha.kotak@conexus-ns.com'], 'credit' => 2, 'carry' => 0, 'used' => 3, 'encashed' => 0, 'available' => -1],
        'Ankita' => ['name' => 'Ankita', 'emails' => ['ankita.rawat@conexus-ns.com'], 'credit' => 2, 'carry' => 5, 'used' => 0, 'encashed' => 0, 'available' => 7],
    ];

    return hhRegister(array_values(array_intersect_key($all, array_flip($names))));
}

/**
 * Production as the ledger evidence shows it.
 *  - Yogesh, Pratish: CSL loaded from the register; no CSL September accrual —
 *    the September fix credits September on Earned Leave instead.
 *  - Gayatri, Sunita: CSL loaded from the OLD register (used 0: 4.5 / 9), the
 *    September CSL accrual, then HR corrected CSL to the corrected register
 *    figure by hand — Gayatri −1 then −2 (−3), Sunita −1.5 — which took the
 *    CSL September back with the history. Then, as production stands today:
 *    the September fix credits September on Earned Leave (once), Gayatri takes
 *    0.5 day, and both are already restated to the HR register (NO CHANGE):
 *    Gayatri 2.5 + 1 − 0.5 = 3, Sunita 8.5 + 1 = 9.5.
 *  - Nikita: register 1, September CSL accrual, then a genuine HR correction −2 ("System Issuee").
 *  - Shradha: register −1 (0 + 2 − 3), September CSL accrual → 0.
 *  - Ankita: register 7, September CSL accrual → 8; migrated opening 8, split by
 *    leave:earned-opening-sources into carry forward 5 / current year 3.
 */
function hhProduction(): void
{
    test()->sunita = hhEmployee('CNS046', 'qa@businessenergyservices.co.uk', 'Sunita Kotak');
    hhRegisterOnCsl(test()->sunita, credit: 2, carry: 7, used: 0);
    test()->gayatri = hhEmployee('CNS005', 'carol@businessenergyservices.co.uk', 'Gayatri Navlakhe');
    hhRegisterOnCsl(test()->gayatri, credit: 2, carry: 2.5, used: 0);
    test()->nikita = hhEmployee('CNS007', 'nikita@conexus-ns.com', 'Nikita Dalal');
    hhRegisterOnCsl(test()->nikita, credit: 2, carry: 1, used: 2);
    test()->shradha = hhEmployee('CNS044', 'shradha.kotak@conexus-ns.com', 'Shradha');
    hhRegisterOnCsl(test()->shradha, credit: 2, carry: 0, used: 3);
    test()->ankita = hhEmployee('CNS047', 'ankita.rawat@conexus-ns.com', 'Ankita');
    hhRegisterOnCsl(test()->ankita, credit: 2, carry: 5, used: 0);
    @unlink(test()->register);
    test()->register = hhRegisterOf(['Yogesh', 'Pratish', 'Sunita', 'Gayatri', 'Nikita', 'Shradha', 'Ankita']);

    test()->travelTo(Carbon::parse('2026-10-01 00:30:00'));
    foreach ([test()->sunita, test()->gayatri, test()->nikita, test()->shradha, test()->ankita] as $employee) {
        hhCslAccrual($employee);
    }
    hhCslAdjust(test()->gayatri, 'debit', 1, 'Register correction', '2026-10-02');
    hhCslAdjust(test()->gayatri, 'debit', 2, 'Register correction — leave taken in September', '2026-10-05');
    hhCslAdjust(test()->sunita, 'debit', 1.5, 'Register correction', '2026-10-05');
    hhCslAdjust(test()->nikita, 'debit', 2, 'System Issuee', '2026-10-07');

    hhOctober(); // Yogesh 1 day on CSL (14 Oct), Yogesh/Pratish migrated 20 Oct, Pratish 1.5 on Earned (26 Oct)
    test()->travelTo(Carbon::parse('2026-10-20 11:00:00'));
    foreach ([test()->sunita, test()->gayatri, test()->nikita, test()->shradha, test()->ankita] as $employee) {
        hhMigrate($employee);
    }

    // leave:earned-opening-sources --apply.
    test()->travelTo(Carbon::parse('2026-10-22 10:00:00'));
    $sources = app(EarnedOpeningSourceService::class);
    foreach (['CNS021', 'CNS042', 'CNS047'] as $code) {
        $row = $sources->plan([$code])->sole();
        expect($row['status'])->toBeIn(EarnedOpeningSourceService::CLASSIFIABLE);
        $sources->apply($row, test()->hr, 'Evidence confirmed');
    }

    test()->travelTo(Carbon::parse('2026-10-28 10:00:00'));
    hhTaken(test()->gayatri, test()->earned, 0.5, '2026-10-28');
    test()->travelTo(Carbon::parse('2026-10-29 10:00:00'));
    hhSeptemberFix(test()->yogesh, test()->pratish, test()->gayatri, test()->sunita);
    foreach (['CNS005', 'CNS046'] as $code) {
        hhApply($code)->expectsOutputToContain('RECONCILED')->run();
    }
}

test('production shapes: Gayatri 3 and Sunita 9.5 NO CHANGE; Yogesh 4, Pratish 4, Nikita 0, Shradha 0, Ankita 8 MATCH', function () {
    hhProduction();
    $rows = hhPlan();

    expect(hhBreakdown($this->ankita))->toMatchArray(['opening' => 0.0, 'carry_forward' => 5.0, 'current_year_accrued' => 3.0, 'total_available' => 8.0])
        // Already restated: the CSL normalisation stays excluded, the Earned Leave September accrual counts once.
        ->and($rows['CNS005'])->toMatchArray(['hr_baseline' => 2.5, 'used_after' => 0.5, 'adjustments_after' => 0.0, 'accrual_after' => 1.0, 'excluded_after' => -2.0,
            'expected' => 3.0, 'existing' => 3.0, 'status' => History::NO_CHANGE])
        ->and($rows['CNS046'])->toMatchArray(['hr_baseline' => 8.5, 'used_after' => 0.0, 'adjustments_after' => 0.0, 'accrual_after' => 1.0, 'excluded_after' => -0.5,
            'expected' => 9.5, 'existing' => 9.5, 'status' => History::NO_CHANGE])
        ->and(hhBreakdown($this->gayatri))->toMatchArray(['carry_forward' => 2.5, 'current_year_accrued' => 3.0, 'opening' => 0.0,
            'historical_used' => 2.0, 'portal_used' => 0.5, 'total_available' => 3.0, 'monthly_credits' => 1, 'consistent' => true])
        ->and(hhBreakdown($this->sunita))->toMatchArray(['carry_forward' => 7.0, 'current_year_accrued' => 3.0, 'opening' => 0.0,
            'historical_used' => 0.5, 'portal_used' => 0.0, 'total_available' => 9.5, 'monthly_credits' => 1, 'consistent' => true])
        ->and($rows['CNS021'])->toMatchArray(['hr_baseline' => 4.0, 'used_after' => 1.0, 'adjustments_after' => 0.0, 'accrual_after' => 1.0, 'excluded_after' => 0.0,
            'expected' => 4.0, 'existing' => 4.0, 'status' => History::MATCH, 'apply_blocker' => null])
        ->and($rows['CNS042'])->toMatchArray(['hr_baseline' => 4.5, 'used_after' => 1.5, 'adjustments_after' => 0.0, 'accrual_after' => 1.0,
            'expected' => 4.0, 'existing' => 4.0, 'status' => History::MATCH, 'apply_blocker' => null])
        // The genuine HR correction counts: 1 + September 1 − 2 = 0.
        ->and($rows['CNS007'])->toMatchArray(['hr_baseline' => 1.0, 'used_after' => 0.0, 'adjustments_after' => -2.0, 'accrual_after' => 1.0, 'excluded_after' => 0.0,
            'expected' => 0.0, 'existing' => 0.0, 'status' => History::MATCH, 'apply_blocker' => null])
        // A baseline below zero, offset by September: −1 + 1 = 0.
        ->and($rows['CNS044'])->toMatchArray(['hr_baseline' => -1.0, 'adjustments_after' => 0.0, 'accrual_after' => 1.0,
            'expected' => 0.0, 'existing' => 0.0, 'status' => History::MATCH, 'apply_blocker' => null])
        // September counted once, although the opening (and its split) absorbed it: 7 + 1 = 8.
        ->and($rows['CNS047'])->toMatchArray(['hr_baseline' => 7.0, 'adjustments_after' => 0.0, 'accrual_after' => 1.0, 'excluded_after' => 0.0,
            'expected' => 8.0, 'existing' => 8.0, 'status' => History::MATCH, 'apply_blocker' => null]);

    $classes = fn (string $code) => collect($rows[$code]['entries'])->where('ledger', 'CSL')->whereNotIn('class', [History::C_REGISTER])->pluck('class', 'days')->all();
    expect($classes('CNS005'))->toBe(['1' => History::C_NORMALISATION, '-1' => History::C_NORMALISATION, '-2' => History::C_NORMALISATION])
        ->and($classes('CNS007'))->toBe(['1' => History::C_ACCRUAL, '-2' => History::C_ADJUSTMENT]);

    // September is present exactly once for Gayatri and Sunita: the Earned Leave accrual counts, the CSL one was settled.
    foreach (['CNS005', 'CNS046'] as $code) {
        $september = collect($rows[$code]['entries'])->filter(fn ($e) => $e['type'] === LeaveLedgerEntry::TYPE_ACCRUAL);
        expect($september->where('counted', true)->map(fn ($e) => [$e['ledger'], $e['days']])->values()->all())->toBe([['Earned', 1.0]], $code)
            ->and($september->where('ledger', 'CSL')->pluck('class')->all())->toBe([History::C_NORMALISATION], $code);
    }

    $this->artisan('leave:reconcile-hr-history', ['--register' => $this->register, '--employee' => ['CNS007'], '--details' => true])
        ->expectsOutputToContain('monthly accrual for 2026-09 — not covered by the HR credit')
        ->expectsOutputToContain('by user '.$this->hr->id.': "System Issuee"')->assertSuccessful();
    $this->artisan('leave:reconcile-hr-history', ['--register' => $this->register, '--employee' => ['CNS005'], '--details' => true])
        ->expectsOutputToContain('Not counted (CSL bridge / migration corrections): -2')
        ->expectsOutputToContain('settled by the register normalisation #')
        ->expectsOutputToContain('brings CSL from the loaded 4.5 to the HR register\'s 2.5')->assertSuccessful();
    $this->artisan('leave:reconcile-hr-history', ['--register' => $this->register, '--employee' => ['CNS047'], '--details' => true])
        ->expectsOutputToContain('Leave V2 bookkeeping — the HR baseline replaces it')
        ->expectsOutputToContain('monthly accrual for 2026-09 — not covered by the HR credit')->assertSuccessful();
});

test('apply restates every production shape, keeps every total, never goes below zero, and a second run is NO CHANGE', function () {
    hhProduction();
    $expected = [
        'CNS021' => [$this->yogesh, 4.0, 7.0, 3.0, 5.0, 1.0, 0.0], 'CNS042' => [$this->pratish, 4.0, 8.0, 3.0, 5.5, 1.5, 0.0],
        'CNS007' => [$this->nikita, 0.0, 1.0, 3.0, 2.0, 0.0, -2.0], 'CNS044' => [$this->shradha, 0.0, 0.0, 3.0, 3.0, 0.0, 0.0],
        'CNS047' => [$this->ankita, 8.0, 5.0, 3.0, 0.0, 0.0, 0.0],
    ];

    foreach ($expected as $code => [$employee, $total, $carry, $current, $historical, $portal, $hrAdjustment]) {
        expect(hhBreakdown($employee)['total_available'])->toBe($total, $code);
        hhApply($code)->expectsOutputToContain('RECONCILED')->assertSuccessful();
        expect(hhBreakdown($employee))->toMatchArray(['carry_forward' => $carry, 'current_year_accrued' => $current, 'opening' => 0.0, 'hr_adjustment' => $hrAdjustment,
            'historical_used' => $historical, 'portal_used' => $portal, 'total_available' => $total, 'consistent' => true]);
    }

    // Nikita's correction now lives on Earned Leave, still linked to its adjustment record.
    $moved = LeaveLedgerEntry::where('employee_id', $this->nikita->id)->where('leave_type_id', $this->earned->id)->where('entry_type', LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT)->sole();
    expect($moved->source_type)->toBe('leave_balance_adjustment')
        ->and(LeaveBalanceAdjustment::find($moved->source_id)?->reason)->toBe('System Issuee')
        ->and(LeaveLedgerEntry::where('employee_id', $this->ankita->id)->where('idempotency_key', 'like', 'hr-history:%:current_year')->sole()->reason)->toContain('2026-09 CSL monthly accrual');

    $entries = LeaveLedgerEntry::count();
    foreach ([...array_keys($expected), 'CNS005', 'CNS046'] as $code) {
        hhApply($code)->expectsOutputToContain('NO CHANGE')->assertSuccessful();
    }
    expect(LeaveLedgerEntry::count())->toBe($entries)
        ->and(hhBreakdown($this->gayatri)['total_available'])->toBe(3.0)
        ->and(hhBreakdown($this->sunita)['total_available'])->toBe(9.5)
        ->and(hhPlan()->pluck('status')->unique()->values()->all())->toBe([History::NO_CHANGE])
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([])
        ->and(app(EarnedOpeningSourceService::class)->plan(['CNS021'])->sole()['status'])->toBe(EarnedOpeningSourceService::DONE);
    $this->artisan('leave:verify', ['--year' => '2026/27'])->assertSuccessful();
});

test('after a register normalisation, a later genuine HR correction on CSL still counts and moves with its record', function () {
    hhProduction();
    $this->hasan = hhEmployee('CNS090', 'harry@businessenergyservices.co.uk', 'Hasan');
    hhRegisterOnCsl($this->hasan, credit: 2, carry: 2, used: 0);              // loaded from the old register: 4
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Hasan', 'emails' => ['harry@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 2, 'used' => 1, 'encashed' => 0, 'available' => 3]]);
    hhCslAccrual($this->hasan);                                              // 5
    hhCslAdjust($this->hasan, 'debit', 2, 'Register correction', '2026-10-03');      // 3 — the corrected HR figure
    hhCslAdjust($this->hasan, 'debit', 0.5, 'System issue', '2026-10-08');           // 2.5 — genuine
    hhMigrate($this->hasan);

    $row = hhPlan(['CNS090'])['CNS090'];
    expect($row)->toMatchArray(['hr_baseline' => 3.0, 'adjustments_after' => -0.5, 'accrual_after' => 0.0, 'excluded_after' => -1.0,
        'expected' => 2.5, 'existing' => 2.5, 'status' => History::MATCH, 'apply_blocker' => null])
        ->and(collect($row['entries'])->where('ledger', 'CSL')->whereNotIn('class', [History::C_REGISTER])->pluck('class')->all())
        ->toBe([History::C_NORMALISATION, History::C_NORMALISATION, History::C_ADJUSTMENT]);

    hhApply('CNS090')->expectsOutputToContain('RECONCILED')->assertSuccessful();
    expect(hhBreakdown($this->hasan))->toMatchArray(['carry_forward' => 2.0, 'current_year_accrued' => 2.0, 'historical_used' => 1.0, 'hr_adjustment' => -0.5,
        'opening' => 0.0, 'total_available' => 2.5, 'consistent' => true]);
    hhApply('CNS090')->expectsOutputToContain('NO CHANGE')->assertSuccessful();
    expect(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
});

test('a correction that does not land on the HR figure is never a normalisation: it counts, and the drift is a DIFFERENCE', function () {
    hhProduction();
    $this->hasan = hhEmployee('CNS090', 'harry@businessenergyservices.co.uk', 'Hasan');
    hhRegisterOnCsl($this->hasan, credit: 2, carry: 2, used: 0);
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Hasan', 'emails' => ['harry@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 2, 'used' => 1, 'encashed' => 0, 'available' => 3]]);
    hhCslAccrual($this->hasan);
    hhCslAdjust($this->hasan, 'debit', 1.5, 'Register correction', '2026-10-03');    // 3.5, not 3
    hhMigrate($this->hasan);

    $row = hhPlan(['CNS090'])['CNS090'];
    expect($row)->toMatchArray(['adjustments_after' => -1.5, 'accrual_after' => 1.0, 'expected' => 2.5, 'existing' => 3.5, 'status' => History::DIFFERENCE])
        ->and($row['reason'])->toContain('CSL was loaded with 4 for the HR period but the register now says 3');
    hhApply('CNS090')->expectsOutputToContain('Refused')->assertFailed();
});

test('September credited on CSL and again on Earned Leave, or an expected balance below zero, is NEEDS REVIEW', function () {
    hhProduction();
    $balance = hhBalance($this->ankita, $this->earned);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, 1, Carbon::parse('2026-09-30'),
        EarnedLeaveAccrualService::KEY_PREFIX.":{$this->ankita->id}:{$this->year->id}:2026-09", ['source_type' => 'earned_leave_accrual', 'meta' => ['month' => '2026-09']]);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    $row = hhPlan(['CNS047'])['CNS047'];
    expect($row['status'])->toBe(History::REVIEW)->and($row['reason'])->toContain('2026-09 is credited more than once');
    hhApply('CNS047')->expectsOutputToContain('Refused')->assertFailed();

    // Shradha with one more day used in the HR period: −2 + September 1 = −1.
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Shradha', 'emails' => ['shradha.kotak@conexus-ns.com'], 'credit' => 2, 'carry' => 0, 'used' => 4, 'encashed' => 0, 'available' => -2]]);
    $row = hhPlan(['CNS044'])['CNS044'];
    expect($row['status'])->toBe(History::REVIEW)->and($row['reason'])->toContain('expected balance is negative (-1)');
    hhApply('CNS044')->expectsOutputToContain('Refused')->assertFailed();
});

test('a genuine HR adjustment on Earned Leave after the cutoff still counts; an HR credit marked as an opening correction does not', function () {
    hhProduction();
    app(LeaveBalanceService::class)->adjust($this->sunita, $this->earned, 'credit', 1, 'Worked a holiday', '', $this->hr, 2026,
        addOnType: 'manual_adjustment', effectiveDate: Carbon::parse('2026-10-29'));

    expect(hhPlan()['CNS046'])->toMatchArray(['adjustments_after' => 1.0, 'expected' => 10.5, 'existing' => 10.5, 'status' => History::NO_CHANGE]);

    // HR "corrected" the opening on Earned Leave as well — the register already states that history.
    app(LeaveBalanceService::class)->adjust($this->sunita, $this->earned, 'credit', 0.5, 'Opening fix', '', $this->hr, 2026,
        addOnType: 'opening_balance_correction', effectiveDate: Carbon::parse('2026-10-29'));
    expect(hhPlan()['CNS046'])->toMatchArray(['adjustments_after' => 1.0, 'expected' => 10.5, 'existing' => 11.0,
        'difference' => 0.5, 'status' => History::DIFFERENCE]);
    hhApply('CNS046')->expectsOutputToContain('Refused')->assertFailed();
});

test('an Earned Leave credit of unknown origin is NEEDS REVIEW, never read as mechanics or business', function () {
    hhProduction();
    $balance = hhBalance($this->gayatri, $this->earned);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_BASE, 12, Carbon::parse('2026-07-01'), 'hh-provisioned', ['source_type' => 'leave_entitlement']);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    $row = hhPlan()['CNS005'];
    expect($row['status'])->toBe(History::REVIEW)->and($row['reason'])->toContain('cannot read');
    hhApply('CNS005')->expectsOutputToContain('Refused')->assertFailed();
});

test('a register normalisation posted without an adjustment record still settles the September accrual before it', function () {
    hhProduction();
    $this->reeba = hhEmployee('CNS091', 'reeba@businessenergyservices.co.uk', 'Reeba');
    hhRegisterOnCsl($this->reeba, credit: 2, carry: 0, used: 1);             // loaded from the old register: 1
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Reeba', 'emails' => ['reeba@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 0, 'used' => 2, 'encashed' => 0, 'available' => 0]]);
    hhCslAccrual($this->reeba);                                              // 2
    $balance = hhBalance($this->reeba, $this->csl);
    app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT, 2, Carbon::parse('2026-10-04'), 'hh-reeba-fix',
        ['source_type' => 'leave_balance_adjustment', 'reason' => 'Register correction']);   // 0 — no adjustment record
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
    hhMigrate($this->reeba);

    $row = hhPlan(['CNS091'])['CNS091'];
    expect($row)->toMatchArray(['hr_baseline' => 0.0, 'accrual_after' => 0.0, 'adjustments_after' => 0.0, 'excluded_after' => -1.0,
        'expected' => 0.0, 'existing' => 0.0, 'status' => History::MATCH, 'apply_blocker' => null]);

    hhApply('CNS091')->expectsOutputToContain('RECONCILED')->assertSuccessful();
    expect(hhBreakdown($this->reeba))->toMatchArray(['current_year_accrued' => 2.0, 'historical_used' => 2.0, 'total_available' => 0.0, 'consistent' => true]);
    hhApply('CNS091')->expectsOutputToContain('NO CHANGE')->assertSuccessful();
});

// ── 9 Nov 2026: the HR sheet vs live — leave V1 charged to Sick Leave, and monthly accrual never credited ──

/** A V1 reason with its own old balance (Sick Leave held 6 days a year before Leave V2). */
function hhSickReason(): LeaveType
{
    return LeaveType::firstWhere('code', 'SLH') ?? (test()->sick = LeaveType::create(['name' => 'Sick Leave', 'code' => 'SLH', 'category' => 'sick', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#6366f1']));
}

/** A day V1 approved on the reason's own balance: the request names no Earned Leave pool. */
function hhTakenOnOldBalance(Employee $employee, string $date): LeaveRequest
{
    $sick = hhSickReason();
    $balance = hhBalance($employee, $sick);
    $ledger = app(LeaveLedgerService::class);
    if (! LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $sick->id)->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->exists()) {
        $ledger->credit($balance, LeaveLedgerEntry::TYPE_BASE, 6, Carbon::parse('2026-07-01'), "hh-sick-base-{$employee->id}", ['source_type' => 'leave_entitlement']);
    }
    $request = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $sick->id, 'balance_leave_type_id' => null,
        'start_date' => $date, 'end_date' => $date, 'days' => 1, 'reason' => 'Fever', 'status' => 'approved', 'requested_leave_status' => 'paid']);
    $ledger->debit($balance->fresh(), LeaveLedgerEntry::TYPE_USAGE, 1, Carbon::parse($date), "hh-sick-{$request->id}", ['source_type' => 'leave_request', 'source_id' => $request->id]);
    $ledger->rebuild($balance->fresh());

    return $request;
}

/** Firoz: HR 1 + 2 − 2.5 = 0.5 at 30 Sep; a sick day on 15 Oct charged to Sick Leave; migrated on 20 Oct; no Monthly Accrual Starts From. */
function hhFiroz(): Employee
{
    test()->firoz = hhEmployee('CNS019', 'firoz.khan@conexus-ns.com', 'Firoz');
    hhRegisterOnCsl(test()->firoz, credit: 2, carry: 1, used: 2.5);
    @unlink(test()->register);
    test()->register = hhRegister([['name' => 'Firoz', 'emails' => ['firoz.khan@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 2.5, 'encashed' => 0, 'available' => 0.5]]);
    // As production shows (9 Nov preview): September credited on CSL, then taken back by HR.
    test()->travelTo(Carbon::parse('2026-10-01 00:30:00'));
    hhCslAccrual(test()->firoz);
    hhCslAdjust(test()->firoz, 'debit', 1, 'September is credited on Earned Leave', '2026-10-07');
    test()->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    test()->firozSick = hhTakenOnOldBalance(test()->firoz, '2026-10-15');
    test()->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    hhMigrate(test()->firoz);
    test()->travelTo(Carbon::parse('2026-11-09 10:00:00'));

    return test()->firoz;
}

test('Firoz: the sheet says 0.5 at 30 Sep; September and October were never credited and the 15 Oct sick day sits on Sick Leave — the preview says so and what to do', function () {
    hhFiroz();

    $row = hhPlan(['CNS019'])['CNS019'];
    expect($row)->toMatchArray(['hr_baseline' => 0.5, 'used_after' => 1.0, 'accrual_after' => 0.0, 'charged_elsewhere' => 1.0,
        'expected' => -0.5, 'existing' => 0.5, 'accrual_due' => ['2026-09', '2026-10'], 'accrual_missing' => ['2026-09', '2026-10'],
        'expected_today' => 1.5, 'status' => History::REVIEW])
        ->and($row['charged_elsewhere_label'])->toBe('Sick Leave LR-'.str_pad((string) $this->firozSick->id, 4, '0', STR_PAD_LEFT))
        ->and($row['reason'])->toContain('Monthly accrual not credited for Sep 2026, Oct 2026 (+2 when credited)')
        ->and($row['next_step'])->toStartWith('set Monthly Accrual Starts From to Sep 2026')
        ->and($row['next_step'])->toContain('leave:accrue-earned --apply --employee=CNS019');

    $this->artisan('leave:reconcile-hr-history', ['--register' => $this->register, '--employee' => ['CNS019']])
        ->expectsOutputToContain('Accrual Not Credited')->expectsOutputToContain('Sep 2026, Oct 2026')
        ->expectsOutputToContain('Agree with the HR register and monthly accrual: 0 of 1.')->assertSuccessful();
});

test('Firoz once HR sets the accrual start: CHARGE TO EARNED, and apply restates him and moves the sick day — 0.5 + 2 − 1 = 1.5, the request untouched', function () {
    hhFiroz();
    app(LeaveAccrualTransitionService::class)->set($this->firoz, '2026-09-01', 'HR credit covers Jul–Aug', $this->hr);
    $accrual = app(EarnedLeaveAccrualService::class);
    $planned = $accrual->planFor($this->firoz->fresh(), $this->year, Carbon::today());
    expect($planned['due_months'])->toBe(['2026-09', '2026-10']);
    $accrual->apply($planned);
    $requestBefore = $this->firozSick->fresh()->getAttributes();

    expect(hhPlan(['CNS019'])['CNS019'])->toMatchArray(['expected' => 1.5, 'existing' => 2.5, 'difference' => 1.0, 'charged_elsewhere' => 1.0,
        'accrual_missing' => [], 'status' => History::CHARGE, 'apply_blocker' => null]);

    hhApply('CNS019')->expectsOutputToContain('Sick Leave LR-'.str_pad((string) $this->firozSick->id, 4, '0', STR_PAD_LEFT).' moved to Earned Leave; available 2.5 → 1.5')->assertSuccessful();

    expect(hhBreakdown($this->firoz))->toMatchArray(['carry_forward' => 1.0, 'current_year_accrued' => 4.0, 'opening' => 0.0,
        'historical_used' => 2.5, 'portal_used' => 1.0, 'total_available' => 1.5, 'consistent' => true])
        // Sick Leave no longer holds the day; cancelling it would return it to Earned Leave.
        ->and(app(LeaveLedgerService::class)->activeEntryFor('leave_request', $this->firozSick->id, LeaveLedgerEntry::TYPE_USAGE)->leave_type_id)->toBe($this->earned->id)
        ->and((float) app(LeaveBalanceCalculator::class)->summary(hhBalance($this->firoz, $this->sick))['used'])->toBe(0.0)
        ->and($this->firozSick->fresh()->getAttributes())->toEqual($requestBefore)
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
    $this->artisan('leave:verify', ['--year' => '2026/27'])->assertSuccessful();

    $entries = LeaveLedgerEntry::count();
    hhApply('CNS019')->expectsOutputToContain('NO CHANGE')->assertSuccessful();
    expect(LeaveLedgerEntry::count())->toBe($entries);
});

test('an employee already restated with a sick day left on Sick Leave: apply only moves the day (Yogesh 4 → 3), then NO CHANGE', function () {
    hhFourStaff();
    hhApply('CNS021')->assertSuccessful();
    $bases = LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('leave_type_id', $this->earned->id)->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->count();
    $sick = hhTakenOnOldBalance($this->yogesh, '2026-10-16');

    expect(hhPlan(['CNS021'])['CNS021'])->toMatchArray(['expected' => 3.0, 'existing' => 4.0, 'status' => History::CHARGE, 'apply_blocker' => null]);
    hhApply('CNS021')->expectsOutputToContain('RECONCILED')->assertSuccessful();

    expect(hhBreakdown($this->yogesh))->toMatchArray(['carry_forward' => 7.0, 'historical_used' => 5.0, 'portal_used' => 2.0, 'total_available' => 3.0, 'consistent' => true])
        ->and(LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('leave_type_id', $this->earned->id)->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->count())->toBe($bases)
        ->and(app(LeaveLedgerService::class)->activeEntryFor('leave_request', $sick->id, LeaveLedgerEntry::TYPE_USAGE)->leave_type_id)->toBe($this->earned->id)
        ->and(AuditLog::where('event', 'LEAVE_CHARGED_TO_EARNED')->where('subject_employee_id', $this->yogesh->id)->count())->toBe(1);
    hhApply('CNS021')->expectsOutputToContain('NO CHANGE')->assertSuccessful();
});

test('a restated employee whose October accrual was never credited: still NO CHANGE, but the missing month and the command to credit it are shown', function () {
    hhFourStaff();
    hhApply('CNS021')->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-11-09 10:00:00'));

    $row = hhPlan(['CNS021'])['CNS021'];
    expect($row)->toMatchArray(['status' => History::NO_CHANGE, 'expected' => 4.0, 'existing' => 4.0, 'accrual_missing' => ['2026-10'], 'expected_today' => 5.0])
        ->and($row['next_step'])->toBe('php artisan leave:accrue-earned --apply --employee=CNS021 → re-run this preview');

    $accrual = app(EarnedLeaveAccrualService::class);
    $accrual->apply($accrual->planFor($this->yogesh->fresh(), $this->year, Carbon::today()));

    expect(hhPlan(['CNS021'])['CNS021'])->toMatchArray(['status' => History::NO_CHANGE, 'expected' => 5.0, 'existing' => 5.0, 'accrual_missing' => [], 'next_step' => '—']);
});

/**
 * The 9 Nov production shape: CSL loaded from the register, September credited
 * on CSL (1 Oct) and taken back by an HR deduction of 1, migrated 20 Oct.
 * With $accrue, HR set Monthly Accrual Starts From = Sep 2026 and Earned
 * Leave credited September and October.
 */
function hhNovemberShape(string $code, string $email, string $name, float $credit, float $carry, float $used, bool $accrue): Employee
{
    $employee = hhEmployee($code, $email, $name);
    hhRegisterOnCsl($employee, credit: $credit, carry: $carry, used: $used);
    test()->travelTo(Carbon::parse('2026-10-01 00:30:00'));
    hhCslAccrual($employee);
    hhCslAdjust($employee, 'debit', 1, 'September is credited on Earned Leave', '2026-10-07');
    test()->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    hhMigrate($employee);
    test()->travelTo(Carbon::parse('2026-11-09 10:00:00'));
    if ($accrue) {
        app(LeaveAccrualTransitionService::class)->set($employee, '2026-09-01', 'HR credit covers Jul–Aug', test()->hr);
        $accrual = app(EarnedLeaveAccrualService::class);
        $accrual->apply($accrual->planFor($employee->fresh(), test()->year, Carbon::today()));
    }

    return $employee;
}

test('production 9 Nov: September on CSL taken back by HR and credited on Earned Leave is read once — Chaitanya 3.5 + 2 = 5.5 MATCH, not "credited more than once"', function () {
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Chaitanya', 'emails' => ['chaitanya.naik@conexus-ns.com'], 'credit' => 2, 'carry' => 4, 'used' => 2.5, 'encashed' => 0, 'available' => 3.5]]);
    $chaitanya = hhNovemberShape('CNS051', 'chaitanya.naik@conexus-ns.com', 'Chaitanya', 2, 4, 2.5, accrue: true);

    $row = hhPlan(['CNS051'])['CNS051'];
    expect($row)->toMatchArray(['hr_baseline' => 3.5, 'adjustments_after' => 0.0, 'accrual_after' => 2.0, 'excluded_after' => 0.0,
        'expected' => 5.5, 'existing' => 5.5, 'accrual_missing' => [], 'status' => History::MATCH, 'apply_blocker' => null])
        ->and(collect($row['entries'])->where('ledger', 'CSL')->where('class', History::C_WITHDRAWN)->pluck('days')->all())->toBe([1.0, -1.0]);

    hhApply('CNS051')->expectsOutputToContain('available unchanged at 5.5')->assertSuccessful();
    expect(hhBreakdown($chaitanya))->toMatchArray(['carry_forward' => 4.0, 'current_year_accrued' => 4.0, 'historical_used' => 2.5, 'total_available' => 5.5, 'consistent' => true])
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
});

test('production 9 Nov: Shivani — September taken back on CSL but Earned Leave never credited it: 0 now, 2 once September and October are credited', function () {
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Shivani', 'emails' => ['shivani.shinde@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 3, 'encashed' => 0, 'available' => 0]]);
    $shivani = hhNovemberShape('CNS037', 'shivani.shinde@conexus-ns.com', 'Shivani', 2, 1, 3, accrue: false);

    $row = hhPlan(['CNS037'])['CNS037'];
    expect($row)->toMatchArray(['expected' => 0.0, 'existing' => 0.0, 'accrual_missing' => ['2026-09', '2026-10'], 'expected_today' => 2.0, 'status' => History::MATCH])
        ->and($row['next_step'])->toStartWith('set Monthly Accrual Starts From to Sep 2026');

    app(LeaveAccrualTransitionService::class)->set($shivani, '2026-09-01', 'HR credit covers Jul–Aug', $this->hr);
    $accrual = app(EarnedLeaveAccrualService::class);
    $accrual->apply($accrual->planFor($shivani->fresh(), $this->year, Carbon::today()));

    expect(hhPlan(['CNS037'])['CNS037'])->toMatchArray(['expected' => 2.0, 'existing' => 2.0, 'accrual_missing' => [], 'status' => History::MATCH]);
});

test('production 9 Nov: Nikita on the corrected register (3 used to 30 Sep) — HR\'s 7 Oct −2 brought CSL to it; September and October are still to credit (0 now, 2 then)', function () {
    $nikita = hhEmployee('CNS007', 'nikita@conexus-ns.com', 'Nikita Dalal');
    hhRegisterOnCsl($nikita, credit: 2, carry: 1, used: 2);                 // loaded from the old register: 1
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Nikita Dalal', 'emails' => ['nikita@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 3, 'encashed' => 0, 'available' => 0]]);
    $this->travelTo(Carbon::parse('2026-10-01 00:30:00'));
    hhCslAccrual($nikita);                                                   // 2
    hhCslAdjust($nikita, 'debit', 2, 'System Issuee', '2026-10-07');        // 0
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    hhMigrate($nikita);
    $this->travelTo(Carbon::parse('2026-11-09 10:00:00'));

    expect(hhPlan(['CNS007'])['CNS007'])->toMatchArray(['hr_baseline' => 0.0, 'adjustments_after' => 0.0, 'accrual_after' => 0.0,
        'expected' => 0.0, 'existing' => 0.0, 'accrual_missing' => ['2026-09', '2026-10'], 'expected_today' => 2.0, 'status' => History::MATCH]);
});

test('HR makes Firoz\'s unpaid 15 Oct sick day (from before Leave V2) paid: it comes out of Earned Leave — 2.5 → 1.5, as the October HR sheet says — and the check stays NO CHANGE', function () {
    @unlink($this->register);
    $this->register = hhRegister([['name' => 'Firoz', 'emails' => ['firoz.khan@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 2.5, 'encashed' => 0, 'available' => 0.5]]);
    $firoz = hhNovemberShape('CNS027', 'firoz.khan@conexus-ns.com', 'Firoz', 2, 1, 2.5, accrue: true);
    hhApply('CNS027')->expectsOutputToContain('available unchanged at 2.5')->assertSuccessful();

    // V1 approved it unpaid on Sick Leave: no balance named, nothing on any ledger.
    $sick = hhSickReason();
    $sick->update(['allow_hr_override' => true]);
    $request = LeaveRequest::create(['employee_id' => $firoz->id, 'leave_type_id' => $sick->id, 'balance_leave_type_id' => null,
        'start_date' => '2026-10-15', 'end_date' => '2026-10-15', 'days' => 1, 'reason' => 'Fever', 'status' => 'approved', 'requested_leave_status' => 'unpaid']);
    expect(hhPlan(['CNS027'])['CNS027'])->toMatchArray(['existing' => 2.5, 'status' => History::NO_CHANGE]);

    app(LeaveService::class)->hrOverridePaymentStatus($request->fresh(), $this->hr, 'paid', 'Counted as paid sick leave in the HR register');

    expect($request->fresh())->toMatchArray(['balance_leave_type_id' => $this->earned->id, 'approved_leave_status' => 'paid', 'status' => 'approved'])
        ->and(app(LeaveLedgerService::class)->activeEntryFor('leave_request', $request->id, LeaveLedgerEntry::TYPE_USAGE)->leave_type_id)->toBe($this->earned->id)
        ->and(hhBreakdown($firoz))->toMatchArray(['portal_used' => 1.0, 'total_available' => 1.5])
        ->and(hhPlan(['CNS027'])['CNS027'])->toMatchArray(['used_after' => 1.0, 'expected' => 1.5, 'existing' => 1.5, 'status' => History::NO_CHANGE])
        ->and(LeaveBalance::where('employee_id', $firoz->id)->where('leave_type_id', $sick->id)->exists())->toBeFalse()
        ->and(app(LeaveIntegrityService::class)->scan($this->year)->all())->toBe([]);
});
