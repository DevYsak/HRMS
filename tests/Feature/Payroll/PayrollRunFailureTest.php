<?php

use App\Models\Payroll;
use App\Models\PayrollRunFailure;
use App\Models\User;
use App\Services\PayrollService;

/**
 * A bad generateDraft() run used to just vanish as a toast — nothing recorded
 * it. Now every throw writes a PayrollRunFailure row first (then still
 * rethrows, so existing callers/toasts behave exactly as before).
 */
test('a failed generateDraft run is recorded with the reason', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $payroll = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'finalized', 'processed_by' => $admin->id, 'processed_at' => now(),
        'finance_approved_by' => $admin->id, 'finance_approved_at' => now(),
    ]);
    app(PayrollService::class)->lock($payroll, $admin->id);

    expect(fn () => app(PayrollService::class)->generateDraft('July', 2026, 'cycle_a', $admin->id))
        ->toThrow(DomainException::class);

    $failure = PayrollRunFailure::where('month', 'July')->where('year', 2026)->where('cycle', 'cycle_a')->first();

    expect($failure)->not->toBeNull()
        ->and($failure->attempted_by)->toBe($admin->id)
        ->and($failure->reason)->toContain('locked')
        ->and($failure->context['exception'])->toBe(DomainException::class);
});

test('a successful generateDraft run does not create a failure row', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    app(PayrollService::class)->generateDraft('August', 2026, 'cycle_a', $admin->id);

    expect(PayrollRunFailure::where('month', 'August')->where('year', 2026)->exists())->toBeFalse();
});
