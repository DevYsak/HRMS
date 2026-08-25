<?php

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Support\Facades\Hash;

/**
 * biometric:sync-employees is an employee-directory feed. It must never form
 * an opinion about credentials or privileges.
 *
 * It used to put `password` and `role` in the update half of updateOrCreate,
 * so every run reset the matched user's password to the literal string
 * "password" and demoted anyone who had been promoted. Both are now
 * create-only.
 */
function bioSyncMaster(array $rows): void
{
    // The same override a deployment would use to supply the roster from
    // config rather than editing the command.
    config(['biometric.employee_master' => $rows]);
}

beforeEach(function () {
    ShiftSetting::firstOrCreate(['name' => 'IT Shift'], [
        'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 15,
    ]);
    Department::factory()->create(['code' => 'PRD', 'name' => 'Production']);

    bioSyncMaster([[
        'employee_code' => 4242,
        'name' => 'Sync Person',
        'email' => 'sync.person@conexus-ns.com',
        'shift' => 'IT Shift',
        'dept' => 'PRD',
        'joining_date' => '2024-01-01',
    ]]);
});

test('1 — a new biometric employee never gets the literal password', function () {
    $this->artisan('biometric:sync-employees')->assertSuccessful();

    $user = User::where('email', 'sync.person@conexus-ns.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeFalse()
        // No forced-change flag any more; what matters is that the generated
        // credential is not something anyone could guess.
        ->and(strlen($user->password))->toBeGreaterThan(20);
});

test('2 — an existing user keeps their password', function () {
    $user = User::factory()->create([
        'email' => 'sync.person@conexus-ns.com',
        'password' => 'TheirOwn!Passw0rd1',
        'role' => UserRole::Employee,
    ]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 4242]);

    $this->artisan('biometric:sync-employees')->assertSuccessful();

    expect(Hash::check('TheirOwn!Passw0rd1', $user->fresh()->password))->toBeTrue();
});

test('3 — a password the employee changed themselves survives the sync', function () {
    $user = User::factory()->create(['email' => 'sync.person@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 4242]);

    app(PasswordService::class)->changePassword($user, 'Chosen!ByMe#2026');

    $this->artisan('biometric:sync-employees')->assertSuccessful();

    $fresh = $user->fresh();

    expect(Hash::check('Chosen!ByMe#2026', $fresh->password))->toBeTrue()
        ->and($fresh->password_changed_at)->not->toBeNull();
});

test('4 — a promoted user is not demoted back to employee', function () {
    $user = User::factory()->create([
        'email' => 'sync.person@conexus-ns.com',
        'role' => UserRole::HrAdmin,
    ]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 4242]);

    $this->artisan('biometric:sync-employees')->assertSuccessful();

    expect($user->fresh()->role)->toBe(UserRole::HrAdmin);
});

test('4b — a director keeps their role too', function () {
    $user = User::factory()->create([
        'email' => 'sync.person@conexus-ns.com',
        'role' => UserRole::Director,
    ]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 4242]);

    $this->artisan('biometric:sync-employees')->assertSuccessful();

    expect($user->fresh()->role)->toBe(UserRole::Director);
});

test('5 — running the sync repeatedly changes nothing after the first run', function () {
    $this->artisan('biometric:sync-employees')->assertSuccessful();

    $first = User::where('email', 'sync.person@conexus-ns.com')->first();
    $hashAfterCreate = $first->password;

    $this->artisan('biometric:sync-employees')->assertSuccessful();
    $this->artisan('biometric:sync-employees')->assertSuccessful();

    expect(User::where('email', 'sync.person@conexus-ns.com')->count())->toBe(1)
        ->and($first->fresh()->password)->toBe($hashAfterCreate);
});

test('6 — a changed email updates the existing user instead of creating a second one', function () {
    $user = User::factory()->create([
        'email' => 'old.address@conexus-ns.com',
        'password' => 'TheirOwn!Passw0rd1',
        'role' => UserRole::Manager,
    ]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 4242]);

    $this->artisan('biometric:sync-employees')->assertSuccessful();

    // Matched on employee_code, so the address moves onto the same account.
    expect(User::where('email', 'old.address@conexus-ns.com')->exists())->toBeFalse()
        ->and($user->fresh()->email)->toBe('sync.person@conexus-ns.com')
        ->and($user->fresh()->role)->toBe(UserRole::Manager)
        ->and(Hash::check('TheirOwn!Passw0rd1', $user->fresh()->password))->toBeTrue();
});

test('7 — a changed email leaves no orphaned second user', function () {
    $user = User::factory()->create(['email' => 'old.address@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 4242]);

    $before = User::count();

    $this->artisan('biometric:sync-employees')->assertSuccessful();

    expect(User::count())->toBe($before)
        ->and(Employee::where('employee_code', 4242)->count())->toBe(1);
});

test('the sync still creates the employee record it is there to create', function () {
    $this->artisan('biometric:sync-employees')->assertSuccessful();

    $employee = Employee::where('employee_code', 4242)->first();

    expect($employee)->not->toBeNull()
        ->and($employee->user->name)->toBe('Sync Person')
        ->and($employee->shift->name)->toBe('IT Shift');
});

test('a dry run writes nothing at all', function () {
    $this->artisan('biometric:sync-employees', ['--dry-run' => true])->assertSuccessful();

    expect(User::where('email', 'sync.person@conexus-ns.com')->exists())->toBeFalse()
        ->and(Employee::where('employee_code', 4242)->exists())->toBeFalse();
});
