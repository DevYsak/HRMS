<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\TeamAttendance;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('the team live board classifies members by their status today', function () {
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $managerUser->id, 'manager_id' => null]);

    $lateUser = User::factory()->create(['name' => 'LATEPERSON']);
    $late = Employee::factory()->create(['user_id' => $lateUser->id, 'manager_id' => $managerUser->id, 'status' => 'active']);
    Attendance::create([
        'employee_id' => $late->id,
        'date' => today(),
        'check_in' => now()->setTime(11, 0),
        'is_late' => true,
        'status' => 'late',
        'work_mode' => 'wfh',
    ]);

    $absentUser = User::factory()->create(['name' => 'ABSENTPERSON']);
    Employee::factory()->create(['user_id' => $absentUser->id, 'manager_id' => $managerUser->id, 'status' => 'active']);

    Livewire::actingAs($managerUser)->test(TeamAttendance::class)
        ->assertOk()
        ->assertSee('Live Board')
        ->assertSee('LATEPERSON')
        ->assertSee('ABSENTPERSON')
        ->assertViewHas('boardStats', fn ($s) => $s['late'] === 1 && $s['absent'] === 1 && $s['wfh'] === 1 && $s['working'] === 1);
});

test('a completed team member is not counted as working', function () {
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $managerUser->id, 'manager_id' => null]);

    $doneUser = User::factory()->create();
    $done = Employee::factory()->create(['user_id' => $doneUser->id, 'manager_id' => $managerUser->id, 'status' => 'active']);
    Attendance::create([
        'employee_id' => $done->id,
        'date' => today(),
        'check_in' => now()->setTime(9, 0),
        'check_out' => now()->setTime(18, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    Livewire::actingAs($managerUser)->test(TeamAttendance::class)
        ->assertViewHas('boardStats', fn ($s) => $s['working'] === 0 && $s['office'] === 1);
});
