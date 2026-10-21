<?php

use App\Enums\UserRole;
use App\Livewire\Employees\Employee360;
use App\Livewire\TimeOff\LeavePolicyEditor;
use App\Livewire\TimeOff\RequestLeave;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Leave\CombinedLeaveService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategoryBalances;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\LeaveService;
use App\Services\Payroll\PayableDaysService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Leave V2 — Combined Leave: ONE request over a date range, each working date
 * Full/Half Day, Paid/Unpaid, one reason for the whole request. Paid units
 * draw on the shared Earned Leave balance — debited once, never per date.
 *
 * Range used throughout: Tue 10 – Mon 16 Nov 2026, Thu 12 Nov a holiday.
 * Clock: Mon 2 Nov 2026.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    // Saturday and Sunday off.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();

    $this->user = User::factory()->create(['role' => UserRole::Employee]);
    $this->employee = Employee::factory()->create([
        'user_id' => $this->user->id, 'status' => 'active', 'joining_date' => '2024-01-10',
        'manager_id' => null, 'department_id' => Department::factory()->create()->id, 'gender' => 'male',
    ]);
    PublicHoliday::factory()->create([
        'name' => 'Founders Day', 'date' => '2026-11-12',
        'country' => app(HolidayResolver::class)->resolveCountry($this->employee), 'is_active' => true,
    ]);

    $this->policy = LeavePolicy::create([
        'name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12,
        'combined_leave_rules' => ['enabled' => true],
    ]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);

    $make = fn (string $name, string $code, array $extra = []) => LeaveType::create(array_merge([
        'name' => $name, 'code' => $code.random_int(10, 99), 'category' => 'other', 'leave_category' => 'earned',
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true, 'color' => '#10b981',
    ], $extra));
    $this->rule = fn (LeaveType $type, array $extra = []) => LeavePolicyRule::create(array_merge([
        'leave_policy_id' => $this->policy->id, 'leave_type_id' => $type->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE, 'fixed_days' => null,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true,
    ], $extra));

    $this->earned = $make('Earned Leave', 'EL', ['counts_toward_annual_cap' => true]);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    ($this->rule)($this->earned, ['entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12, 'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY]);
    $this->sick = $make('Sick Leave', 'SL');
    $this->casual = $make('Casual Leave', 'CL');
    $this->personal = $make('Personal Leave', 'PRL');
    foreach ([$this->sick, $this->casual, $this->personal] as $reason) {
        ($this->rule)($reason);
    }

    $this->credit = function (LeaveType $type, float $days) {
        $balance = app(LeaveMovementService::class)->balanceFor($this->employee->id, $type->id, Carbon::parse('2026-07-01'));
        app(LeaveMovementService::class)->ledgerReady($balance);
        app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, $days, Carbon::parse('2026-07-01'), 'combined-'.$type->id);
        app(LeaveLedgerService::class)->rebuild($balance->fresh());
    };
    ($this->credit)($this->earned, 8);
});

function combinedAvailable(): float
{
    $balance = LeaveBalance::where('employee_id', test()->employee->id)->where('leave_type_id', test()->earned->id)->sole();

    return app(LeaveBalanceCalculator::class)->summary($balance)['available_to_request'];
}

/** The critical example: 10 + 11 full paid, 13 half paid, 15 + 16 full unpaid (Sunday a working day). */
function combinedCriticalAllocations(): array
{
    return [
        '2026-11-10' => ['type' => LeaveRequestDay::FULL_PAID],
        '2026-11-11' => ['type' => LeaveRequestDay::FULL_PAID],
        '2026-11-13' => ['type' => LeaveRequestDay::HALF_PAID, 'session' => 'first_half'],
        '2026-11-15' => ['type' => LeaveRequestDay::FULL_UNPAID],
        '2026-11-16' => ['type' => LeaveRequestDay::FULL_UNPAID],
    ];
}

function combinedSaturdayOnlyWeek(): void
{
    AttendanceSetting::query()->update(['weekly_off_days' => [6]]);
    AttendanceSetting::flushWeeklyOffCache();
}

function combinedApprove(LeaveRequest $request): LeaveRequest
{
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    return app(LeaveService::class)->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason', 'half_day_period']) + ['is_half_day' => (bool) $request->is_half_day], 'approved', $hr->id);
}

// ── Dates ───────────────────────────────────────────────────────────────────

test('the range is evaluated date by date: holiday and weekly offs are shown and excluded', function () {
    $service = app(CombinedLeaveService::class);
    $rows = collect($service->dates($this->employee, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-16'), CombinedLeaveService::rulesOf($this->policy->fresh())))->keyBy('date');

    expect($rows)->toHaveCount(7)
        ->and($rows['2026-11-10']['status'])->toBe(LeaveRequestDay::STATUS_WORKING)
        ->and($rows['2026-11-12']['status'])->toBe(LeaveRequestDay::STATUS_HOLIDAY)
        ->and($rows['2026-11-12']['label'])->toBe('Founders Day')
        ->and($rows['2026-11-12']['chargeable'])->toBeFalse()
        ->and($rows['2026-11-14']['status'])->toBe(LeaveRequestDay::STATUS_WEEKLY_OFF)
        ->and($rows['2026-11-15']['status'])->toBe(LeaveRequestDay::STATUS_WEEKLY_OFF)
        ->and($rows->where('chargeable', true)->keys()->all())->toBe(['2026-11-10', '2026-11-11', '2026-11-13', '2026-11-16']);
});

test('the configured working week decides which dates are weekly offs', function () {
    combinedSaturdayOnlyWeek();

    $rows = collect(app(CombinedLeaveService::class)->dates($this->employee, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-16'), CombinedLeaveService::rulesOf($this->policy->fresh())))->keyBy('date');

    expect($rows['2026-11-15']['status'])->toBe(LeaveRequestDay::STATUS_WORKING)
        ->and($rows['2026-11-15']['chargeable'])->toBeTrue()
        ->and($rows['2026-11-14']['status'])->toBe(LeaveRequestDay::STATUS_WEEKLY_OFF);
});

// ── The critical example ────────────────────────────────────────────────────

test('critical example: paid 2.5, unpaid 2, Earned 8 → 5.5, debited exactly once (−2.5)', function () {
    combinedSaturdayOnlyWeek();
    $service = app(LeaveService::class);

    $evaluation = app(CombinedLeaveService::class)->evaluate($this->employee, $this->sick, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-16'), combinedCriticalAllocations());

    expect($evaluation['blocked'])->toBeFalse()
        ->and($evaluation['calendar_days'])->toBe(7)
        ->and($evaluation['excluded_days'])->toBe(2)
        ->and($evaluation['working_days'])->toBe(5)
        ->and($evaluation['paid_units'])->toBe(2.5)
        ->and($evaluation['unpaid_units'])->toBe(2.0)
        ->and($evaluation['half_day_count'])->toBe(1)
        ->and($evaluation['charged_units'])->toBe(4.5)
        ->and($evaluation['available'])->toBe(8.0)
        ->and($evaluation['remaining_after'])->toBe(5.5);

    $request = $service->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Flu and recovery');

    // One request, one header, seven date rows.
    expect(LeaveRequest::where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and($request->isCombined())->toBeTrue()
        ->and($request->leave_type_id)->toBe($this->sick->id)
        ->and($request->balance_leave_type_id)->toBe($this->earned->id)
        ->and((float) $request->days)->toBe(4.5)
        ->and($request->paidDays())->toBe(2.5)
        ->and($request->unpaidUnits())->toBe(2.0)
        ->and($request->dayAllocations)->toHaveCount(7)
        ->and((float) $request->dayAllocations->sum('paid_units'))->toBe(2.5)
        ->and((float) $request->dayAllocations->sum('unpaid_units'))->toBe(2.0)
        ->and($request->dayAllocations->firstWhere(fn ($d) => $d->date->toDateString() === '2026-11-13')->session)->toBe('first_half')
        // Pending reserves only the paid units on the common pool.
        ->and(combinedAvailable())->toBe(5.5)
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->exists())->toBeFalse();

    combinedApprove($request);

    $usage = LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->get();
    expect($usage)->toHaveCount(1)
        ->and((float) $usage->first()->days)->toBe(-2.5)
        ->and($usage->first()->leave_type_id)->toBe($this->earned->id)
        ->and((int) $usage->first()->source_id)->toBe($request->id)
        ->and(combinedAvailable())->toBe(5.5)
        // No subtype balance is ever created.
        ->and(LeaveBalance::where('employee_id', $this->employee->id)->whereIn('leave_type_id', [$this->sick->id, $this->casual->id])->exists())->toBeFalse();
});

test('an all-unpaid request never touches Earned Leave', function () {
    $request = app(LeaveService::class)->submitCombinedRequest($this->employee, $this->personal, '2026-11-10', '2026-11-11', [
        '2026-11-10' => ['type' => LeaveRequestDay::FULL_UNPAID],
        '2026-11-11' => ['type' => LeaveRequestDay::HALF_UNPAID, 'session' => 'second_half'],
    ], 'Family');

    expect($request->requested_leave_status)->toBe('unpaid')
        ->and($request->balance_leave_type_id)->toBeNull()
        ->and($request->unpaidUnits())->toBe(1.5)
        ->and(combinedAvailable())->toBe(8.0);

    combinedApprove($request);
    expect(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->exists())->toBeFalse()
        ->and(combinedAvailable())->toBe(8.0);
});

test('rejection releases the reservation and cancellation returns approved paid units', function () {
    combinedSaturdayOnlyWeek();
    $service = app(LeaveService::class);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $rejected = $service->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Flu');
    expect(combinedAvailable())->toBe(5.5);
    $service->reviewRequest($rejected, $rejected->only(['leave_type_id', 'start_date', 'end_date', 'reason']), 'rejected', $hr->id, 'No cover');
    expect(combinedAvailable())->toBe(8.0);

    $approved = combinedApprove($service->submitCombinedRequest($this->employee, $this->casual, '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Trip'));
    expect(combinedAvailable())->toBe(5.5);
    $service->cancelRequest($approved->fresh(), 'Plans changed', $this->user);
    expect(combinedAvailable())->toBe(8.0)
        ->and((float) LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->sum('days'))->toBe(0.0);
});

// ── Balance shortfall ───────────────────────────────────────────────────────

test('paid days beyond the balance are blocked with a clear message', function () {
    LeaveLedgerEntry::query()->delete();
    LeaveBalance::query()->delete();
    ($this->credit)($this->earned, 3);
    combinedSaturdayOnlyWeek();
    $allPaid = collect(['2026-11-10', '2026-11-11', '2026-11-13', '2026-11-15', '2026-11-16'])->mapWithKeys(fn ($d) => [$d => ['type' => LeaveRequestDay::FULL_PAID]])->all();

    $evaluation = app(CombinedLeaveService::class)->evaluate($this->employee, $this->sick, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-16'), $allPaid);

    expect($evaluation['blocked'])->toBeTrue()
        ->and($evaluation['errors'])->toContain('Only 3 days of Earned Leave are available. Change 2 day(s) to Unpaid Leave or reduce the request.')
        ->and($evaluation['suggestion'])->toBeNull()
        ->and(fn () => app(LeaveService::class)->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-16', $allPaid, 'Flu'))
        ->toThrow(DomainException::class, 'Only 3 days of Earned Leave');
});

test('convert-to-LWP proposes 3 paid + 2 unpaid but never applies it silently', function () {
    LeaveLedgerEntry::query()->delete();
    LeaveBalance::query()->delete();
    ($this->credit)($this->earned, 3);
    combinedSaturdayOnlyWeek();
    $this->policy->update(['combined_leave_rules' => ['enabled' => true, 'excess_handling' => 'convert_to_lwp']]);
    $allPaid = collect(['2026-11-10', '2026-11-11', '2026-11-13', '2026-11-15', '2026-11-16'])->mapWithKeys(fn ($d) => [$d => ['type' => LeaveRequestDay::FULL_PAID]])->all();
    $service = app(CombinedLeaveService::class);

    $first = $service->evaluate($this->employee, $this->sick, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-16'), $allPaid);
    expect($first['blocked'])->toBeTrue()->and($first['suggestion'])->not->toBeNull();

    // The employee reviews and accepts the proposal.
    $second = $service->evaluate($this->employee, $this->sick, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-16'), $first['suggestion']);
    expect($second['blocked'])->toBeFalse()
        ->and($second['paid_units'])->toBe(3.0)
        ->and($second['unpaid_units'])->toBe(2.0)
        ->and($first['suggestion']['2026-11-16']['type'])->toBe(LeaveRequestDay::FULL_UNPAID)
        ->and($first['suggestion']['2026-11-10']['type'])->toBe(LeaveRequestDay::FULL_PAID);
});

// ── Reasons and rules ───────────────────────────────────────────────────────

test('reasons come from the policy: active, eligible, allowed — never historical or WFH', function () {
    $old = LeaveType::create(['name' => 'Old Casual', 'code' => 'OC'.random_int(10, 99), 'category' => 'other', 'leave_category' => 'earned',
        'is_paid' => true, 'color' => '#64748b', 'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);
    ($this->rule)($old);
    LeaveType::create(['name' => 'Work From Home', 'code' => 'WFH'.random_int(10, 99), 'category' => 'wfh', 'is_paid' => true, 'color' => '#64748b']);
    $bereavement = LeaveType::create(['name' => 'Bereavement Leave', 'code' => 'BRV'.random_int(10, 99), 'category' => 'bereavement', 'is_paid' => true, 'color' => '#0ea5e9']);
    ($this->rule)($bereavement, ['entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 5]);
    $year = LeaveYear::where('label', '2026/27')->first();

    $names = collect(app(CombinedLeaveService::class)->reasons($this->employee, $year))->pluck('name')->all();
    expect($names)->toEqualCanonicalizing(['Sick Leave', 'Casual Leave', 'Personal Leave', 'Bereavement Leave']);

    // HR can narrow the list.
    $this->policy->update(['combined_leave_rules' => ['enabled' => true, 'reason_type_ids' => [$this->sick->id]]]);
    expect(collect(app(CombinedLeaveService::class)->reasons($this->employee, $year))->pluck('name')->all())->toBe(['Sick Leave']);
});

test('Sick Leave needs a certificate from 2 working days, and half days follow the policy', function () {
    LeavePolicyRule::where('leave_type_id', $this->sick->id)->update(['attachment_after_days' => 2]);
    $twoDays = ['2026-11-10' => ['type' => LeaveRequestDay::FULL_PAID], '2026-11-11' => ['type' => LeaveRequestDay::FULL_UNPAID]];

    expect(app(CombinedLeaveService::class)->evaluate($this->employee, $this->sick->fresh(), Carbon::parse('2026-11-10'), Carbon::parse('2026-11-11'), $twoDays)['attachment_required'])->toBeTrue()
        ->and(fn () => app(LeaveService::class)->submitCombinedRequest($this->employee, $this->sick->fresh(), '2026-11-10', '2026-11-11', $twoDays, 'Flu'))
        ->toThrow(DomainException::class, 'attachment');

    $this->policy->update(['combined_leave_rules' => ['enabled' => true, 'allow_half_days' => false]]);
    $half = app(CombinedLeaveService::class)->evaluate($this->employee, $this->casual, Carbon::parse('2026-11-10'), Carbon::parse('2026-11-10'),
        ['2026-11-10' => ['type' => LeaveRequestDay::HALF_PAID, 'session' => 'first_half']]);
    expect($half['blocked'])->toBeTrue()->and($half['errors'][0])->toContain('not allowed');
});

test('Combined Leave is offered only when the policy enables it', function () {
    $this->policy->update(['combined_leave_rules' => ['enabled' => false]]);

    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)->call('openDrawer');

    expect(array_keys($component->instance()->choices))->not->toContain(RequestLeave::COMBINED)
        ->and(fn () => app(LeaveService::class)->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-10', [], 'x'))
        ->toThrow(DomainException::class, 'not enabled');
});

// ── Approval ────────────────────────────────────────────────────────────────

test('one request gets one approval: manager, then HR when the reason requires it', function () {
    combinedSaturdayOnlyWeek();
    LeavePolicyRule::where('leave_type_id', $this->sick->id)->update(['approval_workflow' => ['steps' => ['manager'], 'conditions' => [['min_days' => 3, 'steps' => ['manager', 'hr']]]]]);
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'manager_id' => null]);
    $this->employee->update(['manager_id' => $manager->id]);
    $service = app(LeaveService::class);

    $request = $service->submitCombinedRequest($this->employee->fresh(), $this->sick->fresh(), '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Flu');
    expect($request->approval_path)->toBe(['manager', 'hr']);

    $afterManager = $service->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason']), 'approved', $manager->id);
    expect($afterManager->status)->toBe('pending_hr')
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->exists())->toBeFalse();

    // A reviewer sending other dates changes nothing: the request is reviewed as submitted.
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $final = $service->reviewRequest($afterManager, ['leave_type_id' => $this->casual->id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'reason' => 'Flu'], 'approved', $hr->id);

    expect($final->status)->toBe('approved')
        ->and($final->leave_type_id)->toBe($this->sick->id)
        ->and($final->end_date->toDateString())->toBe('2026-11-16')
        ->and((float) LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->sum('days'))->toBe(-2.5)
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->count())->toBe(1);
});

// ── Payroll and attendance ──────────────────────────────────────────────────

test('payroll reads the exact paid and unpaid units per date', function () {
    combinedSaturdayOnlyWeek();
    combinedApprove(app(LeaveService::class)->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Flu'));

    $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
    $period = app(PayableDaysService::class)->forPeriod($this->employee->fresh(), Carbon::parse('2026-11-10'), Carbon::parse('2026-11-16'));

    expect($period['paid_leave_days'])->toBe(2.5)
        ->and($period['unpaid_leave_days'])->toBe(2.0)
        ->and($period['days']['2026-11-10']['lop'])->toBe(0.0)
        ->and($period['days']['2026-11-15']['lop'])->toBe(1.0)
        ->and($period['days']['2026-11-16']['lop'])->toBe(1.0)
        ->and($period['days']['2026-11-12']['lop'])->toBe(0.0)
        // Attendance %: the 2 unpaid days stay in the denominator; paid leave never does.
        ->and($period['attendance_eligible_units'])->toBe(2.0)
        ->and($period['attended_units'])->toBe(0.0);
});

// ── Reporting, Employee 360, preview ────────────────────────────────────────

test('usage by reason reports paid and unpaid days — statistics, not balances', function () {
    combinedSaturdayOnlyWeek();
    combinedApprove(app(LeaveService::class)->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Flu'));

    $usage = collect(app(LeaveCategoryBalances::class)->usageByReason($this->employee, $this->earned, LeaveYear::where('label', '2026/27')->first()))->keyBy('name');

    expect($usage['Sick Leave']['used'])->toBe(2.5)
        ->and($usage['Sick Leave']['unpaid'])->toBe(2.0);
});

test('Employee 360 shows one Combined Leave request with its split and dates', function () {
    combinedSaturdayOnlyWeek();
    app(LeaveService::class)->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Flu');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    Livewire::actingAs($admin)->test(Employee360::class, ['employee' => $this->employee])
        ->call('setLeaveTab', 'pending')
        ->assertSee('Combined Leave')
        ->assertSee('Reason: Sick Leave')
        ->assertSee('Paid 2.5 · Unpaid 2')
        ->assertSeeHtml('data-combined-dates')
        ->assertSee('Half Day – Paid · first half');
});

test('the preview checks every Combined header against its day rows', function () {
    combinedSaturdayOnlyWeek();
    $request = combinedApprove(app(LeaveService::class)->submitCombinedRequest($this->employee, $this->sick, '2026-11-10', '2026-11-16', combinedCriticalAllocations(), 'Flu'));

    $this->artisan('leave:v2-preview', ['--year' => '2026/27', '--employee' => [$this->employee->id]])
        ->expectsOutputToContain('Combined Leave requests: 1 (approved 1) · paid 2.5 · unpaid 2 · half days 1 · header vs day rows mismatches: 0');

    // A header that no longer matches its rows is caught.
    LeaveRequest::whereKey($request->id)->update(['lwp_days' => 1]);
    $this->artisan('leave:v2-preview', ['--year' => '2026/27', '--employee' => [$this->employee->id]])
        ->expectsOutputToContain('header vs day rows mismatches: 1')
        ->assertFailed();
});

// ── Drawer and HR settings ──────────────────────────────────────────────────

test('the drawer: choose Combined Leave, a reason and dates, allocate each date, review and submit', function () {
    combinedSaturdayOnlyWeek();

    $component = Livewire::actingAs($this->user)->test(RequestLeave::class)
        ->call('openDrawer')
        ->assertSeeHtml('data-category="combined"')
        ->call('chooseCategory', RequestLeave::COMBINED)
        ->call('next')
        ->assertSeeHtml('data-combined-reason')
        ->set('leaveTypeId', $this->sick->id)
        ->set('startDate', '2026-11-10')
        ->set('endDate', '2026-11-16')
        ->assertSeeHtml('data-combined-rows')
        // Short status badges; the holiday's name underneath; excluded dates muted.
        ->assertSeeHtml('data-status-badge')
        ->assertSee('Working Day')
        ->assertSee('Weekly Off')
        ->assertSee('Holiday')
        ->assertSee('Founders Day')
        ->assertSee('No leave charged')
        ->assertDontSee('Excluded · no leave charged')
        ->assertSeeHtml('data-combined-cards');

    // Every chargeable date starts as a full paid day.
    expect(array_keys($component->get('allocations')))->toBe(['2026-11-10', '2026-11-11', '2026-11-13', '2026-11-15', '2026-11-16']);

    $component->set('allocations.2026-11-13.type', LeaveRequestDay::HALF_PAID)
        ->assertSet('allocations.2026-11-13.session', 'first_half')
        ->set('allocations.2026-11-15.type', LeaveRequestDay::FULL_UNPAID)
        ->set('allocations.2026-11-16.type', LeaveRequestDay::FULL_UNPAID)
        ->set('reason', 'Flu and recovery')
        ->assertSee('Paid leave')
        ->assertSee('5.5 days')
        ->call('next')
        ->assertSet('step', 3)
        ->assertSeeHtml('data-combined-review')
        ->assertSee('Combined Leave')
        ->assertSee('2 unpaid days (Leave Without Pay)')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('step', 4);

    $request = LeaveRequest::where('employee_id', $this->employee->id)->sole();
    expect($request->isCombined())->toBeTrue()
        ->and($request->paidDays())->toBe(2.5)
        ->and($request->unpaidUnits())->toBe(2.0)
        ->and(AuditLog::where('event', 'LEAVE_COMBINED_SUBMITTED')->exists())->toBeTrue();
});

test('HR sets the Combined Leave rules in the policy editor, audited', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    Livewire::actingAs($admin)->test(LeavePolicyEditor::class, ['policyId' => $this->policy->id])
        ->assertSeeHtml('data-combined-rules')
        ->set('combined.allow_half_unpaid', false)
        ->set('combined.excess_handling', 'convert_to_lwp')
        ->set('combined.max_range_days', 14)
        ->set('combined.reason_type_ids', [$this->sick->id, $this->casual->id])
        ->call('saveCombinedRules')
        ->assertHasNoErrors();

    $rules = CombinedLeaveService::rulesOf($this->policy->fresh());
    expect($rules['enabled'])->toBeTrue()
        ->and($rules['allow_half_unpaid'])->toBeFalse()
        ->and($rules['excess_handling'])->toBe('convert_to_lwp')
        ->and($rules['max_range_days'])->toBe(14)
        ->and($rules['reason_type_ids'])->toEqualCanonicalizing([$this->sick->id, $this->casual->id])
        ->and(AuditLog::where('event', 'LEAVE_POLICY_UPDATED')->exists())->toBeTrue();
});

// ── Historical compatibility ────────────────────────────────────────────────

test('standard requests are unchanged: no day rows, and a half day still counts 0.5', function () {
    $request = app(LeaveService::class)->submitRequest($this->employee, $this->casual, '2026-11-10', '2026-11-10', 'Errand', true, 'first_half');
    combinedApprove($request);

    $states = app(WorkingDayResolver::class)->classifyRange($this->employee->fresh(), Carbon::parse('2026-11-10'), Carbon::parse('2026-11-10'));

    expect($request->fresh()->isCombined())->toBeFalse()
        ->and($request->fresh()->dayAllocations)->toHaveCount(0)
        ->and($states['2026-11-10']['leave_days'])->toBe(0.5)
        ->and((float) LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->sum('days'))->toBe(-0.5);
});
