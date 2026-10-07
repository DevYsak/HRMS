<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\PunchTimeline;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * A genuine biometric IN then OUT inside the 60-second merge window is two
 * real actions, not device noise: both are kept, the employee is not shown
 * as still working, and the raw punch rows are never touched.
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

test('a face IN and a face OUT one second apart stay IN + OUT and the employee is not live', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $timeline = gpTimeline($this->employee->id);

    expect(collect($timeline['nodes'])->pluck('dir')->all())->toBe(['IN', 'OUT'])
        ->and($timeline['kept_count'])->toBe(2)
        ->and($timeline['duplicate_count'])->toBe(0)
        ->and($timeline['conflict_count'])->toBe(0)
        ->and($timeline['live'])->toBeFalse()
        ->and($timeline['last_out'])->toBe('11:33 AM')
        ->and($timeline['needs_regularization'])->toBeFalse();
});

test('the pair is preserved inside a full day and closes it', function () {
    gpPunch($this->employee->id, '09:00:00', 'face', 'in');
    gpPunch($this->employee->id, '10:15:00', 'id_card', 'out');
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $timeline = gpTimeline($this->employee->id);

    expect(collect($timeline['nodes'])->pluck('dir')->all())->toBe(['IN', 'OUT', 'IN', 'OUT'])
        ->and($timeline['live'])->toBeFalse()
        ->and($timeline['last_out_at']->format('H:i:s'))->toBe('11:33:21')
        ->and($timeline['session_count'])->toBe(2);
});

test('the employee dashboard journey does not show a genuine pair as currently working', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $journey = Livewire::actingAs($this->user)->test(AttendanceTracker::class)->get('punchJourney');

    expect($journey['live'])->toBeFalse()
        ->and(collect($journey['nodes'])->pluck('dir')->all())->toBe(['IN', 'OUT']);
});

test('true duplicates are still merged — repeated reads of one direction', function () {
    gpPunch($this->employee->id, '09:00:00', 'face', 'in');
    gpPunch($this->employee->id, '09:00:05', 'face', 'in');
    gpPunch($this->employee->id, '09:00:09', 'face', null);

    $timeline = gpTimeline($this->employee->id);

    expect($timeline['kept_count'])->toBe(1)
        ->and($timeline['duplicate_count'])->toBe(2)
        ->and($timeline['live'])->toBeTrue();
});

test('an IN echoing straight after the pair cannot reopen a live session', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');
    gpPunch($this->employee->id, '11:33:24', 'face', 'in');

    $timeline = gpTimeline($this->employee->id);

    expect(collect($timeline['nodes'])->pluck('dir')->all())->toBe(['IN', 'OUT'])
        ->and($timeline['live'])->toBeFalse()
        ->and($timeline['raw_count'])->toBe(3);
});

test('a card can never open the pair — Face starts attendance', function () {
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
    $in = gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    $out = gpPunch($this->employee->id, '11:33:21', 'face', 'out');
    $echo = gpPunch($this->employee->id, '11:33:24', 'face', 'in');
    $before = AttendancePunch::orderBy('id')->get()->map->only(['id', 'punched_at', 'method', 'direction', 'source', 'updated_at'])->toArray();

    gpTimeline($this->employee->id);
    app(PunchTimeline::class)->neutralEvents(AttendancePunch::orderBy('punched_at')->get());

    expect(AttendancePunch::orderBy('id')->get()->map->only(['id', 'punched_at', 'method', 'direction', 'source', 'updated_at'])->toArray())->toEqual($before)
        ->and(AttendancePunch::count())->toBe(3);
});

test('the neutral history list keeps the pair too', function () {
    gpPunch($this->employee->id, '11:33:20', 'face', 'in');
    gpPunch($this->employee->id, '11:33:21', 'face', 'out');

    $events = app(PunchTimeline::class)->neutralEvents(AttendancePunch::orderBy('punched_at')->get());

    expect(collect($events)->pluck('type')->all())->toBe(['in', 'out']);
});
