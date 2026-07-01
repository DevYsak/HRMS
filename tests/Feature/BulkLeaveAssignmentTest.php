<?php

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Livewire\TimeOff\BulkLeaveAssignment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\BulkLeaveService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

function bulkLeaveAdmin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]);
}

test('assign sets the allocation for every matched employee and audits each change', function () {
    $type = LeaveType::create(['name' => 'Annual', 'annual_allocation_days' => 0]);
    $e1 = Employee::factory()->create();
    $e2 = Employee::factory()->create();
    $actor = User::factory()->create();
    $year = now()->year;

    $count = app(BulkLeaveService::class)->apply(collect([$e1, $e2]), $type, 'assign', 12, 'Annual grant', $actor, $year);

    expect($count)->toBe(2);
    expect((float) LeaveBalance::where('employee_id', $e1->id)->where('leave_type_id', $type->id)->value('allocated_days'))->toBe(12.0);
    expect((float) LeaveBalance::where('employee_id', $e2->id)->where('leave_type_id', $type->id)->value('allocated_days'))->toBe(12.0);
    expect(LeaveBalanceAdjustment::where('leave_type_id', $type->id)->count())->toBe(2);
});

test('increase adds to the existing allocation', function () {
    $type = LeaveType::create(['name' => 'Casual', 'annual_allocation_days' => 0]);
    $e = Employee::factory()->create();
    LeaveBalance::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'year' => now()->year, 'allocated_days' => 10, 'used_days' => 0]);

    app(BulkLeaveService::class)->apply(collect([$e]), $type, 'increase', 5, 'Bump', User::factory()->create(), now()->year);

    expect((float) LeaveBalance::where('employee_id', $e->id)->value('allocated_days'))->toBe(15.0);
});

test('decrease never drops a balance below days already used', function () {
    $type = LeaveType::create(['name' => 'Sick', 'annual_allocation_days' => 0]);
    $e = Employee::factory()->create();
    LeaveBalance::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'year' => now()->year, 'allocated_days' => 10, 'used_days' => 8]);

    app(BulkLeaveService::class)->apply(collect([$e]), $type, 'decrease', 5, 'Trim', User::factory()->create(), now()->year);

    // floored at used (8), not 10 - 5 = 5
    expect((float) LeaveBalance::where('employee_id', $e->id)->value('allocated_days'))->toBe(8.0);
});

test('reset restores the leave type default allocation', function () {
    $type = LeaveType::create(['name' => 'Annual', 'annual_allocation_days' => 14]);
    $e = Employee::factory()->create(); // auto-seeded with 14 by the observer
    LeaveBalance::where('employee_id', $e->id)->where('leave_type_id', $type->id)->update(['allocated_days' => 25]);

    app(BulkLeaveService::class)->apply(collect([$e]), $type, 'reset', 0, 'Year reset', User::factory()->create(), now()->year);

    expect((float) LeaveBalance::where('employee_id', $e->id)->value('allocated_days'))->toBe(14.0);
});

test('system-controlled leave types cannot be bulk-assigned', function () {
    $type = LeaveType::create(['name' => 'Comp Off', 'annual_allocation_days' => 0, 'is_system_controlled' => true]);
    $e = Employee::factory()->create();

    expect(fn () => app(BulkLeaveService::class)->apply(collect([$e]), $type, 'assign', 5, 'x', User::factory()->create(), now()->year))
        ->toThrow(DomainException::class);
});

test('affectedEmployees filters employees', function () {
    $active = Employee::factory()->create(['status' => EmployeeStatus::Active->value]);
    $inactive = Employee::factory()->create(['status' => EmployeeStatus::Inactive->value]);

    $matched = app(BulkLeaveService::class)->affectedEmployees(['status' => EmployeeStatus::Active->value]);

    expect($matched->pluck('id')->all())->toContain($active->id);
    expect($matched->pluck('id')->all())->not->toContain($inactive->id);
});

test('admin can bulk-apply from the Livewire page', function () {
    $admin = bulkLeaveAdmin();
    $type = LeaveType::create(['name' => 'Annual', 'annual_allocation_days' => 0]);
    $e = Employee::factory()->create();

    Livewire::actingAs($admin)->test(BulkLeaveAssignment::class)
        ->assertSee('Bulk Leave Assignment')
        ->set('leave_type_id', $type->id)
        ->set('action', 'assign')
        ->set('days', 10)
        ->call('apply');

    expect((float) LeaveBalance::where('employee_id', $e->id)->where('leave_type_id', $type->id)->value('allocated_days'))->toBe(10.0);
});

test('a non-admin cannot open bulk leave assignment', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(BulkLeaveAssignment::class)->assertForbidden();
});
