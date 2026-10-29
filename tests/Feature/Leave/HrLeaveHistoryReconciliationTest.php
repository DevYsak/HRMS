<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveAccrualTransitionService;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveHrHistoryReconciliationService as History;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use App\Services\Leave\LeaveV2MigrationService;
use App\Services\LeaveBalanceService;
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
    $ledger->credit($balance->fresh(), LeaveLedgerEntry::TYPE_CARRY_FORWARD, $carry, Carbon::parse('2026-07-01'), "hh-carry-{$employee->id}", $opts);
    $ledger->debit($balance->fresh(), LeaveLedgerEntry::TYPE_USAGE, $used, Carbon::parse('2026-09-30'), "hh-used-{$employee->id}", $opts);
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
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_OPENING, 8, Carbon::parse('2026-07-01'), 'hh-sunita-opening');
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
