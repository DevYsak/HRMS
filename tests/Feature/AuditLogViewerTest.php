<?php

use App\Enums\UserRole;
use App\Livewire\AuditLogViewer;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

function auditAdmin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]);
}

test('admin can view the audit log and filter by action', function () {
    $admin = auditAdmin();
    $employee = Employee::factory()->create();      // → "created" entry
    $employee->update(['phone' => '9999999999']);   // → "updated" entry

    Livewire::actingAs($admin)->test(AuditLogViewer::class)
        ->assertSee('Audit Log')
        ->assertSee('Employee')
        ->set('action', 'updated')
        ->assertSee('Updated');
});

test('admin can filter the audit log by user search', function () {
    $admin = auditAdmin();
    Employee::factory()->create();

    Livewire::actingAs($admin)->test(AuditLogViewer::class)
        ->set('search', 'no-such-user-xyz')
        ->assertSee('No audit entries match your filters');
});

test('admin can export the audit log to csv', function () {
    $admin = auditAdmin();
    Employee::factory()->create();

    Livewire::actingAs($admin)->test(AuditLogViewer::class)
        ->call('export')
        ->assertFileDownloaded();
});

test('a non-admin cannot open the audit log', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(AuditLogViewer::class)->assertForbidden();
});
