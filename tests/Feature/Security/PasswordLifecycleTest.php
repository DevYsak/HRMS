<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A credential issued on someone's behalf is a shared secret until they
 * replace it. Nothing used to require that replacement, or even record when a
 * password last changed, so an emailed password could stay live indefinitely.
 */
function plcUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes + ['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user;
}

// ── Self-service change ────────────────────────────────────────────────────
//
// Employees change their own password from Settings > Security. There is no
// forced first-login flow: it was built and then withdrawn on the user's
// instruction. `must_change_password` is still set when a credential is issued
// on someone's behalf, but nothing acts on it — so an emailed password stays
// valid until the employee chooses to replace it.

test('changing the password clears the issued-credential flag', function () {
    $user = plcUser(['must_change_password' => true, 'password' => 'Issued!ByHr#2026']);

    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('current_password', 'Issued!ByHr#2026')
        ->set('password', 'MyOwn!Choice#2026')
        ->set('password_confirmation', 'MyOwn!Choice#2026')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $fresh = $user->fresh();

    expect($fresh->must_change_password)->toBeFalse()
        ->and($fresh->password_changed_at)->not->toBeNull()
        ->and(Hash::check('MyOwn!Choice#2026', $fresh->password))->toBeTrue();

    $this->withoutVite()->actingAs($fresh)->get('/')->assertOk();
});

test('a failed change leaves the issued-credential flag set', function () {
    $user = plcUser(['must_change_password' => true, 'password' => 'Issued!ByHr#2026']);

    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('current_password', 'TheWrongOne#2026')
        ->set('password', 'MyOwn!Choice#2026')
        ->set('password_confirmation', 'MyOwn!Choice#2026')
        ->call('updatePassword')
        ->assertHasErrors('current_password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

// ── History ────────────────────────────────────────────────────────────────

test('a self-service change is recorded in password history', function () {
    $user = plcUser(['password' => 'Issued!ByHr#2026']);

    expect(PasswordHistory::where('user_id', $user->id)->count())->toBe(0);

    app(PasswordService::class)->changePassword($user, 'MyOwn!Choice#2026');

    expect(PasswordHistory::where('user_id', $user->id)->count())->toBe(1);
});

test('the current password cannot be re-set as a new password', function () {
    $user = plcUser(['password' => 'Current!Passw0rd#26']);

    expect(fn () => app(PasswordService::class)->changePassword($user, 'Current!Passw0rd#26'))
        ->toThrow(ValidationException::class);
});

test('a recently used password cannot be reused', function () {
    config(['security.password_history_limit' => 5]);
    $user = plcUser(['password' => 'First!Passw0rd#26']);

    $service = app(PasswordService::class);
    $service->changePassword($user, 'Second!Passw0rd#26');
    $service->changePassword($user->fresh(), 'Third!Passw0rd#26');

    expect(fn () => $service->changePassword($user->fresh(), 'Second!Passw0rd#26'))
        ->toThrow(ValidationException::class);
});

test('a password older than the configured window may be reused again', function () {
    config(['security.password_history_limit' => 1]);
    $user = plcUser(['password' => 'First!Passw0rd#26']);

    $service = app(PasswordService::class);
    $service->changePassword($user, 'Second!Passw0rd#26');
    $service->changePassword($user->fresh(), 'Third!Passw0rd#26');

    // Only the single most recent entry is checked, so the first is free again.
    $service->changePassword($user->fresh(), 'First!Passw0rd#26');

    expect(Hash::check('First!Passw0rd#26', $user->fresh()->password))->toBeTrue();
});

test('a limit of zero records history without preventing reuse', function () {
    config(['security.password_history_limit' => 0]);
    $user = plcUser(['password' => 'Same!Passw0rd#2026']);

    app(PasswordService::class)->changePassword($user, 'Same!Passw0rd#2026');

    expect(PasswordHistory::where('user_id', $user->id)->count())->toBe(1);
});

// ── Admin reset ────────────────────────────────────────────────────────────

test('an admin reset always succeeds and re-flags the account', function () {
    // A locked-out account must be recoverable even if the admin happens to
    // pick something the user has had before.
    config(['security.password_history_limit' => 5]);
    $user = plcUser(['password' => 'Known!Passw0rd#26', 'must_change_password' => false]);
    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);

    $plain = app(PasswordService::class)->resetPassword($user, 'Known!Passw0rd#26', $admin);

    $fresh = $user->fresh();

    expect($plain)->toBe('Known!Passw0rd#26')
        ->and($fresh->must_change_password)->toBeTrue()
        ->and($fresh->password_changed_at)->toBeNull();
});

test('a generated reset password is not a guessable literal', function () {
    $user = plcUser();

    $plain = app(PasswordService::class)->resetPassword($user);

    expect(strlen($plain))->toBe(14)
        ->and(Hash::check('password', $user->fresh()->password))->toBeFalse();
});

// ── Login stamping ─────────────────────────────────────────────────────────

test('a successful login stamps last_login_at', function () {
    $user = plcUser(['password' => 'Login!Passw0rd#26']);

    expect($user->last_login_at)->toBeNull();

    $this->post('/login', ['email' => $user->email, 'password' => 'Login!Passw0rd#26']);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

// ── A rejected attempt must be visible ─────────────────────────────────────

test('a wrong current password shows an error instead of silently clearing', function () {
    // "Nothing happens" was the actual report: the form reset all three fields
    // and rendered no message, so a rejected attempt looked like no attempt.
    $user = plcUser(['must_change_password' => true, 'password' => 'Issued!ByHr#2026']);

    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('current_password', 'TheWrongOne#2026')
        ->set('password', 'MyOwn!Choice#2026')
        ->set('password_confirmation', 'MyOwn!Choice#2026')
        ->call('updatePassword')
        ->assertHasErrors('current_password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

test('reusing the current password is reported, not swallowed', function () {
    $user = plcUser(['must_change_password' => true, 'password' => 'Issued!ByHr#2026']);

    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('current_password', 'Issued!ByHr#2026')
        ->set('password', 'Issued!ByHr#2026')
        ->set('password_confirmation', 'Issued!ByHr#2026')
        ->call('updatePassword')
        ->assertHasErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});
