<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\AttendanceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

function scoreEmployee(): Employee
{
    $shift = ShiftSetting::create([
        'name' => 'Score Shift',
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

/** A recent closed day that is never a Sunday (weekend bonus would skew math). */
function scoreDayDate(): CarbonInterface
{
    $d = today()->subDays(2);

    return $d->isSunday() ? $d->subDay() : $d;
}

test('a clean full day scores 100 with an empty audit trail', function () {
    $employee = scoreEmployee();
    $day = scoreDayDate();

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 0),
        'check_out' => $day->copy()->setTime(18, 0),
        'break_minutes' => 60,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    $score = app(AttendanceScoreEngine::class)->scoreDay($employee, $day);

    expect($score->score)->toBe(100.0)
        ->and($score->breakdown)->toBe([]);
});

test('late arrival, excess break and short hours each deduct with an audit line', function () {
    $employee = scoreEmployee();
    $day = scoreDayDate();

    // In 09:45 (35m past 09:10 cutoff), out 18:00, 90m break → 6h45m worked.
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 45),
        'check_out' => $day->copy()->setTime(18, 0),
        'break_minutes' => 90,
        'status' => 'late',
        'is_late' => true,
        'late_minutes' => 35,
        'work_mode' => 'office',
    ]);

    $score = app(AttendanceScoreEngine::class)->scoreDay($employee, $day);
    $factors = collect($score->breakdown)->pluck('points', 'factor');

    // late: -(3 + 1×⌊35/30⌋) = -4 · break: -2 · short hours: -5 → 89.
    expect($factors['late_arrival'])->toEqual(-4)
        ->and($factors['break_violation'])->toEqual(-2)
        ->and($factors['short_hours'])->toEqual(-5)
        ->and($score->score)->toEqual(89);
});

test('an absent working day scores 0 and an unworked Sunday is not scored', function () {
    $employee = scoreEmployee();
    $day = scoreDayDate();

    $absent = app(AttendanceScoreEngine::class)->scoreDay($employee, $day);
    expect($absent->score)->toBe(0.0)
        ->and($absent->breakdown[0]['factor'])->toBe('absent');

    $sunday = today()->subDays(7)->previous(Carbon::SUNDAY);
    expect(app(AttendanceScoreEngine::class)->scoreDay($employee, $sunday))->toBeNull();
});

test('overtime beyond the shift threshold earns the configured bonus, capped at 100', function () {
    $employee = scoreEmployee();
    $day = scoreDayDate();

    // 09:00 → 19:30 with 60m break = 9.5h worked > 9h threshold.
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 0),
        'check_out' => $day->copy()->setTime(19, 30),
        'break_minutes' => 60,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    $score = app(AttendanceScoreEngine::class)->scoreDay($employee, $day);

    expect(collect($score->breakdown)->pluck('factor'))->toContain('overtime')
        ->and($score->score)->toBe(100.0);   // capped
});

test('approving a regularization rescores the day immediately', function () {
    $employee = scoreEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $day = scoreDayDate();

    $attendance = Attendance::create([
        'employee_id' => $employee->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'attendance_id' => $attendance->id,
        'work_date' => $day->toDateString(),
        'regularisation_type' => 'punch',
        'requested_check_in' => $day->toDateString().' 09:00:00',
        'requested_check_out' => $day->toDateString().' 18:00:00',
        'reason' => 'Forgot to punch out',
        'status' => 'pending',
        'stage' => 'admin_approval',
    ]);

    app(AttendanceService::class)->approveRegularisation($reg, $admin->id);

    $score = AttendanceDailyScore::where('employee_id', $employee->id)
        ->whereDate('date', $day->toDateString())
        ->first();

    expect($score)->not->toBeNull()
        ->and(collect($score->breakdown)->pluck('factor'))->toContain('regularization');
});

test('the decision payload explains shift, punches and the persisted breakdown', function () {
    $employee = scoreEmployee();
    $day = scoreDayDate();

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $day,
        'check_in' => $day->copy()->setTime(9, 45),
        'check_out' => $day->copy()->setTime(18, 0),
        'break_minutes' => 30,
        'status' => 'late',
        'is_late' => true,
        'late_minutes' => 35,
        'work_mode' => 'office',
    ]);

    $engine = app(AttendanceScoreEngine::class);
    $engine->scoreDay($employee, $day);
    $decision = $engine->explainDay($employee, $day);

    expect($decision['shift']['window'])->toBe('09:00 AM – 06:00 PM')
        ->and($decision['shift']['grace'])->toBe('10 minutes')
        ->and($decision['late'])->toBe('35m late')
        ->and($decision['score'])->not->toBeNull()
        ->and(collect($decision['breakdown'])->pluck('factor'))->toContain('late_arrival');
});

test('monthly ranking orders employees by average score', function () {
    $top = scoreEmployee();
    $low = Employee::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => 'active',
        'shift_id' => $top->shift_id,
    ]);
    $day = scoreDayDate();

    AttendanceDailyScore::create(['employee_id' => $top->id, 'date' => $day, 'score' => 95, 'status' => 'on_time', 'breakdown' => []]);
    AttendanceDailyScore::create(['employee_id' => $low->id, 'date' => $day, 'score' => 60, 'status' => 'late', 'breakdown' => []]);

    $engine = app(AttendanceScoreEngine::class);

    expect($engine->rankAmong($top, [$top->id, $low->id], now()))->toBe([1, 2])
        ->and($engine->rankAmong($low, [$top->id, $low->id], now()))->toBe([2, 2])
        ->and($engine->monthlyScore($low, now()))->toBe(60.0);
})->skip(fn () => today()->day <= 2, 'Needs the scored day to fall inside the current month.');

test('the nightly command scores yesterday for active employees', function () {
    $employee = scoreEmployee();
    $yesterday = today()->subDay();

    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $yesterday,
        'check_in' => $yesterday->copy()->setTime(9, 0),
        'check_out' => $yesterday->copy()->setTime(18, 0),
        'break_minutes' => 60,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    $this->artisan('hrms:compute-attendance-scores')->assertSuccessful();

    expect(AttendanceDailyScore::where('employee_id', $employee->id)->whereDate('date', $yesterday)->exists())->toBeTrue();
});
