<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard\EmployeeLeaveBalances;
use App\Livewire\Dashboard\LeaveOverview;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Livewire\TimeOff\LeaveRequestDetail;
use App\Livewire\TimeOff\LeaveRequests;
use App\Livewire\TimeOff\MyTimeOff;
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
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveDynamicApproverNotification;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveDashboardService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * HR leave management: the request list and detail page, HR filing leave
 * for an employee (standard and Combined), the audited balance adjustment
 * and the links that lead HR there.
 *
 * Clock: Mon 2 Nov 2026 (leave year 2026/27). Saturday is the only weekly off.
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['weekly_off_days' => [6]]);
    AttendanceSetting::flushWeeklyOffCache();

    $this->department = Department::factory()->create(['name' => 'Production']);
    $this->user = User::factory()->create(['role' => UserRole::Employee, 'name' => 'Mayuresh Mhatre']);
    $this->employee = Employee::factory()->create([
        'user_id' => $this->user->id, 'status' => 'active', 'joining_date' => '2024-01-10', 'employee_id' => 'CNX-0008',
        'manager_id' => null, 'department_id' => $this->department->id,
    ]);

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);
    $this->hrEmployee = Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'joining_date' => '2024-01-10']);

    $this->policy = LeavePolicy::create([
        'name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12,
        'combined_leave_rules' => ['enabled' => true],
    ]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);

    $make = fn (string $name, string $code, array $extra = []) => LeaveType::create(array_merge([
        'name' => $name, 'code' => $code.random_int(10, 99), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true, 'color' => '#10b981',
    ], $extra));
    $rule = fn (LeaveType $type, array $extra = []) => LeavePolicyRule::create(array_merge([
        'leave_policy_id' => $this->policy->id, 'leave_type_id' => $type->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE, 'fixed_days' => null,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true,
    ], $extra));

    $this->earned = $make('Earned Leave', 'EL', ['counts_toward_annual_cap' => true]);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $rule($this->earned, ['entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12]);
    $this->casual = $make('Casual Leave', 'CL');
    $rule($this->casual);
    $this->compOff = $make('Comp Off', 'CO', ['leave_category' => LeaveCategory::COMP_OFF, 'category' => 'comp_off']);
    $rule($this->compOff);
    $this->bereavement = $make('Bereavement Leave', 'BRV', ['leave_category' => LeaveCategory::SPECIAL, 'category' => 'bereavement']);
    $rule($this->bereavement, ['entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 5]);
    $this->oldAnnual = $make('Annual Leave (old)', 'AOL', ['leave_category' => null, 'category' => 'annual', 'availability' => LeaveType::AVAILABILITY_HISTORICAL, 'is_active' => false]);

    $this->credit = function (Employee $employee, LeaveType $type, float $days) {
        $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $type->id, Carbon::parse('2026-07-01'));
        app(LeaveMovementService::class)->ledgerReady($balance);
        app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, $days, Carbon::parse('2026-07-01'), 'hrl-'.$employee->id.'-'.$type->id);
        app(LeaveLedgerService::class)->rebuild($balance->fresh());
    };
    ($this->credit)($this->employee, $this->earned, 12);
    ($this->credit)($this->hrEmployee, $this->earned, 3);
});

/** Bring an employee's Earned Leave to $target days through the audited adjustment flow. */
function hrlEarnedTo(Employee $employee, float $target): void
{
    app(LeaveMovementService::class)->ledgerReady(app(LeaveMovementService::class)->balanceFor($employee->id, test()->earned->id, Carbon::parse('2026-07-01')));
    $current = hrlAvailable($employee, test()->earned);
    if (abs($current - $target) > 0.001) {
        app(LeaveBalanceService::class)->adjust($employee, test()->earned, $current > $target ? 'debit' : 'credit', abs($current - $target), 'Test set-up', '', test()->hr);
    }
}

function hrlAvailable(Employee $employee, LeaveType $type): float
{
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->sole();

    return app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'];
}

function hrlRequest(array $attributes = []): LeaveRequest
{
    return LeaveRequest::create(array_merge([
        'employee_id' => test()->employee->id, 'leave_type_id' => test()->casual->id,
        'main_category' => LeaveCategory::EARNED, 'balance_leave_type_id' => test()->earned->id,
        'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'days' => 2,
        'reason' => 'Family function out of town', 'status' => 'pending',
        'requested_leave_status' => 'paid', 'approved_leave_status' => 'paid',
    ], $attributes));
}

/** Earned Leave usage posted for one request: the sum of its ledger rows. */
function hrlUsage(LeaveRequest $request): float
{
    return (float) LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)
        ->where('leave_type_id', test()->earned->id)->sum('days');
}

// ── Request list ─────────────────────────────────────────────────────────────

test('HR sees every request in scope, searchable by request ID, code and name, and each opens its detail page', function () {
    $request = hrlRequest();
    $other = hrlRequest(['start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'days' => 1, 'status' => 'approved', 'reason' => 'Bank work']);

    $list = Livewire::actingAs($this->hr)->test(LeaveRequests::class)
        ->assertOk()
        ->assertSee('LR-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT))
        ->assertSee(route('time-off.leave-requests.show', $request), false)
        ->assertSeeInOrder(['Request ID', 'Employee', 'Leave Type / Category', 'Reason', 'Dates', 'Paid', 'Unpaid', 'Days', 'Status', 'Approver', 'Applied On', 'Actions']);

    expect($list->instance()->tabCounts)->toMatchArray(['all' => 2, 'pending' => 1, 'approved' => 1]);

    foreach (['LR-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT), 'CNX-0008', 'Mayuresh', 'Family function'] as $term) {
        $ids = Livewire::actingAs($this->hr)->test(LeaveRequests::class)->set('search', $term)->instance()->requests->pluck('id');
        expect($ids)->toContain($request->id);
    }

    expect(Livewire::actingAs($this->hr)->test(LeaveRequests::class)->call('setTab', 'pending')->instance()->requests->pluck('id')->all())->toBe([$request->id])
        ->and(Livewire::actingAs($this->hr)->test(LeaveRequests::class)->set('departmentId', $this->department->id)->instance()->requests)->toHaveCount(2);
});

test('the list filters by request mode, unpaid days and date range', function () {
    $standard = hrlRequest();
    $unpaid = hrlRequest(['start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'days' => 1, 'lwp_days' => 1]);
    $combined = hrlRequest(['start_date' => '2026-11-24', 'end_date' => '2026-11-25', 'request_mode' => LeaveRequest::MODE_COMBINED]);

    $ids = fn (array $set) => Livewire::actingAs($this->hr)->test(LeaveRequests::class)->set($set)->instance()->requests->pluck('id')->sort()->values()->all();

    expect($ids(['mode' => 'combined']))->toBe([$combined->id])
        ->and($ids(['mode' => 'standard']))->toBe(collect([$standard->id, $unpaid->id])->sort()->values()->all())
        ->and($ids(['unpaidOnly' => true]))->toBe([$unpaid->id])
        ->and($ids(['from' => '2026-11-19', 'to' => '2026-11-21']))->toBe([$unpaid->id]);
});

test('an employee without leave management or approval rights cannot open the list or a request', function () {
    $request = hrlRequest();
    $outsider = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $outsider->id, 'status' => 'active']);

    $this->actingAs($outsider)->get(route('time-off.leave-requests'))->assertForbidden();
    $this->actingAs($outsider)->get(route('time-off.leave-requests.show', $request))->assertForbidden();
});

test('a manager sees only their reporting line', function () {
    $managerRole = Role::where('slug', 'manager')->firstOrFail();
    $manager = User::factory()->create(['role' => $managerRole->legacyBucket(), 'role_id' => $managerRole->id]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $this->employee->update(['manager_id' => $manager->id]);
    $mine = hrlRequest();

    // Pinned: the factory may otherwise pick this manager at random.
    $stranger = Employee::factory()->create(['status' => 'active', 'manager_id' => null, 'department_id' => Department::factory()->create()->id]);
    $notMine = hrlRequest(['employee_id' => $stranger->id]);

    $ids = Livewire::actingAs($manager)->test(LeaveRequests::class)->instance()->requests->pluck('id');
    expect($ids)->toContain($mine->id)->not->toContain($notMine->id);

    $this->actingAs($manager)->get(route('time-off.leave-requests.show', $mine))->assertOk();
    $this->actingAs($manager)->get(route('time-off.leave-requests.show', $notMine))->assertForbidden();
});

// ── Request detail ───────────────────────────────────────────────────────────

test('the detail page shows the request, its balance impact and the actions HR may take', function () {
    $request = hrlRequest();

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->assertOk()
        ->assertSee('Mayuresh Mhatre')
        ->assertSee('CNX-0008')
        ->assertSee('Production')
        ->assertSee('Earned Leave')
        ->assertSee('Casual Leave')
        ->assertSee('Family function out of town')
        ->assertSeeHtml('data-section="summary"')
        ->assertSeeHtml('data-section="dates"')
        ->assertSeeHtml('data-section="balance"')
        ->assertSeeHtml('data-section="payroll"')
        ->assertSeeHtml('data-section="attendance"')
        ->assertSeeHtml('data-section="approval"')
        ->assertSeeHtml('data-section="audit"')
        ->assertSeeHtml('data-date-row="2026-11-10"')
        ->assertSee('Reserved while pending; deducted only if approved.')
        ->assertSee('Approve')
        ->assertSee('Reject')
        ->assertSee('Cancel Leave')
        ->assertSee('Leave Balance');

    $impact = Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])->instance()->balanceImpact;
    expect($impact)->toMatchArray(['before' => 12.0, 'paid' => 2.0, 'after' => 10.0]);
});

test('HR approves from the detail page: the ledger is charged exactly once and the decision is audited', function () {
    $request = hrlRequest();

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->call('confirm', 'approve')
        ->call('approve')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('approved')
        ->and(hrlUsage($request))->toEqual(-2.0)
        ->and(hrlAvailable($this->employee, $this->earned))->toEqual(10.0)
        ->and(AuditLog::where('auditable_type', LeaveRequest::class)->where('auditable_id', $request->id)->where('event', 'LEAVE_APPROVED')->exists())->toBeTrue();

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->assertSee('Deducted on approval.')
        ->assertDontSee('Confirm Approval');
});

test('rejecting needs a reason and charges nothing; cancelling approved leave returns the days', function () {
    $rejected = hrlRequest();
    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $rejected])
        ->call('confirm', 'reject')->call('reject')->assertHasErrors('comment')
        ->set('comment', 'Team coverage is too thin that week')->call('reject')->assertHasNoErrors();
    expect($rejected->fresh()->status)->toBe('rejected')->and(hrlUsage($rejected))->toEqual(0.0);

    $approved = hrlRequest(['start_date' => '2026-11-17', 'end_date' => '2026-11-18']);
    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $approved])->call('approve');
    expect(hrlAvailable($this->employee, $this->earned))->toEqual(10.0);

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $approved])
        ->call('confirm', 'cancel')->set('cancelReason', 'Trip called off by the employee')->call('cancel')->assertHasNoErrors();

    expect($approved->fresh()->status)->toBe('cancelled')
        ->and(hrlAvailable($this->employee, $this->earned))->toEqual(12.0);
});

test('HR can never approve their own request', function () {
    $own = hrlRequest(['employee_id' => $this->hrEmployee->id, 'days' => 1, 'end_date' => '2026-11-10']);

    $page = Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $own]);
    expect($page->instance()->canDecide)->toBeFalse()
        ->and($page->instance()->canCancel)->toBeFalse();
    $page->assertDontSee('Confirm Approval')->call('approve')->assertForbidden();

    expect($own->fresh()->status)->toBe('pending');
});

// ── HR files leave for an employee ───────────────────────────────────────────

test('HR mode uses the chosen employee’s policy and balance, never the HR user’s own', function () {
    $drawer = Livewire::actingAs($this->hr)->test(RequestLeave::class, ['hrMode' => true])
        ->dispatch('open-request-leave-for')
        ->assertSet('open', true)
        ->assertSeeHtml('data-employee-picker')
        ->set('employeeSearch', 'CNX-0008')
        ->assertSee('Mayuresh Mhatre')
        ->assertDontSee('Hema HR')
        ->call('chooseEmployee', $this->employee->id)
        ->assertSeeHtml('data-requesting-for')
        ->assertSee('NEXCORE Standard Leave Policy');

    // 12 is the employee's Earned Leave; the HR user holds 3.
    expect($drawer->instance()->choices[LeaveCategory::EARNED]['available'])->toEqual(12.0);

    // An employee drawer never answers the HR event, and the HR drawer never answers the employee one.
    Livewire::actingAs($this->user)->test(RequestLeave::class)->dispatch('open-request-leave-for')->assertSet('open', false);
    Livewire::actingAs($this->hr)->test(RequestLeave::class, ['hrMode' => true])->dispatch('open-request-leave')->assertSet('open', false);
});

test('HR mode refuses the HR user’s own record and anyone outside the permission', function () {
    Livewire::actingAs($this->hr)->test(RequestLeave::class, ['hrMode' => true])
        ->dispatch('open-request-leave-for')
        ->call('chooseEmployee', $this->hrEmployee->id)
        ->assertForbidden();

    Livewire::actingAs($this->user)->test(RequestLeave::class, ['hrMode' => true])
        ->dispatch('open-request-leave-for')
        ->assertForbidden();
});

test('HR files a standard request on the employee’s behalf: audited, and approval charges it once', function () {
    Livewire::actingAs($this->hr)->test(RequestLeave::class, ['hrMode' => true])
        ->dispatch('open-request-leave-for', employeeId: $this->employee->id)
        ->call('chooseCategory', LeaveCategory::EARNED)
        ->call('next')
        ->set('leaveTypeId', $this->casual->id)
        ->set('startDate', '2026-11-10')
        ->set('endDate', '2026-11-11')
        ->set('reason', 'Called in by HR for a family event')
        ->call('next')
        ->assertSet('step', 3)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('step', 4);

    $request = LeaveRequest::where('employee_id', $this->employee->id)->sole();
    expect((int) $request->applied_by_user_id)->toBe($this->hr->id)
        ->and((float) $request->days)->toEqual(2.0)
        ->and(AuditLog::where('event', 'LEAVE_APPLIED_ON_BEHALF')->where('auditable_id', $request->id)->exists())->toBeTrue()
        // The HR user's own balance is untouched.
        ->and(hrlAvailable($this->hrEmployee, $this->earned))->toEqual(3.0);

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])->call('approve');
    expect(hrlUsage($request))->toEqual(-2.0);
});

test('HR files Combined Leave: 2.5 paid days charge Earned Leave once, 2 unpaid days go to LWP', function () {
    $allocations = [
        '2026-11-10' => ['type' => LeaveRequestDay::FULL_PAID, 'session' => null],
        '2026-11-11' => ['type' => LeaveRequestDay::FULL_PAID, 'session' => null],
        '2026-11-12' => ['type' => LeaveRequestDay::HALF_PAID, 'session' => 'first_half'],
        '2026-11-13' => ['type' => LeaveRequestDay::FULL_UNPAID, 'session' => null],
        '2026-11-15' => ['type' => LeaveRequestDay::FULL_UNPAID, 'session' => null],
    ];

    Livewire::actingAs($this->hr)->test(RequestLeave::class, ['hrMode' => true])
        ->dispatch('open-request-leave-for', employeeId: $this->employee->id)
        ->call('chooseCategory', RequestLeave::COMBINED)
        ->call('next')
        ->set('leaveTypeId', $this->casual->id)
        ->set('startDate', '2026-11-10')
        ->set('endDate', '2026-11-15')
        ->set('allocations', $allocations)
        ->set('reason', 'Mixed paid and unpaid days')
        ->call('next')
        ->assertSet('step', 3)
        ->call('submit')
        ->assertHasNoErrors();

    $request = LeaveRequest::where('employee_id', $this->employee->id)->sole();
    expect($request->isCombined())->toBeTrue()
        ->and($request->paidDays())->toEqual(2.5)
        ->and($request->unpaidUnits())->toEqual(2.0)
        ->and((int) $request->applied_by_user_id)->toBe($this->hr->id);

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->assertSeeHtml('data-date-row="2026-11-14"')
        ->assertSee('Half Day – Paid')
        ->call('approve')
        ->assertHasNoErrors();

    expect(hrlUsage($request))->toEqual(-2.5)
        ->and(LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(hrlAvailable($this->employee, $this->earned))->toEqual(9.5)
        ->and((float) $request->fresh()->lwp_days)->toEqual(2.0);
});

// ── Balance adjustment ───────────────────────────────────────────────────────

test('adjusting Earned Leave posts an audited ledger debit with the old and new balance', function () {
    $this->actingAs($this->hr);
    app(LeaveBalanceService::class)->adjust($this->employee, $this->earned, 'debit', 2, 'Correction approved by HR', 'Ticket 41', $this->hr);

    $entry = LeaveLedgerEntry::where('employee_id', $this->employee->id)->where('entry_type', LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT)->sole();
    $audit = AuditLog::where('event', 'LEAVE_DEDUCTED')->where('subject_employee_id', $this->employee->id)->sole();

    expect(hrlAvailable($this->employee, $this->earned))->toEqual(10.0)
        ->and((float) $entry->days)->toEqual(-2.0)
        ->and((int) $entry->created_by)->toBe($this->hr->id)
        ->and($entry->reason)->toBe('Correction approved by HR')
        ->and((float) $audit->old_values['available'])->toEqual(12.0)
        ->and((float) $audit->new_values['available'])->toEqual(10.0)
        ->and((int) $audit->user_id)->toBe($this->hr->id);
});

test('Comp Off and Special Leave adjust through the same audited flow', function () {
    ($this->credit)($this->employee, $this->bereavement, 10);

    app(LeaveBalanceService::class)->adjust($this->employee, $this->compOff, 'credit', 1, 'Worked the shutdown day', '', $this->hr);
    app(LeaveBalanceService::class)->adjust($this->employee, $this->bereavement, 'debit', 5, 'Align the earlier grant to the 5-day policy', '', $this->hr);

    expect(hrlAvailable($this->employee, $this->compOff))->toEqual(1.0)
        ->and(hrlAvailable($this->employee, $this->bereavement))->toEqual(5.0);
});

test('a reason or a historical type can never be adjusted as if it held a balance', function () {
    expect(fn () => app(LeaveBalanceService::class)->adjust($this->employee, $this->casual, 'credit', 2, 'Extra casual days', '', $this->hr))
        ->toThrow(DomainException::class, 'is a reason under Earned Leave');
    expect(fn () => app(LeaveBalanceService::class)->adjust($this->employee, $this->oldAnnual, 'credit', 2, 'Top up old annual', '', $this->hr))
        ->toThrow(DomainException::class, 'is historical');

    expect(LeaveBalance::where('employee_id', $this->employee->id)->whereIn('leave_type_id', [$this->casual->id, $this->oldAnnual->id])->exists())->toBeFalse();

    $types = Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $this->employee])->viewData('balanceTypes')->pluck('id');
    expect($types)->toContain($this->earned->id)->toContain($this->compOff->id)->toContain($this->bereavement->id)
        ->not->toContain($this->casual->id)->not->toContain($this->oldAnnual->id);
});

// ── Links into HR management ─────────────────────────────────────────────────

test('HR viewing as the employee manages a recent request on the HR page, not the employee history tab', function () {
    $request = hrlRequest();

    session([AuditLog::IMPERSONATOR_SESSION_KEY => $this->hr->id]);
    $page = Livewire::actingAs($this->user)->test(MyTimeOff::class);
    $page->assertSeeHtml('data-manage-as-hr')
        ->assertSee(route('impersonate.stop', ['to' => route('time-off.leave-requests.show', $request, false)]), false);

    // Without an HR view the employee keeps their own View.
    session()->forget(AuditLog::IMPERSONATOR_SESSION_KEY);
    Livewire::actingAs($this->user)->test(MyTimeOff::class)->assertDontSeeHtml('data-manage-as-hr');
});

test('ending the view lands on the HR request page, and never on another site', function () {
    $request = hrlRequest();
    $path = route('time-off.leave-requests.show', $request, false);

    $this->actingAs($this->user)->withSession([AuditLog::IMPERSONATOR_SESSION_KEY => $this->hr->id])
        ->get(route('impersonate.stop', ['to' => $path]))->assertRedirect($path);
    $this->assertAuthenticatedAs($this->hr);

    foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example'] as $to) {
        $this->actingAs($this->user)->withSession([AuditLog::IMPERSONATOR_SESSION_KEY => $this->hr->id])
            ->get(route('impersonate.stop', ['to' => $to]))->assertRedirect(route('dashboard'));
    }
});

test('the approver notification opens the request itself', function () {
    $request = hrlRequest();

    $data = (new LeaveDynamicApproverNotification($request->load('employee.user', 'leaveType'), 1))->toArray($this->hr);

    expect($data['url'])->toBe(route('time-off.leave-requests.show', $request));
});

// ── HR dashboard ─────────────────────────────────────────────────────────────

test('the HR dashboard leave cards count requests and Earned Leave in reach, each leading somewhere useful', function () {
    hrlRequest(); // pending
    hrlRequest(['start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'days' => 1, 'status' => 'approved']); // on leave today
    hrlRequest(['start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'days' => 1, 'status' => 'approved', 'lwp_days' => 1]); // upcoming + unpaid this month

    $low = Employee::factory()->create(['status' => 'active', 'department_id' => $this->department->id]);
    hrlEarnedTo($low, 1);
    $negative = Employee::factory()->create(['status' => 'active', 'department_id' => $this->department->id]);
    hrlEarnedTo($negative, 0);
    $balance = LeaveBalance::where('employee_id', $negative->id)->where('leave_type_id', $this->earned->id)->sole();
    app(LeaveLedgerService::class)->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 2, Carbon::parse('2026-10-01'), 'hrl-overdraw', ['reason' => 'Overdrawn before the cap']);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());

    $cards = app(LeaveDashboardService::class)->cards($this->hr);

    expect($cards)->toMatchArray([
        'pending' => 1, 'on_leave_today' => 1, 'upcoming' => 1, 'unpaid_requests' => 1, 'unpaid_days' => 1.0,
        'low_earned' => 1, 'exceptions' => 1,
    ]);

    Livewire::withoutLazyLoading()->actingAs($this->hr)->test(LeaveOverview::class)
        ->assertSeeHtml('data-leave-overview')
        ->assertSee('Pending Leave Requests')
        ->assertSee('Mayuresh Mhatre')
        ->assertSee(route('time-off.leave-requests', ['status' => 'pending']), false)
        ->assertSee('View All Leave Requests');
});

test('Employee Leave Balances searches by code and filters low Earned Leave, opening the full balance detail', function () {
    $low = Employee::factory()->create(['status' => 'active', 'department_id' => $this->department->id, 'employee_id' => 'CNX-0099']);
    hrlEarnedTo($low, 1);

    $list = Livewire::withoutLazyLoading()->actingAs($this->hr)->test(EmployeeLeaveBalances::class)
        ->set('search', 'CNX-0008')
        ->assertSeeHtml('data-balance-row="CNX-0008"')
        ->assertDontSeeHtml('data-balance-row="CNX-0099"')
        ->assertSee('NEXCORE Standard Leave Policy')
        ->assertSee(route('time-off.leave-management.employee', ['employee' => $this->employee]), false);

    $list->set('search', '')->dispatch('leave-balances-filter', filter: 'low')
        ->assertSeeHtml('data-balance-row="CNX-0099"')
        ->assertDontSeeHtml('data-balance-row="CNX-0008"');

    // No leave management permission: nothing shown.
    Livewire::withoutLazyLoading()->actingAs($this->user)->test(EmployeeLeaveBalances::class)
        ->assertDontSeeHtml('data-employee-balances');
});

test('the employee leave page names the policy in force and offers adjustments only on real balances', function () {
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => LeavePolicyAssignment::SCOPE_EMPLOYEE, 'scope_id' => $this->employee->id, 'effective_from' => '2026-07-01']);
    ($this->credit)($this->employee, $this->casual, 1);

    $page = Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $this->employee])
        ->assertSee('NEXCORE Standard Leave Policy')
        ->assertSee('Employee Override')
        ->assertSeeHtml("openAction('add', {$this->earned->id})")
        ->assertDontSeeHtml("openAction('add', {$this->casual->id})");

    $page->call('showTypeHistory', $this->earned->id)
        ->assertSet('tab', 'history')
        ->assertSet('historyTypeId', $this->earned->id);
});
