<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

test('all hrms module routes return 200 for admin', function () {
    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);

    // Most Livewire components require the authenticated user to have an Employee record
    Employee::factory()->create(['user_id' => $admin->id, 'status' => 'active']);

    $routes = [
        'dashboard',
        'employees.index',
        'employees.directory',
        'employees.org-chart',
        'time-off.team',
        'time-off.employees',
        'time-off.settings',
        'attendance.my',
        'attendance.team',
        'attendance.employees',
        'attendance.settings',
        'payroll.payslips',
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
        'role' => UserRole::Employee,
    ]);

    $response = $this
        ->withoutVite()
        ->actingAs($employee)
        ->get(route('dashboard'));

    $response->assertOk();
})->group('smoke');
