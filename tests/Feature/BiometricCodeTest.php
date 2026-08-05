<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Employees\EmployeeIndex;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Biometric\BiometricCodeService;
use Livewire\Livewire;

/**
 * Ownership of the Biometric Device ID (employees.employee_code).
 *
 * The bug these cover: Rule::unique queries the raw table, so a soft-deleted
 * employee kept reserving their device PIN forever. HR saw "already taken"
 * naming somebody who was no longer in the directory, and had no way out.
 */
function bioEmployee(string $name, ?int $code = null): Employee
{
    $user = User::factory()->create(['name' => $name, 'role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'employee_code' => $code,
    ]);
}

function bioHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

test('a deleted employee no longer blocks their device id', function () {
    $leaver = bioEmployee('Departed Person', 17);

    Livewire::actingAs(bioHr())
        ->test(EmployeeIndex::class)
        ->call('deleteEmployee', $leaver->id);

    // Deleting must free the PIN, exactly as offboarding does.
    expect($leaver->fresh()->employee_code)->toBeNull()
        ->and($leaver->fresh()->trashed())->toBeTrue();

    $replacement = bioEmployee('Replacement Person');

    Livewire::actingAs(bioHr())
        ->test(EmployeeEdit::class, ['employee' => $replacement])
        ->set('employee_code', '17')
        ->call('save')
        ->assertHasNoErrors('employee_code');

    expect($replacement->fresh()->employee_code)->toBe(17);
});

test('releasing a device id is written to the audit trail', function () {
    $leaver = bioEmployee('Audited Leaver', 21);

    Livewire::actingAs(bioHr())
        ->test(EmployeeIndex::class)
        ->call('deleteEmployee', $leaver->id);

    expect(AuditLog::where('action', 'biometric_code_released')
        ->where('subject_employee_id', $leaver->id)->exists())->toBeTrue();
});

test('a soft-deleted holder does not block a brand new employee either', function () {
    // The create screen had the same unmodified unique rule.
    $leaver = bioEmployee('Old Holder', 33);
    app(BiometricCodeService::class)->release($leaver);
    $leaver->delete();

    $fresh = bioEmployee('New Starter');

    Livewire::actingAs(bioHr())
        ->test(EmployeeEdit::class, ['employee' => $fresh])
        ->set('employee_code', '33')
        ->call('save')
        ->assertHasNoErrors('employee_code');
});

test('an active holder still blocks the id, and is named', function () {
    $holder = bioEmployee('Current Holder', 42);
    $other = bioEmployee('Someone Else');

    $component = Livewire::actingAs(bioHr())
        ->test(EmployeeEdit::class, ['employee' => $other])
        ->set('employee_code', '42');

    // Named, not a bare "already taken".
    $component->assertSet('codeConflict', fn ($m) => str_contains((string) $m, 'Current Holder'));

    $component->call('save')->assertHasErrors('employee_code');

    expect($other->fresh()->employee_code)->toBeNull()
        ->and($holder->fresh()->employee_code)->toBe(42);
});

test('a deleted holder is named as deleted so HR knows why they cannot see them', function () {
    $ghost = bioEmployee('Ghost Employee', 55);
    $ghost->delete();

    $message = app(BiometricCodeService::class)->conflictMessage(55);

    expect($message)->toContain('Ghost Employee')
        ->and($message)->toContain('deleted');
});

test('reassign moves the id off the previous holder and audits both sides', function () {
    $holder = bioEmployee('Loses The Card', 42);
    $taker = bioEmployee('Gains The Card');
    $hr = bioHr();

    Livewire::actingAs($hr)
        ->test(EmployeeEdit::class, ['employee' => $taker])
        ->set('employee_code', '42')
        ->call('reassignBiometricCode');

    expect($taker->fresh()->employee_code)->toBe(42)
        ->and($holder->fresh()->employee_code)->toBeNull()
        // Re-enrolment is required after a move.
        ->and($taker->fresh()->sync_status)->toBe('pending')
        ->and($holder->fresh()->sync_status)->toBe('removed');

    expect(AuditLog::where('action', 'biometric_code_released')->where('subject_employee_id', $holder->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'biometric_code_assigned')->where('subject_employee_id', $taker->id)->exists())->toBeTrue();
});

test('reassign frees an id held by a deleted employee', function () {
    $ghost = bioEmployee('Ghost Holder', 61);
    $ghost->delete();

    $taker = bioEmployee('Live Taker');

    Livewire::actingAs(bioHr())
        ->test(EmployeeEdit::class, ['employee' => $taker])
        ->set('employee_code', '61')
        ->call('reassignBiometricCode');

    expect($taker->fresh()->employee_code)->toBe(61)
        ->and(Employee::onlyTrashed()->find($ghost->id)->employee_code)->toBeNull();
});

test('a plain employee cannot reassign a device id', function () {
    $holder = bioEmployee('Protected Holder', 70);
    $taker = bioEmployee('Opportunist');

    Livewire::actingAs($taker->user)
        ->test(EmployeeEdit::class, ['employee' => $taker])
        ->set('employee_code', '70')
        ->call('reassignBiometricCode')
        ->assertForbidden();

    expect($holder->fresh()->employee_code)->toBe(70);
});

test('the legacy biometric_id column is kept in step with the device id', function () {
    // EmployeeEdit writes both columns; they must never drift, since the
    // legacy one carries no unique rule of its own.
    $taker = bioEmployee('Sync Check');

    Livewire::actingAs(bioHr())
        ->test(EmployeeEdit::class, ['employee' => $taker])
        ->set('employee_code', '88')
        ->call('reassignBiometricCode');

    $fresh = $taker->fresh();
    expect((string) $fresh->biometric_id)->toBe((string) $fresh->employee_code);
});
