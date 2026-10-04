<?php

use App\Enums\UserRole;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The Conexus Standard Leave Policy and the 2026/27 HR register.
 *
 * Every employee starts the way production does: provisioned on the UK
 * Standard policy with a 28-day "Annual Leave" ledger entitlement. The
 * reconciliation must retire that, restate CSL to the register exactly, and
 * do it only through append-only ledger movements.
 *
 * Clock: 4 October 2026, inside leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    // The six December shutdown days HR configures (Settings › Holidays).
    foreach (range(26, 31) as $day) {
        DecemberMandatoryDay::create(['year' => 2026, 'date' => "2026-12-{$day}", 'description' => 'Company shutdown']);
    }
});

function cxRegister(): array
{
    return require database_path('data/conexus_csl_register_2026_27.php');
}

/** One employee per register row, logged in under its first (live) email. */
function cxSeedRegisterEmployees(): array
{
    $made = [];
    foreach (cxRegister()['employees'] as $row) {
        $user = User::factory()->create(['role' => UserRole::Employee, 'email' => $row['emails'][0], 'name' => $row['name']]);
        $made[$row['name']] = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08']);
    }

    return $made;
}

function cxAnnual(): LeaveType
{
    return LeaveType::withTrashed()->where('code', 'AL')->firstOrFail();
}

function cxAvailable(Employee $employee, string $code): float
{
    $type = LeaveType::withTrashed()->where('code', $code)->firstOrFail();
    $year = LeaveYear::where('label', '2026/27')->first();

    return round(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
        ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', 2026))->get()
        ->sum(fn ($b) => app(LeaveBalanceCalculator::class)->summary($b->fresh())['approved_available']), 2);
}

// ── Starting point ──────────────────────────────────────────────────────────

test('employees start with the wrong 28-day Annual Leave the policy must remove', function () {
    $staff = cxSeedRegisterEmployees();

    expect(cxAvailable($staff['Yogesh'], 'AL'))->toBe(28.0);
});

// ── The register file ───────────────────────────────────────────────────────

test('the register file passes its own checksums', function () {
    $register = app(LeaveRegisterReconciliationService::class)->loadRegister(database_path('data/conexus_csl_register_2026_27.php'));

    expect($register['employees'])->toHaveCount(20)
        ->and(collect($register['employees'])->sum('credit'))->toEqual(40)
        ->and(collect($register['employees'])->sum('carry'))->toEqual(56.5)
        ->and(collect($register['employees'])->sum('used'))->toEqual(51)
        ->and(collect($register['employees'])->sum('available'))->toEqual(45.5);
});

test('a tampered register is refused before anything is touched', function () {
    $tampered = cxRegister();
    $tampered['employees'][0]['carry'] = 3; // available no longer follows
    $path = storage_path('framework/testing-register.php');
    file_put_contents($path, '<?php return '.var_export($tampered, true).';');

    expect(fn () => app(LeaveRegisterReconciliationService::class)->loadRegister($path))
        ->toThrow(DomainException::class);

    @unlink($path);
});

// ── Full reconciliation ─────────────────────────────────────────────────────

test('all 20 register employees reconcile exactly and the checksums hold', function () {
    $staff = cxSeedRegisterEmployees();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])
        ->expectsOutputToContain('20 PASS, 0 FAIL.')
        ->assertExitCode(0);

    $reconciler = app(LeaveRegisterReconciliationService::class);
    $year = LeaveYear::where('label', '2026/27')->first();
    $csl = LeaveType::where('code', 'CSL')->firstOrFail();

    $totals = ['credit' => 0, 'carry' => 0, 'used' => 0, 'available' => 0];
    foreach (cxRegister()['employees'] as $row) {
        $report = $reconciler->report($staff[$row['name']], $row, $year, $csl, cxAnnual(), null);

        expect($reconciler->matches($report, $row))->toBeTrue("{$row['name']} does not match the register")
            ->and($report['legacy_annual_active'])->toBe(0.0)
            ->and($report['mdl_days'])->toBe(6)
            ->and($report['ledger_backed'])->toBeTrue();

        $totals['credit'] += $report['csl_credit'];
        $totals['carry'] += $report['csl_carry'];
        $totals['used'] += $report['csl_used'];
        $totals['available'] += $report['csl_available'];
    }

    expect($totals)->toEqual(['credit' => 40.0, 'carry' => 56.5, 'used' => 51.0, 'available' => 45.5]);
});

test('the worked examples: Yogesh 4, Mayuresh 1, Shradha stays -1', function () {
    $staff = cxSeedRegisterEmployees();
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    expect(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(4.0)
        ->and(cxAvailable($staff['Yogesh'], 'AL'))->toBe(0.0)
        ->and(cxAvailable($staff['Mayuresh'], 'CSL'))->toBe(1.0)
        ->and(cxAvailable($staff['Shradha'], 'CSL'))->toBe(-1.0);
});

test('the live account is used for an employee the register lists under an alias', function () {
    $staff = cxSeedRegisterEmployees();
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    expect($staff['Nikita Dalal']->user->email)->toBe('nikita@conexus-ns.com')
        ->and(cxAvailable($staff['Nikita Dalal'], 'CSL'))->toBe(1.0);
});

test('a preview saves nothing', function () {
    cxSeedRegisterEmployees();
    $entries = LeaveLedgerEntry::count();

    $this->artisan('leave:conexus-reconcile')
        ->expectsOutputToContain('20 PASS, 0 FAIL.')
        ->expectsOutputToContain('Preview only')
        ->assertExitCode(0);

    expect(LeaveLedgerEntry::count())->toBe($entries)
        ->and(LeaveType::where('code', 'CSL')->exists())->toBeFalse()
        ->and(LeaveType::where('code', 'AL')->exists())->toBeTrue();
});

test('re-running the reconciliation posts nothing new', function () {
    cxSeedRegisterEmployees();
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);
    $entries = LeaveLedgerEntry::count();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])
        ->expectsOutputToContain('20 PASS, 0 FAIL.')
        ->assertExitCode(0);

    expect(LeaveLedgerEntry::count())->toBe($entries);
});

test('existing ledger entries are never edited or deleted', function () {
    cxSeedRegisterEmployees();
    $before = LeaveLedgerEntry::orderBy('id')->get(['id', 'entry_type', 'days', 'effective_date', 'leave_type_id'])->toArray();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    $after = LeaveLedgerEntry::whereIn('id', array_column($before, 'id'))->orderBy('id')
        ->get(['id', 'entry_type', 'days', 'effective_date', 'leave_type_id'])->toArray();

    expect($after)->toEqual($before)
        ->and(LeaveLedgerEntry::count())->toBeGreaterThan(count($before));
});

// ── Policy ──────────────────────────────────────────────────────────────────

test('the Conexus policy is data: CSL 12 a year, manual grant, unlimited carry, MDL 6', function () {
    cxSeedRegisterEmployees();
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    $policy = LeavePolicy::where('name', ConexusLeavePolicyService::POLICY_NAME)->firstOrFail();
    $csl = LeaveType::where('code', 'CSL')->firstOrFail();
    $rule = LeavePolicyRule::where('leave_policy_id', $policy->id)->where('leave_type_id', $csl->id)->firstOrFail();

    expect($policy->is_default)->toBeTrue()
        ->and($policy->mandatory_leave_days)->toBe(6)
        ->and((float) $rule->fixed_days)->toBe(12.0)
        ->and($rule->accrual_method)->toBe(LeavePolicyRule::ACCRUAL_MANUAL)
        ->and($rule->carry_forward_enabled)->toBeTrue()
        ->and($rule->carry_forward_max_days)->toBeNull()
        ->and($rule->carry_forward_expiry_months)->toBeNull()
        ->and($csl->name)->toBe('Casual / Sick Leave')
        ->and($csl->allow_half_day)->toBeTrue()
        ->and($csl->allow_encashment)->toBeTrue()
        ->and(LeavePolicy::where('name', 'UK Standard')->value('is_active'))->toBeFalse()
        ->and(Employee::where('leave_policy_id', '!=', $policy->id)->count())->toBe(0);
});

test('Annual Leave is retired for new use but its history is kept', function () {
    cxSeedRegisterEmployees();
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    $annual = cxAnnual();

    expect($annual->trashed())->toBeTrue()
        ->and(LeaveType::where('code', 'AL')->exists())->toBeFalse()
        ->and(LeaveLedgerEntry::where('leave_type_id', $annual->id)->where('entry_type', 'base_entitlement')->whereNull('reverses_entry_id')->count())
        ->toBeGreaterThan(0);
});

test('the daily provisioning job neither recreates 28 days nor touches the register credit', function () {
    $staff = cxSeedRegisterEmployees();
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    // What runs every morning at 05:30, and what a policy change triggers.
    $this->artisan('leave:ensure-balances', ['--apply' => true])->assertExitCode(0);
    $csl = LeaveType::where('code', 'CSL')->firstOrFail();
    $result = app(EnsureEmployeeLeaveBalancesService::class)
        ->ensureType($staff['Yogesh'], $csl, LeaveYear::where('label', '2026/27')->first(), recalculate: true);

    expect($result['status'])->toBe(EnsureEmployeeLeaveBalancesService::MANUAL_GRANT, json_encode($result))
        ->and(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(4.0)
        ->and(cxAvailable($staff['Yogesh'], 'AL'))->toBe(0.0);
});

// ── Edge cases a real database brings ──────────────────────────────────────

test('an existing "Paid Leave" type becomes CSL instead of a duplicate', function () {
    $paid = LeaveType::create(['name' => 'Paid Leave', 'category' => 'annual', 'is_paid' => true, 'allow_paid_request' => true]);
    $staff = cxSeedRegisterEmployees();
    LeaveBalance::create(['employee_id' => $staff['Sunita']->id, 'leave_type_id' => $paid->id, 'year' => 2026, 'allocated_days' => 9, 'used_days' => 0]);

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    expect(LeaveType::where('code', 'CSL')->value('id'))->toBe($paid->id)
        ->and(LeaveType::withTrashed()->whereIn('name', ['Casual / Sick Leave', 'Paid Leave'])->count())->toBe(1)
        ->and(cxAvailable($staff['Sunita'], 'CSL'))->toBe(9.0);
});

test('Annual Leave taken on a real request moves to CSL, and cancelling it returns the day to CSL', function () {
    $staff = cxSeedRegisterEmployees();
    $yogesh = $staff['Yogesh'];
    $request = LeaveRequest::create([
        'employee_id' => $yogesh->id, 'leave_type_id' => cxAnnual()->id, 'start_date' => '2026-08-10', 'end_date' => '2026-08-10',
        'days' => 1, 'reason' => 'Before the policy fix', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);
    app(LeaveMovementService::class)->recordUsage($request, 1, cxAnnual()->id, Carbon::parse('2026-08-10'));

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    // The register total (5) includes that day; it is now CSL usage linked to the request.
    expect(cxAvailable($yogesh, 'CSL'))->toBe(4.0)->and(cxAvailable($yogesh, 'AL'))->toBe(0.0);
    $link = app(LeaveLedgerService::class)->activeEntryFor('leave_request', $request->id, LeaveLedgerEntry::TYPE_USAGE);
    expect($link->leave_type_id)->toBe(LeaveType::where('code', 'CSL')->value('id'));

    app(LeaveService::class)->cancelRequest($request->fresh());

    expect(cxAvailable($yogesh, 'CSL'))->toBe(5.0)->and(cxAvailable($yogesh, 'AL'))->toBe(0.0);
});

test('an encashment the register does not know about fails that employee and changes nothing for them', function () {
    $staff = cxSeedRegisterEmployees();
    $paid = LeaveType::create(['name' => 'Paid Leave', 'category' => 'annual', 'is_paid' => true, 'allow_paid_request' => true]);
    $balance = app(LeaveMovementService::class)->balanceFor($staff['Ankita']->id, $paid->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    $ledger = app(LeaveLedgerService::class);
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 5, Carbon::parse('2026-07-01'), 'test:cf');
    $ledger->debit($balance, LeaveLedgerEntry::TYPE_ENCASHMENT, 1, Carbon::parse('2026-09-01'), 'test:enc');
    $ledger->rebuild($balance);
    $ankitaEntries = LeaveLedgerEntry::where('employee_id', $staff['Ankita']->id)->count();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])
        ->expectsOutputToContain('19 PASS, 1 FAIL.')
        ->assertExitCode(1);

    expect(LeaveLedgerEntry::where('employee_id', $staff['Ankita']->id)->count())->toBe($ankitaEntries)
        ->and(cxAvailable($staff['Ankita'], 'AL'))->toBe(28.0)
        ->and(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(4.0);
});

test('a register employee with no login fails without stopping the others', function () {
    $staff = cxSeedRegisterEmployees();
    $staff['Firoz']->user->forceFill(['email' => 'someone.else@conexus-ns.com'])->save();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])
        ->expectsOutputToContain('19 PASS, 1 FAIL.')
        ->assertExitCode(1);

    expect(cxAvailable($staff['Mayuresh'], 'CSL'))->toBe(1.0);
});

test('recorded leave that exceeds the register fails that employee, names the requests, and changes nothing', function () {
    $staff = cxSeedRegisterEmployees();
    $mayuresh = $staff['Mayuresh'];
    $requests = collect(['2026-08-10', '2026-08-17'])->map(function (string $date) use ($mayuresh) {
        $request = LeaveRequest::create([
            'employee_id' => $mayuresh->id, 'leave_type_id' => cxAnnual()->id, 'start_date' => $date, 'end_date' => $date,
            'days' => 1, 'reason' => 'Recorded before the policy fix', 'status' => 'approved', 'requested_leave_status' => 'paid',
        ]);
        app(LeaveMovementService::class)->recordUsage($request, 1, cxAnnual()->id, Carbon::parse($date));

        return $request;
    });
    $entries = LeaveLedgerEntry::where('employee_id', $mayuresh->id)->count();

    // The register says Mayuresh used 1 day; two approved requests say 2.
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])
        ->expectsOutputToContain('Recorded leave already accounts for 2 day(s) of CSL usage; the register says 1. HR must decide which leave was not taken — recorded: '
            .'10 Aug 2026 1d (leave request #'.$requests->first()->id.'); 17 Aug 2026 1d (leave request #'.$requests->last()->id.')')
        ->expectsOutputToContain('19 PASS, 1 FAIL.')
        ->assertExitCode(1);

    expect(LeaveLedgerEntry::where('employee_id', $mayuresh->id)->count())->toBe($entries)
        ->and(cxAvailable($mayuresh, 'AL'))->toBe(26.0)
        ->and(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(4.0);
});

test('an employee outside the register loses the 28 days too, and their CSL is left for HR', function () {
    cxSeedRegisterEmployees();
    $outsider = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active', 'joining_date' => '2024-01-08',
    ]);

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])
        ->expectsOutputToContain('Employees outside the register')
        ->assertExitCode(0);

    expect(cxAvailable($outsider, 'AL'))->toBe(0.0);
});
