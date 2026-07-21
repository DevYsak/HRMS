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

test('hoursBreakdown derives idle from break beyond the shift allowance', function () {
    $engine = new PunchTimeline;

    // Worked 8h (480m), break 90m, expected 9h (540m), allowance 60m.
    $b = $engine->hoursBreakdown(480, 90, 540, 60);

    expect($b['worked'])->toBe(480);
    expect($b['net'])->toBe(480);              // net = worked (already net of breaks)
    expect($b['break'])->toBe(90);
    expect($b['idle'])->toBe(30);              // 90 break − 60 allowance = 30 over limit
    expect($b['remaining'])->toBe(60);         // 540 expected − 480 worked
    expect($b['overtime'])->toBe(0);
});

test('hoursBreakdown reports overtime and zero idle within allowance', function () {
    $engine = new PunchTimeline;

    // Worked 10h (600m) > expected 9h; break 45m ≤ 60m allowance.
    $b = $engine->hoursBreakdown(600, 45, 540, 60);

    expect($b['overtime'])->toBe(60);          // 600 − 540
    expect($b['remaining'])->toBe(0);
    expect($b['idle'])->toBe(0);               // break within allowance
    expect($b['worked_pct'])->toBe(100);       // capped at 100
});

test('sessions expose break-after and productivity from validated times', function () {
    // Two work blocks with a 30-min gap: 9–11 (120m), break 30m, 11:30–13 (90m).
    $result = $this->engine->process(timelineStream([
        ['09:00:00', 'face'], ['11:00:00', 'id_card'],
        ['11:30:00', 'face'], ['13:00:00', 'id_card'],
    ]), Carbon::parse('2026-07-07'));

    expect($result['sessions'])->toHaveCount(2);

    // Session 1: 120m worked, 30m break after → 120/(120+30) = 80%.
    expect($result['sessions'][0]['minutes'])->toBe(120);
    expect($result['sessions'][0]['break_after'])->toBe(30);
    expect($result['sessions'][0]['productivity'])->toBe(80);

    // Session 2: last block, no break after → 100%.
    expect($result['sessions'][1]['minutes'])->toBe(90);
    expect($result['sessions'][1]['break_after'])->toBe(0);
    expect($result['sessions'][1]['productivity'])->toBe(100);
});
