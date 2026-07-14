<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

/**
 * Department/shift-scoped HR: a scoped HR admin only sees and can act on the
 * employees inside their scope; company-wide HR (no scope) sees everyone.
 */
beforeEach(function () {
    $this->deptA = Department::factory()->create(['name' => 'Engineering']);
    $this->deptB = Department::factory()->create(['name' => 'Sales']);

    $this->empA = Employee::factory()->create([
        'status' => 'active', 'department_id' => $this->deptA->id,
        'user_id' => User::factory()->create(['name' => 'ALICE ENG'])->id,
    ]);
    $this->empB = Employee::factory()->create([
        'status' => 'active', 'department_id' => $this->deptB->id,
        'user_id' => User::factory()->create(['name' => 'BOB SALES'])->id,
    ]);

    foreach ([$this->empA, $this->empB] as $e) {
        Attendance::create([
            'employee_id' => $e->id, 'date' => today(),
            'check_in' => today()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office',
        ]);
    }
});

test('a department-scoped HR only sees employees in their department', function () {
    $hr = User::factory()->create([
        'role' => UserRole::HrAdmin,
        'scope_departments' => [$this->deptA->id],
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertSee('ALICE ENG')
        ->assertDontSee('BOB SALES')
        ->assertViewHas('stats', fn ($s) => $s['total'] === 1);   // only Engineering counted
});

test('a company-wide HR (no scope) sees everyone', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertSee('ALICE ENG')
        ->assertSee('BOB SALES')
        ->assertViewHas('stats', fn ($s) => $s['total'] === 2);
});

test('a scoped HR cannot open the drawer of an out-of-scope employee', function () {
    $hr = User::factory()->create([
        'role' => UserRole::HrAdmin,
        'scope_departments' => [$this->deptA->id],
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $this->empB->id)   // Sales — out of scope
        ->assertForbidden();
});

test('coversEmployee honours department and shift scope', function () {
    $hr = new User(['role' => UserRole::HrAdmin]);
    $hr->scope_departments = [$this->deptA->id];

    expect($hr->coversEmployee($this->empA))->toBeTrue()
        ->and($hr->coversEmployee($this->empB))->toBeFalse();

    // Super admin always covers everyone regardless of scope.
    $admin = new User(['role' => UserRole::SuperAdmin]);
    $admin->scope_departments = [$this->deptA->id];
    expect($admin->coversEmployee($this->empB))->toBeTrue();
});
