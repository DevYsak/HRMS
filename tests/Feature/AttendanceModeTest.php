<?php

use App\Enums\AttendanceMode;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Services\AttendanceService;
use Livewire\Livewire;

function dayShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'Day',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 5,
        'break_duration' => 60,
        'standard_hours' => 9,
    ]);
}

test('the attendance service persists every supported work mode', function () {
    $shift = dayShift();

    foreach (AttendanceMode::values() as $mode) {
        $employee = Employee::factory()->create();
        $attendance = app(AttendanceService::class)->checkIn($employee, $shift, ['work_mode' => $mode]);
        expect($attendance->work_mode)->toBe($mode);
    }
});

test('an unknown work mode falls back to office', function () {
    $employee = Employee::factory()->create();

    $attendance = app(AttendanceService::class)->checkIn($employee, dayShift(), ['work_mode' => 'mars']);

    expect($attendance->work_mode)->toBe('office');
});

test('clocking in from the tracker records the selected mode', function () {
    $shift = dayShift();
    $employee = Employee::factory()->create(['shift_id' => $shift->id]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->set('workMode', 'client_visit')
        ->call('checkIn');

    expect(Attendance::where('employee_id', $employee->id)->value('work_mode'))->toBe('client_visit');
});

test('the tracker shows the multi-mode breakdown and legend', function () {
    $employee = Employee::factory()->create();
    $d = now()->startOfMonth()->addDays(3);
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $d->toDateString(),
        'check_in' => $d->copy()->setTime(10, 0),
        'check_out' => $d->copy()->setTime(18, 0),
        'status' => 'on_time',
        'work_mode' => 'hybrid',
        'total_hours' => 8,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Work Pattern')
        ->assertSee('Hybrid')
        ->assertSee('Client'); // mode legend always renders every mode
});

test('the tracker supports a weekly filter and renders the analytics charts', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('This Week')
        ->assertSee('Working Hours Trend')
        ->assertSee('Work Mode Split')
        ->assertSee('Late Arrival Trend')
        ->set('statsPeriod', 'this_week')
        ->assertOk()
        ->assertSet('statsPeriod', 'this_week');
});
