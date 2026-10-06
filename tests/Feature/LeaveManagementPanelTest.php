<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Livewire\TimeOff\LeaveManagement;
use App\Livewire\TimeOff\LeaveReconciliation;
use App\Livewire\TimeOff\LeaveYearRollover;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveAppliedOnBehalfNotification;
use App\Notifications\LeaveBalanceChangedNotification;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveStatementService;
use App\Services\LeaveService;
use App\Services\Security\RoleDelegationGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Phase 2D — HR Leave Management: the panel, employee detail, HR actions,
 * apply on behalf, bulk operations, rollover/reconciliation screens, and
 * that every one of them authorises on the server.
 *
 * Clock: Wednesday 14 October 2026, inside leave year 2026/27.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30'], ['label' => '2025/26']);
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    $this->seed(RolesAndPermissionsSeeder::class);
});

function lmpHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => Role::where('slug', 'hr_admin')->firstOrFail()->id]);
}

function lmpUser(string $slug, UserRole $role): User
{
    return User::factory()->create(['role' => $role, 'role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
}

/** An employee on a policy granting 12 days of a fixed-days type. */
function lmpSetup(): array
{
    $policy = LeavePolicy::create([
        'name' => 'Panel '.random_int(100, 999), 'statutory_weeks' => 5.6, 'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional', 'irregular_accrual_rate' => 0.1207, 'is_default' => false, 'is_active' => true,
    ]);
    $type = LeaveType::create([
        'name' => 'Casual Leave', 'code' => 'C'.random_int(10000, 99999), 'category' => 'other',
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true,
    ]);
    LeavePolicyRule::create(['leave_policy_id' => $policy->id, 'leave_type_id' => $type->id, 'entitlement_method' => 'fixed_days', 'fixed_days' => 12]);

    $employee = Employee::factory()->create([
        'user_id' => lmpUser('employee', UserRole::Employee)->id,
        'status' => 'active', 'leave_policy_id' => $policy->id, 'joining_date' => '2024-02-01',
    ]);

    return [$employee, $type, $policy];
}

/** Another employee on the same policy (so the same leave type). */
function lmpColleague(LeavePolicy $policy): Employee
{
    return Employee::factory()->create([
        'user_id' => lmpUser('employee', UserRole::Employee)->id,
        'status' => 'active', 'leave_policy_id' => $policy->id, 'joining_date' => '2024-02-01',
    ]);
}

function lmpBalance(Employee $employee, LeaveType $type): LeaveBalance
{
    return LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
}

// ── Access ───────────────────────────────────────────────────────────────────

test('HR can open Leave Management; employees and managers cannot', function () {
    [$employee, $type] = lmpSetup();

    Livewire::actingAs(lmpHr())->test(LeaveManagement::class)
        ->set('leaveTypeId', $type->id)
        ->assertOk()
        ->assertSee($employee->user->name);

    $this->actingAs($employee->user)->get(route('time-off.leave-management'))->assertForbidden();
    $this->actingAs(lmpUser('manager', UserRole::Manager))->get(route('time-off.leave-management'))->assertForbidden();
    $this->actingAs($employee->user)->get(route('time-off.leave-management.employee', $employee))->assertForbidden();
});

test('cards count and filter: a negative balance shows up under its card', function () {
    [$employee, $type, $policy] = lmpSetup();
    $balance = lmpBalance($employee, $type);
    app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 14, Carbon::parse('2026-09-01'), 'test:neg:'.$balance->id);
    app(LeaveLedgerService::class)->rebuild($balance);
    $other = lmpColleague($policy);

    $component = Livewire::actingAs(lmpHr())->test(LeaveManagement::class)->set('leaveTypeId', $type->id);

    expect($component->get('cards')['negative_balances'])->toBeGreaterThanOrEqual(1);

    // Asserted on the rows, not the page text: a name can also appear in
    // the Manager filter.
    $ids = $component->call('setFlag', 'negative')->get('rows')->pluck('employee_id');

    expect($ids)->toContain($employee->id)->not->toContain($other->id);
});

// ── HR actions ───────────────────────────────────────────────────────────────

test('Add Leave posts an add-on lot, needs a reason, is audited and notifies without the internal note', function () {
    [$employee, $type] = lmpSetup();
    $hr = lmpHr();

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'add', $type->id)
        ->set('days', '2')->set('addOnType', 'special_leave')->set('reason', '')
        ->call('submitAction')
        ->assertHasErrors('reason')
        ->set('reason', 'Wedding leave approved by director')->set('internalNote', 'Exception agreed — do not repeat')
        ->set('expiresOn', '2026-12-31')
        ->call('submitAction')
        ->assertHasNoErrors();

    $balance = lmpBalance($employee, $type);

    expect((float) $balance->add_on_days)->toBe(2.0)
        ->and((float) $balance->base_days)->toBe(12.0)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', 'add_on')->first()->expires_on->toDateString())->toBe('2026-12-31')
        ->and(LeaveBalanceAdjustment::where('employee_id', $employee->id)->first()->internal_note)->toBe('Exception agreed — do not repeat')
        ->and(AuditLog::where('event', 'LEAVE_ADD_ON_GRANTED')->where('subject_employee_id', $employee->id)->where('user_id', $hr->id)->exists())->toBeTrue();

    Notification::assertSentTo($employee->user, LeaveBalanceChangedNotification::class,
        fn ($n) => ! str_contains(json_encode($n->toArray($employee->user)), 'do not repeat'));
});

test('Deduct Leave and Correct Balance post adjustments; correcting never overwrites', function () {
    [$employee, $type] = lmpSetup();
    $hr = lmpHr();

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        // High-impact: refused until HR ticks the confirmation.
        ->call('openAction', 'deduct', $type->id)->set('days', '1')->set('reason', 'Unrecorded absence on 2 Sep')
        ->call('submitAction')->assertHasErrors('confirmed')
        ->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors()
        ->call('openAction', 'correct', $type->id)->set('targetBalance', '8')->set('reason', 'Agreed position after audit')
        ->set('confirmed', true)
        ->call('submitAction')->assertHasNoErrors();

    $balance = lmpBalance($employee, $type);
    $summary = app(LeaveBalanceCalculator::class)->summary($balance);

    expect($summary['approved_available'])->toBe(8.0)
        ->and((float) $balance->base_days)->toBe(12.0)
        ->and((float) $balance->adjustment_debit_days)->toBe(4.0)
        ->and(LeaveBalanceAdjustment::where('employee_id', $employee->id)->where('category', 'correction')->value('days'))->toEqual('3.00');
});

test('HR without the permission cannot add leave, even by calling the action directly', function () {
    [$employee, $type] = lmpSetup();
    $hr = lmpHr();
    $hr->assignedRole->permissions()->detach(Permission::where('key', 'add_leave_balance')->value('id'));
    cache()->flush();

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'add', $type->id)
        ->assertForbidden();

    expect(LeaveBalanceAdjustment::count())->toBe(0);
});

test('HR cannot change their own balance from the panel', function () {
    [, $type] = lmpSetup();
    $hr = lmpHr();
    $self = Employee::factory()->create(['user_id' => $hr->id, 'status' => 'active']);

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $self])
        ->call('openAction', 'add', $type->id)->set('days', '5')->set('reason', 'Myself')
        ->call('submitAction')
        ->assertHasErrors('reason');

    expect(LeaveBalanceAdjustment::count())->toBe(0);
});

test('an employee override through the panel recalculates the base', function () {
    [$employee, $type] = lmpSetup();

    Livewire::actingAs(lmpHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'override', $type->id)
        ->set('overrideMode', 'add')->set('days', '5')->set('reason', 'Long-service award')
        ->call('submitAction')->assertHasNoErrors();

    expect((float) lmpBalance($employee, $type)->base_days)->toBe(17.0);
});

// ── Apply on behalf ──────────────────────────────────────────────────────────

test('HR applies leave on behalf: normal checks apply, actor and subject are both recorded', function () {
    [$employee, $type] = lmpSetup();
    $hr = lmpHr();

    Livewire::actingAs($hr)->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'apply', $type->id)
        ->set('startDate', '2026-10-19')->set('endDate', '2026-10-20')
        ->set('reason', 'Called in sick, HR logging it')->set('internalNote', 'Phoned at 9am')
        ->call('submitAction')->assertHasNoErrors();

    $request = LeaveRequest::where('employee_id', $employee->id)->firstOrFail();
    $audit = AuditLog::where('event', 'LEAVE_APPLIED_ON_BEHALF')->firstOrFail();

    expect($request->status)->toBe('pending')
        ->and($request->applied_by_user_id)->toBe($hr->id)
        ->and($request->hr_internal_note)->toBe('Phoned at 9am')
        ->and($audit->user_id)->toBe($hr->id)
        ->and($audit->subject_employee_id)->toBe($employee->id);

    Notification::assertSentTo($employee->user, LeaveAppliedOnBehalfNotification::class,
        fn ($n) => ! str_contains(json_encode($n->toArray($employee->user)), 'Phoned'));
});

test('applying on behalf needs the permission, server-side', function () {
    [$employee, $type] = lmpSetup();

    expect(fn () => app(LeaveService::class)->applyOnBehalf(lmpUser('manager', UserRole::Manager), $employee, $type, '2026-10-19', '2026-10-19', 'x'))
        ->toThrow(AuthorizationException::class);
});

// ── Bulk ─────────────────────────────────────────────────────────────────────

test('bulk provision previews without writing, and a confirmed preview runs once', function () {
    [, $type, $policy] = lmpSetup();
    $missing = Employee::withoutEvents(fn () => Employee::factory()->create([
        'user_id' => lmpUser('employee', UserRole::Employee)->id, 'status' => 'active', 'leave_policy_id' => $policy->id, 'joining_date' => '2024-02-01',
    ]));
    $before = LeaveLedgerEntry::count();

    $component = Livewire::actingAs(lmpHr())->test(LeaveManagement::class)
        ->set('leaveTypeId', $type->id)
        ->set('selected', [$missing->id])
        ->call('previewBulkProvision');

    expect(LeaveLedgerEntry::count())->toBe($before)
        ->and($component->get('bulkPreview')['summary']['valid'])->toBeGreaterThanOrEqual(1);

    $token = $component->get('bulkToken');
    $component->call('confirmBulkProvision');
    // A replayed click with the same token does nothing.
    $component->set('bulkToken', $token)->set('bulkMode', 'provision')->call('confirmBulkProvision');

    expect((float) lmpBalance($missing, $type)->base_days)->toBe(12.0)
        ->and(LeaveLedgerEntry::where('employee_id', $missing->id)->where('leave_type_id', $type->id)->where('entry_type', 'base_entitlement')->count())->toBe(1);
});

test('bulk add-on grants each selected employee one add-on after a preview', function () {
    [$a, $type, $policy] = lmpSetup();
    $b = lmpColleague($policy);

    Livewire::actingAs(lmpHr())->test(LeaveManagement::class)
        ->set('leaveTypeId', $type->id)
        ->set('selected', [$a->id, $b->id])
        ->set('bulkDays', '1')->set('bulkReason', 'Company anniversary day')
        ->call('previewBulkAddOn')
        ->assertSet('bulkMode', 'add_on')
        ->call('confirmBulkAddOn');

    expect((float) lmpBalance($a, $type)->add_on_days)->toBe(1.0)
        ->and((float) lmpBalance($b, $type)->add_on_days)->toBe(1.0)
        ->and(LeaveLedgerEntry::where('entry_type', 'add_on')->where('leave_type_id', $type->id)->count())->toBe(2);
});

// ── Rollover / reconciliation screens and the delegation ceiling ─────────────

test('rollover and reconciliation are HR-only, and only a Super Admin may delegate them', function () {
    lmpSetup();

    Livewire::actingAs(lmpHr())->test(LeaveYearRollover::class)->assertOk();
    Livewire::actingAs(lmpHr())->test(LeaveReconciliation::class)->call('scan')->assertOk();

    $this->actingAs(lmpUser('manager', UserRole::Manager))->get(route('time-off.year-rollover'))->assertForbidden();
    $this->actingAs(lmpUser('manager', UserRole::Manager))->get(route('time-off.reconciliation'))->assertForbidden();

    expect(RoleDelegationGuard::PRIVILEGED)->toContain('run_leave_rollover')->toContain('reconcile_leave');
});

// ── Statements ───────────────────────────────────────────────────────────────

test('the month-wise statement and timeline come from the ledger', function () {
    [$employee, $type] = lmpSetup();
    $balance = lmpBalance($employee, $type);
    $ledger = app(LeaveLedgerService::class);
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 3, Carbon::parse('2026-07-01'), 'test:cf:'.$balance->id);
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, 1.5, Carbon::parse('2026-08-01'), 'test:acc:'.$balance->id);
    $ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 2, Carbon::parse('2026-08-05'), 'test:use:'.$balance->id);
    $ledger->rebuild($balance);

    $months = app(LeaveStatementService::class)->monthly($employee, $type, LeaveYear::where('label', '2026/27')->first());
    $july = $months->firstWhere('month', '2026-07');
    $august = $months->firstWhere('month', '2026-08');

    // The year opens at zero; the 1 July postings are July's credits.
    expect($july['opening'])->toBe(0.0)
        ->and($july['carry_forward'])->toBe(3.0)
        ->and($july['closing'])->toBe(15.0)
        ->and($august['opening'])->toBe(15.0)
        ->and($august['credits'])->toBe(1.5)
        ->and($august['used'])->toBe(2.0)
        ->and($august['closing'])->toBe(14.5);

    $timeline = app(LeaveStatementService::class)->timeline($employee, LeaveYear::where('label', '2026/27')->first(), ['leave_type_id' => $type->id]);

    expect($timeline->pluck('label')->all())->toBe(['Base Entitlement', 'Carry Forward', 'Monthly Accrual', 'Leave Taken'])
        ->and($timeline->last()['running'])->toBe(14.5);
});

// ── Employees with no balance in the year (production crash, lines 203/240) ──

/** An eligible employee with no leave balance at all — provisioning never ran for them. */
function lmpUnprovisioned(): Employee
{
    return Employee::withoutEvents(fn () => Employee::factory()->create([
        'user_id' => lmpUser('employee', UserRole::Employee)->id,
        'status' => 'active', 'joining_date' => '2024-02-01',
    ]));
}

test('A: an employee with zero leave balances can open the leave detail page and every tab', function () {
    lmpSetup();
    $employee = lmpUnprovisioned();

    $component = Livewire::actingAs(lmpHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->assertOk()
        ->assertSee('No leave balances in 2026/27');

    foreach (['history', 'statement', 'carry_forward', 'requests', 'overrides', 'balances'] as $tab) {
        $component->set('tab', $tab)->assertOk();
    }
});

test('B: the statement is an empty collection when there is no type and no balance', function () {
    lmpSetup();
    $employee = lmpUnprovisioned();

    $statement = Livewire::actingAs(lmpHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->set('tab', 'statement')
        ->get('statement');

    expect($statement)->toBeInstanceOf(Collection::class)->toBeEmpty();
});

test('C: opening an action with no balances fails gracefully with a visible message', function () {
    lmpSetup();
    $employee = lmpUnprovisioned();

    Livewire::actingAs(lmpHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->call('openAction', 'add')
        ->assertOk()
        ->assertSet('action', null)
        ->assertHasErrors('formTypeId')
        ->assertSee('No leave balance exists for this employee for the selected leave year.');
});

test('D: an employee with a balance still gets the correct statement', function () {
    [$employee, $type] = lmpSetup();

    // With no type chosen it defaults to the first balance; choose this one.
    $statement = Livewire::actingAs(lmpHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->set('tab', 'statement')
        ->set('statementTypeId', $type->id)
        ->get('statement');

    expect($statement)->not->toBeEmpty()
        // The year opens at zero; the 1 July entitlement is July's credit.
        ->and($statement->firstWhere('month', '2026-07')['opening'])->toBe(0.0)
        ->and($statement->firstWhere('month', '2026-07')['current_credits'])->toBe(12.0)
        ->and($statement->firstWhere('month', '2026-07')['closing'])->toBe(12.0);
});

test('E: viewing the page never provisions a balance', function () {
    lmpSetup();
    $employee = lmpUnprovisioned();

    Livewire::actingAs(lmpHr())->test(EmployeeLeaveDetail::class, ['employee' => $employee])
        ->set('tab', 'statement')
        ->set('tab', 'history')
        ->call('openAction', 'add');

    expect(LeaveBalance::where('employee_id', $employee->id)->count())->toBe(0)
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->count())->toBe(0);
});
