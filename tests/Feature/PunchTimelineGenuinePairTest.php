<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\PunchTimeline;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Face punches the device tagged IN then OUT seconds apart. Under the final
 * business rules the method decides the direction (Face = IN only), so the
 * device's OUT tag is overridden and flagged, the two reads are one Face IN
 * burst — the latest is kept — and the raw rows keep what the device sent.
 *
 * Clock: Wednesday 14 October 2026, 12:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
    $this->user = User::factory()->create();
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
});

function gpPunch(int $employeeId, string $time, ?string $method, ?string $direction, string $source = 'biometric'): AttendancePunch
{
    return AttendancePunch::factory()->create([
        'employee_id' => $employeeId,
        'punched_at' => '2026-10-14 '.$time,
        'punch_date' => '2026-10-14',
        'method' => $method,
        'direction' => $direction,
        'source' => $source,
    ]);
}

function gpTimeline(int $employeeId): array
{
    $punches = AttendancePunch::where('employee_id', $employeeId)->orderBy('punched_at')->get();

    return app(PunchTimeline::class)->process($punches, Carbon::parse('2026-10-14'));
}

test('a face punch the device tagged OUT is still an IN — one burst, the latest kept', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $timeline = gpTimeline($this->employee->id);

    expect(collect($timeline['nodes'])->pluck('dir')->all())->toBe(['IN'])
        ->and($timeline['first_in_at']->format('H:i:s'))->toBe('11:33:21')
        ->and($timeline['duplicate_count'])->toBe(1)
        ->and($timeline['flags']['direction_corrected'])->toBe(1)
        ->and($timeline['live'])->toBeTrue();
});

test('the raw audit row says why the direction was corrected', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $raw = collect(gpTimeline($this->employee->id)['raw_events']);

    expect($raw->pluck('flag')->all())->toBe(['duplicate', 'kept'])
        ->and($raw[1]['raw_direction'])->toBe('out')
        ->and($raw[1]['direction'])->toBe('in')
        ->and($raw[1]['direction_corrected'])->toBeTrue()
        ->and($raw[1]['note'])->toContain('Direction corrected');
});

test('the employee journey shows them working after a face burst', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $journey = Livewire::actingAs($this->user)->test(AttendanceTracker::class)->get('punchJourney');

    expect($journey['live'])->toBeTrue()
        ->and(collect($journey['nodes'])->pluck('dir')->all())->toBe(['IN']);
});

test('a card can never open a session — Face starts attendance', function () {
    gpPunch($this->employee->id, '11:33:20', 'id_card', 'in');
    gpPunch($this->employee->id, '11:33:21', 'id_card', 'out');

    $timeline = gpTimeline($this->employee->id);

    expect($timeline['kept_count'])->toBe(0)
        ->and($timeline['live'])->toBeFalse();
});

test('punches without a biometric method keep the reader flip-flop rule', function () {
    gpPunch($this->employee->id, '10:28:59', null, 'in');
    gpPunch($this->employee->id, '10:29:00', null, 'out');

    $timeline = gpTimeline($this->employee->id);

    expect($timeline['kept_count'])->toBe(1)
        ->and($timeline['conflict_count'])->toBe(1);
});

test('processing never modifies the raw punch rows', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');
    gpPunch($this->employee->id, '11:33:24', 'face', 'in');
    $before = AttendancePunch::orderBy('id')->get()->map->only(['id', 'punched_at', 'method', 'direction', 'source', 'updated_at'])->toArray();

    gpTimeline($this->employee->id);
    app(PunchTimeline::class)->neutralEvents(AttendancePunch::orderBy('punched_at')->get());

    expect(AttendancePunch::orderBy('id')->get()->map->only(['id', 'punched_at', 'method', 'direction', 'source', 'updated_at'])->toArray())->toEqual($before)
        ->and(AttendancePunch::count())->toBe(3);
});

test('the neutral history list shows the burst as one IN', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $events = app(PunchTimeline::class)->neutralEvents(AttendancePunch::orderBy('punched_at')->get());

    expect(collect($events)->pluck('type')->all())->toBe(['in']);
});
