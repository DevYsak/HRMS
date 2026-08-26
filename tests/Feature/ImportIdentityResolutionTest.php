<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImportService;

/**
 * Identity is resolved before any user is created.
 *
 * The importer built its "does this person already exist?" lookups through the
 * default Eloquent scope, which hides soft-deleted rows. But a deleted user
 * still occupies its row in `users`, and users_email_unique knows nothing about
 * deleted_at. So an employee deleted from Manage Employees was invisible to the
 * importer, classified new, and the INSERT died on
 * "Duplicate entry ... for key 'users_email_unique'" — a raw SQL error that
 * rolled back the whole file.
 */
function iirRow(array $overrides = []): array
{
    return $overrides + [
        'employee_id' => 'CNS900',
        'first_name' => 'Test',
        'last_name' => 'Person',
        'email' => 'test.person@conexus-ns.com',
        'joining_date' => '2026-01-01',
    ];
}

// ── The reported failure ───────────────────────────────────────────────────

test('a soft-deleted user holding the email is detected, not re-inserted', function () {
    $user = User::factory()->create(['email' => 'deleted.person@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'OLD-001']);
    $user->delete();

    $parsed = app(EmployeeImportService::class)->parse([
        iirRow(['employee_id' => 'CNS018', 'email' => 'deleted.person@conexus-ns.com']),
    ]);

    expect($parsed['rows'][0]['status'])->toBe('error')
        ->and($parsed['summary']['error'])->toBe(1)
        ->and($parsed['summary']['new'])->toBe(0)
        ->and(implode(' ', $parsed['rows'][0]['errors']))->toContain('deleted employee record');
});

test('the blocked row never reaches an insert', function () {
    // The point of blocking in parse(): import() must not attempt the INSERT
    // that produced the duplicate-key error.
    $user = User::factory()->create(['email' => 'deleted.person@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'OLD-002']);
    $user->delete();

    $service = app(EmployeeImportService::class);
    $before = User::withTrashed()->count();

    $log = $service->import(
        $service->parse([iirRow(['employee_id' => 'CNS018', 'email' => 'deleted.person@conexus-ns.com'])]),
        'skip',
        User::factory()->create(),
    );

    // Only the actor created above is new.
    expect($log->imported)->toBe(0)
        ->and(User::withTrashed()->count())->toBe($before + 1);
});

// ── Case A: existing employee + existing user ──────────────────────────────

test('A — an existing employee and user is an update, never a new user', function () {
    $user = User::factory()->create(['email' => 'existing@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'CNS500']);

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([iirRow(['employee_id' => 'CNS500', 'email' => 'existing@conexus-ns.com'])]);

    expect($parsed['rows'][0]['status'])->toBe('update')
        ->and($parsed['rows'][0]['data']['user_state'])->toBe('existing')
        ->and($parsed['rows'][0]['data']['employee_state'])->toBe('existing');

    $before = User::count();
    $service->import($parsed, 'update', User::factory()->create());

    expect(User::where('email', 'existing@conexus-ns.com')->count())->toBe(1)
        ->and(User::count())->toBe($before + 1);
});

// ── Case B: existing user, no employee ─────────────────────────────────────

test('B — an existing user with no employee record is reused, not duplicated', function () {
    $user = User::factory()->create(['email' => 'loginonly@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::where('user_id', $user->id)->forceDelete();

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([iirRow(['employee_id' => 'CNS600', 'email' => 'loginonly@conexus-ns.com'])]);

    expect($parsed['rows'][0]['data']['user_state'])->toBe('existing')
        ->and($parsed['rows'][0]['data']['employee_state'])->toBe('new');

    $service->import($parsed, 'update', User::factory()->create());

    expect(User::where('email', 'loginonly@conexus-ns.com')->count())->toBe(1)
        ->and(Employee::where('user_id', $user->id)->exists())->toBeTrue();
});

// ── Case C: employee_code conflict ─────────────────────────────────────────

test('C — an employee code already held by somebody else is blocked', function () {
    $other = User::factory()->create(['email' => 'codeholder@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $other->id, 'employee_id' => 'CNS700', 'employee_code' => 16]);

    $parsed = app(EmployeeImportService::class)->parse([
        iirRow(['employee_id' => 'CNS018', 'email' => 'someone.else@conexus-ns.com', 'biometric_pin' => '16']),
    ]);

    expect($parsed['rows'][0]['status'])->toBe('error')
        ->and(implode(' ', $parsed['rows'][0]['errors']))->toContain('already belongs to CNS700');
});

test('C — keeping your own employee code is not a conflict', function () {
    $user = User::factory()->create(['email' => 'owner@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'CNS800', 'employee_code' => 21]);

    $parsed = app(EmployeeImportService::class)->parse([
        iirRow(['employee_id' => 'CNS800', 'email' => 'owner@conexus-ns.com', 'biometric_pin' => '21']),
    ]);

    expect($parsed['rows'][0]['status'])->toBe('update')
        ->and($parsed['rows'][0]['errors'])->toBeEmpty();
});

// ── Case D: email belongs to a different employee ──────────────────────────

test('D — an email belonging to another employee is blocked, never merged', function () {
    $a = User::factory()->create(['email' => 'person.a@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $a->id, 'employee_id' => 'CNS101']);

    $b = User::factory()->create(['email' => 'person.b@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $b->id, 'employee_id' => 'CNS102']);

    // CNS102 tries to take CNS101's address.
    $parsed = app(EmployeeImportService::class)->parse([
        iirRow(['employee_id' => 'CNS102', 'email' => 'person.a@conexus-ns.com']),
    ]);

    expect($parsed['rows'][0]['status'])->toBe('error')
        ->and(implode(' ', $parsed['rows'][0]['errors']))->toContain('already used by another employee');
});

// ── Preview states ─────────────────────────────────────────────────────────

test('a genuinely new person shows both halves as new', function () {
    $parsed = app(EmployeeImportService::class)->parse([iirRow()]);

    expect($parsed['rows'][0]['status'])->toBe('new')
        ->and($parsed['rows'][0]['data']['user_state'])->toBe('new')
        ->and($parsed['rows'][0]['data']['employee_state'])->toBe('new');
});
