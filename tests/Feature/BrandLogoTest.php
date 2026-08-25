<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Company::brandLogoUrl() memoises for the life of a request. Laravel flushes
 * that memo between tests, so each case below starts from a clean lookup.
 */
test('the bundled mark is used until a company logo is uploaded', function () {
    expect(Company::brandLogoUrl())->toContain(Company::FALLBACK_LOGO);
});

test('an uploaded company logo replaces the bundled mark everywhere', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/acme.png', 'not-really-a-png');

    Company::factory()->create(['name' => 'Acme Corp', 'logo' => 'branding/acme.png']);

    expect(Company::brandLogoUrl())->toContain('branding/acme.png')
        ->and(Company::brandLogoUrl())->not->toContain(Company::FALLBACK_LOGO)
        ->and(Company::brandName())->toBe('Acme Corp');
});

test('the logo and the name it labels always come from the same company', function () {
    // Factories spawn extra Company rows freely, and multi-company is a real
    // v4 scenario. Two independent queries could pick different rows and label
    // one company's logo with another's name.
    Storage::fake('public');
    Storage::disk('public')->put('branding/first.png', 'x');

    Company::factory()->create(['name' => 'First Company', 'logo' => 'branding/first.png']);
    Company::factory()->create(['name' => 'Second Company', 'logo' => 'branding/second.png']);

    expect(Company::brandName())->toBe('First Company')
        ->and(Company::brandLogoUrl())->toContain('branding/first.png');
});

test('a logo row pointing at a missing file falls back instead of 404ing', function () {
    // The column can outlive the file — a deleted upload, or a database
    // restored without storage. A broken <img> on the login page is worse
    // than the bundled mark.
    Storage::fake('public');
    Company::factory()->create(['logo' => 'branding/deleted.png']);

    expect(Company::brandLogoUrl())->toContain(Company::FALLBACK_LOGO);
});

test('the logo renders with no box and an automatic width', function () {
    $html = $this->blade('<x-brand-logo size="h-12 w-auto" />');

    $html->assertSee('h-12', false)
        ->assertSee('w-auto', false)
        ->assertSee(Company::FALLBACK_LOGO, false);

    // The point of the change: no wrapper chrome around the mark.
    $html->assertDontSee('rounded-xl', false)
        ->assertDontSee('bg-gradient-to-br', false)
        ->assertDontSee('shadow-lg', false);
});

test('the logo links to the given href', function () {
    $this->blade('<x-brand-logo :href="url(\'/dashboard\')" />')
        ->assertSee('href="'.url('/dashboard').'"', false);
});

test('the login page shows the logo and no longer shows the old icon lockup', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee(Company::FALLBACK_LOGO, false)
        // The old Pulse "P" glyph and its boxed wrapper.
        ->assertDontSee('M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z', false)
        ->assertDontSee('bg-white/20 backdrop-blur-sm', false);
});

test('the dashboard sidebar shows the logo with no box and no company name', function () {
    // Created first on purpose: Employee::factory() pulls in Office, Department
    // and JobTitle, each of which spawns its own Company, and the branding
    // accessor deliberately reads the oldest row.
    Company::factory()->create(['name' => 'Conexus Technologies']);

    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $admin->id, 'status' => 'active']);

    $response = $this->withoutVite()->actingAs($admin)->get(route('dashboard'));
    $response->assertOk();

    // Scope every assertion to the brand element. The orange gradient and the
    // company name both legitimately appear elsewhere on the dashboard, so a
    // page-wide assertDontSee would be testing the wrong thing.
    preg_match('/<a[^>]*data-flux-sidebar-brand.*?<\/a>/s', $response->getContent(), $m);
    $brand = $m[0] ?? '';

    expect($brand)->not->toBe('')
        ->and($brand)->toContain(Company::FALLBACK_LOGO)
        // No boxed tile around the mark.
        ->and($brand)->not->toContain('bg-gradient-to-br')
        ->and($brand)->not->toContain('rounded-xl')
        ->and($brand)->not->toContain('shadow-lg')
        // .pulse-sidebar pins a cream background in both themes, so the
        // dark-mode inversion must not apply — white on cream is invisible.
        ->and($brand)->not->toContain('dark:brightness-0');

    // The company name survives only as the img's alt text — never as a text
    // label rendered beside the mark.
    expect($brand)->toContain('alt="Conexus Technologies"')
        ->and(trim(strip_tags($brand)))->toBe('');
});

test('the dark-themed auth pages get a legible white mark', function () {
    // layouts/auth.simple forces class="dark" — a black wordmark would be
    // invisible there, so the inversion is not optional on that surface.
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee(Company::FALLBACK_LOGO, false)
        ->assertSee('dark:brightness-0 dark:invert', false);
});
