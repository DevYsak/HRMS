<?php

use App\Models\AuditLog;
use App\Models\Payroll;
use App\Models\PayrollApprovalPolicy;
use App\Models\PayrollApprovalStep;
use App\Models\User;
use App\Notifications\PayrollApprovalNotification;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 8: a configurable multi-step payroll approval chain, layered
 * additively under the existing 3-state payrolls.status enum. With no
 * active PayrollApprovalPolicy rows, submitForFinanceApproval() takes the
 * exact legacy single-hop path — this is the backward-compat contract the
 * whole feature depends on, so it gets its own explicit regression test.
 */
function draftPayrollFor(string $month, User $maker): Payroll
{
    return Payroll::create([
        'month' => $month, 'year' => 2027, 'cycle' => 'cycle_a',
        'status' => 'draft', 'processed_by' => $maker->id,
    ]);
}

function policyStep(string $label, string $approverType, int $level, ?int $specificUserId = null): PayrollApprovalPolicy
{
    return PayrollApprovalPolicy::create([
        'level' => $level, 'label' => $label, 'approver_type' => $approverType,
        'specific_user_id' => $specificUserId, 'is_active' => true,
    ]);
}

test('with no active policy, submitting takes the legacy single-hop path unchanged', function () {
    Notification::fake();
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $finance = User::factory()->create(['role' => 'finance']);
    $payroll = draftPayrollFor('January', $maker);

    $payroll = app(PayrollService::class)->submitForFinanceApproval($payroll);

    expect($payroll->status)->toBe('pending_finance')
        ->and(PayrollApprovalStep::where('payroll_id', $payroll->id)->count())->toBe(0);

    Notification::assertSentTo($finance, PayrollApprovalNotification::class, fn ($n) => $n->event === 'submitted');

    // The legacy single-hop finalize still works with zero configured steps.
    $payroll = app(PayrollService::class)->approveFinance($payroll, $finance->id);
    expect($payroll->status)->toBe('finalized');
});

test('submitting with an active policy snapshots ordered steps and notifies only step 1', function () {
    Notification::fake();
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $hrApprover = User::factory()->create(['role' => 'hr_admin']);
    $financeApprover = User::factory()->create(['role' => 'finance']);
    policyStep('HR Review', 'hr_admin', 1);
    policyStep('Finance Sign-off', 'finance', 2);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('February', $maker));

    $steps = PayrollApprovalStep::where('payroll_id', $payroll->id)->orderBy('level')->get();
    expect($steps)->toHaveCount(2)
        ->and($steps[0]->label)->toBe('HR Review')
        ->and($steps[0]->status)->toBe('pending')
        ->and($steps[1]->label)->toBe('Finance Sign-off');

    Notification::assertSentTo($hrApprover, PayrollApprovalNotification::class, fn ($n) => $n->event === 'step_ready' && $n->step->label === 'HR Review');
    Notification::assertNotSentTo($financeApprover, PayrollApprovalNotification::class);
});

test('resubmitting after rejection wipes stale steps rather than duplicating them', function () {
    Notification::fake();
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $hrApprover = User::factory()->create(['role' => 'hr_admin']);
    policyStep('HR Review', 'hr_admin', 1);

    $service = app(PayrollService::class);
    $payroll = $service->submitForFinanceApproval(draftPayrollFor('March', $maker));
    $firstStep = PayrollApprovalStep::where('payroll_id', $payroll->id)->first();
    $service->rejectStep($firstStep, $hrApprover, 'Fix the OT totals.');

    expect($payroll->fresh()->status)->toBe('draft');

    $payroll = $service->submitForFinanceApproval($payroll->fresh());

    expect(PayrollApprovalStep::where('payroll_id', $payroll->id)->count())->toBe(1)
        ->and(PayrollApprovalStep::where('payroll_id', $payroll->id)->first()->status)->toBe('pending');
});

test('editing the policy after submission does not change an in-flight payroll\'s snapshotted steps', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $policy = policyStep('HR Review', 'hr_admin', 1);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('April', $maker));

    $policy->update(['label' => 'Renamed Step', 'approver_type' => 'director']);

    $step = PayrollApprovalStep::where('payroll_id', $payroll->id)->first();
    expect($step->label)->toBe('HR Review')
        ->and($step->approver_type)->toBe('hr_admin');
});

test('a step must be approved in order — an earlier pending step blocks a later one', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $financeApprover = User::factory()->create(['role' => 'finance']);
    policyStep('HR Review', 'hr_admin', 1);
    policyStep('Finance Sign-off', 'finance', 2);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('May', $maker));
    $financeStep = PayrollApprovalStep::where('payroll_id', $payroll->id)->where('label', 'Finance Sign-off')->first();

    expect(fn () => app(PayrollService::class)->approveStep($financeStep, $financeApprover))
        ->toThrow(DomainException::class, 'An earlier step is still pending');
});

test('approving the final step finalizes the payroll exactly like legacy approveFinance', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $hrApprover = User::factory()->create(['role' => 'hr_admin']);
    policyStep('HR Review', 'hr_admin', 1);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('June', $maker));
    $step = PayrollApprovalStep::where('payroll_id', $payroll->id)->first();

    $payroll = app(PayrollService::class)->approveStep($step, $hrApprover);

    expect($payroll->status)->toBe('finalized')
        ->and($payroll->finance_approved_by)->toBe($hrApprover->id)
        ->and($step->fresh()->status)->toBe('approved');
});

test('rejecting a step cascades remaining pending steps to skipped and reverts to draft', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $hrApprover = User::factory()->create(['role' => 'hr_admin']);
    User::factory()->create(['role' => 'finance']);
    User::factory()->create(['role' => 'director']);
    policyStep('HR Review', 'hr_admin', 1);
    policyStep('Finance Sign-off', 'finance', 2);
    policyStep('Director Approval', 'director', 3);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('July', $maker));
    $firstStep = PayrollApprovalStep::where('payroll_id', $payroll->id)->where('level', 1)->first();

    $payroll = app(PayrollService::class)->rejectStep($firstStep, $hrApprover, 'Wrong OT figures.');

    expect($payroll->status)->toBe('draft')
        ->and($firstStep->fresh()->status)->toBe('rejected');

    $remaining = PayrollApprovalStep::where('payroll_id', $payroll->id)->where('level', '>', 1)->get();
    expect($remaining->pluck('status')->unique()->all())->toBe(['skipped']);
});

test('maker-checker blocks the preparer from acting on any step, not just the last', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    User::factory()->create(['role' => 'finance']);
    policyStep('HR Review', 'hr_admin', 1);
    policyStep('Finance Sign-off', 'finance', 2);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('August', $maker));
    $firstStep = PayrollApprovalStep::where('payroll_id', $payroll->id)->where('level', 1)->first();

    expect(fn () => app(PayrollService::class)->approveStep($firstStep, $maker))
        ->toThrow(DomainException::class, 'maker-checker');
});

test('the same approver cannot act on two different steps of one chain', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    // One user holding both roles isn't representable via the legacy role column,
    // so simulate it with a super_admin approving step 1 (always eligible) then
    // trying to also take a role-matched step 2.
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    policyStep('HR Review', 'hr_admin', 1);
    policyStep('Super Admin Sign-off', 'super_admin', 2);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('September', $maker));
    $steps = PayrollApprovalStep::where('payroll_id', $payroll->id)->orderBy('level')->get();

    app(PayrollService::class)->approveStep($steps[0], $superAdmin);

    expect(fn () => app(PayrollService::class)->approveStep($steps[1]->fresh(), $superAdmin))
        ->toThrow(DomainException::class, 'already approved an earlier step');
});

test('a specific_user step can only be actioned by that exact user', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $chosen = User::factory()->create(['role' => 'employee', 'name' => 'Chosen Approver']);
    $otherHrAdmin = User::factory()->create(['role' => 'hr_admin']);
    policyStep('CEO Sign-off', 'specific_user', 1, $chosen->id);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('October', $maker));
    $step = PayrollApprovalStep::where('payroll_id', $payroll->id)->first();

    expect(fn () => app(PayrollService::class)->approveStep($step, $otherHrAdmin))
        ->toThrow(DomainException::class, 'not an eligible approver');

    $payroll = app(PayrollService::class)->approveStep($step, $chosen);
    expect($payroll->status)->toBe('finalized');
});

test('super_admin can act on any step regardless of approver_type', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    User::factory()->create(['role' => 'finance']);
    policyStep('Finance Sign-off', 'finance', 1);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('November', $maker));
    $step = PayrollApprovalStep::where('payroll_id', $payroll->id)->first();

    $payroll = app(PayrollService::class)->approveStep($step, $superAdmin);
    expect($payroll->status)->toBe('finalized');
});

test('submitting fails loudly when a specific_user step references a deleted user', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $ghost = User::factory()->create();
    policyStep('CEO Sign-off', 'specific_user', 1, $ghost->id);
    $ghost->delete();

    expect(fn () => app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('December', $maker)))
        ->toThrow(DomainException::class, 'CEO Sign-off');
});

test('submitting fails loudly when a role-type step has zero eligible users', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    policyStep('Director Approval', 'director', 1);
    // No user with role=director exists.

    expect(fn () => app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('January', $maker)))
        ->toThrow(DomainException::class, 'Director Approval');
});

test('legacy approveFinance and rejectFinance refuse a payroll with configured steps', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $finance = User::factory()->create(['role' => 'finance']);
    policyStep('HR Review', 'hr_admin', 1);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('February', $maker));

    expect(fn () => app(PayrollService::class)->approveFinance($payroll, $finance->id))
        ->toThrow(DomainException::class, 'configured approval flow');
    expect(fn () => app(PayrollService::class)->rejectFinance($payroll))
        ->toThrow(DomainException::class, 'configured approval flow');
});

test('approving a step audits the transition', function () {
    $maker = User::factory()->create(['role' => 'hr_admin']);
    $hrApprover = User::factory()->create(['role' => 'hr_admin']);
    policyStep('HR Review', 'hr_admin', 1);

    $payroll = app(PayrollService::class)->submitForFinanceApproval(draftPayrollFor('March', $maker));
    $step = PayrollApprovalStep::where('payroll_id', $payroll->id)->first();

    app(PayrollService::class)->approveStep($step, $hrApprover);

    expect(AuditLog::where('auditable_type', PayrollApprovalStep::class)->where('auditable_id', $step->id)->where('action', 'approved')->exists())->toBeTrue();
});
