<?php

use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCoach;

function coachEmployee(): Employee
{
    $shift = ShiftSetting::create([
        'name' => 'Coach Shift',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'break_duration' => 60,
        'grace_minutes' => 10,
        'standard_hours' => 8,
        'ot_threshold_hours' => 9,
    ]);

    return Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => 'Coach Person'])->id,
        'status' => 'active',
        'shift_id' => $shift->id,
    ]);
}

test('an employee with no attendance gets an onboarding coach, not fake insights', function () {
    $employee = coachEmployee();

    $coach = app(AttendanceCoach::class)->analyze($employee, now()->startOfMonth(), now());

    expect($coach['has_data'])->toBeFalse()
        ->and($coach['headline'])->toContain('Coach')
        ->and($coach['score_change']['this_score'])->toBeNull();
});

test('the coach explains a score drop from the breakdown factors', function () {
    $employee = coachEmployee();

    // Last month: clean 100s. This month: two late-hit days scored lower.
    AttendanceDailyScore::create([
        'employee_id' => $employee->id,
        'date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3),
        'score' => 100, 'status' => 'on_time', 'breakdown' => [],
    ]);
    foreach ([2, 3] as $i) {
        AttendanceDailyScore::create([
            'employee_id' => $employee->id,
            'date' => now()->startOfMonth()->addDays($i),
            'score' => 92, 'status' => 'late',
            'breakdown' => [['factor' => 'late_arrival', 'label' => 'Late arrival', 'points' => -8, 'detail' => '']],
        ]);
    }

    $coach = app(AttendanceCoach::class)->analyze($employee, now()->startOfMonth(), now());

    expect($coach['score_change']['delta'])->toBeLessThan(0)
        ->and($coach['score_change']['reason'])->toContain('dropped')
        ->and(collect($coach['score_change']['drivers'])->pluck('factor'))->toContain('late_arrival');
})->skip(fn () => now()->day < 4, 'Needs a few elapsed days in the current month.');

test('warning risk is High once the late threshold is reached', function () {
    $employee = coachEmployee();

    foreach (range(0, 2) as $i) {
        $d = now()->startOfMonth()->addDays($i);
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => $d,
            'check_in' => $d->copy()->setTime(10, 0),
            'status' => 'late', 'is_late' => true, 'late_minutes' => 45, 'work_mode' => 'office',
        ]);
    }

    $coach = app(AttendanceCoach::class)->analyze($employee, now()->startOfMonth(), now());

    expect($coach['risk']['level'])->toBe('High')
        ->and($coach['risk']['tone'])->toBe('danger')
        ->and($coach['metrics']['risk']['level'])->toBe('High');
})->skip(fn () => now()->day < 3, 'Needs 3 elapsed days in the current month.');

test('arrival trend reads the average offset versus shift start', function () {
    $employee = coachEmployee();

    foreach (range(1, 4) as $i) {
        $d = now()->startOfMonth()->addDays($i);
        if ($d->isSunday()) {
            continue;
        }
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => $d,
            'check_in' => $d->copy()->setTime(9, 20),   // 20 min after 09:00 start
            'check_out' => $d->copy()->setTime(18, 0),
            'status' => 'on_time', 'work_mode' => 'office',
        ]);
    }

    $coach = app(AttendanceCoach::class)->analyze($employee, now()->startOfMonth(), now());

    expect($coach['arrival_trend']['avg_offset'])->toBe(20)
        ->and($coach['arrival_trend']['text'])->toContain('after your');
})->skip(fn () => now()->day < 5, 'Needs 4 elapsed days in the current month.');

test('achievements surface a perfect-days badge from real scores', function () {
    $employee = coachEmployee();

    foreach (range(1, 6) as $i) {
        AttendanceDailyScore::create([
            'employee_id' => $employee->id,
            'date' => now()->startOfMonth()->addDays($i),
            'score' => 100, 'status' => 'on_time', 'breakdown' => [],
        ]);
    }

    $coach = app(AttendanceCoach::class)->analyze($employee, now()->startOfMonth(), now());

    expect(collect($coach['achievements'])->pluck('label')->implode(' '))->toContain('perfect days');
})->skip(fn () => now()->day < 7, 'Needs the perfect days to fall inside the current month.');
