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

test('bulkDeleteEmployees deletes many and skips protected accounts', function () {
    $actor = purgeAdmin();
    $e1 = Employee::factory()->create();
    $e2 = Employee::factory()->create();
    $admin = Employee::factory()->create();
    $admin->user->update(['role' => UserRole::SuperAdmin]);

    $result = app(DataPurgeService::class)->bulkDeleteEmployees([$e1->id, $e2->id, $admin->id], $actor);

    expect($result['deleted'])->toBe(2);
    expect($result['skipped'])->toBe(1);
    expect(Employee::find($e1->id))->toBeNull();
    expect(Employee::find($e2->id))->toBeNull();
    expect(Employee::find($admin->id))->not->toBeNull();
});

test('deletableEmployees excludes super admins and the actor', function () {
    $actor = purgeAdmin();
    $actorEmp = Employee::factory()->create(['user_id' => $actor->id]);
    $regular = Employee::factory()->create();
    $sa = Employee::factory()->create();
    $sa->user->update(['role' => UserRole::SuperAdmin]);

    $ids = app(DataPurgeService::class)->deletableEmployees($actor)->pluck('id');

    expect($ids->all())->toContain($regular->id);
    expect($ids->all())->not->toContain($actorEmp->id);
    expect($ids->all())->not->toContain($sa->id);
});

test('super admin can open data management; others cannot', function () {
    Livewire::actingAs(purgeAdmin())->test(DataManagement::class)->assertSee('Data Management');

    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Livewire::actingAs($hr)->test(DataManagement::class)->assertForbidden();
});
