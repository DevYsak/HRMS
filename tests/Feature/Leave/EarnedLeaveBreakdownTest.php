<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Livewire\TimeOff\LeaveManagement;
use App\Livewire\TimeOff\MyLeaveBalances;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveAccrualTransition;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveRolloverRecord;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\EarnedLeaveAccrualService as Accrual;
use App\Services\Leave\EarnedLeaveBreakdown;
use App\Services\Leave\EarnedLeaveRolloverService as Rollover;
use App\Services\Leave\EarnedOpeningSourceService as OpeningSources;
use App\Services\Leave\LeaveAdminActionService;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveCategoryBalances;
use App\Services\Leave\LeaveIntegrityService as Integrity;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveStatementService;
use App\Services\Leave\LeaveV2MigrationService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Earned Leave as one pool shown by its sources (EarnedLeaveBreakdown):
 *
 *   Carry Forward + Current Year Accrued + HR Adjustments − Used − Expired
 *   = Total Available, with Pending beside it.
 *
 * Carry forward and current-year accrual stay separate lines; HR adjustments
 * never touch the accrual; only NEW entitlement is limited to 12 a year.
 *
 * Clock: Sun 15 Nov 2026 (leave year 2026/27). Saturday and Sunday are off.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();

    $this->policy = LeavePolicy::create(['name' => 'Conexus Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => true, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELB', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $this->rule = LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12,
        'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1, 'is_active' => true]);
    // Paid reasons: labels on the one Earned Leave pool, never balances.
    $this->reasons = collect(['Casual Leave', 'Sick Leave', 'Privilege Leave', 'Personal Leave', 'Other Leave'])->mapWithKeys(function (string $name) {
        $type = LeaveType::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)).random_int(10, 99), 'category' => 'other',
            'leave_category' => LeaveCategory::EARNED, 'is_paid' => true, 'allow_paid_request' => true, 'color' => '#f59e0b']);
        LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $type->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
            'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);

        return [$name => $type];
    });

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);

    // Yogesh: 3 days carried forward into 2026/27, joined long before.
    $this->yogesh = ebEmployee('CNS021', '2020-12-16');
    ebCarry($this->yogesh, 3);
});

function ebEmployee(string $code, string $joined = '2024-01-10'): Employee
{
    return Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active',
        'joining_date' => $joined, 'employee_id' => $code, 'manager_id' => null, 'department_id' => Department::factory()->create()->id]);
}

function ebBalance(Employee $employee, ?LeaveYear $year = null): LeaveBalance
{
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, test()->earned->id, Carbon::parse(($year ?? test()->year)->starts_on));
    app(LeaveMovementService::class)->ledgerReady($balance);

    return $balance->fresh();
}

/** Carry forward into a year, as the year-end rollover posts it. */
function ebCarry(Employee $employee, float $days, ?LeaveYear $year = null): void
{
    $year ??= test()->year;
    $balance = ebBalance($employee, $year);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, $days, Carbon::parse($year->starts_on),
        "eb-carry:{$employee->id}:{$year->id}", ['source_type' => Rollover::SOURCE_TYPE]);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
}

/** The scheduled run on $date at 00:20: plan, then apply when READY. */
function ebAccrue(Employee $employee, string $date): array
{
    test()->travelTo(Carbon::parse($date.' 00:20:00'));
    $accrual = app(Accrual::class);
    $row = $accrual->planFor($employee->fresh(), $accrual->yearFor(Carbon::parse($date)), Carbon::parse($date));

    return $row['status'] === Accrual::READY ? $accrual->apply($row) : $row;
}

function ebBreakdown(Employee $employee, ?LeaveYear $year = null): array
{
    return app(EarnedLeaveBreakdown::class)->for($employee->fresh(), $year ?? test()->year);
}

/** The monthly accrual entries: month => ledger id. */
function ebAccruals(Employee $employee): array
{
    return LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)
        ->orderBy('id')->get()->mapWithKeys(fn (LeaveLedgerEntry $e) => [$e->meta['month'] => $e->id])->all();
}

/** An approved paid request for one weekday, given a reason. */
function ebLeave(Employee $employee, string $reason, string $date): LeaveRequest
{
    $request = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => test()->reasons[$reason]->id,
        'main_category' => LeaveCategory::EARNED, 'balance_leave_type_id' => test()->earned->id,
        'start_date' => $date, 'end_date' => $date, 'days' => 1,
        'reason' => 'Personal work', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ]);

    return app(LeaveService::class)->reviewRequest($request, [
        'leave_type_id' => $request->leave_type_id, 'start_date' => $date, 'end_date' => $date, 'reason' => $request->reason, 'is_half_day' => false,
    ], 'approved', test()->hr->id);
}

function ebHrPage(Employee $employee)
{
    return Livewire::actingAs(test()->hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee]);
}

// ── 1–3 · Carry forward and current-year accrual are separate lines ──────────

test('carry forward and current-year accrual are separate lines, and both count towards the total', function () {
    ebAccrue($this->yogesh, '2026-09-01');

    expect(ebBreakdown($this->yogesh))->toMatchArray([
        'carry_forward' => 3.0, 'current_year_accrued' => 2.0, 'opening' => 0.0, 'hr_adjustment' => 0.0,
        'used' => 0.0, 'pending' => 0.0, 'expired' => 0.0, 'total_available' => 5.0, 'consistent' => true,
        'monthly_credits' => 2, 'generated_this_year' => 2.0, 'entitlement_remaining' => 10.0,
    ])->and(ebBreakdown($this->yogesh)['next_credit'])->toBe(['month' => '2026-09', 'date' => '2026-10-01', 'days' => 1.0]);

    // Carry forward is never "accrued": the next credit adds to the current year only.
    ebAccrue($this->yogesh, '2026-10-01');
    expect(ebBreakdown($this->yogesh))->toMatchArray(['carry_forward' => 3.0, 'current_year_accrued' => 3.0, 'total_available' => 6.0]);
});

test('the worked example: 3 carried + 2 accrued + 1 HR − 1 used = 5, then next month +1 = 6 without recalculating the year', function () {
    ebAccrue($this->yogesh, '2026-09-01');
    $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
    ebHrPage($this->yogesh)->call('openAction', 'add', $this->earned->id)->set('addOnType', 'manual_adjustment')
        ->set('days', '1')->set('reason', 'Worked a public holiday')->call('submitAction')->assertHasNoErrors();
    ebLeave($this->yogesh, 'Casual Leave', '2026-09-14');
    $before = ebAccruals($this->yogesh);

    expect(ebBreakdown($this->yogesh))->toMatchArray(['carry_forward' => 3.0, 'current_year_accrued' => 2.0, 'hr_adjustment' => 1.0, 'used' => 1.0, 'total_available' => 5.0]);

    ebAccrue($this->yogesh, '2026-10-01');
    expect(ebBreakdown($this->yogesh))->toMatchArray(['carry_forward' => 3.0, 'current_year_accrued' => 3.0, 'hr_adjustment' => 1.0, 'used' => 1.0, 'total_available' => 6.0, 'consistent' => true])
        // July and August were not touched: the same entries, one new one for September.
        ->and(ebAccruals($this->yogesh))->toBe($before + ['2026-09' => ebAccruals($this->yogesh)['2026-09']])
        ->and(array_keys(ebAccruals($this->yogesh)))->toBe(['2026-07', '2026-08', '2026-09']);
});

// ── 4–8, 21 · HR Add / Deduct / Correct ──────────────────────────────────────

test('HR add, deduct and correction change only HR Adjustments, are audited and labelled, and never touch accrual, carry forward or the start date', function () {
    ebAccrue($this->yogesh, '2026-11-01');
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
    $accruals = ebAccruals($this->yogesh);

    ebHrPage($this->yogesh)
        ->assertSeeHtml('data-earned-breakdown')->assertSee('Next eligible credit')
        ->call('openAction', 'add', $this->earned->id)->set('addOnType', 'manual_adjustment')->set('days', '2')->set('reason', 'Agreed compensation')
        ->call('submitAction')->assertHasNoErrors()
        ->call('openAction', 'deduct', $this->earned->id)->set('days', '0.5')->set('reason', 'Unrecorded half day')->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors()
        ->call('openAction', 'correct', $this->earned->id)->set('days', '-1')->set('reason', 'Register reconciliation')->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors()
        ->assertSeeHtml('data-breakdown="hr_adjustment"');

    expect(ebBreakdown($this->yogesh))->toMatchArray(['carry_forward' => 3.0, 'current_year_accrued' => 4.0, 'hr_adjustment' => 0.5, 'total_available' => 7.5, 'consistent' => true])
        ->and(ebAccruals($this->yogesh))->toBe($accruals)
        ->and(LeaveAccrualTransition::where('employee_id', $this->yogesh->id)->exists())->toBeFalse();

    foreach (['LEAVE_BALANCE_ADJUSTED', 'LEAVE_DEDUCTED', 'LEAVE_BALANCE_CORRECTED'] as $event) {
        expect(AuditLog::where('event', $event)->where('subject_employee_id', $this->yogesh->id)->where('user_id', $this->hr->id)->exists())->toBeTrue($event);
    }
    $labels = app(LeaveStatementService::class)->timeline($this->yogesh, $this->year, ['leave_type_id' => $this->earned->id])->pluck('label');
    expect($labels)->toContain('HR Add', 'HR Deduct', 'HR Correction', 'Monthly Accrual', 'Carry Forward');
});

test('a correction is a signed amount: zero or free text is refused, and HR never types the total', function () {
    ebHrPage($this->yogesh)
        ->call('openAction', 'correct', $this->earned->id)->set('days', '0')->set('reason', 'No change')->set('confirmed', true)
        ->call('submitAction')->assertHasErrors('days')
        ->set('days', 'eight')->call('submitAction')->assertHasErrors('days');

    expect(ebBreakdown($this->yogesh)['hr_adjustment'])->toBe(0.0);
});

test('next month still accrues after an HR add, a deduct or a correction, and a correction never replays earlier months', function () {
    ebAccrue($this->yogesh, '2026-11-01');
    $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
    ebHrPage($this->yogesh)->call('openAction', 'deduct', $this->earned->id)->set('days', '2')->set('reason', 'Recovered days')->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors()
        ->call('openAction', 'correct', $this->earned->id)->set('days', '1.5')->set('reason', 'Register reconciliation')->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors();

    expect(ebAccrue($this->yogesh, '2026-12-01'))->toMatchArray(['status' => 'CREDITED', 'due_months' => ['2026-11']])
        ->and(array_keys(ebAccruals($this->yogesh)))->toBe(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
        ->and(ebBreakdown($this->yogesh))->toMatchArray(['current_year_accrued' => 5.0, 'hr_adjustment' => -0.5, 'total_available' => 7.5]);
});

// ── 9 · Carry forward used up: accrual carries on ────────────────────────────

test('using up the carry forward never stops the monthly accrual', function () {
    ebAccrue($this->yogesh, '2026-08-01');
    $this->travelTo(Carbon::parse('2026-08-03 10:00:00'));
    foreach (['2026-08-04', '2026-08-05', '2026-08-06'] as $day) {
        ebLeave($this->yogesh, 'Sick Leave', $day);
    }
    $carry = LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('entry_type', LeaveLedgerEntry::TYPE_CARRY_FORWARD)->sole();
    expect(app(LeaveLedgerService::class)->remaining($carry))->toEqual(0.0);

    ebAccrue($this->yogesh, '2026-09-01');
    expect(ebBreakdown($this->yogesh))->toMatchArray(['carry_forward' => 3.0, 'current_year_accrued' => 2.0, 'used' => 3.0, 'total_available' => 2.0]);
});

// ── 10–11 · The 12 applies to new entitlement only ───────────────────────────

test('the total may exceed 12; new entitlement for the year stops at 12 and the forecast never projects past it', function () {
    $sunita = ebEmployee('CNS030', '2019-04-01');
    ebCarry($sunita, 8.5);

    expect(ebBreakdown($sunita)['forecast'])->toBe(['future_credits' => 12.0, 'year_end_available' => 20.5]);
    ebAccrue($sunita, '2027-07-01');
    expect(ebBreakdown($sunita))->toMatchArray(['carry_forward' => 8.5, 'current_year_accrued' => 12.0, 'total_available' => 20.5,
        'monthly_credits' => 12, 'entitlement_remaining' => 0.0, 'next_credit' => null])
        ->and(ebAccrue($sunita, '2027-07-01')['status'])->toBe(Accrual::NO_CHANGE)
        ->and(ebAccruals($sunita))->toHaveCount(12);
});

// ── 12 · Historical balances stay out ────────────────────────────────────────

test('an old Casual, Sick or Annual balance is history: listed, never added to Earned Leave', function () {
    $annual = LeaveType::create(['name' => 'Annual Leave (old policy)', 'code' => 'AOL'.random_int(10, 99), 'category' => 'annual', 'is_paid' => true,
        'color' => '#64748b', 'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);
    foreach ([$annual, $this->reasons['Casual Leave'], $this->reasons['Sick Leave']] as $old) {
        $balance = app(LeaveMovementService::class)->balanceFor($this->yogesh->id, $old->id, Carbon::parse('2026-07-01'));
        app(LeaveMovementService::class)->ledgerReady($balance);
        app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 6, Carbon::parse('2026-07-01'), 'eb-old-'.$old->id);
        app(LeaveLedgerService::class)->rebuild($balance->fresh());
    }

    $summary = app(LeaveCategoryBalances::class)->forEmployee($this->yogesh, $this->year);
    expect($summary['earned']['breakdown']['total_available'])->toBe(3.0)
        ->and(collect($summary['historical'])->pluck('name')->all())->toContain('Annual Leave (old policy)', 'Casual Leave', 'Sick Leave');

    Livewire::actingAs($this->yogesh->user)->test(MyLeaveBalances::class)
        ->assertSeeHtml('data-historical-note')->assertSee('not included in your current Earned Leave total');
    ebHrPage($this->yogesh)->assertSeeHtml('data-historical-note');
});

// ── 13–15 · One source for every screen ──────────────────────────────────────

test('the HR grid opens on the Earned Leave pool and shows the canonical lines; a historical type says so', function () {
    ebAccrue($this->yogesh, '2026-11-01');
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
    $csl = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true, 'color' => '#f97316',
        'availability' => LeaveType::AVAILABILITY_HISTORICAL]);

    $grid = Livewire::actingAs($this->hr)->test(LeaveManagement::class)->assertSet('leaveTypeId', $this->earned->id)
        ->assertSeeHtml('data-col="carry_forward"')->assertSee('Current Year Accrued')->assertSee('(active Earned Leave)');
    $row = $grid->instance()->rows->firstWhere('employee_id', $this->yogesh->id);
    $canonical = ebBreakdown($this->yogesh);

    expect($row['breakdown'])->toMatchArray(collect($canonical)->only(['carry_forward', 'current_year_accrued', 'opening', 'hr_adjustment', 'used', 'pending', 'total_available'])->all());

    $grid->set('leaveTypeId', $csl->id)->assertSeeHtml('data-historical-type')->assertDontSeeHtml('data-col="carry_forward"');
});

test('My Leave shows the same breakdown as HR: total, carry forward, current year, used, pending and the next credit', function () {
    ebAccrue($this->yogesh, '2026-09-01');
    $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));

    $page = Livewire::actingAs($this->yogesh->user)->test(MyLeaveBalances::class)
        ->assertSeeHtml('data-earned-lines')->assertSee('Carry Forward')->assertSee('Current Year Accrued')
        ->assertSeeHtml('data-earned-rule')->assertSee('next credit 01 Oct 2026');
    $shown = $page->instance()->v2Balances['earned']['breakdown'];

    expect($shown)->toMatchArray(collect(ebBreakdown($this->yogesh))->except(['accrual_reason'])->all())
        ->and($shown['total_available'])->toBe(5.0);
});

test('the forecast is today\'s total plus the credits still to come — carry forward and the opening are never projected again', function () {
    ebAccrue($this->yogesh, '2026-11-01');
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
    // Nov–Jun: 8 more months. 3 carried + 4 accrued + 8 = 15.
    expect(ebBreakdown($this->yogesh)['forecast'])->toBe(['future_credits' => 8.0, 'year_end_available' => 15.0]);

    // An opening of unknown origin and no start date: unknown, never guessed.
    $alex = ebEmployee('GUIDE-0001');
    $balance = ebBalance($alex);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_OPENING, 12, Carbon::parse('2026-07-01'), 'eb-alex-opening');
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
    expect(ebBreakdown($alex))->toMatchArray(['opening' => 12.0, 'total_available' => 12.0, 'forecast' => null, 'next_credit' => null, 'accrual_status' => Accrual::REVIEW]);
});

// ── 16–18 · Year rollover ────────────────────────────────────────────────────

test('the rollover carries unused Earned Leave into the next year once; a rerun or a second carry forward never doubles it', function () {
    $this->rule->update(['carry_forward_enabled' => true, 'carry_forward_max_days' => 30]);
    $next = LeaveYear::firstOrCreate(['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'], ['label' => '2027/28']);
    ebAccrue($this->yogesh, '2027-07-01');
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));

    $rollover = app(Rollover::class);
    $row = $rollover->planFor($this->yogesh->fresh(), $this->year, $next);
    expect($row)->toMatchArray(['status' => Rollover::READY, 'closing' => 15.0, 'carry' => 15.0]);
    $rollover->apply($row);

    expect(ebBreakdown($this->yogesh, $next))->toMatchArray(['carry_forward' => 15.0, 'current_year_accrued' => 0.0, 'total_available' => 15.0])
        ->and($rollover->apply($row)['status'])->toBe(Rollover::DONE)
        ->and(LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('leave_year_id', $next->id)->where('entry_type', LeaveLedgerEntry::TYPE_CARRY_FORWARD)->count())->toBe(1)
        ->and(LeaveRolloverRecord::where('employee_id', $this->yogesh->id)->count())->toBe(1);

    // The new year accrues on its own from August; the carry forward stays its own line.
    ebAccrue($this->yogesh, '2027-08-01');
    expect(ebBreakdown($this->yogesh, $next))->toMatchArray(['carry_forward' => 15.0, 'current_year_accrued' => 1.0, 'total_available' => 16.0]);

    // A carry forward already in the new year (an opening reclassified as carry forward) refuses the rollover…
    $other = ebEmployee('CNS031');
    ebAccrue($other, '2027-07-01');
    $this->travelTo(Carbon::parse('2027-07-01 01:30:00'));
    $balance = ebBalance($other, $next);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 2, Carbon::parse('2027-07-01'), 'eb-reclass', ['source_type' => OpeningSources::SOURCE_TYPE]);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
    expect($rollover->planFor($other->fresh(), $this->year, $next))->toMatchArray(['status' => Rollover::REVIEW])
        ->and($rollover->planFor($other->fresh(), $this->year, $next)['reason'])->toContain('already holds an Earned Leave carry forward');

    // …and two in one year are reported by leave:verify.
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_CARRY_FORWARD, 1, Carbon::parse('2027-07-01'), 'eb-dup', ['source_type' => Rollover::SOURCE_TYPE]);
    expect(app(Integrity::class)->scan($next)->pluck('check'))->toContain(Integrity::DUPLICATE_CARRY_FORWARD);
});

// ── 19–20 · Requests charge the one pool ─────────────────────────────────────

test('every paid reason debits the one Earned Leave pool once and keeps its reason; a cancellation restores it once', function () {
    ebAccrue($this->yogesh, '2026-11-01');
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
    $requests = collect(['Casual Leave' => '2026-11-16', 'Sick Leave' => '2026-11-17', 'Privilege Leave' => '2026-11-18', 'Personal Leave' => '2026-11-19', 'Other Leave' => '2026-11-20'])
        ->map(fn (string $date, string $reason) => ebLeave($this->yogesh, $reason, $date));

    $usage = LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->get();
    expect($usage)->toHaveCount(5)
        ->and($usage->pluck('leave_type_id')->unique()->all())->toBe([$this->earned->id])
        ->and($usage->pluck('meta.reason_type')->sort()->values()->all())->toBe(['Casual Leave', 'Other Leave', 'Personal Leave', 'Privilege Leave', 'Sick Leave'])
        // No reason ever gets a balance of its own.
        ->and(LeaveBalance::where('employee_id', $this->yogesh->id)->whereIn('leave_type_id', $this->reasons->pluck('id'))->exists())->toBeFalse()
        ->and(ebBreakdown($this->yogesh))->toMatchArray(['used' => 5.0, 'total_available' => 2.0]);

    $actions = app(LeaveAdminActionService::class);
    $actions->cancelApproved($requests['Sick Leave']->fresh(), $this->hr, 'Came to work after all');
    expect(fn () => $actions->cancelApproved($requests['Sick Leave']->fresh(), $this->hr, 'Again'))->toThrow(Exception::class)
        ->and(ebBreakdown($this->yogesh))->toMatchArray(['used' => 4.0, 'total_available' => 3.0, 'consistent' => true]);
});

// ── 22–24 · Who may adjust ───────────────────────────────────────────────────

test('only permitted HR adjust Earned Leave: never their own, never a manager or the employee', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager, 'role_id' => Role::where('slug', 'manager')->value('id')]);
    $this->yogesh->update(['manager_id' => $manager->id]);
    Livewire::actingAs($manager)->test(EmployeeLeaveDetail::class, ['employee' => $this->yogesh])->assertForbidden();
    Livewire::actingAs($this->yogesh->user)->test(EmployeeLeaveDetail::class, ['employee' => $this->yogesh])->assertForbidden();

    $own = Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'joining_date' => '2024-01-10']);
    ebCarry($own, 2);
    Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $own])
        ->call('openAction', 'add', $this->earned->id)->set('addOnType', 'manual_adjustment')->set('days', '5')->set('reason', 'For myself')
        ->call('submitAction')->assertHasErrors('reason');

    expect(ebBreakdown($own)['hr_adjustment'])->toBe(0.0)
        ->and(ebBreakdown($this->yogesh)['hr_adjustment'])->toBe(0.0);
});

// ── 25–26 · Ledger and verify ────────────────────────────────────────────────

test('after carry forward, accrual, HR changes, usage and a cancellation the balance agrees with the ledger and leave:verify is clean', function () {
    ebAccrue($this->yogesh, '2026-11-01');
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
    ebHrPage($this->yogesh)->call('openAction', 'add', $this->earned->id)->set('addOnType', 'manual_adjustment')->set('days', '1')->set('reason', 'Agreed')
        ->call('submitAction')->assertHasNoErrors()
        ->call('openAction', 'correct', $this->earned->id)->set('days', '-0.5')->set('reason', 'Register')->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors();
    $request = ebLeave($this->yogesh, 'Casual Leave', '2026-11-16');
    ebLeave($this->yogesh, 'Sick Leave', '2026-11-17');
    app(LeaveAdminActionService::class)->cancelApproved($request->fresh(), $this->hr, 'Plans changed');

    $balance = LeaveBalance::where('employee_id', $this->yogesh->id)->where('leave_type_id', $this->earned->id)->sole();
    $ledger = (float) LeaveLedgerEntry::where('employee_id', $this->yogesh->id)->where('leave_type_id', $this->earned->id)->where('leave_year_id', $this->year->id)->sum('days');

    expect(ebBreakdown($this->yogesh))->toMatchArray(['carry_forward' => 3.0, 'current_year_accrued' => 4.0, 'hr_adjustment' => 0.5, 'used' => 1.0, 'total_available' => 6.5, 'consistent' => true])
        ->and($ledger)->toEqual(6.5)
        ->and((float) $balance->allocated_days - (float) $balance->used_days)->toEqual(6.5)
        ->and(app(Integrity::class)->scan($this->year)->all())->toBe([]);
    $this->artisan('leave:verify', ['--year' => '2026/27'])->assertSuccessful();
});

// ── Migrated openings: evidence before any reclassification ──────────────────

function ebMigrated(string $code, array $lots, float $used = 0): Employee
{
    $csl = LeaveType::firstOrCreate(['code' => 'CSL'], ['name' => 'Casual / Sick Leave', 'category' => 'annual', 'is_paid' => true, 'color' => '#f97316']);
    $employee = ebEmployee($code);
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $csl->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    foreach ($lots as $type => $days) {
        app(LeaveLedgerService::class)->credit($balance->fresh(), $type, $days, Carbon::parse('2026-07-01'), "eb-csl-{$code}-{$type}");
    }
    if ($used > 0) {
        app(LeaveLedgerService::class)->debit($balance->fresh(), LeaveLedgerEntry::TYPE_USAGE, $used, Carbon::parse('2026-07-20'), "eb-csl-{$code}-used");
    }
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
    $migration = app(LeaveV2MigrationService::class);
    $migration->apply($migration->planFor($employee->fresh(), test()->year, $csl), test()->year, test()->hr);

    return $employee;
}

test('a migrated opening is classified from the CSL lots it is made of, shown to HR, and reclassified only for one named employee', function () {
    // CSL: carry forward 7 + current-year 3, 6 used — carry forward is consumed first: 1 carried + 3 current = 4.
    $split = ebMigrated('MIG-0001', [LeaveLedgerEntry::TYPE_CARRY_FORWARD => 7, LeaveLedgerEntry::TYPE_BASE => 3], 6);
    $carried = ebMigrated('MIG-0002', [LeaveLedgerEntry::TYPE_CARRY_FORWARD => 5], 2);
    $mixed = ebMigrated('MIG-0003', [LeaveLedgerEntry::TYPE_BASE => 2, LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT => 1]);

    $rows = app(OpeningSources::class)->plan()->keyBy('code');
    expect($rows['MIG-0001'])->toMatchArray(['status' => OpeningSources::SPLIT, 'migrated' => 4.0, 'carry_forward' => 1.0, 'current_year' => 3.0])
        ->and($rows['MIG-0002'])->toMatchArray(['status' => OpeningSources::CARRY_FORWARD, 'carry_forward' => 3.0, 'current_year' => 0.0])
        ->and($rows['MIG-0003'])->toMatchArray(['status' => OpeningSources::AMBIGUOUS])
        ->and($rows['MIG-0003']['reason'])->toContain('adjustment credit');

    // Until HR decides, the opening is its own line, shown with its evidence.
    expect(ebBreakdown($split))->toMatchArray(['opening' => 4.0, 'carry_forward' => 0.0, 'total_available' => 4.0]);
    ebHrPage($split)->assertSeeHtml('data-opening-source="SPLIT"')->assertSee('Migrated Opening (not yet classified)');

    // The preview writes nothing; --apply refuses more than one employee or no reason.
    $before = LeaveLedgerEntry::count();
    $this->artisan('leave:earned-opening-sources')->expectsOutputToContain('SPLIT')->assertSuccessful();
    $this->artisan('leave:earned-opening-sources', ['--apply' => true, '--actor' => $this->hr->email, '--reason' => 'HR confirmed'])->assertFailed();
    $this->artisan('leave:earned-opening-sources', ['--apply' => true, '--employee' => ['MIG-0001'], '--actor' => $this->hr->email])->assertFailed();
    expect(LeaveLedgerEntry::count())->toBe($before);

    $this->artisan('leave:earned-opening-sources', ['--apply' => true, '--employee' => ['MIG-0001'], '--actor' => $this->hr->email, '--reason' => 'HR confirmed from the register'])
        ->expectsOutputToContain('RECLASSIFIED')->assertSuccessful();

    expect(ebBreakdown($split))->toMatchArray(['opening' => 0.0, 'carry_forward' => 1.0, 'current_year_accrued' => 3.0, 'total_available' => 4.0, 'consistent' => true])
        ->and(app(OpeningSources::class)->plan(['MIG-0001'])->sole()['status'])->toBe(OpeningSources::DONE)
        ->and(ebBreakdown($carried)['opening'])->toBe(3.0)
        ->and(AuditLog::where('event', 'LEAVE_V2_OPENING_RECLASSIFIED')->where('subject_employee_id', $split->id)->count())->toBe(1)
        ->and(app(Integrity::class)->scan($this->year)->all())->toBe([]);

    // Run again: nothing more is posted.
    $after = LeaveLedgerEntry::count();
    $this->artisan('leave:earned-opening-sources', ['--apply' => true, '--employee' => ['MIG-0001'], '--actor' => $this->hr->email, '--reason' => 'Again'])->assertSuccessful();
    expect(LeaveLedgerEntry::count())->toBe($after);
});
