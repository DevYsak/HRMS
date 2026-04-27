<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

/**
 * Basic access tests for the Employee Directory.
 * - Employees must be able to access the directory (read-only)
 * - Managers must be able to access the directory
 */
test('employee user can access employee directory', function () {
    $employeeUser = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $employeeUser->id, 'status' => 'active']);

    $other = Employee::factory()->create(['status' => 'active']);

    $response = $this->actingAs($employeeUser)->get(route('employees.directory'));

    $response->assertStatus(200);
    $response->assertSee('Employee Directory');
    $response->assertSee($other->user->name);
});

test('manager user can access employee directory', function () {
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $managerUser->id, 'status' => 'active']);

    $other = Employee::factory()->create(['status' => 'active']);

    $response = $this->actingAs($managerUser)->get(route('employees.directory'));

    $response->assertStatus(200);
    $response->assertSee($other->user->name);
});
