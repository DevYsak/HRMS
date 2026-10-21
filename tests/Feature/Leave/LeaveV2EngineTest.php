<?php

use App\Enums\UserRole;
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
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveBalanceSource;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveCategoryBalances;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeavePolicyAdminService;
use App\Services\Leave\LeavePolicyAssignmentResolver;
use App\Services\Leave\LeavePolicyCap;
use App\Services\Leave\LeaveRequestEvaluator;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Leave V2 — the shared Earned Leave balance.
 *
 * Earned Leave is ONE balance. Casual, Sick, Privilege, Personal… are reasons
 * that draw on it and never hold a balance of their own; the reason is kept
 * for reporting and its own validation. Comp Off, special leave and unpaid
 * leave stay separate. Also: effective-dated policy assignments, the annual
 * cap, excess handling (block / LWP), day breakdown and approval routing.
 *
 * Clock: Monday 7 December 2026, inside leave year 2026/27 (1 Jul – 30 Jun).
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-12-07 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
});

function v2Policy(array $attributes = []): LeavePolicy
{
    return LeavePolicy::create(array_merge([
        'name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12, 'policy_year_type' => 'jul_jun',
    ], $attributes));
}

function v2Type(string $name, string $code, array $attributes = []): LeaveType
{
    return LeaveType::create(array_merge([
        'name' => $name, 'code' => $code.random_int(100, 999), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'counts_toward_annual_cap' => false, 'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true,
        'color' => '#10b981',
    ], $attributes));
}

function v2Rule(LeavePolicy $policy, LeaveType $type, array $attributes = []): LeavePolicyRule
{
    return LeavePolicyRule::create(array_merge([
        'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 6,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true,
    ], $attributes));
}

/** The policy's one Earned Leave balance: 12 days a year, 1 a month. */
function v2Earned(LeavePolicy $policy, array $rule = []): LeaveType
{
    $earned = v2Type('Earned Leave', 'EL', ['counts_toward_annual_cap' => true]);
    $policy->update(['earned_leave_type_id' => $earned->id]);
    v2Rule($policy, $earned, array_merge(['fixed_days' => 12, 'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1], $rule));

    return $earned;
}

/** A reason that draws on Earned Leave: allowed by the policy, never allocated days. */
function v2Reason(LeavePolicy $policy, string $name, string $code, array $rule = []): LeaveType
{
    $reason = v2Type($name, $code);
    v2Rule($policy, $reason, array_merge(['entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE, 'fixed_days' => null], $rule));

    return $reason;
}

function v2Employee(?Department $department = null): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'joining_date' => '2024-01-10', 'manager_id' => null,
        'department_id' => $department?->id ?? Department::factory()->create()->id,
    ]);
}

/** Credit a balance through the ledger. */
function v2Credit(Employee $employee, LeaveType $type, float $days): LeaveBalance
{
    $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $type->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, $days, Carbon::parse('2026-07-01'), 'v2-test-'.$type->id.'-'.$employee->id);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    return $balance->fresh();
}

function v2Assign(LeavePolicy $policy, string $scope, ?int $scopeId = null, string $from = '2026-07-01', ?string $to = null): LeavePolicyAssignment
{
    return LeavePolicyAssignment::create([
        'leave_policy_id' => $policy->id, 'scope' => $scope, 'scope_id' => $scopeId,
        'effective_from' => $from, 'effective_to' => $to,
    ]);
}

function v2Available(Employee $employee, LeaveType $type): float
{
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->first();

    return $balance ? app(LeaveBalanceCalculator::class)->summary($balance)['available_to_request'] : 0.0;
}

function v2Approve(LeaveRequest $request): LeaveRequest
{
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    return app(LeaveService::class)->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason']) + ['is_half_day' => (bool) $request->is_half_day], 'approved', $hr->id);
}

/**
 * An employee on the NEXCORE policy: Earned Leave with $days available and
 * the Casual, Sick, Personal reasons allowed.
 *
 * @return array{0: Employee, 1: LeavePolicy, 2: LeaveType, 3: array<string, LeaveType>}
 */
function v2Standard(float $days = 8, array $earnedRule = [], array $sickRule = []): array
{
    $employee = v2Employee();
    $policy = v2Policy();
    v2Assign($policy, LeavePolicyAssignment::SCOPE_COMPANY);
    $earned = v2Earned($policy, $earnedRule);
    $reasons = [
        'casual' => v2Reason($policy, 'Casual Leave', 'CL'),
        'sick' => v2Reason($policy, 'Sick Leave', 'SL', $sickRule),
        'personal' => v2Reason($policy, 'Personal Leave', 'PRL'),
    ];
    v2Credit($employee, $earned, $days);

    return [$employee, $policy, $earned, $reasons];
}

// ── Categories ──────────────────────────────────────────────────────────────

test('leave categories come from the type, never from its name', function () {
    expect(LeaveCategory::of(v2Type('Sick Leave', 'SL')))->toBe(LeaveCategory::EARNED)
        ->and(LeaveCategory::of(v2Type('Anything', 'X', ['leave_category' => null, 'category' => 'comp_off'])))->toBe(LeaveCategory::COMP_OFF)
        ->and(LeaveCategory::of(v2Type('Leave Without Pay', 'LWP', ['leave_category' => null, 'category' => 'lwp', 'is_paid' => false])))->toBe(LeaveCategory::UNPAID)
        ->and(LeaveCategory::of(v2Type('Work From Home', 'WFH', ['leave_category' => null, 'category' => 'wfh'])))->toBeNull()
        // HR's explicit category wins over the legacy derivation.
        ->and(LeaveCategory::of(v2Type('Casual Leave', 'CL', ['category' => 'comp_off', 'leave_category' => LeaveCategory::EARNED])))->toBe(LeaveCategory::EARNED);
});

test('paid = yes never makes a type Earned Leave; statutory kinds are Special Leave', function () {
    $legacy = fn (string $name, string $category) => v2Type($name, 'LG', ['leave_category' => null, 'category' => $category, 'is_paid' => true]);

    expect(LeaveCategory::of($legacy('Annual Leave', 'annual')))->toBeNull()
        ->and(LeaveCategory::of($legacy('Sick Leave', 'sick')))->toBeNull()
        ->and(LeaveCategory::of($legacy('Casual / Sick Leave', 'other')))->toBeNull()
        ->and(LeaveCategory::of($legacy('Maternity Leave', 'maternity')))->toBe(LeaveCategory::SPECIAL)
        ->and(LeaveCategory::of($legacy('Paternity Leave', 'paternity')))->toBe(LeaveCategory::SPECIAL)
        ->and(LeaveCategory::of($legacy('Bereavement Leave', 'bereavement')))->toBe(LeaveCategory::SPECIAL)
        ->and(LeaveCategory::label(null))->toBe('Uncategorised');
});

test('an uncategorised paid type cannot be requested through V2', function () {
    $employee = v2Employee();
    $type = v2Type('Annual Leave', 'AL', ['leave_category' => null, 'category' => 'annual']);
    v2Credit($employee, $type, 5);

    expect(fn () => app(LeaveService::class)->submitRequest($employee, $type, '2026-12-08', '2026-12-08', 'Trip'))
        ->toThrow(DomainException::class, 'cannot be requested');
});

// ── One combined Earned Leave balance ───────────────────────────────────────

test('Earned Leave is one balance: the cap counts its entitlement, never the reasons', function () {
    $policy = v2Policy();
    $earned = v2Earned($policy);
    v2Reason($policy, 'Casual Leave', 'CL');
    v2Reason($policy, 'Sick Leave', 'SL');
    v2Rule($policy, v2Type('Comp Off', 'CO', ['leave_category' => LeaveCategory::COMP_OFF]), ['fixed_days' => null]);
    v2Rule($policy, v2Type('Maternity Leave', 'ML', ['leave_category' => null, 'category' => 'maternity']), ['fixed_days' => 180]);

    $summary = app(LeavePolicyCap::class)->summary($policy->fresh());

    expect($summary['allocated'])->toBe(12.0)
        ->and($summary['cap'])->toBe(12.0)
        ->and($summary['over'])->toBeFalse()
        ->and(collect($summary['rows'])->pluck('leave_type_id')->all())->toBe([$earned->id]);
});

test('a reason cannot be given an allocation of its own: there is no Casual 6 + Sick 6', function () {
    $policy = v2Policy();
    v2Earned($policy);
    $casual = v2Type('Casual Leave', 'CL');
    $sick = v2Type('Sick Leave', 'SL');
    $hr = User::factory()->create(['role' => UserRole::SuperAdmin]);

    expect(fn () => app(LeavePolicyAdminService::class)->saveRules($policy->fresh(), [
        $casual->id => ['include' => true, 'fixed_days' => 6],
        $sick->id => ['include' => true, 'fixed_days' => 6],
    ], $hr))->toThrow(DomainException::class, 'draws on Earned Leave');

    // Allowed as reasons, they are saved without any entitlement.
    app(LeavePolicyAdminService::class)->saveRules($policy->fresh(), [
        $casual->id => ['include' => true, 'attachment_after_days' => null],
        $sick->id => ['include' => true, 'attachment_after_days' => 2],
    ], $hr);

    $rules = LeavePolicyRule::where('leave_policy_id', $policy->id)->whereIn('leave_type_id', [$casual->id, $sick->id])->get();
    expect($rules)->toHaveCount(2)
        ->and($rules->pluck('fixed_days')->filter()->all())->toBe([])
        ->and($rules->pluck('entitlement_method')->unique()->all())->toBe([LeavePolicyRule::ENTITLEMENT_NONE])
        ->and(app(LeavePolicyCap::class)->summary($policy->fresh())['allocated'])->toBe(12.0);
});

test('an Earned Leave entitlement above the cap is refused', function () {
    $policy = v2Policy();
    $earned = v2Earned($policy);

    expect(fn () => app(LeavePolicyCap::class)->assertWithinCap($policy->fresh(), [$earned->id => 14]))
        ->toThrow(DomainException::class, '14');
});

test('different reasons consume the same Earned Leave pool: 8 → 6 → 5 → 2', function () {
    [$employee, , $earned, $reasons] = v2Standard(8);
    $service = app(LeaveService::class);

    v2Approve($service->submitRequest($employee, $reasons['sick'], '2026-12-08', '2026-12-09', 'Fever'));
    expect(v2Available($employee, $earned))->toBe(6.0);

    v2Approve($service->submitRequest($employee, $reasons['casual'], '2026-12-10', '2026-12-10', 'Errand'));
    expect(v2Available($employee, $earned))->toBe(5.0);

    v2Approve($service->submitRequest($employee, $reasons['personal'], '2026-12-14', '2026-12-16', 'Family'));
    expect(v2Available($employee, $earned))->toBe(2.0);

    $usage = LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->get();
    expect($usage)->toHaveCount(3)
        // The ledger balance belongs to Earned Leave, never to Sick or Casual.
        ->and($usage->pluck('leave_type_id')->unique()->all())->toBe([$earned->id])
        ->and((float) $usage->sum('days'))->toBe(-6.0)
        ->and($usage->firstWhere('days', -2.0)->meta)->toBe(['reason_type_id' => $reasons['sick']->id, 'reason_type' => 'Sick Leave']);
});

test('a reason never has a balance of its own, and provisioning never opens one', function () {
    [$employee, , $earned, $reasons] = v2Standard(8);
    v2Approve(app(LeaveService::class)->submitRequest($employee, $reasons['sick'], '2026-12-08', '2026-12-08', 'Fever'));

    $ensured = app(EnsureEmployeeLeaveBalancesService::class)->ensureType($employee, $reasons['casual'], LeaveYear::where('label', '2026/27')->first());

    expect(LeaveBalance::where('employee_id', $employee->id)->whereIn('leave_type_id', collect($reasons)->pluck('id'))->exists())->toBeFalse()
        ->and($ensured['status'])->toBe(EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT)
        ->and(LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $earned->id)->count())->toBe(1);
});

test('the reason is stored on the request for reporting', function () {
    [$employee, , $earned, $reasons] = v2Standard(8);

    $request = app(LeaveService::class)->submitRequest($employee, $reasons['sick'], '2026-12-08', '2026-12-09', 'Fever');

    expect($request->leave_type_id)->toBe($reasons['sick']->id)
        ->and($request->main_category)->toBe(LeaveCategory::EARNED)
        ->and($request->balance_leave_type_id)->toBe($earned->id)
        ->and($request->balanceTypeId())->toBe($earned->id)
        ->and((float) $request->days)->toBe(2.0)
        ->and($request->paidDays())->toBe(2.0);

    v2Approve($request);
    $byReason = app(LeaveCategoryBalances::class)->usageByReason($employee, $earned, LeaveYear::where('label', '2026/27')->first());

    expect($byReason)->toHaveCount(1)
        ->and($byReason[0]['name'])->toBe('Sick Leave')
        ->and($byReason[0]['used'])->toBe(2.0);
});

test('Sick Leave can require a certificate after 2 days and still draws on Earned Leave', function () {
    [$employee, , $earned, $reasons] = v2Standard(8, sickRule: ['attachment_after_days' => 2]);
    $evaluator = app(LeaveRequestEvaluator::class);

    $oneDay = $evaluator->evaluate($employee, $reasons['sick'], Carbon::parse('2026-12-08'), Carbon::parse('2026-12-08'));
    $twoDays = $evaluator->evaluate($employee, $reasons['sick'], Carbon::parse('2026-12-08'), Carbon::parse('2026-12-09'));
    $casual = $evaluator->evaluate($employee, $reasons['casual'], Carbon::parse('2026-12-08'), Carbon::parse('2026-12-09'));

    expect($oneDay['attachment_required'])->toBeFalse()
        ->and($twoDays['attachment_required'])->toBeTrue()
        ->and($twoDays['balance_leave_type_id'])->toBe($earned->id)
        ->and($twoDays['available'])->toBe(8.0)
        // Casual has no certificate rule.
        ->and($casual['attachment_required'])->toBeFalse()
        ->and(fn () => app(LeaveService::class)->submitRequest($employee, $reasons['sick'], '2026-12-08', '2026-12-09', 'Fever'))
        ->toThrow(DomainException::class, 'attachment');
});

test('a pending request reserves the common Earned Leave pool, whatever its reason', function () {
    [$employee, , $earned, $reasons] = v2Standard(8);

    app(LeaveService::class)->submitRequest($employee, $reasons['sick'], '2026-12-08', '2026-12-09', 'Fever');

    $casual = app(LeaveRequestEvaluator::class)->evaluate($employee, $reasons['casual'], Carbon::parse('2026-12-14'), Carbon::parse('2026-12-14'));

    expect(v2Available($employee, $earned))->toBe(6.0)
        ->and($casual['available'])->toBe(6.0)
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->exists())->toBeFalse();
});

test('rejection releases the Earned Leave reservation', function () {
    [$employee, , $earned, $reasons] = v2Standard(8);
    $service = app(LeaveService::class);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $request = $service->submitRequest($employee, $reasons['casual'], '2026-12-08', '2026-12-09', 'Trip');
    expect(v2Available($employee, $earned))->toBe(6.0);

    $service->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason']), 'rejected', $hr->id, 'No cover');
    expect(v2Available($employee, $earned))->toBe(8.0);
});

test('cancellation releases the Earned Leave reservation, and returns approved days', function () {
    [$employee, , $earned, $reasons] = v2Standard(8);
    $service = app(LeaveService::class);

    $pending = $service->submitRequest($employee, $reasons['casual'], '2026-12-08', '2026-12-09', 'Trip');
    expect(v2Available($employee, $earned))->toBe(6.0);
    $service->cancelRequest($pending, 'Plans changed', $employee->user);
    expect(v2Available($employee, $earned))->toBe(8.0);

    $approved = v2Approve($service->submitRequest($employee, $reasons['sick'], '2026-12-10', '2026-12-10', 'Fever'));
    expect(v2Available($employee, $earned))->toBe(7.0);
    $service->cancelRequest($approved->fresh(), 'Recovered', $employee->user);
    expect(v2Available($employee, $earned))->toBe(8.0);
});

test('the employee cannot choose payroll treatment: an Earned Leave reason is always paid', function () {
    [$employee, , $earned, $reasons] = v2Standard(8);

    $request = app(LeaveService::class)->submitRequest($employee, $reasons['sick'], '2026-12-08', '2026-12-08', 'Fever', requestedLeaveStatus: 'unpaid');

    expect($request->requested_leave_status)->toBe('paid')
        ->and(v2Available($employee, $earned))->toBe(7.0);
});

test('insufficient Earned Leave is refused when the policy blocks excess', function () {
    [$employee, , , $reasons] = v2Standard(2, ['excess_handling' => 'block']);

    expect(fn () => app(LeaveService::class)->submitRequest($employee, $reasons['sick'], '2026-12-07', '2026-12-10', 'Fever'))
        ->toThrow(DomainException::class, 'You have only 2 Earned Leave day(s) available.');
});

test('excess becomes LWP when the policy converts it: Earned −2, LWP 2', function () {
    [$employee, , $earned, $reasons] = v2Standard(2, ['excess_handling' => 'convert_to_lwp']);

    $request = app(LeaveService::class)->submitRequest($employee, $reasons['personal'], '2026-12-07', '2026-12-10', 'Family');

    expect((float) $request->days)->toBe(4.0)
        ->and((float) $request->lwp_days)->toBe(2.0)
        ->and(v2Available($employee, $earned))->toBe(0.0);

    v2Approve($request);

    expect((float) LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->where('leave_type_id', $earned->id)->sum('days'))->toBe(-2.0)
        ->and(v2Available($employee, $earned))->toBe(0.0);
});

test('a reason the policy does not allow is refused', function () {
    [$employee] = v2Standard(8);
    $privilege = v2Type('Privilege Leave', 'PL');

    $result = app(LeaveRequestEvaluator::class)->evaluate($employee, $privilege, Carbon::parse('2026-12-08'), Carbon::parse('2026-12-08'));

    expect($result['blocked'])->toBeTrue()
        ->and($result['errors'][0])->toContain('not one of the leave reasons');
});

test('without an Earned Leave balance in the policy, Earned reasons cannot be requested', function () {
    $employee = v2Employee();
    $policy = v2Policy();
    v2Assign($policy, LeavePolicyAssignment::SCOPE_COMPANY);
    $sick = v2Reason($policy, 'Sick Leave', 'SL');

    $result = app(LeaveRequestEvaluator::class)->evaluate($employee, $sick, Carbon::parse('2026-12-08'), Carbon::parse('2026-12-08'));

    expect($result['balance_source'])->toBe(LeaveBalanceSource::MISSING_POOL)
        ->and($result['errors'][0])->toContain('Earned Leave is not set up');
});

// ── Separate balances ───────────────────────────────────────────────────────

test('Comp Off remains a separate balance', function () {
    [$employee, $policy, $earned] = v2Standard(8);
    $compOff = v2Type('Comp Off', 'CO', ['leave_category' => LeaveCategory::COMP_OFF]);
    v2Credit($employee, $compOff, 2);

    v2Approve(app(LeaveService::class)->submitRequest($employee, $compOff, '2026-12-08', '2026-12-08', 'Weekend cover'));

    $result = app(LeaveRequestEvaluator::class)->evaluate($employee, $compOff, Carbon::parse('2026-12-14'), Carbon::parse('2026-12-15'));

    expect(v2Available($employee, $compOff))->toBe(1.0)
        ->and(v2Available($employee, $earned))->toBe(8.0)
        ->and($result['errors'][0])->toContain('1 Comp Off');
});

test('unpaid leave / LWP bypasses every balance', function () {
    [$employee, , $earned] = v2Standard(8);
    $unpaid = v2Type('Unpaid Leave', 'LWP', ['leave_category' => LeaveCategory::UNPAID, 'is_paid' => false]);

    $result = app(LeaveRequestEvaluator::class)->evaluate($employee, $unpaid, Carbon::parse('2026-12-08'), Carbon::parse('2026-12-10'));
    $request = app(LeaveService::class)->submitRequest($employee, $unpaid, '2026-12-08', '2026-12-10', 'Travel');

    expect($result['blocked'])->toBeFalse()
        ->and($result['uses_balance'])->toBeFalse()
        ->and($result['unpaid_days'])->toBe(3.0)
        ->and($request->requested_leave_status)->toBe('unpaid')
        ->and($request->balance_leave_type_id)->toBeNull()
        ->and(v2Available($employee, $earned))->toBe(8.0);
});

test('special leave keeps its own entitlement unless HR makes it draw on Earned Leave', function () {
    [$employee, $policy, $earned] = v2Standard(8);
    $bereavement = v2Type('Bereavement Leave', 'BRV', ['leave_category' => null, 'category' => 'bereavement']);
    v2Rule($policy, $bereavement, ['fixed_days' => 5]);
    v2Credit($employee, $bereavement, 5);
    $evaluator = app(LeaveRequestEvaluator::class);

    $own = $evaluator->evaluate($employee, $bereavement, Carbon::parse('2026-12-08'), Carbon::parse('2026-12-09'));
    expect($own['balance_leave_type_id'])->toBe($bereavement->id)
        ->and($own['available'])->toBe(5.0);

    // HR explicitly configures it to consume Earned Leave.
    $bereavement->update(['consumes_earned_leave' => true]);
    $pooled = $evaluator->evaluate($employee, $bereavement->fresh(), Carbon::parse('2026-12-08'), Carbon::parse('2026-12-09'));

    expect($pooled['balance_source'])->toBe(LeaveBalanceSource::EARNED_POOL)
        ->and($pooled['balance_leave_type_id'])->toBe($earned->id)
        ->and($pooled['available'])->toBe(8.0);
});

// ── Assignments ─────────────────────────────────────────────────────────────

test('policy assignment precedence: employee over department over company', function () {
    $department = Department::factory()->create();
    $employee = v2Employee($department);
    $resolver = app(LeavePolicyAssignmentResolver::class);

    v2Assign($company = v2Policy(['name' => 'Company']), LeavePolicyAssignment::SCOPE_COMPANY);
    expect($resolver->resolve($employee)['policy']->id)->toBe($company->id)
        ->and($resolver->resolve($employee)['source_label'])->toBe('Company Policy');

    $resolver->flush();
    v2Assign($dept = v2Policy(['name' => 'UK Operations']), LeavePolicyAssignment::SCOPE_DEPARTMENT, $department->id);
    expect($resolver->resolve($employee)['policy']->id)->toBe($dept->id)
        ->and($resolver->resolve($employee)['source_label'])->toBe('Department Policy');

    $resolver->flush();
    v2Assign($personal = v2Policy(['name' => 'Personal']), LeavePolicyAssignment::SCOPE_EMPLOYEE, $employee->id);
    expect($resolver->resolve($employee)['policy']->id)->toBe($personal->id)
        ->and($resolver->resolve($employee)['source_label'])->toBe('Employee Override');

    // Another employee in the same department keeps the department policy.
    expect($resolver->resolve(v2Employee($department))['policy']->id)->toBe($dept->id);
});

test('assignments are effective-dated and revoked ones never apply', function () {
    $employee = v2Employee();
    $resolver = app(LeavePolicyAssignmentResolver::class);
    $old = v2Policy(['name' => 'Old']);
    $new = v2Policy(['name' => 'New']);

    v2Assign($old, LeavePolicyAssignment::SCOPE_COMPANY, null, '2026-07-01', '2026-12-31');
    v2Assign($new, LeavePolicyAssignment::SCOPE_COMPANY, null, '2027-01-01');
    $revoked = v2Assign(v2Policy(['name' => 'Revoked']), LeavePolicyAssignment::SCOPE_EMPLOYEE, $employee->id);
    $revoked->update(['revoked_at' => now()]);

    expect($resolver->policyFor($employee, Carbon::parse('2026-12-07'))->id)->toBe($old->id)
        ->and($resolver->policyFor($employee, Carbon::parse('2027-02-01'))->id)->toBe($new->id);
});

test('with no assignment the employee record policy, then the default, still apply', function () {
    $employee = v2Employee();
    $employee->update(['leave_policy_id' => null]);
    $employee = $employee->fresh();
    $resolver = app(LeavePolicyAssignmentResolver::class);
    // The database may already hold a seeded default; this one is the only default here.
    LeavePolicy::query()->update(['is_default' => false]);
    $default = v2Policy(['name' => 'Default', 'is_default' => true]);

    expect($resolver->resolve($employee)['policy']->id)->toBe($default->id)
        ->and($resolver->resolve($employee)['source'])->toBe(LeavePolicyAssignmentResolver::SOURCE_DEFAULT);

    $resolver->flush();
    $employee->update(['leave_policy_id' => ($legacy = v2Policy(['name' => 'Legacy']))->id]);
    expect($resolver->resolve($employee->fresh())['policy']->id)->toBe($legacy->id);
});

// ── Day breakdown and approval ──────────────────────────────────────────────

test('weekly offs and holidays inside the range are shown and not charged', function () {
    [$employee, , , $reasons] = v2Standard(8);
    PublicHoliday::factory()->create([
        'name' => 'Founders Day', 'date' => '2026-12-10',
        'country' => app(HolidayResolver::class)->resolveCountry($employee), 'is_active' => true,
    ]);

    // Wed 9 – Mon 14 Dec: 6 calendar days, Sat + Sun off, Thu 10 a holiday.
    $result = app(LeaveRequestEvaluator::class)->evaluate($employee, $reasons['casual'], Carbon::parse('2026-12-09'), Carbon::parse('2026-12-14'));

    expect($result['calendar_days'])->toBe(6)
        ->and($result['weekly_off_days'])->toBe(2)
        ->and($result['holiday_days'])->toBe(1)
        ->and($result['charged_days'])->toBe(3.0)
        ->and($result['holidays'])->toBe(['Founders Day']);
});

test('a reason can route longer requests manager then HR, debiting Earned Leave only on the final approval', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'manager_id' => null]);
    [$employee, , $earned, $reasons] = v2Standard(8, sickRule: [
        'approval_workflow' => ['steps' => ['manager'], 'conditions' => [['min_days' => 3, 'steps' => ['manager', 'hr']]]],
    ]);
    $employee->update(['manager_id' => $manager->id]);
    $service = app(LeaveService::class);

    $short = $service->submitRequest($employee->fresh(), $reasons['sick'], '2026-12-08', '2026-12-08', 'Short');
    $long = $service->submitRequest($employee->fresh(), $reasons['sick'], '2026-12-14', '2026-12-16', 'Long');

    expect($short->approval_path)->toBe(['manager'])
        ->and($long->approval_path)->toBe(['manager', 'hr']);

    $afterManager = $service->reviewRequest($long, $long->only(['leave_type_id', 'start_date', 'end_date', 'reason']), 'approved', $manager->id);

    expect($afterManager->status)->toBe('pending_hr')
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->exists())->toBeFalse();

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $final = $service->reviewRequest($afterManager, $afterManager->only(['leave_type_id', 'start_date', 'end_date', 'reason']), 'approved', $hr->id);

    expect($final->status)->toBe('approved')
        ->and((float) LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->where('leave_type_id', $earned->id)->sum('days'))->toBe(-3.0);
});

// ── Employee view ───────────────────────────────────────────────────────────

test('My Balances shows one Earned Leave balance with usage by reason, not subtype balances', function () {
    [$employee, , $earned, $reasons] = v2Standard(10);
    $service = app(LeaveService::class);
    v2Approve($service->submitRequest($employee, $reasons['casual'], '2026-12-08', '2026-12-09', 'Trip'));
    $service->submitRequest($employee, $reasons['sick'], '2026-12-10', '2026-12-10', 'Fever');

    $view = app(LeaveCategoryBalances::class)->forEmployee($employee, LeaveYear::where('label', '2026/27')->first());
    $usage = collect($view['earned']['usage_by_reason'])->keyBy('name');

    expect($view['policy'])->toBe('NEXCORE Standard Leave Policy')
        ->and($view['annual_cap'])->toBe(12.0)
        ->and($view['earned']['id'])->toBe($earned->id)
        ->and($view['earned']['annual_entitlement'])->toBe(12.0)
        ->and($view['earned']['credits'])->toBe(10.0)
        ->and($view['earned']['used'])->toBe(2.0)
        ->and($view['earned']['pending'])->toBe(1.0)
        ->and($view['earned']['available'])->toBe(7.0)
        ->and($usage['Casual Leave']['used'])->toBe(2.0)
        ->and($usage['Sick Leave']['pending'])->toBe(1.0)
        // Reasons are labels for the request flow, never balances.
        ->and(collect($view['reasons'])->pluck('name')->all())->toEqualCanonicalizing(['Casual Leave', 'Sick Leave', 'Personal Leave'])
        ->and($view)->not->toHaveKey('subtypes');
});

test('old standalone Sick and historical Annual balances are history only, never shown as available', function () {
    [$employee, , , $reasons] = v2Standard(8);
    // A standalone Sick balance from before V2 and a historical Annual Leave row.
    v2Credit($employee, $reasons['sick'], 4);
    $annual = v2Type('Annual Leave', 'AL', ['leave_category' => null, 'category' => 'annual', 'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);
    v2Credit($employee, $annual, 14);
    // A paid type still active but not categorised: other paid leave.
    $csl = v2Type('Casual / Sick Leave', 'CSL', ['leave_category' => null]);
    v2Credit($employee, $csl, 5);

    $view = app(LeaveCategoryBalances::class)->forEmployee($employee, LeaveYear::where('label', '2026/27')->first());

    expect($view['earned']['available'])->toBe(8.0)
        ->and(collect($view['other_paid'])->pluck('id')->all())->toContain($csl->id)
        ->and(collect($view['other_paid'])->pluck('id')->intersect([$reasons['sick']->id, $annual->id])->all())->toBe([])
        ->and($view['historical_balances'])->toBeGreaterThanOrEqual(2);
});

test('a historical type is never offered, requested or given a new balance', function () {
    [$employee, $policy] = v2Standard(8);
    $oldCasual = v2Reason($policy, 'Old Casual', 'OC');
    $oldCasual->update(['availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);
    $wfh = v2Type('Work From Home', 'WFH', ['leave_category' => null, 'category' => 'wfh']);
    $year = LeaveYear::where('label', '2026/27')->first();

    $view = app(LeaveCategoryBalances::class)->forEmployee($employee, $year);

    expect(collect($view['reasons'])->pluck('id')->all())->not->toContain($oldCasual->id)
        ->and(collect($view['reasons'])->pluck('id')->all())->not->toContain($wfh->id)
        ->and($oldCasual->fresh()->isRequestable())->toBeFalse()
        ->and(app(EnsureEmployeeLeaveBalancesService::class)->ensureType($employee, $oldCasual->fresh(), $year)['status'])->toBe(EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT)
        ->and(fn () => app(LeaveService::class)->submitRequest($employee, $oldCasual->fresh(), '2026-12-08', '2026-12-08', 'Old'))->toThrow(DomainException::class, 'cannot be requested')
        ->and(fn () => app(LeaveService::class)->submitRequest($employee, $wfh, '2026-12-09', '2026-12-09', 'Home'))->toThrow(DomainException::class, 'cannot be requested');
});

test('a system-controlled type is never requestable by an employee', function () {
    $lwp = v2Type('Leave Without Pay', 'LWP', ['leave_category' => null, 'category' => 'lwp', 'is_paid' => false, 'is_system_controlled' => true]);

    expect(LeaveCategory::of($lwp))->toBe(LeaveCategory::UNPAID)
        ->and($lwp->isRequestable())->toBeFalse();
});

test('special leave with its own entitlement is its own group', function () {
    [$employee, $policy] = v2Standard(8);
    $bereavement = v2Type('Bereavement Leave', 'BRV', ['leave_category' => null, 'category' => 'bereavement']);
    v2Rule($policy, $bereavement, ['fixed_days' => 5]);
    $maternity = v2Type('Maternity Leave', 'ML', ['leave_category' => null, 'category' => 'maternity']);
    v2Credit($employee, $bereavement, 5);
    v2Credit($employee, $maternity, 180);

    $view = app(LeaveCategoryBalances::class)->forEmployee($employee, LeaveYear::where('label', '2026/27')->first());

    expect($view['earned']['available'])->toBe(8.0)
        ->and($view['special']['available'])->toBe(185.0)
        ->and(collect($view['special']['subtypes'])->pluck('id')->all())->toEqualCanonicalizing([$bereavement->id, $maternity->id])
        ->and(collect($view['other_paid'])->pluck('id')->intersect([$bereavement->id, $maternity->id])->all())->toBe([]);
});

// ── Historical compatibility ────────────────────────────────────────────────

test('historical requests keep charging their own type and keep their full paid days', function () {
    $employee = v2Employee();
    $type = v2Type('Casual / Sick Leave', 'CSL', ['leave_category' => null]);
    $legacy = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-02', 'days' => 2,
        'reason' => 'Before V2', 'requested_leave_status' => 'paid', 'status' => 'approved',
    ])->fresh();

    expect($legacy->lwp_days)->toBeNull()
        ->and($legacy->balance_leave_type_id)->toBeNull()
        ->and($legacy->balanceTypeId())->toBe($type->id)
        ->and($legacy->paidDays())->toBe(2.0)
        ->and(LeaveRequest::chargedTo($type->id)->pluck('id')->all())->toBe([$legacy->id])
        ->and(LeaveCategory::of($type))->toBeNull();
});

test('with the flag off the request flow is unchanged', function () {
    config(['leave_provisioning.policy_engine_v2' => false]);
    $employee = v2Employee();
    $unpaidRequestable = v2Type('Casual Leave', 'CL', ['allow_unpaid_request' => true]);
    PublicHoliday::factory()->create([
        'name' => 'Founders Day', 'date' => '2026-12-10',
        'country' => app(HolidayResolver::class)->resolveCountry($employee), 'is_active' => true,
    ]);
    $service = app(LeaveService::class);

    // A holiday inside the range still blocks, as before.
    expect(fn () => $service->submitRequest($employee, $unpaidRequestable, '2026-12-09', '2026-12-11', 'Trip', requestedLeaveStatus: 'unpaid'))
        ->toThrow(DomainException::class, 'company holiday');

    // The employee's own paid/unpaid choice is still honoured.
    $request = $service->submitRequest($employee, $unpaidRequestable, '2026-12-14', '2026-12-14', 'Errand', requestedLeaveStatus: 'unpaid');
    expect($request->requested_leave_status)->toBe('unpaid')
        ->and($request->lwp_days)->toBeNull()
        ->and($request->approval_path)->toBeNull()
        ->and($request->main_category)->toBeNull()
        ->and($request->balance_leave_type_id)->toBeNull();
});
