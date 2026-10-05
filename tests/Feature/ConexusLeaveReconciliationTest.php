<?php

use App\Enums\UserRole;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
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

    // Production's authoritative type; the CSL is this type, renamed in place.
    $this->paidLeave = conexusPaidLeave();
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

test('the Conexus policy is data: CSL 12 a year earned monthly, unlimited carry, MDL 6', function () {
    cxSeedRegisterEmployees();
    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    $policy = LeavePolicy::where('name', ConexusLeavePolicyService::POLICY_NAME)->firstOrFail();
    $csl = LeaveType::where('code', 'CSL')->firstOrFail();
    $rule = LeavePolicyRule::where('leave_policy_id', $policy->id)->where('leave_type_id', $csl->id)->firstOrFail();

    expect($policy->is_default)->toBeTrue()
        ->and($policy->mandatory_leave_days)->toBe(6)
        ->and((float) $rule->fixed_days)->toBe(12.0)
        // HR-confirmed: 1 day per completed month (ConexusCslAccrualService).
        ->and($rule->accrual_method)->toBe(LeavePolicyRule::ACCRUAL_MONTHLY)
        ->and((float) $rule->accrual_amount)->toBe(1.0)
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

    // CSL accrues by month: never an up-front base, and the register's is kept.
    expect($result['status'])->toBe(EnsureEmployeeLeaveBalancesService::ACCRUAL_ONLY, json_encode($result))
        ->and(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(4.0)
        ->and(cxAvailable($staff['Yogesh'], 'AL'))->toBe(0.0);
});

// ── Edge cases a real database brings ──────────────────────────────────────

test('an existing "Paid Leave" type becomes CSL instead of a duplicate', function () {
    $paid = $this->paidLeave;
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
    $paid = $this->paidLeave;
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

// ── The CSL is the existing Paid Leave type, never a new one ───────────────

test('the preview reuses the existing Paid Leave type and says no leave type will be created', function () {
    cxSeedRegisterEmployees();
    $types = LeaveType::withTrashed()->count();

    $this->artisan('leave:conexus-reconcile')
        ->expectsOutputToContain('CSL: reuse existing Paid Leave type #'.$this->paidLeave->id.' (code PL) as canonical CSL')
        ->expectsOutputToContain('CSL: no new leave type will be created')
        ->doesntExpectOutputToContain('create "Casual / Sick Leave"')
        ->assertExitCode(0);

    expect(LeaveType::withTrashed()->count())->toBe($types)
        ->and($this->paidLeave->fresh()->name)->toBe('Paid Leave');
});

test('reconciling creates no leave type and keeps the Paid Leave id and every row pointing at it', function () {
    $paid = $this->paidLeave;
    $staff = cxSeedRegisterEmployees();
    $yogesh = $staff['Yogesh'];

    // History production already holds under Paid Leave.
    $balance = app(LeaveMovementService::class)->balanceFor($yogesh->id, $paid->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 7, Carbon::parse('2026-07-01'), 'test:paid-carry');
    app(LeaveLedgerService::class)->rebuild($balance);
    $request = LeaveRequest::create([
        'employee_id' => $yogesh->id, 'leave_type_id' => $paid->id, 'start_date' => '2026-03-10', 'end_date' => '2026-03-10',
        'days' => 1, 'reason' => 'Taken last leave year', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);
    $encashment = LeaveEncashment::create(['employee_id' => $yogesh->id, 'leave_type_id' => $paid->id, 'requested_days' => 1, 'status' => 'rejected']);
    $before = [
        'types' => LeaveType::withTrashed()->count(),
        'balances' => LeaveBalance::where('leave_type_id', $paid->id)->pluck('id')->sort()->values()->all(),
        'ledger' => LeaveLedgerEntry::where('leave_type_id', $paid->id)->pluck('id')->sort()->values()->all(),
    ];

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    $csl = LeaveType::where('code', 'CSL')->firstOrFail();
    expect($csl->id)->toBe($paid->id)
        ->and($csl->name)->toBe('Casual / Sick Leave')
        ->and(LeaveType::withTrashed()->count())->toBe($before['types'])
        ->and(LeaveType::withTrashed()->where('name', 'Paid Leave')->exists())->toBeFalse()
        ->and(LeaveBalance::whereIn('id', $before['balances'])->pluck('leave_type_id')->unique()->values()->all())->toBe([$paid->id])
        ->and(LeaveLedgerEntry::whereIn('id', $before['ledger'])->pluck('leave_type_id')->unique()->values()->all())->toBe([$paid->id])
        ->and($request->fresh()->leave_type_id)->toBe($paid->id)
        ->and($encashment->fresh()->leave_type_id)->toBe($paid->id)
        // One balance per employee and year — never a second CSL row.
        ->and(LeaveBalance::where('employee_id', $yogesh->id)->where('leave_type_id', $paid->id)->where('year', 2026)->count())->toBe(1)
        ->and(cxAvailable($yogesh, 'CSL'))->toBe(4.0);
});

test('with no Paid Leave type the command refuses and creates nothing', function () {
    $this->paidLeave->forceDelete(); // the localhost database: no Paid Leave type at all
    cxSeedRegisterEmployees();
    $types = LeaveType::withTrashed()->count();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])
        ->expectsOutputToContain('No existing "Paid Leave" type was found')
        ->assertExitCode(1);

    expect(LeaveType::withTrashed()->count())->toBe($types)
        ->and(LeaveType::withTrashed()->where('code', 'CSL')->exists())->toBeFalse();
});

test('Paternity Leave holding code PL is never taken for the CSL', function () {
    $this->paidLeave->forceDelete();
    $paternity = LeaveType::create(['name' => 'Paternity Leave', 'code' => 'PL', 'category' => 'paternity', 'is_paid' => true]);
    cxSeedRegisterEmployees();

    $this->artisan('leave:conexus-reconcile')->assertExitCode(1);
    $this->artisan('leave:conexus-reconcile', ['--csl-type' => $paternity->id])
        ->expectsOutputToContain('is not the Paid Leave type')
        ->assertExitCode(1);

    expect($paternity->fresh()->only(['name', 'code']))->toBe(['name' => 'Paternity Leave', 'code' => 'PL'])
        ->and($paternity->fresh()->trashed())->toBeFalse();
});

test('legacy Sick Leave keeps its history and nothing of it moves into CSL', function () {
    $sick = LeaveType::create(['name' => 'Sick Leave', 'code' => 'SL', 'category' => 'sick', 'is_paid' => true, 'allow_paid_request' => true]);
    $staff = cxSeedRegisterEmployees();
    $yogesh = $staff['Yogesh'];
    $balance = app(LeaveMovementService::class)->balanceFor($yogesh->id, $sick->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance, LeaveLedgerEntry::TYPE_BASE, 6, Carbon::parse('2026-07-01'), 'test:sick-base');
    app(LeaveLedgerService::class)->rebuild($balance);
    $request = LeaveRequest::create([
        'employee_id' => $yogesh->id, 'leave_type_id' => $sick->id, 'start_date' => '2026-05-04', 'end_date' => '2026-05-04',
        'days' => 1, 'reason' => 'Flu', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);
    $ledger = LeaveLedgerEntry::where('leave_type_id', $sick->id)->orderBy('id')->get(['id', 'entry_type', 'days'])->toArray();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    $sick = LeaveType::withTrashed()->findOrFail($sick->id);
    expect($sick->trashed())->toBeTrue()
        ->and($sick->allow_paid_request)->toBeFalse()
        ->and(LeaveLedgerEntry::where('leave_type_id', $sick->id)->orderBy('id')->get(['id', 'entry_type', 'days'])->toArray())->toEqual($ledger)
        ->and($request->fresh()->leave_type_id)->toBe($sick->id)
        ->and($request->fresh()->leaveType->name)->toBe('Sick Leave')
        ->and(cxAvailable($yogesh, 'SL'))->toBe(6.0)
        ->and(cxAvailable($yogesh, 'CSL'))->toBe(4.0);
});

test('Annual Leave requests stay as Annual Leave history', function () {
    $staff = cxSeedRegisterEmployees();
    $request = LeaveRequest::create([
        'employee_id' => $staff['Yogesh']->id, 'leave_type_id' => cxAnnual()->id, 'start_date' => '2026-04-13', 'end_date' => '2026-04-14',
        'days' => 2, 'reason' => 'Last leave year', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    expect($request->fresh()->leave_type_id)->toBe(cxAnnual()->id)
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->leaveType->name)->toBe('Annual Leave')
        ->and(cxAvailable($staff['Yogesh'], 'AL'))->toBe(0.0);
});

test('a legacy Annual Leave usage total with no request behind it is reversed, not moved into CSL', function () {
    $staff = cxSeedRegisterEmployees();
    $mayuresh = $staff['Mayuresh'];
    // A legacy usage total, as the ledger backfill posts it: 3 "used" days
    // with no approved request behind them — only a pending one.
    $annualBalance = LeaveBalance::where('employee_id', $mayuresh->id)->where('leave_type_id', cxAnnual()->id)->firstOrFail();
    app(LeaveLedgerService::class)->debit($annualBalance, LeaveLedgerEntry::TYPE_USAGE, 3, Carbon::parse('2026-07-01'), 'test:legacy-usage',
        ['source_type' => 'leave_balance', 'source_id' => $annualBalance->id]);
    app(LeaveLedgerService::class)->rebuild($annualBalance);
    LeaveRequest::create([
        'employee_id' => $mayuresh->id, 'leave_type_id' => cxAnnual()->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-04',
        'days' => 3, 'reason' => 'Still pending', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ]);

    $this->artisan('leave:conexus-reconcile', ['--apply' => true])->assertExitCode(0);

    expect(cxAvailable($mayuresh, 'CSL'))->toBe(1.0)
        ->and(cxAvailable($mayuresh, 'AL'))->toBe(0.0);
});

test('--with-accrual credits months only for staff whose reconciliation passed', function () {
    $staff = cxSeedRegisterEmployees();
    $staff['Firoz']->user->forceFill(['email' => 'someone.else@conexus-ns.com'])->save();

    $this->artisan('leave:conexus-reconcile', ['--apply' => true, '--with-accrual' => true])->assertExitCode(1);

    expect(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(5.0)
        ->and(cxAvailable($staff['Mayuresh'], 'CSL'))->toBe(2.0)
        ->and(LeaveLedgerEntry::where('employee_id', $staff['Firoz']->id)->where('source_type', 'conexus_csl_accrual')->exists())->toBeFalse();
});

test('register staff with a missing or late joining date get exactly September, and nobody outside the register is touched', function () {
    $staff = cxSeedRegisterEmployees();
    // Production data: some register staff have no joining date, some a late one.
    $staff['Yogesh']->forceFill(['joining_date' => null])->saveQuietly();
    $staff['Gayatri Chagan Navlakhe']->forceFill(['joining_date' => '2026-09-21'])->saveQuietly();
    $staff['Shradha']->forceFill(['joining_date' => null])->saveQuietly();
    $outsider = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active', 'joining_date' => null,
    ]);
    $outsiderEntries = LeaveLedgerEntry::where('employee_id', $outsider->id)->count();
    $options = ['--with-accrual' => true, '--skip-unlisted' => true, '--as-of' => '2026-10-05'];

    $this->artisan('leave:conexus-reconcile', $options)
        ->expectsOutputToContain('20 PASS, 0 FAIL.')
        ->expectsOutputToContain('Jul, Aug') // the plan table (register covers Jul, Aug; missing Sep)
        ->assertExitCode(0);                    // 0 only when register AND accrual are all PASS

    $this->artisan('leave:conexus-reconcile', $options + ['--apply' => true])->assertExitCode(0);
    $entries = LeaveLedgerEntry::count();
    $this->artisan('leave:conexus-reconcile', $options + ['--apply' => true])->assertExitCode(0);

    $months = LeaveLedgerEntry::where('source_type', 'conexus_csl_accrual')->whereNull('reverses_entry_id')->get();
    expect(LeaveLedgerEntry::count())->toBe($entries)                       // rerun adds 0
        ->and($months)->toHaveCount(20)
        ->and($months->pluck('meta.month')->unique()->all())->toBe(['2026-09'])
        ->and(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(5.0)
        ->and(cxAvailable($staff['Mayuresh'], 'CSL'))->toBe(2.0)
        ->and(cxAvailable($staff['Gayatri Chagan Navlakhe'], 'CSL'))->toBe(5.5)
        ->and(cxAvailable($staff['Nikita Dalal'], 'CSL'))->toBe(2.0)
        ->and(cxAvailable($staff['Shradha'], 'CSL'))->toBe(0.0)
        ->and(LeaveLedgerEntry::where('employee_id', $outsider->id)->count())->toBe($outsiderEntries)
        ->and($staff['Yogesh']->fresh()->joining_date)->toBeNull();
});

test('--skip-unlisted assigns the Conexus policy to the 20 register staff only', function () {
    $staff = cxSeedRegisterEmployees();
    $ownPolicy = LeavePolicy::create(['name' => 'Outsider policy', 'statutory_weeks' => 5.6, 'is_active' => true]);
    $outsider = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active',
        'joining_date' => '2024-01-08', 'leave_policy_id' => $ownPolicy->id,
    ]);

    $this->artisan('leave:conexus-reconcile', ['--skip-unlisted' => true, '--with-accrual' => true, '--as-of' => '2026-10-05'])
        ->expectsOutputToContain('Employees: assign 20 employee(s) to the Conexus policy')
        ->assertExitCode(0);
    $this->artisan('leave:conexus-reconcile', ['--skip-unlisted' => true, '--with-accrual' => true, '--as-of' => '2026-10-05', '--apply' => true])
        ->assertExitCode(0);

    $conexus = LeavePolicy::where('name', ConexusLeavePolicyService::POLICY_NAME)->value('id');
    expect($outsider->fresh()->leave_policy_id)->toBe($ownPolicy->id)
        ->and(Employee::whereKey(collect($staff)->pluck('id'))->pluck('leave_policy_id')->unique()->all())->toBe([$conexus])
        ->and(cxAvailable($staff['Yogesh'], 'CSL'))->toBe(5.0);
});
