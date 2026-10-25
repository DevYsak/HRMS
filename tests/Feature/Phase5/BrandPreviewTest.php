<?php

use App\Enums\UserRole;
use App\Livewire\Settings\UiThemeSettings;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * QA-F-082 · the Brand & Layout live preview always shows light mode: in
 * dark mode its header, sidebar and inputs must not be repainted dark (the
 * wordmark was unreadable), and it names the configured brand.
 */
test('the preview shows the configured brand name instead of a hard-coded one', function () {
    Company::query()->delete();
    Company::create(['name' => 'Preview Brand Ltd']);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]))
        ->test(UiThemeSettings::class)
        ->assertSeeHtml('data-preview-brand>Preview Brand Ltd</span>')
        ->assertDontSeeHtml('>NEXCORE</span>');
});

test('dark-mode overrides leave the light-mode preview alone', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('.dark .bg-white:not([data-ui-preview] *)')
        ->and($css)->toContain('.dark .bg-white\/70:not([data-ui-preview] *)')
        ->and(substr_count($css, '[data-ui-preview] *)'))->toBeGreaterThanOrEqual(7)
        ->and($css)->not->toMatch('/^\.dark \.bg-white\s+\{/m');
});
