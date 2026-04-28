<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
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
