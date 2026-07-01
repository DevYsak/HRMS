<?php

use App\Enums\UserRole;
use App\Models\User;

test('a super admin can view as another user and return to their account', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);
    $employee = User::factory()->create(['role' => UserRole::Employee, 'email_verified_at' => now()]);

    $this->actingAs($admin)->get(route('impersonate.start', $employee))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($employee->id);
    expect(session('impersonator_id'))->toBe($admin->id);

    $this->get(route('impersonate.stop'))->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($admin->id);
    expect(session()->has('impersonator_id'))->toBeFalse();
});

test('a non-super-admin cannot impersonate', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'email_verified_at' => now()]);
    $employee = User::factory()->create(['role' => UserRole::Employee, 'email_verified_at' => now()]);

    $this->actingAs($hr)->get(route('impersonate.start', $employee))->assertForbidden();
});

test('cannot start a second impersonation while already impersonating', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);
    $a = User::factory()->create(['email_verified_at' => now()]);
    $b = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($admin)->get(route('impersonate.start', $a))->assertRedirect(route('dashboard'));

    // Now impersonating $a — a second start must be refused.
    $this->get(route('impersonate.start', $b))->assertForbidden();
});
