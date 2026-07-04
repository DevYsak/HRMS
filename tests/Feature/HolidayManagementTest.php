<?php

use App\Enums\UserRole;
use App\Livewire\Holidays\ManageHolidays;
use App\Models\Employee;
use App\Models\Office;
use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('a regular employee cannot open holiday management', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(ManageHolidays::class)
        ->assertForbidden();
});

test('HR can create a scoped holiday with type and flags', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $office = Office::factory()->create();

    Livewire::actingAs($hr)->test(ManageHolidays::class)
        ->assertOk()
        ->assertSee('Holiday Management')
        ->call('openCreate')
        ->set('form.name', 'Founders Day')
        ->set('form.date', now()->year.'-08-15')
        ->set('form.holiday_type', 'company')
        ->set('form.office_id', $office->id)
        ->set('form.is_recurring', true)
        ->call('save')
        ->assertHasNoErrors();

    $h = PublicHoliday::where('name', 'Founders Day')->firstOrFail();
    expect($h->holiday_type->value)->toBe('company');
    expect($h->office_id)->toBe($office->id);
    expect($h->is_recurring)->toBeTrue();
    expect($h->is_active)->toBeTrue();
});

test('archiving a holiday hides it from the isHoliday check and active list', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $h = PublicHoliday::factory()->create(['date' => now()->toDateString(), 'country' => 'IN', 'is_active' => true]);

    expect(PublicHoliday::isHoliday(Carbon::parse($h->date), 'IN'))->toBeTrue();

    Livewire::actingAs($hr)->test(ManageHolidays::class)->call('toggleArchive', $h->id);

    expect($h->fresh()->is_active)->toBeFalse();
    expect(PublicHoliday::isHoliday(Carbon::parse($h->date), 'IN'))->toBeFalse();
});

test('duplicate creates a copy one year later', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $h = PublicHoliday::factory()->create(['name' => 'Diwali', 'date' => now()->toDateString()]);

    Livewire::actingAs($hr)->test(ManageHolidays::class)->call('duplicate', $h->id);

    $copy = PublicHoliday::where('name', 'Diwali (Copy)')->firstOrFail();
    expect($copy->date->year)->toBe(Carbon::parse($h->date)->year + 1);
});

test('a branch-scoped holiday only applies to employees of that branch', function () {
    $office = Office::factory()->create();
    $otherOffice = Office::factory()->create();
    $inBranch = Employee::factory()->create(['office_id' => $office->id]);
    $otherBranch = Employee::factory()->create(['office_id' => $otherOffice->id]);

    $h = PublicHoliday::factory()->create(['date' => now()->toDateString(), 'office_id' => $office->id]);

    expect($h->appliesToEmployee($inBranch))->toBeTrue();
    expect($h->appliesToEmployee($otherBranch))->toBeFalse();
    expect(PublicHoliday::holidayForEmployeeOn(Carbon::parse($h->date), $inBranch)?->id)->toBe($h->id);
    expect(PublicHoliday::holidayForEmployeeOn(Carbon::parse($h->date), $otherBranch))->toBeNull();
});

test('the legacy isHoliday API and consumers still work after the extension', function () {
    // A plain create() with only the old columns must still behave as before.
    $h = PublicHoliday::create(['date' => '2026-01-26', 'name' => 'Republic Day', 'country' => 'IN']);

    expect($h->is_active)->toBeTrue();          // default
    expect($h->holiday_type->value)->toBe('national'); // default
    expect(PublicHoliday::isHoliday(Carbon::parse('2026-01-26'), 'IN'))->toBeTrue();
});
