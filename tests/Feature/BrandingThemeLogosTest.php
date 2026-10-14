<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Branding\SvgSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Logos per theme and the folded-sidebar icon, managed in Settings → General.
 * An empty slot falls back to the bundled Nexcore artwork, except that an
 * uploaded logo is never silently replaced by Nexcore's.
 */
const BRANDING_SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><style>.a{fill:#c00}</style><path class="a" d="M0 0h20v20H0z"/></svg>';

test('with nothing uploaded every slot shows the bundled Nexcore artwork', function () {
    expect(Company::brandLogoUrl())->toContain(Company::FALLBACK_LOGO)
        ->and(Company::brandLogoDarkUrl())->toContain(Company::FALLBACK_LOGO_DARK)
        ->and(Company::brandMarkUrl())->toContain(Company::FALLBACK_MARK)
        ->and(Company::brandMarkDarkUrl())->toContain(Company::FALLBACK_MARK_DARK);

    foreach ([Company::FALLBACK_LOGO_DARK, Company::FALLBACK_MARK, Company::FALLBACK_MARK_DARK] as $file) {
        expect(public_path($file))->toBeFile();
    }
});

test('an uploaded logo without a dark version is repainted white, never swapped for Nexcore', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/acme.png', 'x');
    Company::factory()->create(['logo' => 'branding/acme.png']);

    expect(Company::brandLogoDarkUrl())->toBeNull()
        ->and(Company::brandMarkUrl())->toBeNull()
        ->and(Company::brandMarkDarkUrl())->toBeNull();

    $this->blade('<x-brand-logo size="h-10 w-auto" />')
        ->assertSee('branding/acme.png', false)
        ->assertSee('dark:brightness-0 dark:invert', false)
        ->assertDontSee(Company::FALLBACK_LOGO_DARK, false);

    // No icon at all: the company's initial stands in on the folded rail.
    $this->blade('<x-brand-logo variant="mark" size="size-7" />')
        ->assertSee('role="img"', false)
        ->assertDontSee(Company::FALLBACK_MARK, false);
});

test('uploaded dark artwork is shown only in dark mode', function () {
    Storage::fake('public');
    foreach (['light.png', 'dark.png', 'mark.png', 'mark-dark.png'] as $file) {
        Storage::disk('public')->put("branding/{$file}", 'x');
    }
    Company::factory()->create([
        'logo' => 'branding/light.png', 'logo_dark' => 'branding/dark.png',
        'logo_mark' => 'branding/mark.png', 'logo_mark_dark' => 'branding/mark-dark.png',
    ]);

    $logo = $this->blade('<x-brand-logo size="h-10 w-auto" />');
    $logo->assertSee('branding/light.png', false)->assertSee('branding/dark.png', false)
        ->assertSee('dark:hidden', false)->assertSee('hidden dark:block', false)
        ->assertDontSee('dark:invert', false);

    $this->blade('<x-brand-logo variant="mark" size="size-7" />')
        ->assertSee('branding/mark.png', false)
        ->assertSee('branding/mark-dark.png', false);
});

test('an admin uploads an SVG logo for each theme and it is stored clean', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    Company::factory()->create(['name' => 'Conexus', 'email' => 'hr@conexus.test']);

    $hostile = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><path d="M0 0h1v1z" onclick="x()"/></svg>';

    Livewire::test('pages::settings.general')
        ->set('logo_dark', UploadedFile::fake()->createWithContent('dark.svg', $hostile))
        ->set('logo_mark', UploadedFile::fake()->createWithContent('mark.svg', BRANDING_SAFE_SVG))
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company = Company::oldest('id')->first();
    $stored = Storage::disk('public')->get($company->logo_dark);

    expect($company->logo_dark)->toEndWith('.svg')
        ->and($stored)->toContain('<path d="M0 0h1v1z"/>')
        ->and($stored)->not->toContain('script')
        ->and($stored)->not->toContain('onload')
        ->and($stored)->not->toContain('onclick')
        ->and(Storage::disk('public')->get($company->logo_mark))->toContain('.a{fill:#c00}');
});

test('an SVG with nothing safe in it is refused with a clear message', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    Company::factory()->create(['name' => 'Conexus', 'email' => 'hr@conexus.test']);

    Livewire::test('pages::settings.general')
        ->set('logo', UploadedFile::fake()->createWithContent('xxe.svg', '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>'))
        ->call('updateCompany')
        ->assertHasErrors('logo');

    expect(Company::oldest('id')->first()->logo)->toBeNull();
});

test('"Use default" deletes the upload and brings the bundled artwork back', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/dark.png', 'x');
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    Company::factory()->create(['name' => 'Conexus', 'email' => 'hr@conexus.test', 'logo_dark' => 'branding/dark.png']);

    Livewire::test('pages::settings.general')->call('removeBrandFile', 'logo_dark');

    expect(Company::oldest('id')->first()->logo_dark)->toBeNull();
    Storage::disk('public')->assertMissing('branding/dark.png');
});

test('only a branding slot can be removed, and only by someone who manages settings', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    Company::factory()->create(['name' => 'Conexus', 'email' => 'hr@conexus.test']);

    Livewire::test('pages::settings.general')->call('removeBrandFile', 'email')->assertNotFound();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test('pages::settings.general')
        ->assertForbidden();
});

test('the sanitizer keeps drawing and CSS but drops everything that can run or fetch', function () {
    $clean = app(SvgSanitizer::class)->clean(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="a()">'
        .'<script>b()</script><foreignObject><div/></foreignObject>'
        .'<style>.k{fill:#111}</style><rect class="k" width="5" height="5"/>'
        .'<use href="https://evil.test/x.svg#a"/><use href="#ok"/>'
        .'<path d="M0 0" style="fill:url(https://evil.test/f)"/></svg>'
    );

    expect($clean)->toContain('viewBox="0 0 10 10"')
        ->toContain('.k{fill:#111}')
        ->toContain('<rect class="k" width="5" height="5"/>')
        ->toContain('<use href="#ok"/>')
        ->not->toContain('onload')
        ->not->toContain('script')
        ->not->toContain('foreignObject')
        ->not->toContain('evil.test');

    expect(app(SvgSanitizer::class)->clean('<svg><style>@import url(https://evil.test/a.css);</style></svg>'))
        ->not->toContain('evil.test')
        ->and(app(SvgSanitizer::class)->clean('<html/>'))->toBeNull()
        ->and(app(SvgSanitizer::class)->clean('not xml'))->toBeNull();
});

test('the sidebar shows the wordmark open and the square icon on the folded rail', function () {
    Company::factory()->create(['name' => 'Conexus Technologies']);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $hr->id, 'status' => 'active']);

    $html = $this->withoutVite()->actingAs($hr)->get(route('dashboard'))->assertOk()->getContent();

    preg_match('/<div data-pulse-sidebar-brand-row.*?<\/ui-sidebar-toggle>/s', $html, $m);
    $row = $m[0] ?? '';

    expect($row)->not->toBe('')
        ->toContain(Company::FALLBACK_LOGO)
        ->toContain(Company::FALLBACK_LOGO_DARK)
        ->toContain(Company::FALLBACK_MARK)
        ->toContain(Company::FALLBACK_MARK_DARK)
        ->toContain('data-flux-sidebar-collapse')
        // The old role chip under the logo is gone.
        ->not->toContain('tracking-wider');
});
