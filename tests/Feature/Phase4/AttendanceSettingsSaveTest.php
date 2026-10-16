<?php

use App\Livewire\Attendance\AttendanceSettings;
use App\Models\AttendanceSetting;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * Phase 4 · Settings: the fallback shift, OT rate and check-in requirements
 * on Attendance Settings bind to a plain array and are saved. They used to
 * bind straight to the Eloquent model, which Livewire 4 refuses to set, so
 * none of them could be changed from the screen.
 */
function asAdmin(string $slug = 'hr_admin'): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
}

beforeEach(function () {
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'ot_rate_per_hour' => 100, 'weekly_off_days' => [0, 6],
        'requires_location' => false, 'requires_photo' => false, 'requires_qr' => false]);
    AttendanceSetting::flushWeeklyOffCache();
});

test('the general fields load, save and persist', function () {
    Livewire::actingAs(asAdmin())->test(AttendanceSettings::class)
        ->assertSet('general.shift_start', '09:00')
        ->assertSet('general.requires_photo', false)
        ->set('general.shift_start', '10:30')
        ->set('general.shift_end', '19:30')
        ->set('general.ot_rate_per_hour', 150.5)
        ->set('general.requires_location', true)
        ->set('general.requires_photo', true)
        ->set('general.requires_qr', true)
        ->call('save')
        ->assertHasNoErrors();

    $saved = AttendanceSetting::first();
    expect(substr((string) $saved->shift_start, 0, 5))->toBe('10:30')
        ->and(substr((string) $saved->shift_end, 0, 5))->toBe('19:30')
        ->and($saved->ot_rate_per_hour)->toBe(150.5)
        ->and([$saved->requires_location, $saved->requires_photo, $saved->requires_qr])->toBe([true, true, true]);

    // A reload shows the saved values.
    Livewire::actingAs(asAdmin())->test(AttendanceSettings::class)
        ->assertSet('general.shift_start', '10:30')->assertSet('general.requires_photo', true);
});

test('bad values are rejected and nothing is written', function () {
    Livewire::actingAs(asAdmin())->test(AttendanceSettings::class)
        ->set('general.shift_start', 'nine')
        ->set('general.ot_rate_per_hour', -5)
        ->call('save')
        ->assertHasErrors(['general.shift_start', 'general.ot_rate_per_hour']);

    expect(substr((string) AttendanceSetting::first()->shift_start, 0, 5))->toBe('09:00');
});

test('people without manage_settings cannot open or save it', function () {
    Livewire::actingAs(asAdmin('employee'))->test(AttendanceSettings::class)->assertForbidden();
});
