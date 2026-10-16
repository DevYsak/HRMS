<?php

use App\Livewire\Holidays\HolidayPaySettings;
use App\Livewire\Settings\MenuSettings;
use App\Models\Company;
use App\Models\MenuSetting;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * Phase 4 · Settings validation: every saved field is validated server-side
 * and an invalid save keeps the current valid data.
 */
function svAdmin(): User
{
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
}

test('company settings reject an invalid timezone, currency, colour or over-long contact field and keep the saved values', function () {
    $company = Company::first() ?? Company::create(['name' => 'Conexus', 'email' => 'hr@example.com', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'currency_symbol' => '₹']);
    $company->update(['timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'primary_color' => '#f97316']);

    $this->actingAs(svAdmin());
    Livewire::test('pages::settings.general')
        ->set('timezone', 'Mars/Olympus')
        ->set('currency', 'RUPEES')
        ->set('primary_color', 'orange; background:url(x)')
        ->set('phone', str_repeat('9', 60))
        ->call('updateCompany')
        ->assertHasErrors(['timezone', 'currency', 'primary_color', 'phone']);

    $fresh = $company->fresh();
    expect([$fresh->timezone, $fresh->currency, $fresh->primary_color])->toBe(['Asia/Kolkata', 'INR', '#f97316']);
});

test('the sidebar menu refuses invented keys and over-long labels', function () {
    $this->actingAs(svAdmin());
    $page = Livewire::test(MenuSettings::class);
    $items = $page->get('items');

    $page->set('items', [...$items, ['key' => 'evil_item', 'label' => 'Evil', 'enabled' => true, 'icon' => $items[0]['icon'], 'type' => $items[0]['type']]])
        ->call('save')->assertHasErrors(['items.'.count($items).'.key']);
    expect(MenuSetting::where('key', 'evil_item')->exists())->toBeFalse();

    $items[0]['label'] = str_repeat('L', 80);
    Livewire::test(MenuSettings::class)->set('items', $items)->call('save')->assertHasErrors(['items.0.label']);
});

test('holiday pay accepts only the known pay types', function () {
    $this->actingAs(svAdmin());

    Livewire::test(HolidayPaySettings::class)
        ->set('enabledTypes', ['overtime', 'free_money'])
        ->set('defaultPayType', 'overtime')
        ->call('save')
        ->assertHasErrors(['enabledTypes.1']);
});
