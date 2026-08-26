<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeIndex;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Deleted employees can be seen, restored, or removed for good.
 *
 * Deleting soft-deleted both records, released the biometric code and left
 * every leave balance, attendance row, payslip and audit entry in place — but
 * nothing in the application could see the result afterwards. The person was
 * unreachable, their email permanently spent, and the only way back was a
 * database edit.
 */
function erpAdmin(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

function erpDeleted(): Employee
{
    $user = User::factory()->create(['email' => 'gone'.Str::random(4).'@conexus-ns.com', 'role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'employee_code' => null]);

    $type = LeaveType::create([
        'name' => 'Annual '.Str::random(4), 'code' => 'A'.strtoupper(Str::random(3)),
        'category' => 'annual', 'allow_paid_request' => true,
    ]);
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'year' => 2026, 'allocated_days' => 28, 'used_days' => 6,
    ]);

    $employee->delete();
    $user->delete();

    return $employee;
}

// ── Seeing them ────────────────────────────────────────────────────────────

test('deleted employees are hidden by default', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->assertOk()
        ->assertViewHas('employees', fn ($e) => ! $e->pluck('id')->contains($deleted->id));
});

test('the deleted view lists them', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->set('showDeleted', true)
        ->assertViewHas('employees', fn ($e) => $e->pluck('id')->contains($deleted->id));
});

test('the deleted view excludes live employees', function () {
    erpDeleted();
    $live = Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id]);

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->set('showDeleted', true)
        ->assertViewHas('employees', fn ($e) => ! $e->pluck('id')->contains($live->id));
});

// ── Restore ────────────────────────────────────────────────────────────────

test('restoring brings back both records', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('restoreEmployee', $deleted->id);

    $employee = Employee::find($deleted->id);

    expect($employee)->not->toBeNull()
        ->and($employee->trashed())->toBeFalse()
        ->and(User::find($deleted->user_id))->not->toBeNull();
});

test('restoring keeps their history', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('restoreEmployee', $deleted->id);

    $balance = LeaveBalance::where('employee_id', $deleted->id)->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->allocated_days)->toBe(28.0)
        ->and((float) $balance->used_days)->toBe(6.0);
});

test('a restore is audit logged', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('restoreEmployee', $deleted->id);

    expect(AuditLog::where('auditable_type', Employee::class)
        ->where('auditable_id', $deleted->id)->where('action', 'restored')->exists())->toBeTrue();
});

test('restoring an employee who is not deleted does nothing', function () {
    $live = Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id]);

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('restoreEmployee', $live->id);

    expect(AuditLog::where('auditable_id', $live->id)->where('action', 'restored')->exists())->toBeFalse();
});

// ── Permanent delete ───────────────────────────────────────────────────────

test('permanent delete removes the employee and the user for good', function () {
    $deleted = erpDeleted();
    $userId = $deleted->user_id;

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('forceDeleteEmployee', $deleted->id);

    expect(Employee::withTrashed()->find($deleted->id))->toBeNull()
        ->and(User::withTrashed()->find($userId))->toBeNull();
});

test('the email is free again afterwards', function () {
    // The point of purging: re-importing that person as new becomes possible.
    $deleted = erpDeleted();
    $email = User::withTrashed()->find($deleted->user_id)->email;

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('forceDeleteEmployee', $deleted->id);

    expect(User::withTrashed()->where('email', $email)->exists())->toBeFalse();
});

test('a permanent delete is audit logged before the row goes', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('forceDeleteEmployee', $deleted->id);

    expect(AuditLog::where('auditable_type', Employee::class)
        ->where('auditable_id', $deleted->id)->where('action', 'force_deleted')->exists())->toBeTrue();
});

test('a live employee cannot be permanently deleted in one step', function () {
    // Removing somebody is always two deliberate actions: delete, then purge.
    $live = Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id]);

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->call('forceDeleteEmployee', $live->id);

    expect(Employee::find($live->id))->not->toBeNull();
});

// ── Authorisation ──────────────────────────────────────────────────────────

test('a manager cannot permanently delete anyone', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->test(EmployeeIndex::class)
        ->call('forceDeleteEmployee', $deleted->id)
        ->assertForbidden();

    expect(Employee::withTrashed()->find($deleted->id))->not->toBeNull();
});

test('an employee cannot permanently delete anyone', function () {
    $deleted = erpDeleted();

    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(EmployeeIndex::class)
        ->call('forceDeleteEmployee', $deleted->id)
        ->assertForbidden();

    expect(Employee::withTrashed()->find($deleted->id))->not->toBeNull();
});

// ── The Deleted view must survive imperfect rows ───────────────────────────

test('a deleted employee whose user row is gone still renders', function () {
    // Live threw exactly this: Attempt to read property "name" on null, from
    // employee-index.blade.php. Locally it cannot happen - employees.user_id is
    // NOT NULL with ON DELETE CASCADE, so the user row cannot vanish underneath
    // an employee. The production database evidently does not enforce that, so
    // the orphan is built here deliberately rather than assumed impossible.
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $employee->delete();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('users')->where('id', $user->id)->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    expect(Employee::withTrashed()->find($employee->id))->not->toBeNull();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->set('showDeleted', true)
        ->assertOk()
        ->assertSee('No user account');
});

test('a deleted employee with no shift, title or department still renders', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'shift_id' => null,
        'job_title_id' => null,
        'department_id' => null,
        'office_id' => null,
    ]);
    $employee->delete();
    $user->delete();

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->set('showDeleted', true)
        ->assertOk();
});

test('the active list still renders normally', function () {
    // The guards must not have changed anything for the ordinary case.
    $user = User::factory()->create(['name' => 'Active Person', 'role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs(erpAdmin())->test(EmployeeIndex::class)
        ->assertOk()
        ->assertSee('Active Person')
        ->assertDontSee('No user account');
});
