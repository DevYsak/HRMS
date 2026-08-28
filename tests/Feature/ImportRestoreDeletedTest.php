<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\Leave\LeaveProvisioningService;
use Illuminate\Support\Str;

/**
 * Re-adding somebody who was deleted.
 *
 * Deleting an employee soft-deletes both records and releases their biometric
 * code, but leaves every leave balance, attendance row, payslip and audit entry
 * in place. Re-importing them used to be impossible: the email still occupied
 * its row in `users`, so the insert was refused, and the application had no
 * restore anywhere — the error message pointed at a screen that could not help.
 *
 * Restoring is opt-in. An import that happens to name a deleted person must not
 * bring them back on its own.
 */
function irdDeletedEmployee(string $email = 'gone@conexus-ns.com'): array
{
    $user = User::factory()->create(['email' => $email, 'name' => 'Gone Person', 'role' => UserRole::Employee]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'employee_id' => 'CNS021',
        'employee_code' => 17,
        'status' => 'active',
    ]);

    $type = LeaveType::create(['name' => 'Annual '.Str::random(4), 'code' => 'A'.strtoupper(Str::random(3)), 'category' => 'annual', 'allow_paid_request' => true]);
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => 2026, 'allocated_days' => 28, 'used_days' => 6,
    ]);

    $employee->delete();
    $user->delete();

    return [$user, $employee];
}

function irdRow(string $email = 'gone@conexus-ns.com'): array
{
    return [
        'employee_id' => 'CNS021',
        'first_name' => 'Yogesh',
        'last_name' => 'Sapkal',
        'email' => $email,
        'joining_date' => '2020-12-17',
        'biometric_pin' => '17',
    ];
}

// ── Default: still blocked ─────────────────────────────────────────────────

test('without the option a deleted employee is still blocked', function () {
    irdDeletedEmployee();

    $parsed = app(EmployeeImportService::class)->parse([irdRow()]);

    expect($parsed['rows'][0]['status'])->toBe('error')
        ->and($parsed['summary']['error'])->toBe(1);
});

test('the message names the option rather than a screen that cannot help', function () {
    // The original wording said "Restore them from Manage Employees" — but the
    // application has no restore screen, so it asked for the impossible.
    irdDeletedEmployee();

    $parsed = app(EmployeeImportService::class)->parse([irdRow()]);
    $message = implode(' ', $parsed['rows'][0]['errors']);

    expect($message)->toContain('Restore deleted employees')
        ->and($message)->not->toContain('from Manage Employees');
});

// ── Opted in ───────────────────────────────────────────────────────────────

test('with the option the row becomes an update, not an error', function () {
    irdDeletedEmployee();

    $parsed = app(EmployeeImportService::class)->parse([irdRow()], true);

    expect($parsed['rows'][0]['status'])->toBe('update')
        ->and($parsed['summary']['error'])->toBe(0)
        ->and($parsed['rows'][0]['data']['user_state'])->toBe('deleted')
        ->and($parsed['rows'][0]['data']['restore'])->toBeTrue()
        ->and(implode(' ', $parsed['rows'][0]['warnings']))->toContain('will be restored');
});

test('importing restores both records instead of creating new ones', function () {
    [$user, $employee] = irdDeletedEmployee();
    $usersBefore = User::withTrashed()->count();

    $service = app(EmployeeImportService::class);
    $service->import($service->parse([irdRow()], true), 'update', User::factory()->create());

    $freshUser = User::find($user->id);
    $freshEmployee = Employee::find($employee->id);

    expect($freshUser)->not->toBeNull()
        ->and($freshUser->trashed())->toBeFalse()
        ->and($freshEmployee)->not->toBeNull()
        ->and($freshEmployee->trashed())->toBeFalse()
        // No second person: only the actor is new.
        ->and(User::withTrashed()->count())->toBe($usersBefore + 1);
});

test('history survives the restore', function () {
    // The whole point of restoring rather than re-creating.
    [, $employee] = irdDeletedEmployee();

    $service = app(EmployeeImportService::class);
    $service->import($service->parse([irdRow()], true), 'update', User::factory()->create());

    // The row seeded before deletion, not the one onboarding provisions for
    // every employee — both sit in the same year.
    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->whereRelation('leaveType', 'code', '!=', LeaveProvisioningService::ANNUAL_CODE)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->allocated_days)->toBe(28.0)
        ->and((float) $balance->used_days)->toBe(6.0);
});

test('a restore is audit logged', function () {
    // Bringing somebody back is exactly the kind of change to account for later.
    [, $employee] = irdDeletedEmployee();

    $service = app(EmployeeImportService::class);
    $service->import($service->parse([irdRow()], true), 'update', User::factory()->create());

    expect(AuditLog::where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->where('action', 'restored')
        ->exists())->toBeTrue();
});

test('the restored employee is updated from the file', function () {
    [, $employee] = irdDeletedEmployee();

    $service = app(EmployeeImportService::class);
    $service->import($service->parse([irdRow()], true), 'update', User::factory()->create());

    // Deleting released the code; the import reassigns it from the file.
    expect((int) Employee::find($employee->id)->employee_code)->toBe(17);
});

// ── The option must not reach anybody else ─────────────────────────────────

test('the option does not disturb a live employee', function () {
    $user = User::factory()->create(['email' => 'active@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'employee_id' => 'CNS300']);

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([irdRow('active@conexus-ns.com') + ['employee_id' => 'CNS300']], true);

    expect($parsed['rows'][0]['data']['user_state'])->toBe('existing')
        ->and($parsed['rows'][0]['data']['restore'])->toBeFalse();
});

test('the option does not resurrect somebody the file never mentions', function () {
    irdDeletedEmployee('untouched@conexus-ns.com');
    $other = User::factory()->create(['email' => 'alsogone@conexus-ns.com', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $other->id, 'employee_id' => 'CNS999']);
    $other->delete();

    $service = app(EmployeeImportService::class);
    $service->import($service->parse([irdRow('untouched@conexus-ns.com')], true), 'update', User::factory()->create());

    expect(User::find($other->id))->toBeNull()
        ->and(User::withTrashed()->find($other->id)->trashed())->toBeTrue();
});
