<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Role;
use App\Models\UiThemeSetting;
use App\Models\User;
use App\Services\Appearance\UiTheme;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

/**
 * QA-F-060 · one brand: PDFs take Brand & Layout's primary colour (the app's
 * own), never a second company colour; logos and favicons are only rendered
 * when their file exists, falling back to the bundled Nexcore artwork.
 */
beforeEach(function () {
    UiTheme::flush();
    Company::query()->delete();
});

test('documents use the Brand & Layout primary colour, not the company colour field', function () {
    Company::create(['name' => 'Acme', 'primary_color' => '#4f46e5']);
    expect(Company::brandColor())->toBe('#f97316');   // the default design

    UiThemeSetting::current()->update(['settings' => ['primary_light' => '#0f766e']]);
    UiTheme::flush();

    expect(Company::brandColor())->toBe('#0f766e')
        ->and(view('pdf.partials.payslip-styles')->render())->toContain('#0f766e')->not->toContain('#4f46e5');
});

test('every PDF reads the shared brand colour and the checked logo path', function () {
    foreach (['pdf/experience-letter', 'pdf/increment-letter', 'pdf/reports/payroll-summary', 'pdf/reports/warning-letter'] as $view) {
        $source = file_get_contents(resource_path("views/{$view}.blade.php"));

        expect($source)->toContain('Company::brandColor()')->toContain('Company::brandLogoPath()')
            ->not->toContain('{{ $company->primary_color')
            ->not->toContain("public_path('storage/'.\$company->logo)")
            ->not->toContain("storage_path('app/public/'");
    }
});

test('a logo is used only when its file exists, otherwise the bundled mark', function () {
    Storage::fake('public');
    $company = Company::create(['name' => 'Acme', 'logo' => 'branding/missing.png']);

    // A missing upload is never rendered. The bundled PNG needs GD in dompdf:
    // without it the document goes without a logo instead of failing.
    expect(Company::brandLogoPath())->toBe(extension_loaded('gd') ? public_path(Company::FALLBACK_LOGO) : null);

    Storage::disk('public')->put('branding/acme.png', 'x');
    $company->update(['logo' => 'branding/acme.png']);
    Once::flush();   // brandCompany() is memoised per request

    expect(Company::brandLogoPath())->toBe(Storage::disk('public')->path('branding/acme.png'))
        ->and(Company::brandFaviconUrl('branding/missing.ico'))->toBeNull()
        ->and(Company::brandFaviconUrl('branding/acme.png'))->toContain('branding/acme.png');
});

test('the page head never links a favicon whose file is missing', function () {
    Storage::fake('public');
    Company::create(['name' => 'Acme', 'favicon' => 'branding/gone.ico']);
    cache()->forget('company.favicon');

    $head = view('partials.head')->render();

    expect($head)->not->toContain('branding/gone.ico')->toContain('/favicon.ico');
});

test('General settings points to Brand & Layout for colours instead of a second picker', function () {
    Company::create(['name' => 'Acme']);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]))
        ->test('pages::settings.general')
        ->assertSeeHtml('data-brand-colours-note')
        ->assertSee(route('settings.ui-theme'), false)
        ->assertDontSee('Secondary Color');
});
