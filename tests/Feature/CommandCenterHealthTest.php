<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\CommandCenter;
use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('the command center shows an engine-scored attendance health strip', function () {
    // A working Wednesday after shift end: "absent" means due today, never in,
    // shift over (TodayStatusService) — not a weekend or before the shift.
    $this->travelTo(Carbon::parse('2026-10-14 20:00:00'));
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    // A shift, so the day can be judged: absent = never in by shift end.
    $shift = ShiftSetting::create(['name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 9, 'ot_threshold_hours' => 9]);
    $present = Employee::factory()->create(['status' => 'active', 'shift_id' => $shift->id, 'joining_date' => '2024-01-01']);
    $risky = Employee::factory()->create(['status' => 'active', 'shift_id' => $shift->id, 'joining_date' => '2024-01-01']);

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
