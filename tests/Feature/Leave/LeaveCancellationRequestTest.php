<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard\LeaveOverview;
use App\Livewire\TimeOff\LeaveRequestDetail;
use App\Livewire\TimeOff\LeaveRequests;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveCancellationNotification;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCancellationService;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use App\Services\LeaveService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * An employee never cancels APPROVED leave directly: they send HR a
 * cancellation request with a reason, the leave stays approved (and its days
 * deducted) until HR decides, and only HR approving it cancels the leave.
 * Pending leave can still be withdrawn by the employee.
 *
 * Clock: Mon 2 Nov 2026 (leave year 2026/27).
 */
beforeEach(function () {
    Notification::fake();
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    $this->user = User::factory()->create(['role' => UserRole::Employee, 'name' => 'Mayuresh Mhatre']);
    $this->employee = Employee::factory()->create([
        'user_id' => $this->user->id, 'status' => 'active', 'joining_date' => '2024-01-10', 'employee_id' => 'CNX-0008',
        'manager_id' => null, 'department_id' => Department::factory()->create()->id,
    ]);
    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);
    Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'manager_id' => null]);

    $policy = LeavePolicy::create(['name' => 'NEXCORE Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => false, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'EL'.random_int(10, 99), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $policy->update(['earned_leave_type_id' => $this->earned->id]);
    LeavePolicyRule::create(['leave_policy_id' => $policy->id, 'leave_type_id' => $this->earned->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED,
        'fixed_days' => 12, 'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);
    $this->casual = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL'.random_int(10, 99), 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'allow_half_day' => true, 'color' => '#f59e0b']);
    LeavePolicyRule::create(['leave_policy_id' => $policy->id, 'leave_type_id' => $this->casual->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);

    $balance = app(LeaveMovementService::class)->balanceFor($this->employee->id, $this->earned->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($balance);
    app(LeaveLedgerService::class)->credit($balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 12, Carbon::parse('2026-07-01'), 'lc-earned-'.$this->employee->id);
    app(LeaveLedgerService::class)->rebuild($balance->fresh());
});

function lcAvailable(): float
{
    $balance = LeaveBalance::where('employee_id', test()->employee->id)->where('leave_type_id', test()->earned->id)->sole();

    return app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'];
}

/** A 2-day Earned Leave (Casual) request, approved by HR so it is deducted. */
function lcApproved(array $attributes = []): LeaveRequest
{
    $request = LeaveRequest::create(array_merge([
        'employee_id' => test()->employee->id, 'leave_type_id' => test()->casual->id,
        'main_category' => LeaveCategory::EARNED, 'balance_leave_type_id' => test()->earned->id,
        'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'days' => 2,
        'reason' => 'Family function', 'status' => 'pending', 'requested_leave_status' => 'paid', 'approved_leave_status' => 'paid',
    ], $attributes));

    app(LeaveService::class)->reviewRequest($request, $request->only(['leave_type_id', 'start_date', 'end_date', 'reason']) + ['is_half_day' => false], 'approved', test()->hr->id);

    return $request->fresh();
}

function lcAskHr(LeaveRequest $request, string $reason = 'Trip called off'): LeaveCancellationRequest
{
    return app(LeaveCancellationService::class)->request($request, test()->user, $reason);
}

// ── The employee ─────────────────────────────────────────────────────────────

test('cancelling approved leave opens a request for HR and never cancels it directly', function () {
    $request = lcApproved();
    expect(lcAvailable())->toEqual(10.0);

    Livewire::actingAs($this->user)->test(MyTimeOff::class)
        ->assertSeeHtml('data-request-cancellation')
        ->call('cancelRequest', $request->id)
        ->assertSet('cancellingId', $request->id)
        ->assertSeeHtml('data-cancellation-form')
        ->call('submitCancellationRequest')
        ->assertHasErrors('cancellationReason')
        ->set('cancellationReason', 'Trip called off')
        ->call('submitCancellationRequest')
        ->assertHasNoErrors()
        ->assertSet('cancellingId', null)
        ->assertSeeHtml('data-cancellation-pending')
        ->assertSee('Withdraw request');

    $cancellation = LeaveCancellationRequest::sole();
    expect($request->fresh()->status)->toBe('approved')
        ->and(lcAvailable())->toEqual(10.0)
        ->and($cancellation->status)->toBe(LeaveCancellationRequest::PENDING)
        ->and($cancellation->reason)->toBe('Trip called off')
        ->and(AuditLog::where('event', 'LEAVE_CANCELLATION_REQUESTED')->where('auditable_id', $request->id)->exists())->toBeTrue();

    Notification::assertSentTo($this->hr, LeaveCancellationNotification::class, fn ($n) => $n->stage === LeaveCancellationNotification::REQUESTED);
});

test('the service refuses a direct employee cancellation of approved leave and a second open request', function () {
    $request = lcApproved();

    expect(fn () => app(LeaveCancellationService::class)->withdrawPending($request, $this->user))
        ->toThrow(DomainException::class, 'already approved');

    lcAskHr($request);
    expect(fn () => lcAskHr($request))->toThrow(DomainException::class, 'already with HR');

    $someoneElse = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $someoneElse->id, 'status' => 'active']);
    expect(fn () => app(LeaveCancellationService::class)->request($request, $someoneElse, 'Not mine to cancel'))
        ->toThrow(AuthorizationException::class);
    expect($request->fresh()->status)->toBe('approved');
});

test('pending leave is still withdrawn by the employee at once', function () {
    $pending = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->casual->id,
        'main_category' => LeaveCategory::EARNED, 'balance_leave_type_id' => $this->earned->id,
        'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'days' => 1, 'reason' => 'Errand', 'status' => 'pending', 'requested_leave_status' => 'paid']);

    Livewire::actingAs($this->user)->test(MyTimeOff::class)->call('cancelRequest', $pending->id)->assertSet('cancellingId', null);

    expect($pending->fresh()->status)->toBe('cancelled')
        ->and(LeaveCancellationRequest::count())->toBe(0);
});

test('the employee can take back a cancellation request; the leave stays approved', function () {
    $request = lcApproved();
    lcAskHr($request);

    Livewire::actingAs($this->user)->test(MyTimeOff::class)->call('withdrawCancellationRequest', $request->id);

    expect(LeaveCancellationRequest::sole()->status)->toBe(LeaveCancellationRequest::WITHDRAWN)
        ->and($request->fresh()->status)->toBe('approved')
        ->and(lcAvailable())->toEqual(10.0);
});

// ── HR ───────────────────────────────────────────────────────────────────────

test('HR approving the request cancels the leave and returns the days', function () {
    $request = lcApproved();
    lcAskHr($request);

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->assertSeeHtml('data-cancellation-request')
        ->assertSee('Trip called off')
        ->call('confirm', 'approve_cancellation')
        ->call('approveCancellation')
        ->assertHasNoErrors();

    $cancellation = LeaveCancellationRequest::sole();
    expect($request->fresh()->status)->toBe('cancelled')
        ->and(lcAvailable())->toEqual(12.0)
        ->and($cancellation->status)->toBe(LeaveCancellationRequest::APPROVED)
        ->and((int) $cancellation->decided_by)->toBe($this->hr->id)
        ->and(AuditLog::where('event', 'LEAVE_CANCELLATION_APPROVED')->where('auditable_id', $request->id)->exists())->toBeTrue()
        ->and(AuditLog::where('event', 'LEAVE_CANCELLED')->where('auditable_id', $request->id)->exists())->toBeTrue();
});

test('HR rejecting needs a reason, keeps the leave approved and tells the employee', function () {
    $request = lcApproved();
    lcAskHr($request);

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->call('confirm', 'reject_cancellation')
        ->call('rejectCancellation')
        ->assertHasErrors('cancellationNote')
        ->set('cancellationNote', 'The team is already short that week')
        ->call('rejectCancellation')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('approved')
        ->and(lcAvailable())->toEqual(10.0)
        ->and(LeaveCancellationRequest::sole()->status)->toBe(LeaveCancellationRequest::REJECTED);
    Notification::assertSentTo($this->user, LeaveCancellationNotification::class, fn ($n) => $n->stage === LeaveCancellationNotification::REJECTED);
});

test('only HR who manage approved leave decide it — never a manager without it, never their own', function () {
    $managerRole = Role::where('slug', 'manager')->firstOrFail();
    $manager = User::factory()->create(['role' => $managerRole->legacyBucket(), 'role_id' => $managerRole->id]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active', 'manager_id' => null]);
    $this->employee->update(['manager_id' => $manager->id]);
    $request = lcApproved();
    lcAskHr($request);

    $page = Livewire::actingAs($manager)->test(LeaveRequestDetail::class, ['leaveRequest' => $request]);
    expect($page->instance()->canDecideCancellation)->toBeFalse();
    $page->assertDontSee('Approve Cancellation')->call('approveCancellation')->assertForbidden();

    expect(app(LeaveCancellationService::class)->hrMayDecide(LeaveCancellationRequest::sole(), $this->user))->toBeFalse()
        ->and($request->fresh()->status)->toBe('approved');
});

test('HR finds open cancellation requests on the list and the dashboard', function () {
    $request = lcApproved();
    $other = lcApproved(['start_date' => '2026-11-24', 'end_date' => '2026-11-24', 'days' => 1]);
    lcAskHr($request);

    $list = Livewire::actingAs($this->hr)->test(LeaveRequests::class)->call('setTab', 'cancellation');
    expect($list->instance()->requests->pluck('id')->all())->toBe([$request->id])
        ->and($list->instance()->tabCounts['cancellation'])->toBe(1);
    $list->assertSeeHtml('data-cancellation-pending');

    Livewire::withoutLazyLoading()->actingAs($this->hr)->test(LeaveOverview::class)
        ->assertSeeHtml('data-cancellation-alert')
        ->assertSee(route('time-off.leave-requests', ['status' => 'cancellation']), false);

    expect($other->fresh()->status)->toBe('approved');
});
