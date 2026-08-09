<?php

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\PunchClassifier;
use App\Services\Attendance\PunchTimeline;
use Illuminate\Support\Carbon;

/**
 * The classification layer of the punch pipeline:
 *
 *   raw punches → dedupe → PunchTimeline → neutral events → enrich() → journey
 *
 * The 3.0 timeline redesign left the employee's Attendance Journey showing bare
 * IN/OUT: break and resume interpretation was still implemented and unit-tested
 * in PunchClassifier, but nothing wired it into the page any more. These pin
 * the restored behaviour, and pin that enrichment never alters the worked or
 * break minutes the timeline computed.
 *
 * Punch methods matter. config/biometric.php maps face/fingerprint to IN and
 * id_card/physical_card to OUT, so a day is modelled the way the reader
 * actually reports it rather than as an alternating sequence.
 */
const J_IN = 'face';
const J_OUT = 'id_card';

function jPunch(int $employeeId, string $time, string $method): AttendancePunch
{
    return AttendancePunch::create([
        'employee_id' => $employeeId,
        'punched_at' => Carbon::today()->toDateString().' '.(strlen($time) === 5 ? $time.':00' : $time),
        'punch_date' => Carbon::today(),
        'method' => $method,
        'source' => 'biometric',
    ]);
}

/** @param array<int, array{0:string,1:string}> $punches [time, method] */
function jDay(Employee $employee, array $punches): array
{
    foreach ($punches as [$time, $method]) {
        jPunch($employee->id, $time, $method);
    }

    $raw = AttendancePunch::where('employee_id', $employee->id)->orderBy('punched_at')->get();

    return app(PunchClassifier::class)->enrich(app(PunchTimeline::class)->neutralEvents($raw));
}

beforeEach(function () {
    $this->emp = Employee::factory()->create();
});

test('scenario 1 — a plain IN then OUT is clock in and clock out', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['18:00', J_OUT]]);

    expect($j)->toHaveCount(2)
        ->and($j[0]['type'])->toBe('in')
        ->and($j[0]['title'])->toBe('Clocked in')
        ->and($j[1]['type'])->toBe('out')
        ->and($j[1]['title'])->toBe('Clocked out');
});

test('scenario 2 — a duplicate IN burst keeps the first arrival', function () {
    $j = jDay($this->emp, [['09:00:00', J_IN], ['09:00:40', J_IN], ['18:00', J_OUT]]);

    expect($j)->toHaveCount(2)
        // First read wins for an arrival — they were at the door then.
        ->and($j[0]['time'])->toBe('09:00 AM');
});

test('scenario 3 — a duplicate OUT burst keeps the last departure', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['18:00:00', J_OUT], ['18:00:50', J_OUT]]);

    expect($j)->toHaveCount(2)
        ->and($j[1]['type'])->toBe('out')
        // Last read wins for a departure — they were gone after it.
        ->and($j[1]['time'])->toBe('06:00 PM');
});

test('scenario 4 — IN OUT IN OUT is a break between two sessions', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['12:00', J_OUT], ['14:00', J_IN], ['18:00', J_OUT]]);

    expect($j)->toHaveCount(4)
        ->and($j[0]['type'])->toBe('in')
        ->and($j[1]['type'])->toBe('break')
        ->and($j[2]['type'])->toBe('resume')
        ->and($j[3]['type'])->toBe('out');
});

test('scenario 5 — IN break resume OUT is named end to end', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['13:00', J_OUT], ['13:45', J_IN], ['18:00', J_OUT]]);

    expect($j[0]['title'])->toBe('Clocked in')
        ->and($j[1]['title'])->toBe('Lunch break · 45m')
        ->and($j[2]['title'])->toBe('Returned from break')
        ->and($j[3]['title'])->toBe('Clocked out');
});

test('scenario 6 — multiple breaks are each classified on their own merits', function () {
    $j = jDay($this->emp, [
        ['09:00', J_IN], ['11:00', J_OUT], ['11:10', J_IN],
        ['13:00', J_OUT], ['13:45', J_IN], ['18:00', J_OUT],
    ]);

    expect($j)->toHaveCount(6)
        ->and($j[1]['title'])->toBe('Tea break · 10m')       // short, mid-morning
        ->and($j[2]['type'])->toBe('resume')
        ->and($j[3]['title'])->toBe('Lunch break · 45m')     // long enough, midday
        ->and($j[4]['type'])->toBe('resume')
        ->and($j[5]['title'])->toBe('Clocked out');
});

test('scenario 7 — a midday gap of 20 minutes or more is lunch', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['13:00', J_OUT], ['13:30', J_IN], ['18:00', J_OUT]]);

    expect($j[1]['title'])->toBe('Lunch break · 30m')
        ->and($j[1]['break_minutes'])->toBe(30);
});

test('scenario 8 — a short midday gap is a tea break, not a meal', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['13:00', J_OUT], ['13:10', J_IN], ['18:00', J_OUT]]);

    expect($j[1]['title'])->toBe('Tea break · 10m');
});

test('scenario 9 — a gap over 90 minutes is a long break whatever the hour', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['12:00', J_OUT], ['14:00', J_IN], ['18:00', J_OUT]]);

    expect($j[1]['title'])->toBe('Long break · 120m');
});

test('scenario 10 — a same-reader double read does not invent a break', function () {
    $j = jDay($this->emp, [['09:00:00', J_IN], ['09:00:03', J_IN], ['18:00', J_OUT]]);

    expect($j)->toHaveCount(2)
        ->and(collect($j)->pluck('type')->all())->not->toContain('break');
});

test('scenario 11 — same-direction duplicates inside the window collapse to one', function () {
    $j = jDay($this->emp, [
        ['09:00:00', J_IN], ['09:00:30', J_IN], ['09:00:50', J_IN], ['18:00', J_OUT],
    ]);

    expect($j)->toHaveCount(2)
        ->and($j[0]['time'])->toBe('09:00 AM');
});

test('scenario 12 — a second IN with no OUT between is not called a resume', function () {
    // The OUT was never recorded. Labelling this "Returned from break" would
    // hide the very punch the employee needs to regularise.
    $j = jDay($this->emp, [['09:00', J_IN], ['13:00', J_IN]]);

    expect($j)->toHaveCount(2)
        ->and($j[0]['title'])->toBe('Clocked in')
        ->and($j[1]['type'])->toBe('in')
        ->and($j[1]['title'])->toBe('Punch in')
        ->and(collect($j)->pluck('type')->all())->not->toContain('resume');
});

test('scenario 13 — the final OUT is the departure, never a break', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['13:00', J_OUT], ['13:45', J_IN], ['18:00', J_OUT]]);

    $outs = collect($j)->where('type', 'out');

    expect($outs)->toHaveCount(1)
        ->and($outs->first()['time'])->toBe('06:00 PM');
});

test('scenario 14 — an employee still working shows no closing event', function () {
    $j = jDay($this->emp, [['09:00', J_IN]]);

    expect($j)->toHaveCount(1)
        ->and($j[0]['type'])->toBe('in')
        ->and($j[0]['title'])->toBe('Clocked in');
});

test('enrichment never changes the worked or break minutes the timeline computed', function () {
    // The classifier names things; it must not quietly re-derive the numbers
    // payroll depends on.
    foreach ([['09:00', J_IN], ['13:00', J_OUT], ['13:45', J_IN], ['18:00', J_OUT]] as [$t, $m]) {
        jPunch($this->emp->id, $t, $m);
    }

    $raw = AttendancePunch::where('employee_id', $this->emp->id)->orderBy('punched_at')->get();
    $before = app(PunchTimeline::class)->process($raw, Carbon::today());

    app(PunchClassifier::class)->enrich(app(PunchTimeline::class)->neutralEvents($raw));

    $after = app(PunchTimeline::class)->process($raw, Carbon::today());

    expect($after['working_minutes'])->toBe($before['working_minutes'])
        ->and($after['break_minutes'])->toBe($before['break_minutes'])
        // 09:00-13:00 plus 13:45-18:00 worked, 45m away.
        ->and($after['break_minutes'])->toBe(45)
        ->and($after['working_minutes'])->toBe(495);
});

test('an empty day classifies to an empty journey', function () {
    expect(jDay($this->emp, []))->toBe([]);
});

test('the gap on each event is the time since the previous punch', function () {
    $j = jDay($this->emp, [['09:00', J_IN], ['13:00', J_OUT], ['13:37', J_IN], ['18:00', J_OUT]]);

    expect($j[0]['gap_min'])->toBeNull()
        ->and($j[1]['gap_min'])->toBe(240)   // 09:00 → 13:00
        ->and($j[2]['gap_min'])->toBe(37)    // the break itself
        ->and($j[3]['gap_min'])->toBe(263);  // 13:37 → 18:00
});
