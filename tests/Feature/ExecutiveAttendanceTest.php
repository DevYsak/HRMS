<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\ExecutiveAttendance;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('a regular employee cannot open the executive dashboard', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(ExecutiveAttendance::class)
        ->assertForbidden();
});

test('the executive dashboard renders the KPI sections for HR', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(ExecutiveAttendance::class)
        ->assertOk()
        ->assertSee('Executive Attendance')
        ->assertSee('Company Attendance')
        ->assertSee('Workforce Availability')
        ->assertSee('Monthly Attendance Trend')
        ->assertSee('AI Insights')
        ->assertSee('Top Performers')
        ->assertSee('Attendance Risk');
});

test('company attendance percent reflects present records this month', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    // One active employee, present on a past weekday this month.
    $emp = Employee::factory()->create(['status' => 'active', 'manager_id' => null]);
    $day = now()->startOfMonth()->next(Carbon::MONDAY);
    if ($day->gt(now())) {
        $day = now()->startOfMonth();
    }
    Attendance::create([
        'employee_id' => $emp->id, 'date' => $day->toDateString(),
        'check_in' => $day->copy()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office',
    ]);

    Livewire::actingAs($hr)->test(ExecutiveAttendance::class)
        ->assertViewHas('companyPct', fn ($v) => $v > 0)
        ->assertViewHas('activeCount', fn ($v) => $v >= 1);
});

test('a department below 80 percent surfaces as attendance risk', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $dept = Department::factory()->create(['name' => 'RISKY DEPT']);
    // 3 employees in the dept, none present → 0% attendance.
    Employee::factory()->count(3)->create(['status' => 'active', 'department_id' => $dept->id, 'manager_id' => null]);

    Livewire::actingAs($hr)->test(ExecutiveAttendance::class)
        ->assertViewHas('risk', fn ($risk) => collect($risk)->contains(fn ($r) => $r['name'] === 'RISKY DEPT'))
        ->assertSee('RISKY DEPT');
});
