<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\LeaveAccrualTransition;
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
use App\Services\Leave\LeaveAccrualTransitionService as Transitions;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveIntegrityService as Integrity;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveV2MigrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * "Monthly Accrual Starts From": an employee who already holds Earned Leave
 * (an opening or an up-front grant) accrues only from an explicit date; the
 * balance held stays exactly as it is and earlier months are never
 * backfilled. Without the date they are NEEDS REVIEW.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    $this->policy = LeavePolicy::create(['name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELT', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $this->rule = LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12,
        'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1, 'joining_month_rule' => LeavePolicyRule::JOINING_HALF_MONTH, 'is_active' => true]);

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);

    // Mayuresh: an up-front base of 3 (this year's new entitlement, so 9
    // monthly credits remain). Alex: an opening of 12 of unknown origin.
    $this->mayuresh = atEmployee('CNX-0008');
    atCredit($this->mayuresh, LeaveLedgerEntry::TYPE_BASE, 3);
    $this->alex = atEmployee('GUIDE-0001');
    atCredit($this->alex, LeaveLedgerEntry::TYPE_OPENING, 12);
});

function atEmployee(string $code, string $joined = '2024-01-10'): Employee
{
    return Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active',
        'joining_date' => $joined, 'employee_id' => $code, 'manager_id' => null, 'department_id' => Department::factory()->create()->id]);
}

function atCredit(Employee $employee, string $type, float $days, string $on = '2026-07-01'): void
{
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, test()->earned->id, Carbon::parse($on));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), $type, $days, Carbon::parse($on), 'at-'.$type.'-'.$employee->id.'-'.$on);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
}

function atSummary(Employee $employee, ?LeaveYear $year = null): array
{
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', test()->earned->id)->where('leave_year_id', ($year ?? test()->year)->id)->first();

    return $balance ? app(LeaveBalanceCalculator::class)->summary($balance) : ['approved_available' => 0.0, 'opening' => 0.0, 'base' => 0.0, 'accrued' => 0.0];
}

function atPlan(Employee $employee, string $asOf, ?LeaveYear $year = null): array
{
    return app(Accrual::class)->planFor($employee->fresh(), $year ?? test()->year, Carbon::parse($asOf));
}

function atRun(Employee $employee, string $asOf, ?LeaveYear $year = null): array
{
    $row = atPlan($employee, $asOf, $year);

    return $row['status'] === Accrual::READY ? app(Accrual::class)->apply($row) : $row;
}

function atAccruals(Employee $employee): array
{
    return LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)->orderBy('id')->pluck('idempotency_key')->all();
}

// ── Without a start date: never inferred ─────────────────────────────────────

test('no start date with an up-front base, or an opening of unknown origin, is NEEDS REVIEW and credits nothing', function () {
    expect(atPlan($this->mayuresh, '2026-12-01'))->toMatchArray(['status' => Accrual::REVIEW, 'transition' => null, 'proposed' => 0.0])
        ->and(atPlan($this->mayuresh, '2026-12-01')['reason'])->toContain('up-front base entitlement')
        ->and(atPlan($this->alex, '2026-12-01'))->toMatchArray(['status' => Accrual::REVIEW, 'proposed' => 0.0])
        ->and(atPlan($this->alex, '2026-12-01')['reason'])->toContain('origin and months are unknown');

    atRun($this->mayuresh, '2026-12-01');
    expect(atAccruals($this->mayuresh))->toBe([])->and(atSummary($this->mayuresh)['approved_available'])->toEqual(3.0);
});

// ── With a start date of 1 November 2026 ─────────────────────────────────────

test('from 1 November: nothing mid-November, +1 on 1 December, +2 by 1 January — the held balance untouched, never backfilled', function () {
    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    app(Transitions::class)->set($this->alex, '2026-11-01', 'Monthly accrual from November', $this->hr);

    expect(atRun($this->mayuresh, '2026-11-15'))->toMatchArray(['status' => Accrual::NO_CHANGE, 'transition' => '2026-11-01', 'expected_months' => []]);

    $this->travelTo(Carbon::parse('2026-12-01 00:20:00'));
    expect(atRun($this->mayuresh, '2026-12-01'))->toMatchArray(['status' => 'CREDITED', 'due_months' => ['2026-11']]);
    expect(atRun($this->alex, '2026-12-01'))->toMatchArray(['status' => 'CREDITED', 'due_months' => ['2026-11']]);

    $this->travelTo(Carbon::parse('2027-01-01 00:20:00'));
    atRun($this->mayuresh, '2027-01-01');
    // Rerun: nothing more.
    expect(atRun($this->mayuresh, '2027-01-01')['status'])->toBe(Accrual::NO_CHANGE);

    $m = atSummary($this->mayuresh);
    $a = atSummary($this->alex);
    expect(atAccruals($this->mayuresh))->toBe(["earned-accrual:{$this->mayuresh->id}:{$this->year->id}:2026-11", "earned-accrual:{$this->mayuresh->id}:{$this->year->id}:2026-12"])
        // The base and the opening are exactly as they were; accrual sits beside them.
        ->and((float) $m['base'])->toEqual(3.0)->and((float) $m['accrued'])->toEqual(2.0)->and((float) $m['approved_available'])->toEqual(5.0)
        ->and((float) $a['opening'])->toEqual(12.0)->and((float) $a['accrued'])->toEqual(1.0)
        ->and((float) LeaveLedgerEntry::where('employee_id', $this->mayuresh->id)->where('leave_type_id', $this->earned->id)->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->sum('days'))->toEqual(3.0);
});

test('an up-front base of 12 is this year\'s whole new entitlement: no monthly credit on top, the balance untouched', function () {
    $full = atEmployee('CNX-0012');
    atCredit($full, LeaveLedgerEntry::TYPE_BASE, 12);
    app(Transitions::class)->set($full, '2026-11-01', 'Monthly accrual from November', $this->hr);
    $this->travelTo(Carbon::parse('2027-01-01 00:20:00'));

    $row = atRun($full, '2027-01-01');
    expect($row)->toMatchArray(['status' => Accrual::NO_CHANGE, 'due_months' => [], 'base_days' => 12.0])
        ->and($row['reason'])->toContain('12 days of new entitlement are already granted')
        ->and(atAccruals($full))->toBe([])
        ->and((float) atSummary($full)['approved_available'])->toEqual(12.0);
});

test('one employee is READY while another without a start date stays NEEDS REVIEW; --apply writes only the READY one', function () {
    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    $this->travelTo(Carbon::parse('2026-12-01 00:20:00'));

    $rows = app(Accrual::class)->plan(Carbon::parse('2026-12-01'))->keyBy('code');
    expect($rows['CNX-0008']['status'])->toBe(Accrual::READY)->and($rows['GUIDE-0001']['status'])->toBe(Accrual::REVIEW);

    $this->artisan('leave:accrue-earned', ['--preview' => true])
        ->expectsOutputToContain('Accrual starts')->expectsOutputToContain('2026-11-01')->expectsOutputToContain('NEEDS REVIEW')->assertSuccessful();
    expect(atAccruals($this->mayuresh))->toBe([]);

    $this->artisan('leave:accrue-earned', ['--apply' => true])->assertSuccessful();
    expect(atAccruals($this->mayuresh))->toHaveCount(1)->and(atAccruals($this->alex))->toBe([]);
});

test('a non-monthly Earned Leave rule is BLOCKED even with a start date', function () {
    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    $this->rule->update(['accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT]);

    expect(atPlan($this->mayuresh, '2026-12-01')['status'])->toBe(Accrual::BLOCKED);
});

test('a retry or a second run with the same plan credits once', function () {
    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    $this->travelTo(Carbon::parse('2027-01-01 00:20:00'));
    $plan = atPlan($this->mayuresh, '2027-01-01');

    app(Accrual::class)->apply($plan);
    $again = app(Accrual::class)->apply($plan);

    expect($again['status'])->toBe(Accrual::NO_CHANGE)
        ->and(atAccruals($this->mayuresh))->toHaveCount(2)
        ->and((float) atSummary($this->mayuresh)['accrued'])->toEqual(2.0);
});

test('an up-front grant dated after the start date is NEEDS REVIEW (it would grant twice)', function () {
    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    atCredit($this->mayuresh, LeaveLedgerEntry::TYPE_BASE, 3, '2026-11-10');

    expect(atPlan($this->mayuresh, '2026-12-01')['status'])->toBe(Accrual::REVIEW);
});

// ── Year boundary and new joiners ────────────────────────────────────────────

test('the next leave year accrues from July — the start date is not an anchor', function () {
    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    $next = LeaveYear::firstOrCreate(['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'], ['label' => '2027/28']);
    $this->travelTo(Carbon::parse('2027-07-01 00:20:00'));
    expect(atRun($this->mayuresh, '2027-07-01')['due_months'])->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06']);

    $this->travelTo(Carbon::parse('2027-08-01 00:20:00'));
    expect(atRun($this->mayuresh, '2027-08-01', $next))->toMatchArray(['status' => 'CREDITED', 'due_months' => ['2027-07']])
        ->and((float) atSummary($this->mayuresh, $next)['accrued'])->toEqual(1.0);
});

test('a new joiner needs no start date: accrual follows their own joining date, never an earlier one', function () {
    // Joined on the 1st: December is a whole month (a partial month is covered below).
    $joiner = atEmployee('NEW-0001', '2026-12-01');
    $this->travelTo(Carbon::parse('2027-02-01 00:20:00'));
    expect(atPlan($joiner, '2027-02-01'))->toMatchArray(['status' => Accrual::READY, 'transition' => null, 'due_months' => ['2026-12', '2027-01']]);

    // A start date earlier than joining changes nothing: the later of the two applies.
    app(Transitions::class)->set($joiner, '2026-11-01', 'Same date as the migrated staff', $this->hr);
    expect(atPlan($joiner, '2027-02-01')['due_months'])->toBe(['2026-12', '2027-01']);
});

// ── Setting the date ─────────────────────────────────────────────────────────

test('the start date is the 1st of a month, set only by permitted HR, never for oneself, and audited', function () {
    expect(fn () => app(Transitions::class)->set($this->mayuresh, '2026-11-15', 'Mid-month start', $this->hr))->toThrow(DomainException::class);

    $employeeUser = $this->alex->user;
    expect(fn () => app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Not my call to make', $employeeUser))->toThrow(AuthorizationException::class);

    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    app(Transitions::class)->set($this->mayuresh, '2026-12-01', 'Moved after review', $this->hr);

    $audits = AuditLog::where('event', 'LEAVE_ACCRUAL_START_SET')->where('subject_employee_id', $this->mayuresh->id)->orderBy('id')->get();
    expect(LeaveAccrualTransition::where('employee_id', $this->mayuresh->id)->sole()->starts_on->toDateString())->toBe('2026-12-01')
        ->and($audits)->toHaveCount(2)
        ->and($audits[1]->old_values['monthly_accrual_starts_from'])->toBe('2026-11-01')
        ->and((int) $audits[1]->user_id)->toBe($this->hr->id);
});

test('HR sets Monthly Accrual Starts From on the employee leave page', function () {
    Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $this->mayuresh])
        ->assertSee('Monthly Accrual Starts From')
        ->assertSee('Existing opening balances remain unchanged. Automatic Earned Leave accrual begins only after completed months from this date.')
        ->call('openAccrualStart')
        ->set('accrualStartMonth', '2026-11')
        ->set('accrualStartReason', 'Monthly accrual from November')
        ->call('saveAccrualStart')
        ->assertHasNoErrors()
        ->assertSee('November 2026');

    expect(app(Transitions::class)->startsOn($this->mayuresh)->toDateString())->toBe('2026-11-01')
        ->and(atSummary($this->mayuresh)['approved_available'])->toEqual(3.0);
});

// ── leave:verify ─────────────────────────────────────────────────────────────

test('leave:verify reports accruals before the start date, outside the eligible period, under a malformed key, or above 12', function () {
    app(Transitions::class)->set($this->mayuresh, '2026-11-01', 'Monthly accrual from November', $this->hr);
    $balance = LeaveBalance::where('employee_id', $this->mayuresh->id)->where('leave_type_id', $this->earned->id)->sole();
    $credit = fn (string $month, string $key, string $effective) => app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_ACCRUAL, 1,
        Carbon::parse($effective), $key, ['meta' => ['month' => $month]]);
    $y = $this->year->id;
    $e = $this->mayuresh->id;

    $credit('2026-08', "earned-accrual:{$e}:{$y}:2026-08", '2026-08-31');          // before the start date
    $credit('2026-11', "earned-accrual:{$e}:{$y}:2026-11", '2026-11-30');          // November not complete on 15 Nov
    $credit('2026-09', "earned-accrual:{$this->alex->id}:{$y}:2026-09", '2026-09-30'); // names another employee
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    $checks = app(Integrity::class)->scan($this->year)->pluck('check');
    expect($checks)->toContain(Integrity::ACCRUAL_BEFORE_START, Integrity::ACCRUAL_OUTSIDE_PERIOD, Integrity::MALFORMED_ACCRUAL_KEY)
        ->not->toContain(Integrity::ACCRUAL_OVER_CAP);

    foreach (range(1, 10) as $i) {
        $credit('2027-0'.min(6, $i % 7), "earned-accrual:{$e}:{$y}:x{$i}", '2026-07-31');
    }
    expect(app(Integrity::class)->scan($this->year)->pluck('check'))->toContain(Integrity::ACCRUAL_OVER_CAP);

    $before = LeaveLedgerEntry::count();
    $this->artisan('leave:verify', ['--year' => '2026/27'])->expectsOutputToContain('accrual_before_monthly_start')->assertFailed();
    expect(LeaveLedgerEntry::count())->toBe($before);
});

// ── HR workflow on the employee leave page ───────────────────────────────────

function atLedgerCount(Employee $employee): int
{
    return LeaveLedgerEntry::where('employee_id', $employee->id)->count();
}

test('HR saves the month from the list: one row on the 1st, set_by is the HR user, nothing else changes, and a change updates the same row', function () {
    $before = [atSummary($this->mayuresh)['approved_available'], atLedgerCount($this->mayuresh)];

    $page = Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $this->mayuresh])
        ->call('openAccrualStart')
        ->assertSet('accrualStartMonth', '')
        ->set('accrualStartMonth', '2026-10')
        ->set('accrualStartReason', 'Opening covers the old system to September')
        ->call('saveAccrualStart')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-accrual-start-saved')
        ->assertSee('October 2026');

    $row = LeaveAccrualTransition::where('employee_id', $this->mayuresh->id)->sole();
    expect($row->starts_on->toDateString())->toBe('2026-10-01')
        ->and((int) $row->set_by)->toBe($this->hr->id)
        ->and($row->reason)->toBe('Opening covers the old system to September')
        ->and([atSummary($this->mayuresh)['approved_available'], atLedgerCount($this->mayuresh)])->toBe($before);

    $page->call('openAccrualStart')->assertSet('accrualStartMonth', '2026-10')
        ->set('accrualStartMonth', '2026-11')->set('accrualStartReason', 'Moved after HR review')
        ->call('saveAccrualStart')->assertHasNoErrors();

    $audit = AuditLog::where('event', 'LEAVE_ACCRUAL_START_SET')->where('subject_employee_id', $this->mayuresh->id)->latest('id')->first();
    expect(LeaveAccrualTransition::where('employee_id', $this->mayuresh->id)->count())->toBe(1)
        ->and(LeaveAccrualTransition::where('employee_id', $this->mayuresh->id)->sole()->starts_on->toDateString())->toBe('2026-11-01')
        ->and($audit->old_values['monthly_accrual_starts_from'])->toBe('2026-10-01')
        ->and($audit->new_values['monthly_accrual_starts_from'])->toBe('2026-11-01')
        ->and(atLedgerCount($this->mayuresh))->toBe($before[1]);
});

test('free text or a month outside the list is refused with a plain message, and nothing is saved', function () {
    foreach (['October 2026', '10/2026', '2026-10-15', '1999-01'] as $typed) {
        Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $this->mayuresh])
            ->call('openAccrualStart')
            ->set('accrualStartMonth', $typed)->set('accrualStartReason', 'Trying an odd value')
            ->call('saveAccrualStart')
            ->assertHasErrors(['accrualStartMonth' => 'in']);
    }

    expect(LeaveAccrualTransition::count())->toBe(0);
});

test('a manager without the HR permission, or HR on their own record, cannot change it', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->mayuresh->update(['manager_id' => $manager->id]);

    expect(app(Transitions::class)->mayChange($manager, $this->mayuresh))->toBeFalse()
        ->and(fn () => app(Transitions::class)->set($this->mayuresh, '2026-10-01', 'Manager trying it', $manager))->toThrow(AuthorizationException::class);

    $own = Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'joining_date' => '2024-01-10']);
    expect(app(Transitions::class)->mayChange($this->hr, $own))->toBeFalse()
        ->and(fn () => app(Transitions::class)->set($own, '2026-10-01', 'My own accrual start', $this->hr))->toThrow(AuthorizationException::class)
        ->and(LeaveAccrualTransition::count())->toBe(0);
});

test('a suggested month is shown, never saved; a missing joining date stays BLOCKED and is said plainly', function () {
    $csl = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSLT', 'category' => 'annual', 'is_paid' => true, 'color' => '#f97316']);
    $migrated = atEmployee('MIG-0001');
    $cslBalance = app(LeaveMovementService::class)->balanceFor($migrated->id, $csl->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($cslBalance);
    app(LeaveLedgerService::class)->credit($cslBalance->fresh(), LeaveLedgerEntry::TYPE_BASE, 3, Carbon::parse('2026-07-01'), 'at-csl-base');
    app(LeaveLedgerService::class)->rebuild($cslBalance->fresh());
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    $migration = app(LeaveV2MigrationService::class);
    $migration->apply($migration->planFor($migrated->fresh(), $this->year, $csl), $this->year, $this->hr);
    $this->travelTo(Carbon::parse('2026-10-28 10:00:00'));

    Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $migrated])
        ->assertSeeHtml('data-accrual-start-suggested')->assertSee('Suggested: October 2026')
        ->assertSeeHtml('data-accrual-status="NEEDS REVIEW"')
        ->call('openAccrualStart')->assertSet('accrualStartMonth', '');
    expect(LeaveAccrualTransition::where('employee_id', $migrated->id)->exists())->toBeFalse();

    $noDate = atEmployee('NODATE-01');
    $noDate->forceFill(['joining_date' => null])->save();
    atCredit($noDate, LeaveLedgerEntry::TYPE_OPENING, 2);
    app(Transitions::class)->set($noDate, '2026-10-01', 'Start confirmed, joining date pending', $this->hr);

    expect(atPlan($noDate, '2026-12-01'))->toMatchArray(['status' => Accrual::BLOCKED, 'joining_date' => null, 'proposed' => 0.0])
        ->and(atPlan($noDate, '2026-12-01')['reason'])->toContain('No joining date');
    Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $noDate])
        ->assertSeeHtml('data-accrual-joining-missing')->assertSeeHtml('data-accrual-status="BLOCKED"');
});

// ── The Yogesh canary and the 12-day rule ────────────────────────────────────

test('Yogesh: opening 3 from 1 October — 0 on 28 October, +1 on 1 November (balance 4), a rerun adds nothing', function () {
    $yogesh = atEmployee('CNS021', '2020-12-16');
    atCredit($yogesh, LeaveLedgerEntry::TYPE_OPENING, 3);
    app(Transitions::class)->set($yogesh, '2026-10-01', 'Canary: opening covers to September', $this->hr);

    $this->travelTo(Carbon::parse('2026-10-28 10:00:00'));
    expect(atPlan($yogesh, '2026-10-28'))->toMatchArray(['status' => Accrual::NO_CHANGE, 'transition' => '2026-10-01', 'expected_months' => [], 'proposed' => 0.0]);

    $this->travelTo(Carbon::parse('2026-11-01 00:20:00'));
    expect(atPlan($yogesh, '2026-11-01'))->toMatchArray(['status' => Accrual::READY, 'due_months' => ['2026-10'], 'proposed' => 1.0]);
    $this->artisan('leave:accrue-earned', ['--apply' => true, '--employee' => ['CNS021']])->assertSuccessful();
    $this->artisan('leave:accrue-earned', ['--apply' => true, '--employee' => ['CNS021']])->assertSuccessful();

    expect(atAccruals($yogesh))->toBe(["earned-accrual:{$yogesh->id}:{$this->year->id}:2026-10"])
        ->and((float) atSummary($yogesh)['opening'])->toEqual(3.0)
        ->and((float) atSummary($yogesh)['approved_available'])->toEqual(4.0)
        ->and(app(Integrity::class)->scan($this->year)->pluck('detail', 'check')->all())->toBe([]);
});

test('Sunita: an opening of 8.5 does not shrink the year of 12 monthly credits, and the total may exceed 12', function () {
    $sunita = atEmployee('SUN-0001');
    atCredit($sunita, LeaveLedgerEntry::TYPE_OPENING, 8.5);
    app(Transitions::class)->set($sunita, '2026-07-01', 'HR confirmed: the opening is carried-forward balance', $this->hr);

    $this->travelTo(Carbon::parse('2027-07-01 00:20:00'));
    expect(atRun($sunita, '2027-07-01')['due_months'])->toHaveCount(12);
    // Never a 13th credit for the year.
    expect(atPlan($sunita, '2027-09-01'))->toMatchArray(['status' => Accrual::NO_CHANGE, 'proposed' => 0.0])
        ->and(atAccruals($sunita))->toHaveCount(12)
        ->and((float) atSummary($sunita)['opening'])->toEqual(8.5)
        ->and((float) atSummary($sunita)['approved_available'])->toEqual(20.5);
});

// ── Joining mid-month ────────────────────────────────────────────────────────

test('a partial joining month earns nothing: joined 15 October, the first day is for November', function () {
    $mid = atEmployee('NEW-1015', '2026-10-15');
    $first = atEmployee('NEW-1001', '2026-10-01');

    expect(atPlan($mid, '2026-11-01'))->toMatchArray(['status' => Accrual::NO_CHANGE, 'proposed' => 0.0])
        ->and(atPlan($mid, '2026-12-01')['due_months'])->toBe(['2026-11'])
        // Joined on the 1st: October is a whole month.
        ->and(atPlan($first, '2026-11-01')['due_months'])->toBe(['2026-10']);
});

// ── Guarantees ───────────────────────────────────────────────────────────────

test('a monthly key exists once; no month before the start date or after employment is ever proposed', function () {
    app(Transitions::class)->set($this->alex, '2026-10-01', 'Monthly accrual from October', $this->hr);
    $this->travelTo(Carbon::parse('2027-03-01 00:20:00'));
    $plan = atPlan($this->alex, '2027-03-01');
    expect($plan['due_months'])->toBe(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02']);

    app(Accrual::class)->apply($plan);
    $entry = LeaveLedgerEntry::where('idempotency_key', "earned-accrual:{$this->alex->id}:{$this->year->id}:2026-10")->sole();
    $balance = LeaveBalance::where('employee_id', $this->alex->id)->where('leave_type_id', $this->earned->id)->sole();

    // The same key again returns the existing entry; a raw duplicate is refused by the unique index.
    expect(app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, 1, Carbon::parse('2026-10-31'), $entry->idempotency_key)->id)->toBe($entry->id)
        ->and(fn () => LeaveLedgerEntry::query()->insert(collect($entry->getAttributes())->except('id')->all()))->toThrow(QueryException::class);

    $leaver = atEmployee('LEFT-0001', '2024-01-10');
    $leaver->update(['status' => 'notice_period']);
    ExitRecord::create(['employee_id' => $leaver->id, 'last_working_day' => '2026-11-20', 'exit_type' => 'resignation']);
    expect(atPlan($leaver, '2027-03-01')['due_months'])->toBe(['2026-07', '2026-08', '2026-09', '2026-10'])
        ->and(app(Integrity::class)->scan($this->year)->pluck('detail', 'check')->all())->toBe([]);
});
