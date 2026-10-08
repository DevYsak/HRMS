<?php

use App\Enums\UserRole;
use App\Livewire\HrAdminDashboard;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('hr-admin dashboard page renders premium HR content', function () {
    $user = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user);

    // Redesigned HR overview (8 Oct 2026): today's attendance, decisions
    // waiting and HR alerts — the same view "/" shows HR.
    Livewire::test(HrAdminDashboard::class)
        ->assertOk()
        ->assertSee('Present today')
        ->assertSee('Open onboarding tasks')
        ->assertSee('Waiting for a decision')
        ->assertSee('Attendance regularisations');
});
