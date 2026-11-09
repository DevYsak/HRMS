<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveRequestDetail;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyAssignment;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestRevision;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\NotificationSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\LeaveRevisionNotification;
use App\Services\Leave\EarnedLeaveAccrualService as Accrual;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveRequestEvaluator;
use App\Services\Leave\LeaveRequestRevisionService;
use App\Services\LeaveService;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Earned Leave balance and HR approval workflow (9 Nov 2026):
 *  - beyond the Earned Leave available, the rest is Leave Without Pay (when
 *    the policy converts) and the employee is told; with nothing left, plainly;
 *  - HR changes a request directly (dates, type, paid / unpaid) and decides
 *    in one step, recorded and audited; remarks are optional;
 *  - HR adds people to the decision's email; the email carries the facts;
 *  - the balance at application and every notification / email are kept.
 *
 * Clock: Mon 2 Nov 2026. July–October credited (4 days). Sat/Sun off.
 */
beforeEach(function () {
    config(['leave_provisioning.policy_engine_v2' => true]);
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();

    $this->policy = LeavePolicy::create(['name' => 'Conexus Standard Leave Policy', 'statutory_weeks' => 0, 'contractual_additional_weeks' => 0,
        'is_default' => true, 'is_active' => true, 'annual_paid_leave_cap' => 12]);
    LeavePolicyAssignment::create(['leave_policy_id' => $this->policy->id, 'scope' => 'company', 'effective_from' => '2026-07-01']);
    $this->earned = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELW', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'allow_hr_override' => true, 'counts_toward_annual_cap' => true, 'color' => '#10b981']);
    $this->policy->update(['earned_leave_type_id' => $this->earned->id]);
    $this->rule = LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->earned->id,
        'entitlement_method' => LeavePolicyRule::ENTITLEMENT_FIXED, 'fixed_days' => 12,
        'accrual_method' => LeavePolicyRule::ACCRUAL_MONTHLY, 'accrual_amount' => 1, 'is_active' => true,
        'excess_handling' => LeaveRequestEvaluator::EXCESS_CONVERT_TO_LWP]);
    $this->casual = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CLW', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'allow_hr_override' => true, 'color' => '#f59e0b']);
    LeavePolicyRule::create(['leave_policy_id' => $this->policy->id, 'leave_type_id' => $this->casual->id, 'entitlement_method' => LeavePolicyRule::ENTITLEMENT_NONE,
        'accrual_method' => LeavePolicyRule::ACCRUAL_UPFRONT, 'is_active' => true]);

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);
    $this->payroll = User::factory()->create(['name' => 'Priya Payroll', 'email' => 'payroll@example.com']);

    $this->employee = Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee, 'name' => 'Mayuresh Mhatre'])->id,
        'status' => 'active', 'joining_date' => '2020-12-16', 'employee_id' => 'CNS008', 'manager_id' => null, 'department_id' => Department::factory()->create()->id]);

    // July–October credited by the monthly job: 4 days.
    $this->travelTo(Carbon::parse('2026-11-01 00:20:00'));
    $accrual = app(Accrual::class);
    $accrual->apply($accrual->planFor($this->employee->fresh(), $this->year, Carbon::parse('2026-11-01')));
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
});

function hoSubmit(string $start, string $end): LeaveRequest
{
    return app(LeaveService::class)->submitRequest(test()->employee->fresh(), test()->casual, $start, $end, 'Family function', false, null, 'paid', null, null, []);
}

function hoPage(LeaveRequest $request)
{
    return Livewire::actingAs(test()->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request]);
}

// ── 1 · Only the Earned Leave available is paid; the rest is LWP, and the employee is told ──

test('beyond the Earned Leave available the rest becomes Leave Without Pay, the employee is told, and the balance is kept as applied', function () {
    Notification::fake();
    // 16–20 Nov: 5 days, 4 available (November is not complete before the 16th).
    $request = hoSubmit('2026-11-16', '2026-11-20');

    expect($request->only(['days', 'lwp_days']))->toEqual(['days' => '5.00', 'lwp_days' => '1.00'])
        ->and($request->balance_snapshot)->toMatchArray(['available_for_request' => 4.0, 'paid_days' => 4.0, 'lwp_days' => 1.0, 'balance_type' => 'Earned Leave', 'balance_today' => 4.0]);

    Notification::assertSentTo($this->employee->user, LeaveRequestNotification::class, function (LeaveRequestNotification $n) {
        $data = $n->toArray($this->employee->user);

        return $n->event === LeaveRequestNotification::SUBMITTED
            && str_contains($data['body'], '4 day(s) paid from your Earned Leave and 1 day(s) unpaid (Leave Without Pay)');
    });

    $evaluation = app(LeaveRequestEvaluator::class)->evaluate($this->employee->fresh(), $this->casual, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-24'));
    expect($evaluation)->toMatchArray(['paid_days' => 0.0, 'lwp_days' => 2.0, 'blocked' => false])
        ->and($evaluation['warnings'][0])->toStartWith('You have no Earned Leave available for these dates, so all 2 day(s) will be Leave Without Pay (unpaid).');
});

test('with the policy set to block, an exhausted balance is refused with a plain message', function () {
    $this->rule->update(['excess_handling' => LeaveRequestEvaluator::EXCESS_BLOCK]);
    Notification::fake();
    hoSubmit('2026-11-16', '2026-11-19');   // all 4 days

    $evaluation = app(LeaveRequestEvaluator::class)->evaluate($this->employee->fresh(), $this->casual, Carbon::parse('2026-11-23'), Carbon::parse('2026-11-23'));
    expect($evaluation['blocked'])->toBeTrue()
        ->and($evaluation['errors'][0])->toStartWith('You have only 0 Earned Leave day(s) available.')
        ->and($evaluation['errors'][0])->toEndWith('Apply for fewer days, or choose Unpaid Leave.');
});

// ── 2 · HR changes and decides directly; remarks optional; recorded ─────────

test('HR converts Earned Leave to unpaid and approves in one step: recorded, audited, nothing deducted for unpaid days', function () {
    Notification::fake();
    $request = hoSubmit('2026-11-16', '2026-11-17');   // 2 days, fits

    hoPage($request)->call('openProposal')
        ->set('proposal.paid_days', '0')
        ->set('proposalReason', '')
        ->call('submitProposal', 'approved')
        ->assertHasNoErrors();

    $request->refresh();
    expect($request->status)->toBe('approved')
        ->and((float) $request->lwp_days)->toBe(2.0)
        ->and((float) LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->sum('days'))->toBe(0.0)
        ->and(LeaveRequestRevision::where('leave_request_id', $request->id)->sole())->status->toBe(LeaveRequestRevision::APPLIED)
        ->and(AuditLog::where('event', 'LEAVE_CHANGED_BY_HR')->where('subject_employee_id', $this->employee->id)->exists())->toBeTrue();
    Notification::assertSentTo($this->employee->user, LeaveRequestNotification::class, fn ($n) => $n->statusAtDispatch === 'approved');
});

test('HR changes the dates without deciding: the request stays open, the employee is told, and approval keeps HR\'s paid / unpaid split', function () {
    Notification::fake();
    $request = hoSubmit('2026-11-16', '2026-11-17');

    app(LeaveRequestRevisionService::class)->applyByHr($request, $this->hr, ['start_date' => '2026-11-18', 'end_date' => '2026-11-20', 'leave_type_id' => $this->casual->id, 'paid_days' => '2'], 'Moved to the agreed week');

    $request->refresh();
    expect($request->only(['status']))->toBe(['status' => 'pending'])
        ->and($request->start_date->toDateString())->toBe('2026-11-18')
        ->and((float) $request->days)->toBe(3.0)
        ->and((float) $request->lwp_days)->toBe(1.0);
    Notification::assertSentTo($this->employee->user, LeaveRevisionNotification::class, fn ($n) => $n->stage === LeaveRevisionNotification::APPLIED);

    // The policy alone would pay all three (4 available): approval keeps HR's 2 paid / 1 unpaid.
    app(LeaveService::class)->reviewRequest($request->fresh(), ['leave_type_id' => $this->casual->id, 'start_date' => '2026-11-18', 'end_date' => '2026-11-20',
        'reason' => $request->reason, 'is_half_day' => false], 'approved', $this->hr->id);
    expect((float) $request->fresh()->lwp_days)->toBe(1.0)
        ->and((float) LeaveLedgerEntry::where('source_type', 'leave_request')->where('source_id', $request->id)->sum('days'))->toBe(-2.0);
});

test('every HR remark is optional: rejecting without a reason, and a paid / unpaid change unless the type asks for one', function () {
    Notification::fake();
    $request = hoSubmit('2026-11-16', '2026-11-16');
    hoPage($request)->call('confirm', 'reject')->set('comment', '')->call('reject')->assertHasNoErrors();
    expect($request->fresh()->status)->toBe('rejected');

    $other = hoSubmit('2026-11-23', '2026-11-23');
    app(LeaveService::class)->hrOverridePaymentStatus($other, $this->hr, 'unpaid', '');
    expect($other->fresh()->approved_leave_status)->toBe('unpaid');

    $this->casual->update(['hr_remark_required' => true]);
    $third = hoSubmit('2026-11-24', '2026-11-24');
    expect(fn () => app(LeaveService::class)->hrOverridePaymentStatus($third, $this->hr, 'unpaid', ''))
        ->toThrow(DomainException::class, 'An HR remark is required');
});

// ── 3 · People HR adds, and the email ────────────────────────────────────────

test('HR adds people to the decision: preset from settings, each told once, never the employee or the approver, audited', function () {
    Notification::fake();
    NotificationSetting::updateOrCreate(['key' => LeaveRequestNotification::class], ['label' => 'Leave request', 'group' => 'Leave', 'include_user_ids' => [$this->payroll->id]]);
    $manager = User::factory()->create(['name' => 'Mo Manager']);
    $request = hoSubmit('2026-11-16', '2026-11-16');

    $page = hoPage($request)->assertSet('notifyUserIds', [$this->payroll->id])
        ->call('confirm', 'approve')
        ->assertSeeHtml('data-notify-recipients')
        ->set('recipientSearch', 'Mo Man')
        ->assertSee('Mo Manager')
        ->call('addRecipient', $manager->id)
        ->call('addRecipient', $this->employee->user_id)   // shown nowhere; never notified as a recipient
        ->call('approve')
        ->assertHasNoErrors();

    foreach ([$this->payroll, $manager] as $user) {
        Notification::assertSentTo($user, LeaveRequestNotification::class, fn ($n) => $n->notificationRole() === 'recipient'
            && str_contains($n->toArray($user)['title'], 'Leave Approved — Mayuresh Mhatre'));
    }
    Notification::assertNotSentTo($this->hr, LeaveRequestNotification::class, fn ($n) => $n->notificationRole() === 'recipient');
    Notification::assertSentTo($this->employee->user, LeaveRequestNotification::class, fn ($n) => $n->notificationRole() !== 'recipient');
    expect(AuditLog::where('event', 'LEAVE_RECIPIENTS_NOTIFIED')->sole()->new_values['recipients'])->toHaveCount(2);
});

test('the leave email carries the facts, is logged against its request, and the request page lists every notification and email', function () {
    $request = hoSubmit('2026-11-16', '2026-11-20');   // 4 paid, 1 unpaid
    app(LeaveService::class)->reviewRequest($request->fresh(), ['leave_type_id' => $this->casual->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-20',
        'reason' => $request->reason, 'is_half_day' => false], 'approved', $this->hr->id, 'Enjoy the break');

    $mail = (new LeaveRequestNotification($request->fresh()))->forRole('employee')->toMail($this->employee->user);
    $text = implode("\n", $mail->introLines);
    expect($text)->toContain('**Employee:** Mayuresh Mhatre (CNS008)')
        ->toContain('**Dates:** 16 Nov 2026 – 20 Nov 2026')
        ->toContain('**Duration:** 5 day(s)')
        ->toContain('**Earned Leave available when applied:** 4 day(s)')
        ->toContain('**Paid / unpaid:** 4 paid · 1 unpaid (Leave Without Pay)')
        ->toContain('**Decision:** Approved by Hema HR')
        ->toContain('**Remarks:** Enjoy the break');

    // Sent for real (array mailer): the leave workflow emails by default and the log names the request.
    expect(NotificationCatalog::isCriticalMail(LeaveRequestNotification::class))->toBeTrue()
        ->and(EmailLog::where('context_type', 'leave_request')->where('context_id', $request->id)->where('status', 'sent')->exists())->toBeTrue();

    $history = hoPage($request->fresh())->assertSeeHtml('data-notification-history')->instance()->notificationHistory;
    expect($history->pluck('channel')->unique()->sort()->values()->all())->toBe(['Email', 'In-app'])
        ->and($history->pluck('to'))->toContain('Mayuresh Mhatre');
});

test('the email defaults command switches the leave workflow on and everything else off', function () {
    NotificationSetting::updateOrCreate(['key' => LeaveRequestNotification::class], ['label' => 'Leave request', 'group' => 'Leave', 'mail_enabled' => false]);
    NotificationSetting::updateOrCreate(['key' => 'App\Notifications\OtRequestNotification'], ['label' => 'Overtime', 'group' => 'Overtime', 'mail_enabled' => true]);

    $this->artisan('notifications:apply-email-defaults')->expectsOutputToContain('would have email switched off and 1 switched on')->assertSuccessful();
    expect(NotificationSetting::where('key', LeaveRequestNotification::class)->value('mail_enabled'))->toBeFalsy();

    $this->artisan('notifications:apply-email-defaults', ['--apply' => true])->assertSuccessful();
    expect(NotificationSetting::where('key', LeaveRequestNotification::class)->value('mail_enabled'))->toBeTruthy()
        ->and(NotificationSetting::where('key', 'App\Notifications\OtRequestNotification')->value('mail_enabled'))->toBeFalsy();
});
