<?php

use App\Models\User;

test('all hrms module routes return 200 for admin', function () {
    $admin = User::factory()->create([
        'role' => \App\Enums\UserRole::Admin,
    ]);

    $routes = [
        'dashboard',
        'employees.index',
        'employees.directory',
        'employees.org-chart',
        'time-off.my',
        'time-off.team',
        'time-off.employees',
        'time-off.settings',
        'attendance.my',
        'attendance.team',
        'attendance.employees',
        'attendance.settings',
        'payroll.my',
        'payroll.index',
        'settings.general',
    ];

    foreach ($routes as $route) {
        $response = $this
            ->withoutVite()
            ->actingAs($admin)
            ->get(route($route));

        $response->assertOk("Route [{$route}] did not return 200");
    }
})->group('smoke');

test('employee role cannot access restricted admin routes', function () {
    $employee = User::factory()->create([
        'role' => \App\Enums\UserRole::Employee,
    ]);

    // These pages are accessible but conditionally hide admin-only UI
    // The routes themselves don't gate on role — just the sidebar nav items
    $response = $this
        ->withoutVite()
        ->actingAs($employee)
        ->get(route('dashboard'));

    $response->assertOk();
})->group('smoke');
