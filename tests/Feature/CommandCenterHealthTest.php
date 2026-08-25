<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\CommandCenter;
use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('the command center shows an engine-scored attendance health strip', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $present = Employee::factory()->create(['status' => 'active']);
    $risky = Employee::factory()->create(['status' => 'active']);

    Attendance::create([
        'employee_id' => $present->id,
        'date' => today(),
        'check_in' => today()->setTime(9, 0),
        'status' => 'on_time', 'work_mode' => 'office',
    ]);

    // MTD engine scores: one healthy, one at-risk (< 60).
    AttendanceDailyScore::create(['employee_id' => $present->id, 'date' => today()->startOfMonth(), 'score' => 95, 'status' => 'on_time', 'breakdown' => []]);
    AttendanceDailyScore::create(['employee_id' => $risky->id, 'date' => today()->startOfMonth(), 'score' => 40, 'status' => 'late', 'breakdown' => []]);

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->assertOk()
        ->assertSee('Attendance')
        ->assertViewHas('health', function ($h) {
            return $h['active'] === 2
                && $h['present_today'] === 1
                && $h['absent_today'] === 1
                && $h['at_risk'] === 1                 // the 40-score member
                && $h['bands']['excellent'] === 1      // the 95-score member
                && $h['mtd_score'] === 68;             // avg of 95 & 40
        });
})->skip(fn () => today()->day < 1, 'Runs any day; MTD includes the 1st.');

test('a regular employee cannot open the command center', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(CommandCenter::class)
        ->assertForbidden();
});
