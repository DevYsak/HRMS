<?php

use App\Livewire\Payroll\AuditTrail;
use App\Models\AuditLog;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PayrollService;
use Livewire\Livewire;

test('the payroll audit trail only shows payroll and payslip events, never other models', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    // A Payroll create fires via the observer just by existing.
    $payroll = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'draft', 'processed_by' => $admin->id,
    ]);

    // An unrelated model's audit entry — inserted directly rather than via a
    // real model, so this test only depends on AuditLog's own schema — must
    // never leak into a view that's supposed to be Payroll/Payslip-only.
    AuditLog::create([
        'user_id' => $admin->id, 'action' => 'created',
        'auditable_type' => User::class, 'auditable_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)->test(AuditTrail::class)
        ->assertSee('Payroll')
        ->assertSee((string) $payroll->id)
        ->assertDontSee('App\Models\User');
});

test('a user without view_payroll permission cannot open the payroll audit trail', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    Livewire::actingAs($employee)->test(AuditTrail::class)
        ->assertForbidden();
});

test('filtering by action narrows the results', function () {
    $maker = User::factory()->create(['role' => 'super_admin']);
    $checker = User::factory()->create(['role' => 'super_admin']);
    $payroll = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'pending_finance', 'processed_by' => $maker->id, 'processed_at' => now(),
    ]);

    app(PayrollService::class)->approveFinance($payroll, $checker->id);

    // "Approved" also legitimately appears in the filter dropdown's own option
    // list regardless of the active filter, so assert on row content/emptiness
    // instead of a bare assertDontSee — the dropdown chrome would false-fail it.
    Livewire::actingAs($maker)->test(AuditTrail::class)
        ->set('action', 'approved')
        ->assertSee('Approved')
        ->set('action', 'locked')
        ->assertSee('No payroll audit entries match your filters');
});

test('a record\'s full timeline is returned oldest first', function () {
    $maker = User::factory()->create(['role' => 'super_admin']);
    $checker = User::factory()->create(['role' => 'super_admin']);
    $payroll = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'pending_finance', 'processed_by' => $maker->id, 'processed_at' => now(),
    ]);
    app(PayrollService::class)->approveFinance($payroll, $checker->id);
    app(PayrollService::class)->lock($payroll->fresh(), $checker->id);

    $component = Livewire::actingAs($maker)->test(AuditTrail::class);
    $log = AuditLog::where('auditable_type', Payroll::class)->where('auditable_id', $payroll->id)->orderBy('id')->first();

    $steps = $component->instance()->timelineStepsFor($log);
    $labels = collect($steps)->pluck('label')->all();

    expect($labels)->toContain('Created')
        ->and(array_search('Created', $labels))->toBeLessThan(array_search('Approved', $labels))
        ->and(array_search('Approved', $labels))->toBeLessThan(array_search('Locked', $labels));
});
