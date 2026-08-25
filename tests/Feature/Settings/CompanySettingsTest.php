<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);
    $this->company = Company::factory()->create();
});

test('company settings page can be rendered', function () {
    $this->actingAs($this->user)
        ->get(route('settings.general'))
        ->assertStatus(200)
        ->assertSee('Company Settings');
});

test('company details can be updated', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::settings.general')
        ->set('name', 'New Company Name')
        ->set('email', 'new@company.com')
        ->call('updateCompany')
        ->assertHasNoErrors();

    $this->assertEquals('New Company Name', $this->company->fresh()->name);
});

test('the company logo can be uploaded', function () {
    Storage::fake('public');
    $this->actingAs($this->user);

    // create() with an explicit mime avoids the GD extension that image() needs.
    Livewire::test('pages::settings.general')
        ->set('logo', UploadedFile::fake()->create('logo.png', 120, 'image/png'))
        ->call('updateCompany')
        ->assertHasNoErrors();

    $logo = $this->company->fresh()->logo;
    expect($logo)->not->toBeNull();
    Storage::disk('public')->assertExists($logo);
});

test('the favicon can be uploaded and clears the head cache', function () {
    Storage::fake('public');
    cache()->put('company.favicon', 'old/favicon.png', now()->addHour());
    $this->actingAs($this->user);

    Livewire::test('pages::settings.general')
        ->set('favicon', UploadedFile::fake()->create('favicon.png', 20, 'image/png'))
        ->call('updateCompany')
        ->assertHasNoErrors();

    $favicon = $this->company->fresh()->favicon;
    expect($favicon)->not->toBeNull();
    Storage::disk('public')->assertExists($favicon);
    // Stale cached path must be dropped so the new icon shows.
    expect(cache()->has('company.favicon'))->toBeFalse();
});

test('a non-image logo is rejected', function () {
    Storage::fake('public');
    $this->actingAs($this->user);

    Livewire::test('pages::settings.general')
        ->set('logo', UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf'))
        ->call('updateCompany')
        ->assertHasErrors('logo');
});

test('the branding upload fields render on the settings page', function () {
    $this->actingAs($this->user)
        ->get(route('settings.general'))
        ->assertOk()
        ->assertSee('Company Logo')
        ->assertSee('Favicon');
});

test('offices can be added', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::settings.general')
        ->set('officeName', 'New Office')
        ->set('officeCity', 'New York')
        ->set('officeCountry', 'USA')
        ->call('saveOffice')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('offices', [
        'name' => 'New Office',
        'city' => 'New York',
    ]);
});

test('departments can be added', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::settings.general')
        ->set('deptName', 'Engineering')
        ->set('deptCode', 'ENG')
        ->call('saveDepartment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('departments', [
        'name' => 'Engineering',
        'code' => 'ENG',
    ]);
});
