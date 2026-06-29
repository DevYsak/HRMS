<?php

use App\Enums\UserRole;
use App\Livewire\ExecutiveDashboard;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('director / executive dashboard renders premium content', function () {
    $user = User::factory()->create(['role' => UserRole::Director]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user);

    Livewire::test(ExecutiveDashboard::class)
        ->assertOk()
        ->assertSee('Executive Summary')
        ->assertSee('Company Growth')
        ->assertSee('Department Ranking')
        ->assertSee('Organization Health');
});

test('director route resolves to the executive dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::Director]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user)->get(route('dashboard.director'))->assertOk()->assertSee('Executive Summary');
});
