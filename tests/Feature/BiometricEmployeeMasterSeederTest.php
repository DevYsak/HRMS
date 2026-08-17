<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BiometricEmployeeMasterSeeder;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\ShiftSettingSeeder;
use Illuminate\Console\Command;

const YOGESH_EMAIL = 'yogesh.sakpal@conexus-ns.com';

beforeEach(function () {
    // Departments carry a non-null company_id, so the company must exist first.
    $this->seed(CompanySeeder::class);
    $this->seed(DepartmentSeeder::class);
    $this->seed(ShiftSettingSeeder::class);
});

it('creates the biometric employee with the role declared in the master data', function () {
    $this->seed(BiometricEmployeeMasterSeeder::class);

    $user = User::where('email', YOGESH_EMAIL)->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::SuperAdmin);
});

it('syncs role_id to the matching database role', function () {
    $this->seed(BiometricEmployeeMasterSeeder::class);

    $user = User::where('email', YOGESH_EMAIL)->first();

    expect($user->role_id)->toBe(Role::where('slug', 'super_admin')->value('id'))
        ->and($user->assignedRole->slug)->toBe('super_admin');
});

it('grants every permission-gated capability to that account', function () {
    $this->seed(BiometricEmployeeMasterSeeder::class);

    $user = User::where('email', YOGESH_EMAIL)->first();

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->canManageEmployees())->toBeTrue()
        ->and($user->canRunPayroll())->toBeTrue()
        ->and($user->canApproveLeave())->toBeTrue()
        ->and($user->canManageSettings())->toBeTrue();
});

it('does not demote the account when the seeder is re-run', function () {
    $this->seed(BiometricEmployeeMasterSeeder::class);
    $this->seed(BiometricEmployeeMasterSeeder::class);

    $user = User::where('email', YOGESH_EMAIL)->first();

    expect($user->role)->toBe(UserRole::SuperAdmin)
        ->and(User::where('email', YOGESH_EMAIL)->count())->toBe(1);
});

it('defaults to the employee role when a master row omits one', function () {
    $seeder = new BiometricEmployeeMasterSeeder;

    $rows = (new ReflectionClass($seeder))->getProperty('employees')->getValue($seeder);
    $rows[] = [
        'employee_code' => 999,
        'name' => 'Roleless Tester',
        'email' => 'roleless.tester@conexus-ns.com',
        'shift' => 'IT Shift',
        'dept' => 'PRD',
    ];
    (new ReflectionClass($seeder))->getProperty('employees')->setValue($seeder, $rows);

    $seeder->setCommand(Mockery::mock(Command::class)->shouldIgnoreMissing());
    $seeder->run();

    // ->value() returns the cast enum, not the raw column, so compare enums.
    // The behaviour under test — a row without a role defaults to employee —
    // is unchanged.
    expect(User::where('email', 'roleless.tester@conexus-ns.com')->value('role'))
        ->toBe(UserRole::Employee);
});

it('links the employee record to the biometric enrolment code', function () {
    $this->seed(BiometricEmployeeMasterSeeder::class);

    $user = User::where('email', YOGESH_EMAIL)->first();
    $employee = Employee::where('user_id', $user->id)->first();

    expect($employee)->not->toBeNull()
        ->and($employee->employee_code)->toBe(17);
});
