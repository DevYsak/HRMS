<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Public self-registration is deliberately switched off.
 *
 * These tests previously asserted the opposite — that anyone could register
 * and be authenticated. That was accurate about the code but wrong about the
 * product: Pulse is an internal HRMS, so the only legitimate way to get an
 * account is for HR to create an employee. While registration was enabled an
 * anonymous visitor could sign up, receive the default 'employee' role and
 * reach the dashboard, with no email verification in the way.
 *
 * The assertions are inverted rather than deleted, so the door cannot quietly
 * reopen.
 */
test('the registration screen is not reachable', function () {
    $this->get('/register')->assertNotFound();
});

test('registration cannot be posted', function () {
    $this->post('/register', [
        'name' => 'Outside Person',
        'email' => 'outsider@example.net',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertNotFound();

    expect(User::where('email', 'outsider@example.net')->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

test('no route is named register any more', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();
});

test('existing users can still log in', function () {
    // Disabling registration must not touch authentication.
    $user = User::factory()->create([
        'role' => UserRole::Employee,
        'password' => 'Str0ng!Passw0rd#2026',
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Str0ng!Passw0rd#2026',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('the login screen is still reachable', function () {
    $this->withoutVite()->get(route('login'))->assertOk();
});
