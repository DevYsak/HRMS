<?php

use App\Livewire\Payroll\Overview;
use App\Models\Payroll;
use App\Models\PayrollRunFailure;
use App\Models\SalaryCycle;
use App\Models\User;
use Livewire\Livewire;

/**
 * Phase 3: the dashboard previously used bespoke one-off markup and only
 * showed recent payrolls + payout totals. This locks in the redesign's real
 * widgets (employee/status counts, failed-run tracking, upcoming cycle).
 */
test('a user without run_payroll permission cannot open the payroll overview', function () {
    $employee = User::factory()->create(['role' => 'employee']);

    Livewire::actingAs($employee)->test(Overview::class)->assertForbidden();
});

test('the overview shows employee, processed, pending, draft and failed counts', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    Payroll::create(['month' => 'July', 'year' => now()->year, 'cycle' => 'cycle_a', 'status' => 'draft', 'processed_by' => $admin->id]);
    Payroll::create(['month' => 'June', 'year' => now()->year, 'cycle' => 'cycle_a', 'status' => 'finalized', 'processed_by' => $admin->id]);
    PayrollRunFailure::create([
        'month' => 'May', 'year' => now()->year, 'cycle' => 'cycle_a',
        'attempted_by' => $admin->id, 'reason' => 'No statutory rule configured.',
    ]);

    Livewire::actingAs($admin)->test(Overview::class)
        ->assertSet('filterYear', '')
        ->assertViewHas('processedCount', 1)
        ->assertViewHas('draftCount', 1)
        ->assertViewHas('failedCount', 1)
        ->assertSee('Payroll Calendar')
        ->assertSee('Upcoming Payroll Cycle')
        ->assertSee('Recent Payroll Activity');
});

test('the upcoming cycle widget computes the next pay date from an active salary cycle', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    SalaryCycle::create(['name' => 'Cycle A', 'slug' => 'cycle_a', 'start_day' => 1, 'end_day' => 31, 'pay_day' => 5, 'is_default' => true, 'is_active' => true]);

    Livewire::actingAs($admin)->test(Overview::class)
        ->assertSee('Cycle A')
        ->assertViewHas('upcomingCycles', fn ($cycles) => $cycles->pluck('name')->contains('Cycle A') && $cycles->first()['days'] >= 0);
});

test('an inactive salary cycle is excluded from the upcoming-cycle widget', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    SalaryCycle::create(['name' => 'Retired Cycle', 'slug' => 'retired', 'start_day' => 1, 'end_day' => 31, 'pay_day' => 10, 'is_default' => false, 'is_active' => false]);

    Livewire::actingAs($admin)->test(Overview::class)
        ->assertDontSee('Retired Cycle');
});
