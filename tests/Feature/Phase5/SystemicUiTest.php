<?php

use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\AttendanceReports;
use App\Livewire\Payroll\Overview;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * Phase 5 · systemic UI: dark-mode fallbacks, wide-table scroll hints, a
 * global busy bar and one page-title style. The JS behaviour was checked in
 * a browser (Playwright, throwaway e2e app); these pin the wiring.
 */
function suUser(string $slug): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
}

test('a light background or border without a dark partner falls back to the dark tokens', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('.dark .bg-white:not([class*="dark:bg-"])')
        ->and($css)->toContain('.border-orange-100\/70')
        ->and($css)->toContain(':not([class*="dark:border"])')
        // The page canvas itself is never repainted by the fallback.
        ->and($css)->toContain(':not([class*="dark:bg-"], body, [data-flux-main])');
});

test('wide tables get a scroll hint and slow updates a busy bar', function () {
    $app = file_get_contents(resource_path('js/app.js'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($app)->toContain("import './table-scroll';")->toContain("import './busy-indicator';")
        ->and(file_get_contents(resource_path('js/table-scroll.js')))->toContain("toggleAttribute('data-scroll-right'")
        ->and(file_get_contents(resource_path('js/busy-indicator.js')))->toContain("hook('commit'")
        ->and($css)->toContain('[data-scroll-right] {')
        ->and($css)->toContain('#pulse-busy[data-active]')
        ->and($css)->toContain('prefers-reduced-motion');
});

test('plain page titles share one style and are real h1 headings', function () {
    $hr = suUser('hr_admin');

    Livewire::actingAs($hr)->test(AttendanceReports::class)->assertSeeHtml('<h1 class="pulse-page-title">Attendance Reports</h1>');
    Livewire::actingAs($hr)->test(AllAttendance::class)->assertSeeHtml('<h1 class="pulse-page-title">Employees Attendance</h1>');
    Livewire::actingAs($hr)->test(Overview::class)->assertSeeHtml('pulse-page-title')->assertSeeHtml('<h1');
});

test('All Attendance tiles keep their full label', function () {
    Livewire::actingAs(suUser('hr_admin'))->test(AllAttendance::class)
        ->assertSeeHtml('line-clamp-2 break-words text-[9px] font-bold uppercase leading-snug tracking-wide text-zinc-400">Total Employees');
});

test('pages the role journeys found overflowing on phones wrap or stack', function () {
    $view = fn (string $name) => file_get_contents(resource_path("views/livewire/{$name}.blade.php"));

    expect($view('performance/kpi-templates'))->toContain('flex flex-col gap-6 lg:flex-row lg:items-start')->toContain('w-full shrink-0 space-y-2 lg:w-72')
        ->and($view('employees/org-chart'))->toContain('flex flex-wrap items-center justify-between gap-3')
        ->and($view('documents/document-manager'))->toContain('flex w-fit max-w-full flex-wrap items-center gap-1')
        ->and($view('performance/kpi-dashboard'))->toContain('flex flex-wrap items-center gap-3')
        ->and($view('attendance/biometric-summary'))->toContain('flex flex-wrap items-center gap-2');
});
