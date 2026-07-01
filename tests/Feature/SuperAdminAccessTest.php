<?php

use App\Enums\UserRole;
use App\Livewire\Overtime\ManageOtRequests;
use App\Livewire\Performance\Dashboard as PerformanceDashboard;
use App\Models\User;
use Livewire\Livewire;

test('super admin (no employee) can open the OT manage page', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);

    Livewire::actingAs($admin)->test(ManageOtRequests::class)->assertOk();
});

test('super admin (no employee) sees a friendly performance empty state, not a 403', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);

    Livewire::actingAs($admin)->test(PerformanceDashboard::class)
        ->assertOk()
        ->assertSee('No personal performance data');
});
