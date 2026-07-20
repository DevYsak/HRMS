<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\ExecutiveAttendance;
use App\Livewire\Attendance\TeamAttendance;
use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('the executive dashboard scopes every metric to the department filter', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $deptA = Department::factory()->create(['name' => 'ALPHA DEPT']);
    $deptB = Department::factory()->create(['name' => 'BETA DEPT']);

    Employee::factory()->create(['status' => 'active', 'department_id' => $deptA->id]);
    Employee::factory()->create(['status' => 'active', 'department_id' => $deptB->id]);

    Livewire::actingAs($hr)->test(ExecutiveAttendance::class)
        // No filter → both departments counted.
        ->assertViewHas('activeCount', 2)
        // Filter to A → only its headcount remains (recomputed, not cached).
        ->set('departmentId', $deptA->id)
        ->assertViewHas('activeCount', 1);
});

test('the executive attendance score comes from the engine daily scores', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $emp = Employee::factory()->create(['status' => 'active']);

    AttendanceDailyScore::create([
        'employee_id' => $emp->id,
        'date' => now()->startOfMonth()->addDay(),
        'score' => 76, 'status' => 'on_time', 'breakdown' => [],
    ]);

    Livewire::actingAs($hr)->test(ExecutiveAttendance::class)
        ->assertViewHas('attendanceScore', 76);
});

test('changing the executive period recomputes the range label', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['status' => 'active']);

    Livewire::actingAs($hr)->test(ExecutiveAttendance::class)
        ->set('period', 'last_month')
        ->assertViewHas('rangeLabel', fn ($l) => str_contains($l, now()->subMonthNoOverflow()->format('M')));
});

test('the team dashboard analytics strip aggregates the period from the engine', function () {
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $managerUser->id, 'manager_id' => null, 'status' => 'active']);
    $report = Employee::factory()->create(['status' => 'active', 'manager_id' => $managerUser->id]);

    $day = now()->startOfMonth()->addDay();
    Attendance::create([
        'employee_id' => $report->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 0),
        'check_out' => $day->copy()->setTime(18, 0),
        'break_minutes' => 60,
        'status' => 'on_time', 'work_mode' => 'office',
    ]);
    AttendanceDailyScore::create([
        'employee_id' => $report->id, 'date' => $day, 'score' => 88, 'status' => 'on_time', 'breakdown' => [],
    ]);

    Livewire::actingAs($managerUser)->test(TeamAttendance::class)
        ->assertViewHas('periodStats', fn ($s) => $s['present'] === 1 && $s['avg_score'] === 88)
        ->assertViewHas('scoreRanking', fn ($r) => count($r) === 1 && $r[0]['score'] === 88.0);
})->skip(fn () => now()->day < 2, 'Needs an elapsed day in the current month.');
