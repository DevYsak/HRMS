<?php

use App\Livewire\Payroll\FinanceApproval;
use App\Models\Payroll;
use App\Models\User;
use Livewire\Livewire;

/**
 * PayrollService::rejectFinance() always accepted an optional $note, but
 * FinanceApproval::reject() never passed one — every rejection reason was
 * silently discarded. This locks in the fix: reject() is now a two-step
 * openReject()/confirmReject() flow that carries a real reason into
 * payrolls.finance_note.
 */
function pendingPayroll(User $admin): Payroll
{
    return Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'pending_finance', 'processed_by' => $admin->id, 'processed_at' => now(),
    ]);
}

test('rejecting a payroll with a reason stores it and returns the payroll to draft', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = pendingPayroll($admin);

    Livewire::actingAs($admin)->test(FinanceApproval::class)
        ->call('openReject', $payroll->id)
        ->assertSet('rejectingId', $payroll->id)
        ->set('rejectReason', 'Missing OT approvals for Engineering.')
        ->call('confirmReject');

    $payroll->refresh();

    expect($payroll->status)->toBe('draft')
        ->and($payroll->finance_note)->toBe('Missing OT approvals for Engineering.')
        ->and($payroll->finance_approved_by)->toBeNull();
});

test('rejecting with a blank reason leaves the note null instead of an empty string', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = pendingPayroll($admin);

    Livewire::actingAs($admin)->test(FinanceApproval::class)
        ->call('openReject', $payroll->id)
        ->set('rejectReason', '   ')
        ->call('confirmReject');

    expect($payroll->refresh()->finance_note)->toBeNull();
});

test('confirming reject on an already-resolved payroll is a no-op with a warning toast', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = pendingPayroll($admin);
    $payroll->update(['status' => 'finalized']);

    Livewire::actingAs($admin)->test(FinanceApproval::class)
        ->call('openReject', $payroll->id)
        ->call('confirmReject');

    expect($payroll->refresh()->status)->toBe('finalized');
});
