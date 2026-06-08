<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

test('wfh, pip, and promotions employee pages return 200', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $employee->id, 'status' => 'active']);

    foreach (['wfh.my', 'performance.pip.my', 'performance.promotions.my'] as $route) {
        $response = $this->withoutVite()->actingAs($employee)->get(route($route));

        $response->assertOk("Route [{$route}] did not return 200 for an employee");
    }
})->group('smoke');

test('wfh, pip, and promotions manage pages return 200 for super admin', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Employee::factory()->create(['user_id' => $admin->id, 'status' => 'active']);

    foreach (['wfh.manage', 'performance.pip.manage', 'performance.promotions.manage'] as $route) {
        $response = $this->withoutVite()->actingAs($admin)->get(route($route));

        $response->assertOk("Route [{$route}] did not return 200 for a super admin");
    }
})->group('smoke');

test('employee role cannot access manage pages gated by review-performance permission', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $employee->id, 'status' => 'active']);

    foreach (['performance.pip.manage', 'performance.promotions.manage'] as $route) {
        $response = $this->withoutVite()->actingAs($employee)->get(route($route));

        $response->assertForbidden("Route [{$route}] should be forbidden for an employee");
    }
})->group('smoke');
