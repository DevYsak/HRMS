<?php

use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Support\DateDisplay;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('account settings show name and work email read-only; nothing writes them directly', function () {
    $user = User::factory()->create(['name' => 'Original Name', 'email' => 'original@example.com']);
    Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSee('Original Name')
        ->assertSee('original@example.com')
        ->assertSee('Managed by HR')
        ->assertDontSeeHtml('wire:model="email"')
        ->assertDontSeeHtml('wire:model="name"');

    // The old direct-update action is gone, not merely hidden.
    expect(fn () => Livewire::test('pages::settings.profile')->call('updateProfileInformation'))
        ->toThrow(Exception::class);

    expect($user->fresh()->only(['name', 'email']))->toBe(['name' => 'Original Name', 'email' => 'original@example.com']);
});

test('an employee requests a name change for HR approval; the name itself does not change', function () {
    $user = User::factory()->create(['name' => 'Original Name', 'role' => 'employee']);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('requestedName', 'Corrected Name')
        ->set('nameReason', 'Legal name change')
        ->call('requestNameChange')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-name-request-pending');

    $request = ProfileChangeRequest::where('employee_id', $employee->id)->where('field', 'name')->sole();
    expect($request->status)->toBe(ProfileChangeRequest::STATUS_PENDING)
        ->and($request->new_value)->toBe('Corrected Name')
        ->and($user->fresh()->name)->toBe('Original Name');

    // A second request while one is open is refused.
    Livewire::test('pages::settings.profile')
        ->set('requestedName', 'Another Name')
        ->call('requestNameChange')
        ->assertHasErrors('requestedName');
});

test('the same name, or an account with no employee record, cannot raise a request', function () {
    $user = User::factory()->create(['name' => 'Same Name', 'role' => 'employee']);
    Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('requestedName', 'Same Name')
        ->call('requestNameChange')
        ->assertHasErrors('requestedName');

    $this->actingAs(User::factory()->create());
    Livewire::test('pages::settings.profile')
        ->assertSee('Your name is managed by your administrator.')
        ->set('requestedName', 'Anything')
        ->call('requestNameChange')
        ->assertForbidden();
});

test('new accounts start on the 12-hour clock; a saved preference is kept', function () {
    $newUser = User::factory()->create()->fresh();
    expect($newUser->time_format)->toBe('h:i A');

    $this->actingAs($newUser);
    expect(app(DateDisplay::class)->time('2026-10-05 14:30:00'))->toBe('02:30 PM');

    $keeps24 = User::factory()->create(['time_format' => 'H:i']);
    $this->actingAs($keeps24->fresh());
    expect(app(DateDisplay::class)->time('2026-10-05 14:30:00'))->toBe('14:30');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
