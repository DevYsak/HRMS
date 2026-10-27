<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\EmployeeLeaveDetail;
use App\Livewire\TimeOff\LeaveRequestDetail;
use App\Livewire\TimeOff\MyTimeOff;
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
use App\Models\LeaveRequestRevision;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveRevisionNotification;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCancellationService;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveCategoryBalances;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveRequestPresenter;
use App\Services\Leave\LeaveRequestRevisionService as Revisions;
use App\Services\Leave\LeaveRequestTimeline;
use App\Services\LeaveService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * HR proposes changes to an open request; the employee confirms them before
 * anything is approved, and only the final approval deducts — exactly once.
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

    $this->user = User::factory()->create(['role' => UserRole::Employee, 'name' => 'Mayuresh Mhatre']);
    $this->employee = Employee::factory()->create([
        'user_id' => $this->user->id, 'status' => 'active', 'joining_date' => '2024-01-10', 'employee_id' => 'RV-0001',
        'manager_id' => null, 'department_id' => Department::factory()->create()->id,
    ]);
    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);
    $this->hrEmployee = Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'joining_date' => '2024-01-10']);

    $this->policy = LeavePolicy::create(['name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12, 'combined_leave_rules' => ['enabled' => true]]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $make = fn (string $name, string $code) => LeaveType::create(['name' => $name, 'code' => $code.random_int(10, 99), 'category' => 'other',
        'leave_category' => LeaveCategory::EARNED, 'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true, 'color' => '#10b981']);
    $this->earned = $make('Earned Leave', 'EL');
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED,
        'fixed_days' => 12, 'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);
    $this->casual = $make('Casual Leave', 'CL');
    LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->casual->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);

    foreach ([$this->employee, $this->hrEmployee] as $employee) {
        $balance = app(LeaveMovementService::class)->balanceFor($employee->id, $this->earned->id, Carbon::parse('2026-07-01'));
        app(LeaveMovementService::class)->ledgerReady($balance);
        app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 10, Carbon::parse('2026-07-01'), 'rv-'.$employee->id);
        app(LeaveLedgerService::class)->rebuild($balance->fresh());
    }
});

function rvSummary(): array
{
    $balance = LeaveBalance::where('employee_id', test()->employee->id)->where('leave_type_id', test()->earned->id)->sole();

    return app(LeaveBalanceCalculator::class)->summary($balance);
}

/** Tue 10 – Thu 12 Nov: three paid Casual (Earned) days, pending. */
function rvRequest(array $attributes = []): LeaveRequest
{
    return LeaveRequest::create(array_merge([
        'employee_id' => test()->employee->id, 'leave_type_id' => test()->casual->id,
        'main_category' => LeaveCategory::EARNED, 'balance_leave_type_id' => test()->earned->id,
        'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'days' => 3,
        'reason' => 'Family function out of town', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ], $attributes));
}

function rvUsage(LeaveRequest $request): float
{
    return (float) LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->sum('days');
}

function rvApprove(LeaveRequest $request): LeaveRequest
{
    $request = $request->fresh();

    return app(LeaveService::class)->reviewRequest($request, [
        'leave_type_id' => $request->leave_type_id, 'start_date' => $request->start_date->toDateString(),
        'end_date' => $request->end_date->toDateString(), 'reason' => $request->reason, 'is_half_day' => (bool) $request->is_half_day,
    ], 'approved', test()->hr->id);
}

// ── J. HR edit → employee confirmation → final approval ─────────────────────

test('J: HR proposes 2 paid + 1 unpaid; nothing changes until the employee accepts; only the final approval deducts', function () {
    $request = rvRequest();

    $revision = app(Revisions::class)->propose($request, $this->hr, ['paid_days' => 2], 'Only two days can be paid this month');

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(LeaveRequest::AWAITING_EMPLOYEE)
        // The request still reads as submitted, and still reserves its days.
        ->and((float) $fresh->days)->toEqual(3.0)->and($fresh->lwp_days)->toBeNull()
        ->and(rvSummary()['pending'])->toEqual(3.0)
        ->and(rvSummary()['approved_available'])->toEqual(10.0)
        ->and(rvUsage($request))->toEqual(0.0)
        ->and($revision->original)->toMatchArray(['days' => 3.0, 'lwp_days' => 0.0, 'status' => 'pending'])
        ->and($revision->proposed)->toMatchArray(['days' => 3.0, 'lwp_days' => 1.0, 'requested_leave_status' => 'paid'])
        ->and($revision->changed_fields)->toBe(['lwp_days'])
        ->and((int) $revision->proposed_by)->toBe($this->hr->id);
    Notification::assertSentTo($this->user, LeaveRevisionNotification::class);

    // HR cannot approve while the employee has not answered.
    expect(fn () => rvApprove($request))->toThrow(DomainException::class);

    app(Revisions::class)->accept($revision, $this->user, 'Fine by me');
    $fresh = $request->fresh();
    expect($fresh->status)->toBe('pending')
        ->and((float) $fresh->lwp_days)->toEqual(1.0)
        ->and($fresh->paidDays())->toEqual(2.0)
        ->and(rvUsage($request))->toEqual(0.0)
        ->and($revision->fresh()->status)->toBe(LeaveRequestRevision::ACCEPTED)
        ->and((int) $revision->fresh()->responded_by)->toBe($this->user->id);

    rvApprove($request);
    expect(rvUsage($request))->toEqual(-2.0)
        ->and(rvSummary()['approved_available'])->toEqual(8.0)
        ->and(LeaveRequestPresenter::unpaid($request->fresh()))->toEqual(1.0)
        ->and(AuditLog::whereIn('event', ['LEAVE_CHANGE_PROPOSED', 'LEAVE_CHANGE_ACCEPTED', 'LEAVE_APPROVED'])->where('auditable_id', $request->id)->count())->toBe(3);
});

test('J: the employee rejects — the request stays as submitted and HR may approve it as it was', function () {
    $request = rvRequest();
    $revision = app(Revisions::class)->propose($request, $this->hr, ['start_date' => '2026-11-10', 'end_date' => '2026-11-11'], 'Please shorten to two days');
    expect($revision->changed_fields)->toContain('end_date')->toContain('days');

    app(Revisions::class)->reject($revision, $this->user, 'I need all three days');

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('pending')
        ->and($fresh->end_date->toDateString())->toBe('2026-11-12')
        ->and((float) $fresh->days)->toEqual(3.0)
        ->and($revision->fresh()->status)->toBe(LeaveRequestRevision::REJECTED);
    Notification::assertSentTo($this->hr, LeaveRevisionNotification::class);

    rvApprove($request);
    expect(rvUsage($request))->toEqual(-3.0)->and(rvSummary()['approved_available'])->toEqual(7.0);
});

test('a reviewer cannot change the dates of an open request while approving it', function () {
    $request = rvRequest();

    expect(fn () => app(LeaveService::class)->reviewRequest($request, [
        'leave_type_id' => $request->leave_type_id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-10',
        'reason' => $request->reason, 'is_half_day' => false,
    ], 'approved', $this->hr->id))->toThrow(DomainException::class, 'confirm');

    expect($request->fresh()->status)->toBe('pending')->and(rvUsage($request))->toEqual(0.0);
});

test('HR withdraws a proposal: the request is back as submitted', function () {
    $request = rvRequest(['status' => 'pending_hr']);
    $revision = app(Revisions::class)->propose($request, $this->hr, ['paid_days' => 1], 'Two days unpaid instead');

    app(Revisions::class)->withdraw($revision, $this->hr);

    expect($request->fresh()->status)->toBe('pending_hr')
        ->and($revision->fresh()->status)->toBe(LeaveRequestRevision::WITHDRAWN);
});

test('the employee may withdraw a request that waits on them; the proposal closes with it', function () {
    $request = rvRequest();
    $revision = app(Revisions::class)->propose($request, $this->hr, ['paid_days' => 2], 'Only two days can be paid');

    app(LeaveCancellationService::class)->withdrawPending($request, $this->user);

    expect($request->fresh()->status)->toBe('cancelled')
        ->and($revision->fresh()->status)->toBe(LeaveRequestRevision::WITHDRAWN)
        ->and(rvSummary()['pending'])->toEqual(0.0);
});

test('Combined Leave: HR proposes one paid day as unpaid; on acceptance and approval only the paid units are deducted', function () {
    $request = app(LeaveService::class)->submitCombinedRequest($this->employee, $this->casual, '2026-11-10', '2026-11-11', [
        '2026-11-10' => ['type' => LeaveRequestDay::FULL_PAID, 'session' => null],
        '2026-11-11' => ['type' => LeaveRequestDay::FULL_PAID, 'session' => null],
    ], 'Two days away', actor: $this->user);

    $revision = app(Revisions::class)->propose($request, $this->hr, ['allocations' => [
        '2026-11-10' => ['type' => LeaveRequestDay::FULL_PAID, 'session' => null],
        '2026-11-11' => ['type' => LeaveRequestDay::FULL_UNPAID, 'session' => null],
    ]], 'The second day is unpaid');
    expect($revision->changed_fields)->toContain('allocation')->toContain('lwp_days')
        ->and($request->fresh()->dayAllocations()->sum('unpaid_units'))->toEqual(0);

    app(Revisions::class)->accept($revision, $this->user);
    $fresh = $request->fresh();
    expect($fresh->dayAllocations()->sum('unpaid_units'))->toEqual(1)
        ->and((float) $fresh->lwp_days)->toEqual(1.0)->and((float) $fresh->days)->toEqual(2.0)
        // The submitted allocation is kept on the revision.
        ->and(collect($revision->fresh()->original['rows'])->pluck('allocation')->all())->toBe([LeaveRequestDay::FULL_PAID, LeaveRequestDay::FULL_PAID]);

    rvApprove($request);
    expect(rvUsage($request))->toEqual(-1.0)->and(rvSummary()['approved_available'])->toEqual(9.0);
});

test('only in-scope HR proposes, never on their own leave; only the employee answers', function () {
    $request = rvRequest();
    $other = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $other->id, 'status' => 'active']);

    expect(fn () => app(Revisions::class)->propose($request, $other, ['paid_days' => 2], 'Not my call at all'))->toThrow(AuthorizationException::class);

    $own = rvRequest(['employee_id' => $this->hrEmployee->id, 'start_date' => '2026-11-17', 'end_date' => '2026-11-17', 'days' => 1]);
    expect(fn () => app(Revisions::class)->propose($own, $this->hr, ['paid_days' => 0], 'My own leave edit'))->toThrow(AuthorizationException::class);

    $revision = app(Revisions::class)->propose($request, $this->hr, ['paid_days' => 2], 'Only two days paid');
    expect(fn () => app(Revisions::class)->accept($revision, $this->hr))->toThrow(AuthorizationException::class)
        ->and(fn () => app(Revisions::class)->accept($revision, $other))->toThrow(AuthorizationException::class);
});

test('a proposal that changes nothing, or asks for more paid days than are available, is refused', function () {
    $request = rvRequest();

    expect(fn () => app(Revisions::class)->propose($request, $this->hr, ['paid_days' => 3], 'Same as before really'))->toThrow(DomainException::class, 'nothing to confirm')
        ->and(fn () => app(Revisions::class)->propose(rvRequest(['start_date' => '2026-11-16', 'end_date' => '2026-11-16', 'days' => 1]), $this->hr,
            ['start_date' => '2026-11-16', 'end_date' => '2026-11-30', 'paid_days' => 12], 'Longer and paid'))->toThrow(DomainException::class);
});

// ── 4. Approval: exactly once ───────────────────────────────────────────────

test('approving twice — a double click or a retry with a stale copy — deducts once', function () {
    $request = rvRequest();
    $stale = LeaveRequest::find($request->id);

    rvApprove($request);
    expect(fn () => app(LeaveService::class)->reviewRequest($stale, [
        'leave_type_id' => $stale->leave_type_id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12',
        'reason' => $stale->reason, 'is_half_day' => false,
    ], 'approved', $this->hr->id))->toThrow(DomainException::class);

    expect(rvUsage($request))->toEqual(-3.0)
        ->and(LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->count())->toBe(1);
});

test('rejection deducts nothing; a pending request only reserves', function () {
    $request = rvRequest();
    expect(rvSummary())->toMatchArray(['pending' => 3.0, 'approved_available' => 10.0]);

    app(LeaveService::class)->reviewRequest($request, [
        'leave_type_id' => $request->leave_type_id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12',
        'reason' => $request->reason, 'is_half_day' => false,
    ], 'rejected', $this->hr->id, 'Not this week, sorry');

    expect(rvSummary())->toMatchArray(['pending' => 0.0, 'approved_available' => 10.0])->and(rvUsage($request))->toEqual(0.0);
});

test('cancelling approved leave that never posted usage returns nothing (no phantom credit)', function () {
    // Approved after the balance moved to the ledger, yet with no usage row (e.g. approved as unpaid, then flagged paid by hand).
    $request = rvRequest(['status' => 'approved', 'approved_at' => now()->addMinute(), 'approved_leave_status' => 'paid']);

    $entries = LeaveLedgerEntry::where('employee_id', $this->employee->id)->count();

    app(LeaveService::class)->cancelRequest($request, 'HR correction', $this->hr);

    expect(rvSummary()['approved_available'])->toEqual(10.0)
        ->and(LeaveLedgerEntry::where('employee_id', $this->employee->id)->count())->toBe($entries)
        ->and($request->fresh()->status)->toBe('cancelled');
});

test('H: an approved cancellation returns the paid days once, and a Combined request only its paid units', function () {
    $request = app(LeaveService::class)->submitCombinedRequest($this->employee, $this->casual, '2026-11-10', '2026-11-13', [
        '2026-11-10' => ['type' => LeaveRequestDay::FULL_PAID, 'session' => null],
        '2026-11-11' => ['type' => LeaveRequestDay::FULL_PAID, 'session' => null],
        '2026-11-12' => ['type' => LeaveRequestDay::HALF_PAID, 'session' => 'first_half'],
        '2026-11-13' => ['type' => LeaveRequestDay::FULL_UNPAID, 'session' => null],
    ], 'Mixed days', actor: $this->user);
    rvApprove($request);
    expect(rvUsage($request))->toEqual(-2.5)->and(rvSummary()['approved_available'])->toEqual(7.5);

    $cancellation = app(LeaveCancellationService::class)->request($request->fresh(), $this->user, 'Plans changed, not travelling');
    app(LeaveCancellationService::class)->approve($cancellation, $this->hr);
    expect(fn () => app(LeaveCancellationService::class)->approve($cancellation->fresh(), $this->hr))->toThrow(DomainException::class);

    expect($request->fresh()->status)->toBe('cancelled')
        ->and(rvUsage($request))->toEqual(0.0)
        ->and(rvSummary()['approved_available'])->toEqual(10.0);
});

// ── Screens ─────────────────────────────────────────────────────────────────

test('HR proposes from the request page; the employee sees original vs proposed on My Time Off and accepts', function () {
    $request = rvRequest();

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->assertSee('Propose Changes')
        ->call('openProposal')
        ->assertSeeHtml('data-proposal-form')
        ->set('proposal.paid_days', '2')
        ->assertSeeHtml('data-proposal-summary')
        ->set('proposalReason', 'Only two days can be paid')
        ->call('submitProposal')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-pending-revision')
        ->assertDontSee('Confirm Approval');
    expect($request->fresh()->status)->toBe(LeaveRequest::AWAITING_EMPLOYEE);

    $revision = LeaveRequestRevision::sole();
    Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->assertSeeHtml('data-awaiting-revision="'.$revision->id.'"')
        ->assertSeeHtml('data-revision-compare')
        ->call('rejectRevision', $revision->id)
        ->assertHasErrors(["revisionNotes.{$revision->id}"])
        ->call('acceptRevision', $revision->id)
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-awaiting-revision');

    expect($request->fresh()->status)->toBe('pending')->and((float) $request->fresh()->lwp_days)->toEqual(1.0);
});

test('the request timeline shows each step, the deduction with its ledger entry, and the payroll days', function () {
    $request = rvRequest();
    $revision = app(Revisions::class)->propose($request, $this->hr, ['paid_days' => 2], 'Only two days can be paid');
    app(Revisions::class)->accept($revision, $this->user);
    rvApprove($request);
    $usage = LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->sole();

    $timeline = app(LeaveRequestTimeline::class)->for($request->fresh());
    expect($timeline->pluck('action')->all())->toContain('Submitted', 'HR proposed changes', 'Employee accepted the proposed changes', 'Approved', 'Paid leave deducted', 'Unpaid days sent to payroll')
        ->and($timeline->firstWhere('action', 'Paid leave deducted')['ledger_ids'])->toBe([$usage->id])
        ->and($timeline->firstWhere('action', 'Unpaid days sent to payroll')['system'])->toBeTrue()
        ->and($timeline->firstWhere('action', 'HR proposed changes')['actor'])->toBe('Hema HR');

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->assertSeeHtml('data-timeline-event="ledger"')
        ->assertSee('ledger #'.$usage->id)
        ->assertSeeHtml('data-section="revisions"');
});

test('HR sees the employee\'s Earned Leave, Unpaid and Combined figures from the canonical services', function () {
    rvApprove(rvRequest());

    Livewire::actingAs($this->hr)->test(EmployeeLeaveDetail::class, ['employee' => $this->employee])
        ->assertSeeHtml('data-v2-earned')
        ->assertSeeHtml('data-v2-unpaid')
        ->assertSeeHtml('data-v2-combined')
        ->assertSeeInOrder(['Opening', 'Carry forward', 'Accrued', 'Used', 'Pending', 'Available']);

    $summary = app(LeaveCategoryBalances::class)->forEmployee($this->employee, LeaveYear::where('label', '2026/27')->sole());
    expect($summary['earned'])->toMatchArray(['used' => 3.0, 'approved_available' => 7.0]);
});
