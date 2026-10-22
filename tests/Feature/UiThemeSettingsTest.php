<?php

use App\Enums\UserRole;
use App\Livewire\Settings\UiThemeSettings;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\UiThemeSetting;
use App\Models\User;
use App\Services\Appearance\UiTheme;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function uiThemeUser(string $slug = 'super_admin', UserRole $role = UserRole::SuperAdmin): User
{
    test()->seed(RolesAndPermissionsSeeder::class);

    return User::factory()->create(['role' => $role, 'role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
}

/** @param  array<string, mixed>  $values */
function uiThemeSave(array $values): void
{
    UiThemeSetting::current()->update(['settings' => $values]);
}

beforeEach(fn () => UiTheme::flush());

test('the default design writes no theme stylesheet, so pages are unchanged', function () {
    $theme = app(UiTheme::class);

    expect($theme->css())->toBe('')
        ->and($theme->fontUrl())->toBeNull()
        ->and($theme->current())->toBe(UiTheme::defaults());

    $this->actingAs(uiThemeUser())->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('id="ui-theme"', false);
});

test('a saved font changes the global font token and loads the font', function () {
    uiThemeSave(['font_family' => 'poppins', 'font_size_base' => 16]);

    $css = app(UiTheme::class)->css();

    expect($css)->toContain('--font-sans:')->toContain('Poppins')
        // Every text-* token scales with the base size (14 → 16).
        ->toContain('--text-sm:'.round(0.875 * 16 / 14, 4).'rem')
        ->and(app(UiTheme::class)->fontUrl())->toContain('fonts.googleapis.com')->toContain('Poppins');

    $this->actingAs(uiThemeUser())->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('<style id="ui-theme">', false)
        ->assertSee('family=Poppins', false);
});

test('sidebar width and collapsed width become global variables', function () {
    uiThemeSave(['sidebar_width' => 320, 'sidebar_collapsed_width' => 72, 'sidebar_density' => 'compact']);

    expect(app(UiTheme::class)->css())
        ->toContain('--ui-sidebar-width:320px')
        ->toContain('--ui-sidebar-collapsed-width:72px')
        ->toContain('--ui-sidebar-item-height:28px');
});

test('the sidebar width only applies to the desktop sidebar, so the mobile drawer stays usable', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // Read only by the desktop-only Flux attribute…
    preg_match_all('/^.*var\(--ui-sidebar-width.*$/m', $css, $uses);
    expect($uses[0])->not->toBeEmpty();
    foreach ($uses[0] as $line) {
        expect($line)->toContain('[data-flux-sidebar-on-desktop]');
    }

    // …and menu text / icon / item sizes sit inside the desktop media query.
    $desktopBlock = str($css)->after('Sidebar menu text / icons / item height')->before('@layer components')->toString();
    expect($desktopBlock)->toContain('@media (min-width: 1024px)')
        ->toContain('var(--ui-sidebar-text-size')
        ->toContain('var(--ui-sidebar-icon-size');

    // The generated theme never sizes the sidebar element itself.
    uiThemeSave(['sidebar_width' => 360]);
    expect(app(UiTheme::class)->css())->not->toContain('.pulse-sidebar');
});

test('card radius and padding feed the shared card components', function () {
    uiThemeSave(['card_radius' => 4, 'card_padding' => 28, 'badge_radius' => 'square', 'shadow_strength' => 'none']);

    expect(app(UiTheme::class)->css())
        ->toContain('--ui-card-radius:4px')
        ->toContain('--ui-card-padding:28px')
        ->toContain('--ui-badge-radius:2px')
        ->toContain('--ui-card-shadow:none');

    // The shared components read those variables (current design as fallback).
    $card = Blade::render('<x-pulse.card title="Team">Body</x-pulse.card>');
    expect($card)->toContain('ui-surface')->toContain('ui-card-pad');
    expect(Blade::render('<x-pulse.badge>On time</x-pulse.badge>'))->toContain('ui-badge-shape');
    expect(file_get_contents(resource_path('css/app.css')))
        ->toContain('border-radius: var(--ui-card-radius, 1rem)')
        ->toContain('padding: var(--ui-card-padding, 1.25rem)');
});

test('colours update the brand palette, surfaces and dark mode separately', function () {
    uiThemeSave([
        'primary_light' => '#2563EB',
        'surface_light' => '#fefefe',
        'background_dark' => '#000000',
        'border_dark' => '#334155',
    ]);

    $css = app(UiTheme::class)->css();

    expect($css)
        // Primary replaces the brand/orange/accent tokens used across the panel.
        ->toContain('--color-brand-500:#2563eb')
        ->toContain('--color-orange-500:#2563eb')
        ->toContain('--color-accent:#2563eb')
        ->toContain('--ui-surface:#fefefe')
        ->toContain('.dark{--ui-border-dark:#334155;}')
        ->toContain('.dark body, .dark [data-flux-main] { background-color: #000000; }');

    // A light colour never leaks into dark mode.
    expect(str($css)->after('.dark{')->before('}')->toString())->not->toContain('#fefefe');

    expect(UiTheme::palette('#2563eb'))->toHaveKeys([50, 100, 500, 900, 950])
        ->and(UiTheme::palette('#2563eb')[500])->toBe('#2563eb');
});

test('invalid or missing stored values fall back to the default design', function () {
    uiThemeSave([
        'sidebar_width' => 9999,
        'font_family' => 'comic-sans',
        'primary_light' => 'red; } body { display:none',
        'card_radius' => 'abc',
        'unknown_key' => 'x',
    ]);

    expect(app(UiTheme::class)->current())->toBe(UiTheme::defaults())
        ->and(app(UiTheme::class)->css())->toBe('');

    // A broken table / cache never breaks the page head.
    Cache::forever(UiTheme::VALUES_CACHE_KEY, 'not-an-array');
    Cache::forget(UiTheme::CACHE_KEY);
    expect(app(UiTheme::class)->current())->toBe(UiTheme::defaults());
});

test('admins see every tab and a live preview of unsaved values', function () {
    $admin = uiThemeUser();

    $this->actingAs($admin)->get(route('settings.ui-theme'))
        ->assertOk()
        ->assertSee('Brand & Layout')
        ->assertSee(['Branding', 'Typography', 'Layout', 'Sidebar', 'Components', 'Theme']);

    Livewire::actingAs($admin)->test(UiThemeSettings::class)
        ->call('setTab', 'sidebar')
        ->assertSee('Sidebar width')
        ->set('values.sidebar_width', 300)
        ->set('values.card_radius', 6)
        // The preview is a miniature: the sidebar is drawn at 62% (300px → 186px).
        ->assertSee('--pv-sidebar-width:186px', false)
        ->assertSee('--ui-card-radius:6px', false)
        ->assertSee('2 settings differ from the default design');

    // Previewing saves nothing.
    expect(app(UiTheme::class)->css())->toBe('');
});

test('saving stores only the changes, is audited and applies everywhere at once', function () {
    $admin = uiThemeUser();
    app(UiTheme::class)->css(); // warm the cache

    Livewire::actingAs($admin)->test(UiThemeSettings::class)
        ->call('setTab', 'layout')
        ->set('values.card_radius', 10)
        ->set('values.button_height', 44)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.ui-theme', ['tab' => 'layout']));

    expect(UiThemeSetting::first()->settings)->toBe(['card_radius' => 10, 'button_height' => 44])
        ->and(UiThemeSetting::first()->updated_by)->toBe($admin->id)
        ->and(app(UiTheme::class)->css())->toContain('--ui-card-radius:10px')
        ->toContain('[data-flux-button].h-10 { height: 44px; }');

    $log = AuditLog::where('event', 'UI_THEME_UPDATED')->firstOrFail();
    expect($log->new_values)->toMatchArray(['card_radius' => 10, 'button_height' => 44]);
});

test('out-of-range values are rejected on save', function () {
    Livewire::actingAs(uiThemeUser())->test(UiThemeSettings::class)
        ->set('values.sidebar_width', 900)
        ->set('values.primary_light', 'orange')
        ->call('save')
        ->assertHasErrors(['values.sidebar_width', 'values.primary_light']);

    expect(UiThemeSetting::first()?->settings ?? [])->toBe([]);
});

test('reset to defaults loads the original design into the form', function () {
    uiThemeSave(['sidebar_width' => 340]);

    Livewire::actingAs(uiThemeUser())->test(UiThemeSettings::class)
        ->assertSet('values.sidebar_width', 340)
        ->call('resetToDefaults')
        ->assertSet('values', UiTheme::defaults())
        ->call('save');

    expect(UiThemeSetting::first()->settings)->toBe([])
        ->and(app(UiTheme::class)->css())->toBe('');
});

test('the branding tab shows the light and dark logos and sidebar icons', function () {
    $company = Company::query()->first() ?? Company::factory()->create();
    $company->forceFill(array_filter([
        'logo' => 'branding/logo-light.png',
        'logo_dark' => Schema::hasColumn('companies', 'logo_dark') ? 'branding/logo-dark.png' : null,
    ]))->save();

    $component = Livewire::actingAs(uiThemeUser())->test(UiThemeSettings::class)
        ->assertSet('tab', 'branding')
        ->assertSee(['Logo — light', 'Logo — dark', 'Sidebar icon — light', 'Sidebar icon — dark', 'Favicon'])
        ->assertSee('branding/logo-light.png');

    if (Schema::hasColumn('companies', 'logo_dark')) {
        $component->assertSee('branding/logo-dark.png');
    }
});

test('only settings managers can open or save brand & layout', function () {
    $employee = uiThemeUser('employee', UserRole::Employee);

    $this->actingAs($employee)->get(route('settings.ui-theme'))->assertForbidden();
    Livewire::actingAs($employee)->test(UiThemeSettings::class)->assertForbidden();
});

test('brand & layout is linked from the settings navigation', function () {
    $this->actingAs(uiThemeUser())->get(route('settings.ui-theme'))
        ->assertOk()
        ->assertSee(route('settings.ui-theme'), false);
});

test('saving on a database that has not been migrated explains what to run instead of failing', function () {
    $this->actingAs(uiThemeUser());
    Schema::drop('ui_theme_settings');

    Livewire::test(UiThemeSettings::class)
        ->call('save')
        ->assertOk()
        ->assertHasErrors('values');

    expect(Schema::hasTable('ui_theme_settings'))->toBeFalse();
});
