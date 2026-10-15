<?php

use App\Exports\EmployeesExport;
use App\Livewire\Employees\EmployeeImport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The employee export carries identity and bank details, so it follows the
 * exporter's manage-employees scope (Phase 1 export audit): an HR admin
 * scoped to one department exports only that department.
 */
function eesHr(Department $department, ?string $scope = null): User
{
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
    if ($scope !== null) {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id, 'permission_id' => Permission::where('key', 'manage_employees')->value('id'),
            'effect' => 'grant', 'scope' => $scope, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::forget(UserPermissionOverride::cacheKey($user->id));
    }

    return $user->fresh();
}

beforeEach(function () {
    $this->own = Department::factory()->create();
    $this->other = Department::factory()->create();
    $this->inside = Employee::factory()->create(['department_id' => $this->own->id, 'employee_id' => 'EXP-IN-1', 'manager_id' => null]);
    $this->outside = Employee::factory()->create(['department_id' => $this->other->id, 'employee_id' => 'EXP-OUT-1', 'manager_id' => null]);
});

test('a department-scoped HR admin exports only their department', function () {
    $hr = eesHr($this->own, 'department');
    $ids = $hr->accessibleEmployeeIds('manage_employees');

    $exported = collect((new EmployeesExport($ids))->rows())->pluck(0);

    expect($exported)->toContain('EXP-IN-1')->not->toContain('EXP-OUT-1');
});

test('company-wide HR exports everyone', function () {
    $hr = eesHr($this->own);

    expect($hr->accessibleEmployeeIds('manage_employees'))->toBeNull();
    expect(collect((new EmployeesExport(null))->rows())->pluck(0))->toContain('EXP-IN-1')->toContain('EXP-OUT-1');

    Livewire::actingAs($hr)->test(EmployeeImport::class)->call('exportEmployees')->assertFileDownloaded();
});
