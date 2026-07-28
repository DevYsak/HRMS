<?php

use App\Livewire\Payroll\FinanceApproval;
use App\Livewire\Payroll\Process;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PayrollService;
use Livewire\Livewire;

/**
 * Phase 1 governance: separation of duties (the same user who generated a
 * payroll cannot also approve it into finalized) and an explicit lock state
 * once a payroll is finalized, so it can't be silently regenerated.
 */
function pendingPayrollFor(User $processor): Payroll
{
    return Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'pending_finance', 'processed_by' => $processor->id, 'processed_at' => now(),
    ]);
}

function finalizedPayrollFor(User $processor, ?User $approver = null): Payroll
{
    return Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'finalized', 'processed_by' => $processor->id, 'processed_at' => now(),
        'finance_approved_by' => ($approver ?? $processor)->id, 'finance_approved_at' => now(),
    ]);
}

test('the user who processed a payroll cannot also approve it', function () {
    $maker = User::factory()->create(['role' => 'super_admin']);
    $payroll = pendingPayrollFor($maker);

    expect(fn () => app(PayrollService::class)->approveFinance($payroll, $maker->id))
        ->toThrow(DomainException::class, 'You processed this payroll');

    expect($payroll->refresh()->status)->toBe('pending_finance');
});

test('a different user can approve a payroll they did not process', function () {
    $maker = User::factory()->create(['role' => 'super_admin']);
    $checker = User::factory()->create(['role' => 'super_admin']);
    $payroll = pendingPayrollFor($maker);

    $result = app(PayrollService::class)->approveFinance($payroll, $checker->id);

    expect($result->status)->toBe('finalized')
        ->and($result->finance_approved_by)->toBe($checker->id);
});

test('the finance approval screen surfaces the maker-checker rejection as a toast, not a crash', function () {
    $maker = User::factory()->create(['role' => 'super_admin']);
    $payroll = pendingPayrollFor($maker);

    Livewire::actingAs($maker)->test(FinanceApproval::class)
        ->call('approve', $payroll->id)
        ->assertOk();

    expect($payroll->refresh()->status)->toBe('pending_finance');
});

test('locking a finalized payroll records who and when', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = finalizedPayrollFor($admin);

    $locked = app(PayrollService::class)->lock($payroll, $admin->id);

    expect($locked->isLocked())->toBeTrue()
        ->and($locked->locked_by)->toBe($admin->id)
        ->and($locked->locked_at)->not->toBeNull();
});

test('a draft payroll cannot be locked', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'draft', 'processed_by' => $admin->id,
    ]);

    expect(fn () => app(PayrollService::class)->lock($payroll, $admin->id))
        ->toThrow(DomainException::class, 'Only a finalized payroll can be locked.');
});

test('an already-locked payroll cannot be locked again', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = finalizedPayrollFor($admin);
    app(PayrollService::class)->lock($payroll, $admin->id);

    expect(fn () => app(PayrollService::class)->lock($payroll->fresh(), $admin->id))
        ->toThrow(DomainException::class, 'already locked');
});

test('unlocking clears the lock so the payroll can be regenerated again', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = finalizedPayrollFor($admin);
    app(PayrollService::class)->lock($payroll, $admin->id);

    $unlocked = app(PayrollService::class)->unlock($payroll->fresh());

    expect($unlocked->isLocked())->toBeFalse()
        ->and($unlocked->locked_by)->toBeNull();
});

test('generateDraft refuses to touch a locked payroll', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = finalizedPayrollFor($admin);
    app(PayrollService::class)->lock($payroll, $admin->id);

    expect(fn () => app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id))
        ->toThrow(DomainException::class, 'locked');
});

test('a user without lock_payroll permission is forbidden from locking via the Process screen', function () {
    // 'finance' can run payroll (so it legitimately reaches this screen) but was
    // deliberately not granted lock_payroll — only super_admin/hr_admin were.
    $finance = User::factory()->create(['role' => 'finance']);
    $admin = User::factory()->create(['role' => 'super_admin']);
    finalizedPayrollFor($admin);

    Livewire::actingAs($finance)->test(Process::class)
        ->call('lockPayroll')
        ->assertForbidden();
});
