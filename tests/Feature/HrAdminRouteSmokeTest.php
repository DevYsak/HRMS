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

    Livewire::test(HrAdminDashboard::class)
        ->assertOk()
        ->assertSee('Attendance Overview')
        ->assertSee('Employee Lifecycle')
        ->assertSee('Pending Approvals')
        ->assertSee('Regularisations');
});
