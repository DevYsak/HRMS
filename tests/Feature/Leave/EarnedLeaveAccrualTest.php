<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\EarnedLeaveAccrualService as Accrual;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveAccrualTransitionService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveV2MigrationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Leave V2 Earned Leave accrual: 1 day per COMPLETED month of the Jul–Jun
 * leave year (12 a year), the only entitlement source, idempotent per month,
 * catching up missed months, and never re-crediting months a migrated
 * opening already holds.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-07-15 10:00:00'));
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    $this->policy = LeavePolicy::create(['name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELV2', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $this->rule = LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12,
        'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1, 'joining_month_rule' => LeavePolicyRule::JOINING_HALF_MONTH, 'is_active' => true]);

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'email' => 'hr.accrual@example.com']);
});

function eaEmployee(string $code, string $joined = '2024-01-10', array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active', 'joining_date' => $joined, 'employee_id' => $code,
        'manager_id' => null, 'department_id' => Department::factory()->create()->id]);
}

function eaAvailable(Employee $employee, ?LeaveType $type = null): float
{
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', ($type ?? test()->earned)->id)->where('leave_year_id', test()->year->id)->first();

    return $balance ? (float) app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'] : 0.0;
}

function eaRun(Employee $employee, string $asOf): array
{
    $row = app(Accrual::class)->planFor($employee->fresh(), test()->year, Carbon::parse($asOf));

    return $row['status'] === Accrual::READY ? app(Accrual::class)->apply($row) : $row;
}

// ── A/B/C: months, reruns, catch-up and the year cap ─────────────────────────

test('July earns nothing until it is complete; the first day lands effective 31 July', function () {
    $employee = eaEmployee('EA-001');

    expect(eaRun($employee, '2026-07-31'))->toMatchArray(['status' => Accrual::NO_CHANGE, 'expected' => 0.0])
        ->and(eaAvailable($employee))->toEqual(0.0);

    $done = eaRun($employee, '2026-08-01');
    $entry = LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $this->earned->id)->sole();

    expect($done['status'])->toBe('CREDITED')
        ->and(eaAvailable($employee))->toEqual(1.0)
        ->and($entry->entry_type)->toBe(LeaveLedgerEntry::TYPE_ACCRUAL)
        ->and($entry->idempotency_key)->toBe("earned-accrual:{$employee->id}:{$this->year->id}:2026-07")
        ->and($entry->effective_date->toDateString())->toBe('2026-07-31')
        ->and($entry->created_by)->toBeNull()
        ->and($entry->meta['actor'])->toBe('system:manual');
});

test('running again adds nothing; a missed run catches up every completed month once', function () {
    $employee = eaEmployee('EA-002');
    eaRun($employee, '2026-08-01');
    expect(eaRun($employee, '2026-08-01')['status'])->toBe(Accrual::NO_CHANGE)
        ->and(eaAvailable($employee))->toEqual(1.0);

    // September and October runs were missed: November catches up Aug–Oct.
    expect(eaRun($employee, '2026-11-02')['due_months'])->toBe(['2026-08', '2026-09', '2026-10'])
        ->and(eaAvailable($employee))->toEqual(4.0);
    expect(eaRun($employee, '2026-11-02')['status'])->toBe(Accrual::NO_CHANGE)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $this->earned->id)->count())->toBe(4);
});

test('the year accrues 12 at most: 11 by June, the 12th when June completes', function () {
    $employee = eaEmployee('EA-003');

    eaRun($employee, '2027-06-15');
    expect(eaAvailable($employee))->toEqual(11.0);

    eaRun($employee, '2027-07-01');
    expect(eaAvailable($employee))->toEqual(12.0);

    expect(app(Accrual::class)->planFor($employee->fresh(), $this->year, Carbon::parse('2027-09-01'))['status'])->toBe(Accrual::NO_CHANGE)
        ->and(eaAvailable($employee))->toEqual(12.0);
});

test('joining and leaving mid-year: only months worked to their end are earned', function () {
    // Joined 20 Sep (after the 15th): September is not earned under the half-month rule.
    $joiner = eaEmployee('EA-004', '2026-09-20');
    expect(app(Accrual::class)->planFor($joiner->fresh(), $this->year, Carbon::parse('2026-12-01'))['due_months'])->toBe(['2026-10', '2026-11']);

    // Leaving 15 Oct: October is not completed.
    $leaver = eaEmployee('EA-005', '2024-01-10', ['status' => 'notice_period']);
    ExitRecord::create(['employee_id' => $leaver->id, 'last_working_day' => '2026-10-15', 'exit_type' => 'resignation']);
    expect(app(Accrual::class)->planFor($leaver->fresh(), $this->year, Carbon::parse('2026-12-01'))['due_months'])->toBe(['2026-07', '2026-08', '2026-09']);
});

// ── One entitlement source ───────────────────────────────────────────────────

test('Earned Leave is never granted up front, and the in-advance monthly job never touches it', function () {
    $employee = eaEmployee('EA-006');
    $this->rule->update(['accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT]);

    $ensure = app(EnsureEmployeeLeaveBalancesService::class)->ensureType($employee->fresh(), $this->earned, $this->year);
    expect($ensure['status'])->toBe(EnsureEmployeeLeaveBalancesService::ACCRUAL_ONLY)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $this->earned->id)->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->exists())->toBeFalse();

    $this->rule->update(['accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY]);
    expect(app(LeaveAccrualService::class)->creditOne($employee->fresh(), $this->earned, Carbon::parse('2026-08-01'), $this->year, 2026, 8))->toBe('skipped')
        ->and(eaAvailable($employee))->toEqual(0.0);

    // The policy must say monthly, 12 a year, 1 a month.
    $this->rule->update(['accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT]);
    expect(app(Accrual::class)->planFor($employee->fresh(), $this->year, Carbon::parse('2026-09-01'))['status'])->toBe(Accrual::BLOCKED);
});

test('an existing up-front base is reported for review, never accrued on top', function () {
    $employee = eaEmployee('EA-007');
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $this->earned->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 12, Carbon::parse('2026-07-01'), 'ea-base-'.$employee->id);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    $row = eaRun($employee, '2026-11-02');
    expect($row['status'])->toBe(Accrual::REVIEW)
        ->and(eaAvailable($employee))->toEqual(12.0);
});

// ── Migrated employees ───────────────────────────────────────────────────────

test('a migrated employee waits for an explicit start date (suggested, never inferred), then accrues from it', function () {
    $csl = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true, 'color' => '#f97316']);
    $employee = eaEmployee('EA-008');
    $movements = app(LeaveMovementService::class);
    $ledger = app(LeaveLedgerService::class);
    $cslBalance = $movements->balanceFor($employee->id, $csl->id, Carbon::parse('2026-07-01'));
    $movements->ledgerReady($cslBalance);
    // Register base 2 (July, August) + September's monthly credit; 1 day used.
    $ledger->credit($cslBalance->fresh(), LeaveLedgerEntry::TYPE_BASE, 2, Carbon::parse('2026-07-01'), 'ea-csl-base');
    $ledger->credit($cslBalance->fresh(), LeaveLedgerEntry::TYPE_ACCRUAL, 1, Carbon::parse('2026-09-30'), 'ea-csl-sep');
    $ledger->debit($cslBalance->fresh(), LeaveLedgerEntry::TYPE_USAGE, 1, Carbon::parse('2026-08-10'), 'ea-csl-use');
    $ledger->rebuild($cslBalance->fresh());

    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    $migration = app(LeaveV2MigrationService::class);
    $migrated = $migration->apply($migration->planFor($employee->fresh(), $this->year, $csl), $this->year, $this->hr);
    expect($migrated['status'])->toBe(LeaveV2MigrationService::MIGRATED)
        ->and(eaAvailable($employee))->toEqual(2.0);

    $this->travelTo(Carbon::parse('2026-11-02 01:00:00'));
    $row = app(Accrual::class)->planFor($employee->fresh(), $this->year, Carbon::parse('2026-11-02'));
    // Its CSL held Jul–Sep: October is suggested, but nothing is credited until HR sets it.
    expect($row)->toMatchArray(['status' => Accrual::REVIEW, 'transition' => null, 'suggested_start' => '2026-10-01', 'due_months' => []]);

    app(LeaveAccrualTransitionService::class)->set($employee, '2026-10-01', 'Migration held Jul–Sep', $this->hr);
    $row = app(Accrual::class)->planFor($employee->fresh(), $this->year, Carbon::parse('2026-11-02'));
    expect($row)->toMatchArray(['status' => Accrual::READY, 'transition' => '2026-10-01', 'due_months' => ['2026-10']]);

    app(Accrual::class)->apply($row);
    expect(eaAvailable($employee))->toEqual(3.0)
        ->and(app(Accrual::class)->planFor($employee->fresh(), $this->year, Carbon::parse('2026-11-02'))['status'])->toBe(Accrual::NO_CHANGE);
});

test('before the switch, an employee awaiting migration is never accrued', function () {
    config(['leave_provisioning.policy_engine_v2' => false]);
    $employee = eaEmployee('EA-009');

    expect(eaRun($employee, '2026-11-02')['status'])->toBe(Accrual::BLOCKED)
        ->and(eaAvailable($employee))->toEqual(0.0);
});

// ── The command and the schedule ─────────────────────────────────────────────

test('the preview writes nothing; --apply credits READY employees and audits the run', function () {
    $employee = eaEmployee('EA-010');
    $this->travelTo(Carbon::parse('2026-10-01 00:20:00'));
    $counts = fn () => [LeaveLedgerEntry::count(), AuditLog::where('event', 'LEAVE_EARNED_ACCRUED')->count(), LeaveYear::count()];
    $before = $counts();

    $this->artisan('leave:accrue-earned', ['--preview' => true, '--employee' => ['EA-010']])
        ->expectsOutputToContain('PREVIEW — nothing is written')->expectsOutputToContain('READY')->assertSuccessful();
    expect($counts())->toBe($before);

    $this->artisan('leave:accrue-earned', ['--apply' => true, '--employee' => ['EA-010']])->assertSuccessful();
    expect(eaAvailable($employee))->toEqual(3.0)
        ->and(AuditLog::where('event', 'LEAVE_EARNED_ACCRUED')->where('subject_employee_id', $employee->id)->sole()->user_id)->toBeNull();

    $this->artisan('leave:accrue-earned', ['--apply' => true, '--employee' => ['EA-010']])->assertSuccessful();
    expect(eaAvailable($employee))->toEqual(3.0);
});

test('the monthly schedule runs the accrual only when it is switched on', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'leave:accrue-earned --apply'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 0 1 * *');

    config(['leave_provisioning.earned_accrual_enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();
    config(['leave_provisioning.earned_accrual_enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});
