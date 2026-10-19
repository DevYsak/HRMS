<?php

use App\Livewire\ExecutiveDashboard;
use App\Livewire\Payroll\Process;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * Phase 5 · Responsive / honest UI. Screens measured at 375px before and
 * after (Playwright, throwaway e2e app): these pin the markup that fixed them.
 */
function rlUser(string $slug): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
}

test('a KPI tile never cuts its label to one letter, and draws a sparkline only from a real series', function () {
    $empty = Blade::render('<x-dashboard.kpi-card label="Pending Compliance" value="77" compare="docs + reviews" :spark="[]" />');
    $real = Blade::render('<x-dashboard.kpi-card label="Total Employees" value="7" :spark="[5, 6, 7]" />');

    expect($empty)->toContain('line-clamp-2')->not->toContain('truncate text-[13px]')->not->toContain('<polyline')
        ->and($empty)->toContain('min-h-[150px]')
        ->and($real)->toContain('<polyline')->toContain('hidden h-10 w-20 shrink-0 overflow-visible sm:block');
});

test('the Executive view shows sparklines only for real six-month series, never an invented trend', function () {
    $html = Livewire::actingAs(rlUser('super_admin'))->test(ExecutiveDashboard::class)->html();

    // Headcount, attendance and payroll payout have real series; approvals, compliance and performance do not.
    expect(substr_count($html, '<polyline'))->toBe(3);
});

test('Run Payroll filter rows wrap on a phone', function () {
    Livewire::actingAs(rlUser('hr_admin'))->test(Process::class)
        ->assertSeeHtml('<div class="flex flex-wrap gap-2">')
        ->assertSeeHtml('mt-8 flex flex-wrap justify-center gap-3');
});

test('QA open-finding rows wrap instead of squeezing the title', function () {
    $this->actingAs(rlUser('super_admin'))->get(route('qa-audit.index'))->assertOk()
        ->assertSee('flex w-full flex-wrap items-center gap-x-3 gap-y-1.5', false)
        ->assertSee('min-w-[12rem] flex-1', false);
});
