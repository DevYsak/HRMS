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

/** Insert a punch for the acting employee at today's H:i. */
function punchAt(int $employeeId, string $time): void
{
    $at = Carbon::today()->setTimeFromTimeString($time);
    AttendancePunch::factory()->create([
        'employee_id' => $employeeId,
        'punched_at' => $at,
        'punch_date' => $at->toDateString(),
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
            'direction' => $dir,
        ]);
    }
    AttendanceDailySummary::create([
        'employee_id' => $this->employee->id, 'employee_code' => 16, 'date' => Carbon::today(),
        'first_punch' => Carbon::today()->setTime(10, 19), 'last_punch' => Carbon::today()->setTime(16, 34),
        'break_minutes' => 18, 'working_hours' => 5.98, 'raw_punch_count' => 10, 'synced_at' => now(),
    ]);

    $pj = Livewire::test(AttendanceTracker::class)->get('punchJourney');

    expect($pj['session_count'])->toBe(5)          // five real sessions, not 2-3 mangled ones
        ->and($pj['duplicate_count'])->toBe(0)      // nothing dropped as "noise"
        ->and($pj['break_minutes'])->toBe(18)       // engine truth, not a phantom 3h31m
        ->and($pj['working_minutes'])->toBe(359)    // 5.98h from the engine summary
        ->and($pj['live'])->toBeFalse()
        ->and(collect($pj['nodes'])->pluck('dir')->all())
        ->toBe(['IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT']);
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
