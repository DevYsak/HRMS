<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImportService;
use Illuminate\Support\Facades\Hash;

/**
 * A spreadsheet must not be able to change who someone is.
 *
 * Employee import updates the directory. It never touched passwords, which was
 * right, but it did update `role` — so a stale column could promote a clerk to
 * HR or demote a director, with no approval and nothing visible to review.
 */
test('an import does not change an existing user password', function () {
    $user = User::factory()->create([
        'email' => 'existing@conexus-ns.com',
        'password' => 'TheirOwn!Passw0rd1',
        'role' => UserRole::Employee,
    ]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'EMP-9001']);

    $service = app(EmployeeImportService::class);
    $reflection = new ReflectionMethod($service, 'updateExisting');
    $reflection->invoke($service, [
        'existing_user_id' => $user->id,
        'name' => 'Existing Person',
        'email' => 'existing@conexus-ns.com',
        'role' => 'hr_admin',
        'employee' => [],
        'payroll' => [],
    ]);

    expect(Hash::check('TheirOwn!Passw0rd1', $user->fresh()->password))->toBeTrue();
});

test('an import does not change an existing user role', function () {
    $user = User::factory()->create([
        'email' => 'director@conexus-ns.com',
        'role' => UserRole::Director,
    ]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'EMP-9002']);

    $service = app(EmployeeImportService::class);
    $reflection = new ReflectionMethod($service, 'updateExisting');
    $reflection->invoke($service, [
        'existing_user_id' => $user->id,
        // The sheet says employee. The sheet does not get to decide.
        'role' => 'employee',
        'name' => 'Director Person',
        'email' => 'director@conexus-ns.com',
        'employee' => [],
        'payroll' => [],
    ]);

    expect($user->fresh()->role)->toBe(UserRole::Director);
});

test('an import still updates the fields it is responsible for', function () {
    $user = User::factory()->create([
        'email' => 'stand-in@placeholder.invalid',
        'name' => 'Old Name',
        'role' => UserRole::Employee,
    ]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'EMP-9003']);

    $service = app(EmployeeImportService::class);
    $reflection = new ReflectionMethod($service, 'updateExisting');
    $reflection->invoke($service, [
        'existing_user_id' => $user->id,
        'name' => 'Real Name',
        'email' => 'real.address@conexus-ns.com',
        'role' => 'employee',
        'employee' => [],
        'payroll' => [],
    ]);

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('Real Name')
        ->and($fresh->email)->toBe('real.address@conexus-ns.com');
});
