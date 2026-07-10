<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Session-based Attendance Journey: neutral IN/OUT pairing, working/break
 * minutes, duplicate collapsing, live timer, and missing-punch detection.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(18, 30));
    $this->user = User::factory()->create();
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Insert a punch with no method, so direction comes from alternation (these
 * tests predate the Face=IN / Card=OUT method-direction mapping).
 */
function punchAt(int $employeeId, string $time): void
{
    $at = Carbon::today()->setTimeFromTimeString($time);
    AttendancePunch::factory()->create([
        'employee_id' => $employeeId,
        'punched_at' => $at,
        'punch_date' => $at->toDateString(),
        'method' => null,
    ]);
}

/** Insert a punch by verification method (face → IN, id_card → OUT on this device). */
function methodPunch(int $employeeId, string $time, string $method): void
{
    $at = Carbon::today()->setTimeFromTimeString($time);
    AttendancePunch::factory()->create([
        'employee_id' => $employeeId,
        'punched_at' => $at,
        'punch_date' => $at->toDateString(),
        'method' => $method,
        'direction' => null,   // direction is derived from method
    ]);
}

test('two clean punch pairs form two sessions with correct working and break time', function () {
    punchAt($this->employee->id, '09:00'); // in
    punchAt($this->employee->id, '11:30'); // out
    punchAt($this->employee->id, '11:45'); // in
    punchAt($this->employee->id, '18:15'); // out

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['session_count'])->toBe(2)
        ->and($pj['working_minutes'])->toBe(540) // 2h30m + 6h30m
        ->and($pj['break_minutes'])->toBe(15)     // 11:30 -> 11:45
        ->and($pj['live'])->toBeFalse()
        ->and($pj['missing_out'])->toBeFalse()
        ->and($pj['duplicate_count'])->toBe(0)
        ->and($pj['first_in'])->toBe('09:00 AM')
        ->and($pj['last_out'])->toBe('06:15 PM');
});

test('a trailing unmatched IN today is a live session, not a break', function () {
    punchAt($this->employee->id, '09:00'); // in
    punchAt($this->employee->id, '13:00'); // out
    punchAt($this->employee->id, '13:30'); // in (still inside — no out)

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['live'])->toBeTrue()
        ->and($pj['missing_out'])->toBeFalse()
        ->and($pj['session_count'])->toBe(2)
        ->and($pj['live_start_label'])->toBe('01:30 PM')
        // working = 4h (09-13) + live 5h (13:30 -> 18:30) = 540
        ->and($pj['working_minutes'])->toBe(540)
        ->and($pj['live_start_ms'])->not->toBeNull();
});

test('duplicate punches inside the debounce window are collapsed and counted', function () {
    punchAt($this->employee->id, '09:00:00'); // in
    punchAt($this->employee->id, '09:00:05'); // duplicate read (< 4 min)
    punchAt($this->employee->id, '17:00');     // out

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['raw_count'])->toBe(3)
        ->and($pj['kept_count'])->toBe(2)
        ->and($pj['duplicate_count'])->toBe(1)
        ->and($pj['session_count'])->toBe(1)
        ->and($pj['working_minutes'])->toBe(480);
});

test('a past day with an unmatched IN is flagged as a missing punch', function () {
    $day = Carbon::today()->subDay();
    foreach (['09:00' => true, '18:00' => false, '18:30' => true] as $time => $_) {
        $at = $day->copy()->setTimeFromTimeString($time);
        AttendancePunch::factory()->create([
            'employee_id' => $this->employee->id,
            'punched_at' => $at,
            'punch_date' => $at->toDateString(),
        ]);
    }

    // buildPunchJourney only reads today; exercise the assembler for the past day.
    $tracker = new AttendanceTracker;
    $raw = AttendancePunch::where('employee_id', $this->employee->id)
        ->orderBy('punched_at')->get();

    $pj = (fn () => $this->assemblePunchJourney($raw, $day))->call($tracker);

    expect($pj['missing_out'])->toBeTrue()
        ->and($pj['live'])->toBeFalse()
        ->and(collect($pj['nodes'])->last()['type'])->toBe('missing');
});

test('engine-directed punches pair by real IN/OUT, not alternation', function () {
    // Mayuresh's noisy stream: a 12:58 return two minutes after a 12:56 exit, and
    // a triple 13:30 cluster. Alternation + dedup mangles this into a huge phantom
    // break; the engine's real direction pairs it into an 18-minute break.
    $rows = [
        ['10:19:28', 'in'], ['12:56:29', 'out'], ['12:58:41', 'in'], ['13:19:11', 'out'],
        ['13:30:30', 'in'], ['13:30:33', 'out'], ['13:30:35', 'in'], ['15:02:45', 'out'],
        ['15:04:53', 'in'], ['16:34:25', 'out'],
    ];
    foreach ($rows as [$t, $dir]) {
        AttendancePunch::factory()->create([
            'employee_id' => $this->employee->id,
            'punched_at' => Carbon::today()->setTimeFromTimeString($t),
            'punch_date' => Carbon::today()->toDateString(),
            'direction' => $dir, 'method' => null,
        ]);
    }
    AttendanceDailySummary::create([
        'employee_id' => $this->employee->id, 'employee_code' => 16, 'date' => Carbon::today(),
        'first_punch' => Carbon::today()->setTime(10, 19), 'last_punch' => Carbon::today()->setTime(16, 34),
        'break_minutes' => 18, 'working_hours' => 5.98, 'raw_punch_count' => 10, 'synced_at' => now(),
    ]);

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    // 13:30:30 IN + 13:30:33 OUT (3s, opposite) is a reader flip-flop — ONE
    // edge kept (the IN, which alternates after the 13:19 OUT); 13:30:35 IN is a
    // same-direction duplicate. Four clean sessions.
    expect($pj['session_count'])->toBe(4)
        ->and($pj['conflict_count'])->toBe(1)       // the 13:30:33 flip-flop echo
        ->and($pj['duplicate_count'])->toBe(1)      // the 13:30:35 re-read
        ->and($pj['break_minutes'])->toBe(18)       // engine truth, not a phantom 3h31m
        ->and($pj['working_minutes'])->toBe(359)    // 5.98h from the engine summary
        ->and($pj['live'])->toBeFalse()
        ->and($pj['needs_regularization'])->toBeFalse()
        ->and(collect($pj['nodes'])->pluck('dir')->all())
        ->toBe(['IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT']);
});

test('a Card OUT seconds after a Face IN is a real departure, not a flip-flop', function () {
    // EMP015 case: a compulsory Card OUT 51s after the Face IN was wrongly
    // merged away, leaving two INs and a phantom Missing OUT. Different methods
    // = two real actions → keep both.
    $rows = [
        ['09:59:22', 'face'],     // IN
        ['09:59:24', 'face'],     // IN — duplicate read, dropped
        ['10:00:13', 'id_card'],  // OUT — real card departure, MUST survive
        ['10:02:31', 'face'],     // IN — returned
        ['10:02:32', 'face'],     // IN — duplicate read, dropped
    ];
    foreach ($rows as [$t, $method]) {
        methodPunch($this->employee->id, $t, $method);
    }

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['needs_regularization'])->toBeFalse()   // no phantom Missing OUT
        ->and($pj['duplicate_count'])->toBe(2)          // the two face re-reads
        ->and($pj['conflict_count'])->toBe(0)           // the card OUT is NOT a flip-flop
        ->and(collect($pj['nodes'])->pluck('dir')->all())->toBe(['IN', 'OUT', 'IN'])
        ->and($pj['live'])->toBeTrue()                  // the trailing 10:02 IN
        ->and($pj['session_count'])->toBe(2);
});

test('a one-second IN then OUT keeps ONE edge — the real arrival, never both', function () {
    // The exact drawer case: 10:28:59 IN + 10:29:00 OUT one second apart. It is
    // one physical tap — keep the IN (the arrival), drop the echo. The genuine
    // missing OUT before the 13:50 re-entry is flagged, never invented.
    $rows = [
        ['10:28:59', 'in'],
        ['10:29:00', 'out'],
        ['13:50:07', 'in'],
        ['13:54:04', 'out'],
    ];
    foreach ($rows as [$t, $dir]) {
        AttendancePunch::factory()->create([
            'employee_id' => $this->employee->id,
            'punched_at' => Carbon::today()->setTimeFromTimeString($t),
            'punch_date' => Carbon::today()->toDateString(),
            'direction' => $dir, 'method' => null,
        ]);
    }

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['conflict_count'])->toBe(1)                // 10:29:00 echo merged, not both dropped
        ->and($pj['kept_count'])->toBe(3)                // IN@10:28, IN@13:50, OUT@13:54
        ->and($pj['first_in'])->toBe('10:28 AM')         // the real arrival survives
        ->and($pj['needs_regularization'])->toBeTrue()   // genuine missing OUT is flagged
        ->and($pj['raw_count'])->toBe(4);                // raw log still holds all 4
});

test('a real 6pm OUT that bounces an IN keeps the OUT, not the echo', function () {
    // Data-loss guard: dropping both would delete the whole 9-6 day.
    $rows = [
        ['09:00:00', 'in'],
        ['18:00:00', 'out'],
        ['18:00:02', 'in'],   // reader echo — must NOT survive
    ];
    foreach ($rows as [$t, $dir]) {
        AttendancePunch::factory()->create([
            'employee_id' => $this->employee->id,
            'punched_at' => Carbon::today()->setTimeFromTimeString($t),
            'punch_date' => Carbon::today()->toDateString(),
            'direction' => $dir, 'method' => null,
        ]);
    }

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['session_count'])->toBe(1)
        ->and($pj['last_out'])->toBe('06:00 PM')          // the real departure survives
        ->and($pj['live'])->toBeFalse()
        ->and($pj['needs_regularization'])->toBeFalse()
        ->and($pj['working_minutes'])->toBe(540);         // full 9-hour day intact
});

test('direction is taken from the verification method — Face = IN, Card = OUT', function () {
    // The reported drawer record: the engine mis-tags face punches as OUT, but
    // on this device Face = IN and Card = OUT. Method wins, so the day pairs
    // cleanly with no phantom missing punch.
    $rows = [
        ['10:28:59', 'face'],     // IN
        ['10:29:00', 'face'],     // IN — duplicate read, dropped
        ['13:50:07', 'id_card'],  // OUT
        ['13:54:04', 'face'],     // IN
        ['15:02:43', 'id_card'],  // OUT
    ];
    foreach ($rows as [$t, $method]) {
        methodPunch($this->employee->id, $t, $method);
    }

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect(collect($pj['nodes'])->pluck('dir')->all())->toBe(['IN', 'OUT', 'IN', 'OUT'])
        ->and($pj['duplicate_count'])->toBe(1)           // the 10:29:00 face re-read
        ->and($pj['session_count'])->toBe(2)             // 10:28→13:50 and 13:54→15:02
        ->and($pj['needs_regularization'])->toBeFalse()  // no more phantom Missing OUT
        ->and($pj['working_minutes'])->toBe(201 + 68);   // 3h21m + 1h08m
});

test('an accidental re-punch straight after checkout cannot open a new session', function () {
    $rows = [['09:00:00', 'in'], ['18:00:00', 'out'], ['18:00:40', 'in']];
    foreach ($rows as [$t, $dir]) {
        AttendancePunch::factory()->create([
            'employee_id' => $this->employee->id,
            'punched_at' => Carbon::today()->setTimeFromTimeString($t),
            'punch_date' => Carbon::today()->toDateString(),
            'direction' => $dir, 'method' => null,
        ]);
    }

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['session_count'])->toBe(1)           // the stray 18:00:40 IN is merged noise
        ->and($pj['live'])->toBeFalse()             // NOT "currently working"
        ->and($pj['conflict_count'])->toBe(1)
        ->and($pj['last_out'])->toBe('06:00 PM')
        ->and($pj['working_minutes'])->toBe(540);
});

/** Insert a directional punch (in|out) with no method, so direction is taken from the tag. */
function dirPunch(int $employeeId, string $time, string $dir): void
{
    $at = Carbon::today()->setTimeFromTimeString($time);
    AttendancePunch::factory()->create([
        'employee_id' => $employeeId, 'punched_at' => $at,
        'punch_date' => $at->toDateString(), 'direction' => $dir, 'method' => null,
    ]);
}

test('same-direction duplicate reads within the window are ignored, raw kept', function () {
    dirPunch($this->employee->id, '09:00:00', 'in');
    dirPunch($this->employee->id, '09:00:20', 'in');  // duplicate IN, 20s later
    dirPunch($this->employee->id, '17:00:00', 'out');

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['raw_count'])->toBe(3)          // all three rows still exist
        ->and($pj['duplicate_count'])->toBe(1)  // the 09:00:20 read is suppressed
        ->and($pj['session_count'])->toBe(1)
        ->and($pj['needs_regularization'])->toBeFalse()
        ->and(collect($pj['nodes'])->where('type', 'missing'))->toHaveCount(0);
});

test('IN then IN beyond the window is a missing OUT, flagged for regularization', function () {
    dirPunch($this->employee->id, '10:19:00', 'in');
    dirPunch($this->employee->id, '12:58:00', 'in');  // missing OUT between
    dirPunch($this->employee->id, '18:00:00', 'out');

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['needs_regularization'])->toBeTrue()
        ->and($pj['duplicate_count'])->toBe(0)      // not a duplicate — a real gap
        ->and(collect($pj['nodes'])->pluck('type')->all())
        ->toBe(['first_in', 'missing', 'in', 'last_out'])
        ->and(collect($pj['nodes'])->firstWhere('type', 'missing')['dir'])->toBe('OUT');
});

test('OUT then OUT beyond the window is a missing IN', function () {
    dirPunch($this->employee->id, '09:00:00', 'in');
    dirPunch($this->employee->id, '13:00:00', 'out');
    dirPunch($this->employee->id, '18:00:00', 'out');  // missing IN between

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['needs_regularization'])->toBeTrue()
        ->and(collect($pj['nodes'])->pluck('dir')->all())
        ->toBe(['IN', 'OUT', 'IN', 'OUT'])   // synthetic missing IN inserted
        ->and(collect($pj['nodes'])->firstWhere('type', 'missing')['dir'])->toBe('IN');
});

test('the enterprise timeline renders the attendance card and session summary', function () {
    punchAt($this->employee->id, '09:02'); // in
    punchAt($this->employee->id, '18:18'); // out

    Livewire::test(AttendanceTracker::class)
        ->assertSee('Attendance journey')
        ->assertSee('Working today')
        ->assertSee('Session summary')
        ->assertSee('Total working hours');
});
