<?php

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('hrms:reset-admin sets a given password and records history', function () {
    $user = User::factory()->create(['email' => 'admin@conexus.in']);

    $this->artisan('hrms:reset-admin', ['email' => 'admin@conexus.in', '--password' => 'Conexus@2026!'])
        ->expectsOutputToContain('Password reset for')
        ->assertSuccessful();

    expect(Hash::check('Conexus@2026!', $user->fresh()->password))->toBeTrue();
    expect(PasswordHistory::where('user_id', $user->id)->exists())->toBeTrue();
});

test('hrms:reset-admin generates a secure password when none is given', function () {
    $user = User::factory()->create(['email' => 'boss@conexus.in', 'password' => Hash::make('old-secret')]);

    $this->artisan('hrms:reset-admin', ['email' => 'boss@conexus.in'])->assertSuccessful();

    expect(Hash::check('old-secret', $user->fresh()->password))->toBeFalse();
});

test('hrms:reset-admin fails for an unknown email', function () {
    $this->artisan('hrms:reset-admin', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user found')
        ->assertFailed();
});
