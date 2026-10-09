<?php

use App\Enums\UserRole;
use App\Livewire\ExecutiveDashboard;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * The executive dashboard is company-wide, so (D1) a Director reaches it only
 * once the role is granted company-wide reach.
 */
beforeEach(function () {
    $role = Role::where('slug', 'director')->firstOrFail();
    $role->permissions()->updateExistingPivot(Permission::where('key', 'manage_employees')->value('id'), ['scope' => 'all']);
    $role->flushPermissionCache();
});

test('director / executive dashboard renders premium content', function () {
    $user = User::factory()->create(['role' => UserRole::Director]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user);

    Livewire::test(ExecutiveDashboard::class)
        ->assertOk()
        ->assertSee('Executive Summary')
        ->assertSee('Company Growth')
        ->assertSee('Department Ranking')
        ->assertDontSee('Organization Health')
        ->assertDontSee('Satisfaction');
});

test('director route resolves to the executive dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::Director]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $this->actingAs($user)->get(route('dashboard.director'))->assertOk()->assertSee('Executive Summary');
});
