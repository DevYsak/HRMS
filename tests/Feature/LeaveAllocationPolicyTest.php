<?php

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Livewire\TimeOff\LeaveAllocationPolicies;
use App\Models\Employee;
use App\Models\LeaveAllocationPolicy;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

function leavePolicyAdmin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]);
}

function allocated(int $employeeId, int $typeId): float
{
    return (float) LeaveBalance::where('employee_id', $employeeId)->where('leave_type_id', $typeId)->value('allocated_days');
}

test('a matching policy overrides the uniform default on hire; non-matches fall back', function () {
    $type = LeaveType::create(['name' => 'Maternity', 'annual_allocation_days' => 12]);
    LeaveAllocationPolicy::create(['leave_type_id' => $type->id, 'gender' => 'female', 'allocated_days' => 26, 'is_active' => true]);

    $female = Employee::factory()->create(['gender' => 'female']);
    $male = Employee::factory()->create(['gender' => 'male']);

    expect(allocated($female->id, $type->id))->toBe(26.0); // policy
    expect(allocated($male->id, $type->id))->toBe(12.0);   // fallback to uniform default
});

test('the most specific matching policy wins', function () {
    $type = LeaveType::create(['name' => 'Annual', 'annual_allocation_days' => 10]);
    LeaveAllocationPolicy::create(['leave_type_id' => $type->id, 'gender' => 'female', 'allocated_days' => 15]);
    LeaveAllocationPolicy::create(['leave_type_id' => $type->id, 'gender' => 'female', 'requires_probation_complete' => true, 'allocated_days' => 20]);

    $employee = Employee::factory()->create(['gender' => 'female', 'status' => EmployeeStatus::Active->value]);

    expect(allocated($employee->id, $type->id))->toBe(20.0); // 2 conditions beats 1
});

test('requires_probation_complete excludes employees still on probation', function () {
    $type = LeaveType::create(['name' => 'Annual', 'annual_allocation_days' => 10]);
    LeaveAllocationPolicy::create(['leave_type_id' => $type->id, 'requires_probation_complete' => true, 'allocated_days' => 20]);

    $onProbation = Employee::factory()->create(['status' => EmployeeStatus::Probation->value]);

    expect(allocated($onProbation->id, $type->id))->toBe(10.0); // fallback
});

test('min service months excludes new joiners', function () {
    $type = LeaveType::create(['name' => 'Annual', 'annual_allocation_days' => 10]);
    LeaveAllocationPolicy::create(['leave_type_id' => $type->id, 'min_service_months' => 12, 'allocated_days' => 25]);

    $newJoiner = Employee::factory()->create(['joining_date' => now()->toDateString()]);

    expect(allocated($newJoiner->id, $type->id))->toBe(10.0); // fallback
});

test('admin can create a leave policy', function () {
    $admin = leavePolicyAdmin();
    $type = LeaveType::create(['name' => 'Annual', 'annual_allocation_days' => 10]);

    Livewire::actingAs($admin)->test(LeaveAllocationPolicies::class)
        ->call('openCreate')
        ->set('leave_type_id', (string) $type->id)
        ->set('allocated_days', '18')
        ->set('gender', 'female')
        ->call('save');

    expect(LeaveAllocationPolicy::where('leave_type_id', $type->id)->where('allocated_days', 18)->where('gender', 'female')->exists())->toBeTrue();
});

test('a non-admin cannot manage leave policies', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(LeaveAllocationPolicies::class)->assertForbidden();
});
