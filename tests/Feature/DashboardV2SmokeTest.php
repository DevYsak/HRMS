<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('v2 dashboard page renders for an authorised user', function () {
    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $admin->id, 'status' => 'active']);

    $this->withoutVite()->actingAs($admin)
        ->get(route('attendance.dashboard-v2'))
        ->assertOk()
        ->assertSee('Attendance')
        ->assertSee('Detailed Attendance')
        ->assertSee('dashboard-v2/api', false); // proxy base injected for the JS
});

test('v2 dashboard page is forbidden for a plain employee', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $employee->id, 'status' => 'active']);

    $this->withoutVite()->actingAs($employee)
        ->get(route('attendance.dashboard-v2'))
        ->assertForbidden();
});

test('the proxy forwards an allowlisted engine endpoint', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    Http::fake(['*/api/dashboard*' => Http::response(['kpis' => ['present' => 3], 'table' => []], 200)]);

    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $admin->id, 'status' => 'active']);

    $this->actingAs($admin)
        ->getJson(route('attendance.dashboard-v2.proxy', ['path' => 'dashboard']))
        ->assertOk()
        ->assertJsonPath('kpis.present', 3);
});

test('the proxy rejects paths that are not allowlisted', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    Http::fake(); // ensure no real call is made

    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $admin->id, 'status' => 'active']);

    $this->actingAs($admin)
        ->getJson(route('attendance.dashboard-v2.proxy', ['path' => 'secrets']))
        ->assertNotFound();

    Http::assertNothingSent();
});
