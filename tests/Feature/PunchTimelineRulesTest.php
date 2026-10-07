<?php

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\PunchTimeline;
use Illuminate\Support\Carbon;

/**
 * The final punch-processing rules, for every employee:
 * Face = IN, ID Card = OUT (whatever the device tagged), the latest of a
 * same-direction burst within 60 seconds, opposite actions never merged,
 * stray cards ignored, repeated Face flagged as a missing OUT, worked = final
 * OUT − first IN with breaks informational only, and no cross-day spans.
 *
 * Clock: Wednesday 14 October 2026, 21:00 (the day is over).
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 21:00:00'));
    $shift = ShiftSetting::create([
        'name' => 'Rules Shift', 'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
    $this->employee = Employee::factory()->create(['status' => 'active', 'shift_id' => $shift->id, 'joining_date' => '2024-01-08']);
});

/** @param  array<int, array{0: string, 1: string, 2?: ?string}>  $punches  [time, method, device direction] */
function ptrDay(Employee $employee, array $punches, string $date = '2026-10-14'): array
{
    foreach ($punches as $p) {
        AttendancePunch::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => strlen($p[0]) > 8 ? $p[0] : $date.' '.$p[0],
            'punch_date' => $date,
            'method' => $p[1],
            'direction' => $p[2] ?? null,
            'source' => 'biometric',
        ]);
    }

    return app(PunchTimeline::class)->process(
        AttendancePunch::where('employee_id', $employee->id)->whereDate('punch_date', $date)->orderBy('punched_at')->get(),
        Carbon::parse($date),
    );
}

function ptrDirs(array $t): array
{
    return collect($t['nodes'])->pluck('dir')->all();
}

test('Face is always IN and ID Card always OUT, whatever the device tagged', function () {
    $t = ptrDay($this->employee, [['10:30:00', 'face', 'out'], ['19:30:00', 'id_card', 'in']]);

    expect(ptrDirs($t))->toBe(['IN', 'OUT'])
        ->and($t['flags']['direction_corrected'])->toBe(2)
        ->and(collect($t['raw_events'])->pluck('direction')->all())->toBe(['in', 'out']);
});

test('two Face punches within 60 seconds keep the latest', function () {
    $t = ptrDay($this->employee, [['10:30:01', 'face'], ['10:30:48', 'face'], ['19:30:00', 'id_card']]);

    expect($t['first_in_at']->format('H:i:s'))->toBe('10:30:48')
        ->and($t['duplicate_count'])->toBe(1)
        ->and(collect($t['raw_events'])->pluck('flag')->all())->toBe(['duplicate', 'kept', 'kept']);
});

test('three Face punches within 60 seconds keep only the latest', function () {
    $t = ptrDay($this->employee, [['10:30:01', 'face'], ['10:30:22', 'face'], ['10:30:48', 'face'], ['19:30:00', 'id_card']]);

    expect($t['first_in_at']->format('H:i:s'))->toBe('10:30:48')
        ->and($t['duplicate_count'])->toBe(2)
        ->and($t['kept_count'])->toBe(2);
});

test('two ID Card punches within 60 seconds keep the latest', function () {
    $t = ptrDay($this->employee, [['10:30:00', 'face'], ['19:30:03', 'id_card'], ['19:30:24', 'id_card']]);

    expect($t['last_out_at']->format('H:i:s'))->toBe('19:30:24')
        ->and($t['duplicate_count'])->toBe(1);
});

test('a Face IN and an ID Card OUT a minute apart are both kept', function () {
    $t = ptrDay($this->employee, [['10:30:00', 'face'], ['10:31:00', 'id_card']]);

    expect(ptrDirs($t))->toBe(['IN', 'OUT'])
        ->and($t['duplicate_count'] + $t['conflict_count'])->toBe(0)
        ->and($t['last_out_at']->format('H:i'))->toBe('10:31');
});

test('a stray ID Card with no Face IN is ignored and makes no hours', function () {
    $t = ptrDay($this->employee, [['09:15:00', 'id_card'], ['10:30:00', 'face'], ['19:30:00', 'id_card']]);

    expect($t['ignored_count'])->toBe(1)
        ->and($t['flags']['stray'])->toBe(1)
        ->and($t['first_in_at']->format('H:i'))->toBe('10:30')
        ->and($t['working_minutes'])->toBe(540);

    $only = ptrDay($this->employee, [['12:00:00', 'id_card']], '2026-10-13');
    expect($only['kept_count'])->toBe(0)->and($only['working_minutes'])->toBe(0);
});

test('a repeated Face outside 60 seconds is flagged as a missing OUT, never an invented one', function () {
    $t = ptrDay($this->employee, [['10:30:00', 'face'], ['13:00:00', 'face'], ['19:30:00', 'id_card']]);

    expect(ptrDirs($t))->toBe(['IN', 'OUT', 'IN', 'OUT'])
        ->and(collect($t['nodes'])->where('type', 'missing')->count())->toBe(1)
        ->and($t['needs_regularization'])->toBeTrue()
        ->and($t['flags']['missing_out'])->toBeTrue();
});

test('breaks come only from valid OUT → next IN, never from duplicates or strays', function () {
    $t = ptrDay($this->employee, [
        ['10:30:00', 'face'],
        ['13:00:00', 'id_card'], ['13:00:20', 'id_card'],   // duplicate OUT — latest kept
        ['13:30:00', 'id_card'],                              // stray (no open session)
        ['14:00:20', 'face'],
        ['19:30:00', 'id_card'],
    ]);

    expect($t['break_minutes'])->toBe(60)                    // 13:00:20 → 14:00:20
        ->and($t['ignored_count'])->toBe(1)
        ->and($t['session_count'])->toBe(2);
});

test('breaks are never deducted from worked hours — 10:30 → 19:30 is 9h00m', function () {
    $t = ptrDay($this->employee, [['10:30:00', 'face'], ['13:00:00', 'id_card'], ['14:00:00', 'face'], ['19:30:00', 'id_card']]);

    $day = app(AttendanceCalculator::class)->forDay($this->employee, '2026-10-14');

    expect($t['break_minutes'])->toBe(60)
        ->and($t['working_minutes'])->toBe(540)
        ->and($day->workedMinutes)->toBe(540)
        ->and($day->breakMinutes)->toBe(60);
});

test('the last valid punch decides: Face IN is live, ID Card OUT is completed', function () {
    $this->travelTo(Carbon::parse('2026-10-14 15:00:00'));

    $live = ptrDay($this->employee, [['10:30:00', 'face'], ['13:00:00', 'id_card'], ['14:00:00', 'face']]);
    expect($live['live'])->toBeTrue()
        ->and(app(AttendanceCalculator::class)->forDay($this->employee, '2026-10-14')->live)->toBeTrue();

    $other = Employee::factory()->create(['status' => 'active', 'shift_id' => $this->employee->shift_id, 'joining_date' => '2024-01-08']);
    $done = ptrDay($other, [['10:30:00', 'face'], ['14:30:00', 'id_card']]);
    expect($done['live'])->toBeFalse()
        ->and(app(AttendanceCalculator::class)->forDay($other, '2026-10-14')->status)->not->toBe('working');
});

test('no 20h/24h phantom sessions — a punch from another day never joins the day', function () {
    $t = ptrDay($this->employee, [['10:30:00', 'face'], ['2026-10-14 06:00:00', 'id_card']], '2026-10-13');

    expect($t['flags']['other_day'])->toBe(1)
        ->and($t['last_out_at'])->toBeNull()
        ->and($t['working_minutes'])->toBe(0)
        ->and(collect($t['raw_events'])->last()['note'])->toContain('another day');

    expect(app(AttendanceCalculator::class)->forDay($this->employee, '2026-10-13')->workedMinutes)->toBe(0);
});

test('a forgotten checkout stops counting once it is missing — no growing phantom day', function () {
    ptrDay($this->employee, [['10:30:00', 'face']]);

    $day = app(AttendanceCalculator::class)->forDay($this->employee, '2026-10-14');   // 21:00 > 19:30 + 60m

    expect($day->missingCheckout)->toBeTrue()
        ->and($day->workedMinutes)->toBe(0)
        ->and($day->live)->toBeFalse();
});

test('every employee and date is processed independently', function () {
    $second = Employee::factory()->create(['status' => 'active', 'shift_id' => $this->employee->shift_id, 'joining_date' => '2024-01-08']);

    $a = ptrDay($this->employee, [['10:30:00', 'face'], ['10:30:30', 'face'], ['19:30:00', 'id_card']], '2026-10-13');
    $b = ptrDay($this->employee, [['11:00:00', 'face'], ['18:00:00', 'id_card']], '2026-10-14');
    $c = ptrDay($second, [['10:45:00', 'face'], ['20:15:00', 'id_card']], '2026-10-14');

    expect($a['first_in_at']->format('H:i:s'))->toBe('10:30:30')
        ->and($a['working_minutes'])->toBe(539)
        ->and($b['working_minutes'])->toBe(420)
        ->and($c['working_minutes'])->toBe(570);
});
