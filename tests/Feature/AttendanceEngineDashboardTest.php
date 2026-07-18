<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Livewire\Livewire;

function dashboardEmployee(): Employee
{
    $shift = ShiftSetting::create([
        'name' => 'Dash Shift',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'break_duration' => 60,
        'grace_minutes' => 10,
        'standard_hours' => 8,
        'ot_threshold_hours' => 9,
    ]);

    return Employee::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => 'active',
        'shift_id' => $shift->id,
    ]);
}

test('period working hours come from engine sessions, not the mis-paired attendance row', function () {
    $employee = dashboardEmployee();
    $day = today()->subDays(2);

    // The attendance row claims only 2h (mis-paired by the device sync)…
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 0),
        'check_out' => $day->copy()->setTime(11, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
        'total_hours' => 2.0,
    ]);

    // …but the raw punches prove a full 9-to-6 day with a 60m lunch (8h net).
    foreach ([['09:00:00', 'face'], ['13:00:00', 'id_card'], ['14:00:00', 'face'], ['18:00:00', 'id_card']] as [$t, $m]) {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => $day->toDateString().' '.$t,
            'punch_date' => $day->toDateString(),
            'method' => $m,
            'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        // 8h 0m from validated sessions — NOT the row's 2h.
        ->assertSet('stats.hours', '8h 0m')
        ->assertSet('chartDaily', function ($daily) use ($day) {
            $entry = collect($daily)->firstWhere('label', $day->format('d M'));

            return $entry !== null
                && $entry['hours'] === 8.0
                && $entry['break'] === 60
                && $entry['in_min'] === 9 * 60;   // arrival series data
        });
});

test('three late marks this month raise the Rule 10 warning and deduct from the score', function () {
    $employee = dashboardEmployee();

    foreach ([2, 3, 4] as $i) {
        $d = today()->startOfMonth()->addDays($i);
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => $d,
            'check_in' => $d->copy()->setTime(9, 45),
            'check_out' => $d->copy()->setTime(18, 0),
            'status' => 'late',
            'is_late' => true,
            'late_minutes' => 35,
            'work_mode' => 'office',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('analytics.late_month_count', 3)
        ->assertSet('analytics.late_warning', true)
        ->assertSet('analytics.late_penalty', 2)
        ->assertSee('Late-mark warning');
})->skip(fn () => today()->day < 6, 'Needs at least 5 elapsed days in the current month.');

test('changing the stats period recalculates the history list, not just the charts', function () {
    $employee = dashboardEmployee();

    $lastMonthDay = today()->subMonthNoOverflow()->startOfMonth()->addDays(9);
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $lastMonthDay,
        'check_in' => $lastMonthDay->copy()->setTime(9, 0),
        'check_out' => $lastMonthDay->copy()->setTime(18, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        // Default (this month) — last month's day is not in the log.
        ->assertSet('logTimeline', fn ($t) => collect($t)->pluck('date')->doesntContain($lastMonthDay->toDateString()))
        // Switch the filter — the history recomputes to the selected period.
        ->set('statsPeriod', 'last_month')
        ->assertSet('logTimeline', fn ($t) => collect($t)->pluck('date')->contains($lastMonthDay->toDateString()));
});

test('the day log exposes engine sessions and ignored card scans', function () {
    $employee = dashboardEmployee();
    $day = today()->subDays(3);

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 3),
        'check_out' => $day->copy()->setTime(18, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    // Stray card tap BEFORE the first face IN → must surface as ignored.
    foreach ([['08:58:00', 'id_card'], ['09:03:00', 'face'], ['18:00:00', 'id_card']] as [$t, $m]) {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => $day->toDateString().' '.$t,
            'punch_date' => $day->toDateString(),
            'method' => $m,
            'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('logTimeline', function ($t) use ($day) {
            $entry = collect($t)->firstWhere('date', $day->toDateString());

            return $entry !== null
                && count($entry['sessions']) === 1
                && count($entry['ignored_events']) === 1
                && str_contains($entry['ignored_events'][0]['reason'], 'card scan');
        });
});
