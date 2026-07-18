<?php

use App\Models\AttendancePunch;
use App\Services\Attendance\PunchTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

// Boot the Laravel app (config + Eloquent need the container). No DB is
// touched — punches are unsaved and the engine is pure.
uses(TestCase::class);

/**
 * Build a stream of unsaved punches from [time, method] pairs on a fixed
 * PAST date (so the engine treats trailing INs as missing, not live).
 *
 * @param  array<int, array{0: string, 1: string}>  $rows
 */
function timelineStream(array $rows): Collection
{
    return collect($rows)->map(fn (array $r) => new AttendancePunch([
        'punched_at' => Carbon::parse("2026-07-07 {$r[0]}"),
        'method' => $r[1],
        'source' => 'biometric',
    ]));
}

beforeEach(function () {
    $this->engine = new PunchTimeline;
    // Deployment mapping: Face opens, Card closes.
    config()->set('biometric.method_direction', ['face' => 'in', 'id_card' => 'out']);
});

test('Rule 1 — a card tap before the first face IN is ignored, not a phantom session', function () {
    $result = $this->engine->process(timelineStream([
        ['08:58:00', 'id_card'],   // stray card before check-in → ignored
        ['09:03:00', 'face'],      // real IN
        ['18:00:00', 'id_card'],   // real OUT
    ]), Carbon::parse('2026-07-07'));

    expect($result['ignored_count'])->toBe(1)
        ->and($result['first_in'])->toBe('09:03 AM')
        ->and($result['session_count'])->toBe(1)
        ->and($result['sessions'][0]['missing'])->toBeFalse()
        ->and($result['needs_regularization'])->toBeFalse()
        // 09:03 → 18:00 = 8h 57m worked, break 0.
        ->and($result['working_minutes'])->toBe(537);
});

test('Rule 2 — a burst of duplicate face punches keeps the LATEST', function () {
    $result = $this->engine->process(timelineStream([
        ['09:00:02', 'face'],
        ['09:00:08', 'face'],
        ['09:00:15', 'face'],   // the kept IN
        ['18:00:00', 'id_card'],
    ]), Carbon::parse('2026-07-07'));

    expect($result['duplicate_count'])->toBe(2)
        ->and($result['first_in'])->toBe('09:00 AM')
        ->and($result['sessions'][0]['in'])->toBe('09:00 AM')
        // The kept IN is 09:00:15, so the session runs 09:00:15 → 18:00:00.
        ->and($result['working_minutes'])->toBe(539);
});

test('a genuine two-session day pairs correctly and counts the break', function () {
    $result = $this->engine->process(timelineStream([
        ['09:00:00', 'face'],
        ['13:00:00', 'id_card'],   // lunch out
        ['13:45:00', 'face'],      // back in
        ['18:00:00', 'id_card'],   // out
    ]), Carbon::parse('2026-07-07'));

    expect($result['session_count'])->toBe(2)
        ->and($result['break_minutes'])->toBe(45)
        // 4h + 4h15m = 495m worked.
        ->and($result['working_minutes'])->toBe(495)
        ->and($result['needs_regularization'])->toBeFalse();
});

test('a trailing IN with no OUT on a past day flags a missing punch', function () {
    $result = $this->engine->process(timelineStream([
        ['09:00:00', 'face'],
    ]), Carbon::parse('2026-07-07'));

    expect($result['missing_out'])->toBeTrue()
        ->and($result['needs_regularization'])->toBeTrue();
});
