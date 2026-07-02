<?php

use App\Enums\UserRole;
use App\Livewire\Settings\DataManagement;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Services\DataPurgeService;
use Livewire\Livewire;

function purgeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);
}

function makeAttendance(Employee $e): void
{
    Attendance::create([
        'employee_id' => $e->id,
        'date' => now()->toDateString(),
        'check_in' => now()->setTime(9, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);
}

test('purge clears every row in a domain', function () {
    makeAttendance(Employee::factory()->create());
    makeAttendance(Employee::factory()->create());
    expect(Attendance::count())->toBe(2);

    $deleted = app(DataPurgeService::class)->purge('attendance', purgeAdmin());

    expect($deleted)->toBeGreaterThanOrEqual(2);
    expect(Attendance::count())->toBe(0);
});

test('deleteEmployee permanently removes the employee, user and their data', function () {
    $actor = purgeAdmin();
    $employee = Employee::factory()->create();
    $userId = $employee->user_id;
    makeAttendance($employee);

    app(DataPurgeService::class)->deleteEmployee($employee, $actor);

    expect(Employee::withTrashed()->find($employee->id))->toBeNull();
    expect(User::withTrashed()->find($userId))->toBeNull();
    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('deleteEmployee refuses to delete a super admin', function () {
    $actor = purgeAdmin();
    $target = Employee::factory()->create();
    $target->user->update(['role' => UserRole::SuperAdmin]);

    expect(fn () => app(DataPurgeService::class)->deleteEmployee(Employee::find($target->id), $actor))
        ->toThrow(DomainException::class);

    expect(Employee::find($target->id))->not->toBeNull();
});

test('super admin can open data management; others cannot', function () {
    Livewire::actingAs(purgeAdmin())->test(DataManagement::class)->assertSee('Data Management');

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Livewire::actingAs($hr)->test(DataManagement::class)->assertForbidden();
});
