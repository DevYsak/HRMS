<?php

use App\Enums\UserRole;
use App\Livewire\Settings\ControlPanel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

function controlPanelAdmin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]);
}

test('admin can open the control panel and see setting links', function () {
    $admin = controlPanelAdmin();

    Livewire::actingAs($admin)->test(ControlPanel::class)
        ->assertOk()
        ->assertSee('Control Panel')
        ->assertSee('Roles & Permissions')
        ->assertSee('Audit Log')
        ->assertSee('Leave Policies');
});

test('a non-admin cannot open the control panel', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(ControlPanel::class)->assertForbidden();
});
