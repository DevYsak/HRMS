<?php

use App\Livewire\Payroll\Process;
use App\Models\Payroll;
use App\Models\User;
use Livewire\Livewire;

/**
 * The old "Finalize & Pay" button on this screen claimed (via its confirm text)
 * that clicking it would mark payslips paid and notify employees — it never did
 * either; only Finance Approval can. This locks in the honest replacement: a
 * real link to Finance Approval for users who can approve, a plain status badge
 * for users who can't, and no button that lies about what it does.
 */
test('a user who can approve finance sees a real link to the finance approval page', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'pending_finance', 'processed_by' => $admin->id, 'processed_at' => now(),
    ]);

    Livewire::actingAs($admin)->test(Process::class)
        ->assertSee('Go to Finance Approval')
        ->assertDontSee('Finalize & Pay')
        ->assertDontSeeHtml('wire:click="finalize"');
});

test('a run-payroll-only user sees a plain status badge, not an actionable button', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'pending_finance', 'processed_by' => $hr->id, 'processed_at' => now(),
    ]);

    Livewire::actingAs($hr)->test(Process::class, ['month' => 'July', 'year' => 2026])
        ->assertSee('Awaiting Finance Approval')
        ->assertDontSee('Go to Finance Approval')
        ->assertDontSee('Finalize & Pay');
});
