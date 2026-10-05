<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\TimeOff\MyLeaveBalances;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\ConexusCslAccrualService;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * CSL is earned 1 day per COMPLETED calendar month of the July–June leave
 * year (HR-confirmed), at most 12. The HR register's current-year credit of 2
 * stands for July and August; accrual continues from September and never
 * credits a month twice.
 *
 * Clock: Monday 5 October 2026 — July, August and September are complete.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    foreach (range(26, 31) as $day) {
        DecemberMandatoryDay::create(['year' => 2026, 'date' => "2026-12-{$day}", 'description' => 'Company shutdown']);
    }
    // The CSL is production's existing Paid Leave type, renamed in place.
    conexusPaidLeave();
    app(ConexusLeavePolicyService::class)->apply();
});

/** An employee on the Conexus policy, optionally reconciled to a register row. */
function caEmployee(?array $register = null, array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08']);

    if ($register !== null) {
        $register += ['encashed' => 0];
        app(LeaveRegisterReconciliationService::class)->reconcileEmployee($employee->fresh(), $register + [
            'name' => 'Test', 'emails' => [$user->email],
            'available' => $register['credit'] + $register['carry'] - $register['used'] - $register['encashed'],
        ], caYear(), caCsl(), LeaveType::withTrashed()->where('code', 'AL')->first(), null);
    }

    return $employee->fresh();
}

function caCsl(): LeaveType
{
    return LeaveType::where('code', 'CSL')->firstOrFail();
}

function caYear(string $label = '2026/27'): LeaveYear
{
    return LeaveYear::where('label', $label)->firstOrFail();
}

/** Run the accrual (as the scheduler does) for one employee, as of a date. */
function caAccrue(Employee $employee, string $asOf = '2026-10-05'): array
{
    $service = app(ConexusCslAccrualService::class);
    $asOfDate = Carbon::parse($asOf);
    $plan = $service->plan($employee->fresh(['exitRecord']), caCsl(), $service->yearFor($asOfDate), $asOfDate);
    $service->apply($plan, caCsl());

    return $plan;
}

function caSummary(Employee $employee, string $label = '2026/27'): array
{
    $year = caYear($label);
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', caCsl()->id)
        ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))->firstOrFail();

    return app(LeaveBalanceCalculator::class)->summary($balance);
}

function caAccruals(Employee $employee): Collection
{
    return LeaveLedgerEntry::where('employee_id', $employee->id)
        ->where('source_type', ConexusCslAccrualService::SOURCE_TYPE)
        ->whereNull('reverses_entry_id')->whereDoesntHave('reversedBy')
        ->orderBy('effective_date')->get();
}

// ── The year and the months ─────────────────────────────────────────────────

test('the leave year runs July to June, and June belongs to the finishing year', function () {
    $service = app(ConexusCslAccrualService::class);

    expect($service->months(caYear()))->toBe(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
        '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06'])
        ->and($service->yearFor(Carbon::parse('2027-07-01'))->label)->toBe('2026/27')
        ->and($service->yearFor(Carbon::parse('2026-07-01'))->label)->toBe('2025/26');
});

test('one credit per completed month, effective the last day of that month', function () {
    $employee = caEmployee();

    caAccrue($employee);

    $credits = caAccruals($employee);
    expect($credits->map(fn ($e) => $e->effective_date->toDateString())->all())->toBe(['2026-07-31', '2026-08-31', '2026-09-30'])
        ->and($credits->pluck('entry_type')->unique()->all())->toBe([LeaveLedgerEntry::TYPE_ACCRUAL])
        ->and($credits->first()->idempotency_key)->toBe("conexus-csl-accrual:{$employee->id}:".caYear()->id.':2026-07')
        ->and(caSummary($employee)['accrued'])->toBe(3.0);
});

test('the current year never exceeds 12, and the June day lands in the finishing year', function () {
    $employee = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);

    caAccrue($employee, '2027-07-01');

    $june = caAccruals($employee)->last();
    expect(caSummary($employee)['base'] + caSummary($employee)['accrued'])->toBe(12.0)
        ->and(caAccruals($employee))->toHaveCount(10)
        ->and($june->effective_date->toDateString())->toBe('2027-06-30')
        ->and($june->leave_year_id)->toBe(caYear()->id);

    // A later run in the new year adds nothing to the finished one.
    caAccrue($employee, '2027-07-15');
    expect(caSummary($employee)['base'] + caSummary($employee)['accrued'])->toBe(12.0);
});

test('re-running posts nothing: a month is credited once', function () {
    $employee = caEmployee();

    caAccrue($employee);
    $entries = LeaveLedgerEntry::count();
    $again = caAccrue($employee);
    $this->artisan('leave:conexus-csl-accrual', ['--apply' => true])->assertExitCode(0);

    expect(LeaveLedgerEntry::count())->toBe($entries)
        ->and($again['missing_months'])->toBe([])
        ->and(caAccruals($employee))->toHaveCount(3);
});

// ── The register snapshot ───────────────────────────────────────────────────

test('the register credit of 2 stands for July and August and is not credited again', function () {
    $yogesh = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);

    $plan = caAccrue($yogesh);

    expect($plan['base_months'])->toBe(['2026-07', '2026-08'])
        ->and($plan['missing_months'])->toBe(['2026-09'])
        ->and(caAccruals($yogesh)->map(fn ($e) => $e->meta['month'])->all())->toBe(['2026-09']);
});

test('the worked examples after September: Yogesh 3 accrued and 5 available, Mayuresh 2', function () {
    $yogesh = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    $mayuresh = caEmployee(['credit' => 2, 'carry' => 0, 'used' => 1]);
    $registerEntry = LeaveLedgerEntry::where('employee_id', $yogesh->id)->where('entry_type', LeaveLedgerEntry::TYPE_BASE)
        ->where('source_type', LeaveRegisterReconciliationService::SOURCE_TYPE)->firstOrFail();

    caAccrue($yogesh);
    caAccrue($mayuresh);

    $y = caSummary($yogesh);
    $m = caSummary($mayuresh);
    expect([$y['base'] + $y['accrued'], $y['carry_forward'], $y['used'], $y['available_to_request']])->toBe([3.0, 7.0, 5.0, 5.0])
        ->and([$m['base'] + $m['accrued'], $m['carry_forward'], $m['used'], $m['available_to_request']])->toBe([3.0, 0.0, 1.0, 2.0])
        // The increase is a new ledger accrual; the register entry is untouched.
        ->and($registerEntry->fresh()->days)->toEqual(2.0)
        ->and($registerEntry->fresh()->reversedBy)->toBeNull();
});

test('the next completed month adds exactly one day', function () {
    $yogesh = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    caAccrue($yogesh);

    $this->travelTo(Carbon::parse('2026-11-01 00:15'));
    $plan = caAccrue($yogesh, '2026-11-01');

    expect($plan['missing_months'])->toBe(['2026-10'])
        ->and(caSummary($yogesh)['available_to_request'])->toBe(6.0);
});

test('a month credited before the register was applied is reversed as a duplicate, not counted twice', function () {
    $employee = caEmployee();
    caAccrue($employee, '2026-08-01'); // July credited while no register credit existed

    app(LeaveRegisterReconciliationService::class)->reconcileEmployee($employee->fresh(), [
        'name' => 'Late', 'emails' => [$employee->user->email], 'credit' => 2, 'carry' => 0, 'used' => 0, 'encashed' => 0, 'available' => 2,
    ], caYear(), caCsl(), LeaveType::withTrashed()->where('code', 'AL')->first(), null);
    $plan = caAccrue($employee);

    expect($plan['duplicates'])->toHaveCount(1)
        ->and(caAccruals($employee)->map(fn ($e) => $e->meta['month'])->all())->toBe(['2026-09'])
        ->and(caSummary($employee)['base'] + caSummary($employee)['accrued'])->toBe(3.0);
});

// ── Nothing else moves ──────────────────────────────────────────────────────

test('carry forward, usage and encashment are untouched by accrual', function () {
    $employee = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', caCsl()->id)->firstOrFail();
    app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_ENCASHMENT, 1, Carbon::parse('2026-09-15'), 'test:encash');
    app(LeaveLedgerService::class)->rebuild($balance);

    caAccrue($employee);

    $s = caSummary($employee);
    expect([$s['carry_forward'], $s['used'], $s['encashed'], $s['approved_available']])->toBe([7.0, 5.0, 1.0, 4.0]);
});

test('a negative balance stays negative and readable, reduced only by the new month', function () {
    $employee = caEmployee(['credit' => 2, 'carry' => 0, 'used' => 6]);

    caAccrue($employee);

    expect(caSummary($employee)['approved_available'])->toBe(-3.0)
        ->and(app(EmployeeLeaveOverviewService::class)->for($employee)['available_leave'])->toBe(-3.0);
});

// ── What Available Leave is made of ─────────────────────────────────────────

test('the retired 28-day Annual Leave and MDL never count toward Available Leave', function () {
    $employee = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    $annual = LeaveType::withTrashed()->where('code', 'AL')->firstOrFail();
    $old = app(LeaveMovementService::class)->balanceFor($employee->id, $annual->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($old);
    caAccrue($employee);
    app(LeaveLedgerService::class)->credit($old, LeaveLedgerEntry::TYPE_BASE, 28, Carbon::parse('2026-07-01'), 'test:al-28', ['allow_closed_year' => true]);

    $overview = app(EmployeeLeaveOverviewService::class)->for($employee);

    expect($annual->trashed())->toBeTrue()
        ->and($overview['available_leave'])->toBe(5.0)
        ->and($overview['others'])->toHaveCount(0)
        ->and($overview['mdl']['configured'])->toBe(6);
});

test('Comp Off stays its own earned balance and is never accrued', function () {
    $employee = caEmployee(['credit' => 2, 'carry' => 0, 'used' => 0]);
    app(LeaveService::class)->creditCompOff($employee, Carbon::parse('2026-09-12'));

    caAccrue($employee);
    $overview = app(EmployeeLeaveOverviewService::class)->for($employee);
    $compOff = LeaveType::where('category', 'comp_off')->firstOrFail();

    expect($overview['csl']['summary']['available_to_request'])->toBe(3.0)
        ->and($overview['comp_off']['summary']['available_to_request'])->toBe(1.0)
        ->and($overview['available_leave'])->toBe(4.0)
        ->and(LeaveLedgerEntry::where('leave_type_id', $compOff->id)->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)->exists())->toBeFalse();
});

// ── Joining and leaving ─────────────────────────────────────────────────────

test('nothing is earned before joining, and a joining month counts only when worked in full', function () {
    $midMonth = caEmployee(attributes: ['joining_date' => '2026-08-10']);
    $firstOfMonth = caEmployee(attributes: ['joining_date' => '2026-09-01']);
    $future = caEmployee(attributes: ['joining_date' => '2026-11-02', 'status' => 'onboarding']);

    caAccrue($midMonth);
    caAccrue($firstOfMonth);
    caAccrue($future);

    expect(caAccruals($midMonth)->map(fn ($e) => $e->meta['month'])->all())->toBe(['2026-09'])
        ->and(caAccruals($firstOfMonth)->map(fn ($e) => $e->meta['month'])->all())->toBe(['2026-09'])
        ->and(caAccruals($future))->toHaveCount(0)
        ->and(LeavePolicyRule::where('leave_type_id', caCsl()->id)->value('joining_month_rule'))->toBe(LeavePolicyRule::JOINING_NONE);
});

test('nothing is earned after the last working day', function () {
    $leaver = caEmployee(attributes: ['status' => 'resigned']);
    ExitRecord::create(['employee_id' => $leaver->id, 'last_working_day' => '2026-08-20', 'exit_type' => 'resignation']);
    $serving = caEmployee(attributes: ['status' => 'notice_period']);
    ExitRecord::create(['employee_id' => $serving->id, 'last_working_day' => '2026-10-31', 'exit_type' => 'resignation']);

    caAccrue($leaver);
    caAccrue($serving);

    expect(caAccruals($leaver)->map(fn ($e) => $e->meta['month'])->all())->toBe(['2026-07'])
        ->and(caAccruals($serving))->toHaveCount(3);
});

test('probation, confirmed and onboarding staff earn like everyone employed', function (string $status) {
    $employee = caEmployee(attributes: ['status' => $status]);

    caAccrue($employee);

    expect(caAccruals($employee))->toHaveCount(3);
})->with(['probation', 'confirmed', 'onboarding', 'notice_period']);

test('an employee the accrual cannot read is blocked, not guessed', function () {
    $noJoining = caEmployee(attributes: ['joining_date' => null]);
    $leftNoDate = caEmployee(['credit' => 2, 'carry' => 0, 'used' => 0], ['status' => 'terminated']);

    $a = caAccrue($noJoining);
    $b = caAccrue($leftNoDate);

    expect($a['status'])->toBe(ConexusCslAccrualService::BLOCKED)
        ->and($b['status'])->toBe(ConexusCslAccrualService::BLOCKED)
        ->and(caAccruals($noJoining))->toHaveCount(0)
        ->and(caAccruals($leftNoDate))->toHaveCount(0);
});

// ── Commands and screens ────────────────────────────────────────────────────

test('the preview proves the register 2 is not duplicated and saves nothing', function () {
    caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    $entries = LeaveLedgerEntry::count();

    $this->artisan('leave:conexus-csl-accrual')
        ->expectsOutputToContain('Jul, Aug')
        ->expectsOutputToContain('Preview only')
        ->assertExitCode(0);

    expect(LeaveLedgerEntry::count())->toBe($entries);
});

test('the register reconciliation with --with-accrual ends on the September figures', function () {
    $user = User::factory()->create(['role' => UserRole::Employee, 'email' => 'yogesh.sakpal@conexus-ns.com']);
    $yogesh = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2022-04-01']);

    $this->artisan('leave:conexus-reconcile', ['--apply' => true, '--with-accrual' => true, '--employee' => ['yogesh.sakpal@conexus-ns.com']])
        ->assertExitCode(0);
    $this->artisan('leave:conexus-reconcile', ['--apply' => true, '--with-accrual' => true, '--employee' => ['yogesh.sakpal@conexus-ns.com']])
        ->assertExitCode(0);

    $s = caSummary($yogesh);
    expect([$s['base'], $s['accrued'], $s['carry_forward'], $s['used'], $s['available_to_request']])->toBe([2.0, 1.0, 7.0, 5.0, 5.0])
        ->and(caAccruals($yogesh))->toHaveCount(1);
});

test('the generic start-of-month accrual never credits CSL', function () {
    $employee = caEmployee();

    $this->artisan('hrms:monthly-leave-accrual', ['--year' => 2026, '--month' => 10])->assertExitCode(0);

    expect(LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', caCsl()->id)
        ->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)->exists())->toBeFalse();
});

test('the dashboard and the leave page show the same available leave', function () {
    $yogesh = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    caAccrue($yogesh);

    $dashboard = Livewire::actingAs($yogesh->user)->test(Dashboard::class);
    $kpi = collect($dashboard->viewData('kpis'))->firstWhere('label', 'Available Leave')['value'];
    $card = $dashboard->viewData('leave')['primary']['available_to_request'];
    $page = Livewire::actingAs($yogesh->user)->test(MyLeaveBalances::class)
        ->assertSee('Accrued this year')
        ->assertSee('Available to request')
        ->assertSee('Approved balance');

    expect($kpi)->toBe('5 days')
        ->and($card)->toBe(5.0)
        ->and($page->instance()->overview['available_leave'])->toBe(5.0);
});

test('the CSL settings read as the confirmed policy', function () {
    $csl = caCsl();
    $rule = LeavePolicyRule::where('leave_type_id', $csl->id)->firstOrFail();

    expect([$csl->name, $csl->code, $csl->allow_half_day, $csl->allow_carry_forward, $csl->allow_encashment, $csl->allow_paid_request])
        ->toBe(['Casual / Sick Leave', 'CSL', true, true, true, true])
        ->and($csl->is_monthly_accrual)->toBeTrue()
        ->and((float) $csl->accrual_days_per_month)->toBe(1.0)
        ->and((float) $rule->fixed_days)->toBe(12.0)
        ->and($rule->accrual_method)->toBe(LeavePolicyRule::ACCRUAL_MONTHLY)
        ->and((float) $rule->accrual_amount)->toBe(1.0)
        ->and($rule->carry_forward_enabled)->toBeTrue()
        ->and($rule->carry_forward_max_days)->toBeNull()
        ->and($rule->carry_forward_expiry_months)->toBeNull()
        ->and(LeaveType::where('code', 'CSL')->count())->toBe(1);
});

test('employees see the CSL once, under its new name, and never a second Paid Leave balance', function () {
    $yogesh = caEmployee(['credit' => 2, 'carry' => 7, 'used' => 5]);
    caAccrue($yogesh);

    $overview = app(EmployeeLeaveOverviewService::class)->for($yogesh);
    Livewire::actingAs($yogesh->user)->test(MyLeaveBalances::class)
        ->assertSee('Casual / Sick Leave')
        ->assertDontSee('Paid Leave');

    expect($overview['csl']['type']->id)->toBe(caCsl()->id)
        ->and($overview['others'])->toHaveCount(0)
        ->and(LeaveType::whereIn('name', ['Casual / Sick Leave', 'Paid Leave'])->count())->toBe(1)
        ->and(LeaveBalance::where('employee_id', $yogesh->id)->where('leave_type_id', caCsl()->id)->count())->toBe(1);
});

test('an employee still on the 28-day Annual Leave is not credited until reconciled', function () {
    $employee = caEmployee();
    $annual = LeaveType::withTrashed()->where('code', 'AL')->firstOrFail();
    $old = app(LeaveMovementService::class)->balanceFor($employee->id, $annual->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($old);
    app(LeaveLedgerService::class)->credit($old, LeaveLedgerEntry::TYPE_BASE, 28, Carbon::parse('2026-07-01'), 'test:al-unreconciled');
    app(LeaveLedgerService::class)->rebuild($old);

    $plan = caAccrue($employee);

    expect($plan['status'])->toBe(ConexusCslAccrualService::BLOCKED)
        ->and($plan['reason'])->toContain('not yet reconciled')
        ->and(caAccruals($employee))->toHaveCount(0);
});
