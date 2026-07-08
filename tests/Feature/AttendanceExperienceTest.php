<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('my attendance page shows the Phase 6 analytics panels', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $d1 = now()->startOfMonth()->addDays(1);
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $d1->toDateString(),
        'check_in' => $d1->copy()->setTime(10, 45),
        'check_out' => $d1->copy()->setTime(19, 30),
        'status' => 'late',
        'is_late' => true,
        'total_hours' => 8,
        'work_mode' => 'office',
        'break_minutes' => 30,
    ]);

    $d2 = now()->startOfMonth()->addDays(2);
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $d2->toDateString(),
        'check_in' => $d2->copy()->setTime(10, 25),
        'check_out' => $d2->copy()->setTime(19, 30),
        'status' => 'remote',
        'is_late' => false,
        'total_hours' => 8,
        'work_mode' => 'wfh',
        'break_minutes' => 90,
    ]);

    Livewire::test(AttendanceTracker::class)
        ->assertSee('Attendance overview')
        ->assertSee('Attendance Score')
        ->assertSee('Working Hours Trend')
        ->assertSee('Attendance Score Trend')
        // Redesigned "Today" hero (replaced the old Shift Progress + Biometric cards).
        ->assertSee('Shift done')
        ->assertSee('Quick actions');
});
