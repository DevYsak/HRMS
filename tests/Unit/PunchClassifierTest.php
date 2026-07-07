<?php

use App\Models\AttendancePunch;
use App\Services\Attendance\PunchClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

// Boot the Laravel app (Eloquent model instances need the container). No DB is
// touched — the punches are unsaved and the classifier is pure.
uses(TestCase::class);

/**
 * Build a collection of unsaved punches from "H:i" times on a fixed date.
 *
 * @param  array<int, string>  $times
 */
function punchStream(array $times): Collection
{
    return collect($times)->map(fn (string $t) => new AttendancePunch([
        'punched_at' => Carbon::parse("2026-07-07 {$t}:00"),
        'method' => 'face',
        'source' => 'biometric',
    ]));
}

beforeEach(function () {
    $this->svc = new PunchClassifier;
});

// The exact noisy stream from the reported screenshots (12 raw punches with a
// stray verify at 13:20, and duplicate reads at 13:27, 14:23 and 15:38).
$noisy = [
    '10:27', // clock in
    '13:17', // break out
    '13:20', // stray face verify (noise — must be dropped)
    '13:27', // return
    '13:27', // duplicate read
    '14:16', // break out
    '14:23', // return
    '14:23', // duplicate read
    '15:35', // clock out
    '15:38', // duplicate read
];

test('device noise and duplicates collapse to the real presence events', function () use ($noisy) {
    $kept = $this->svc->dedupe(punchStream($noisy));

    expect($kept)->toHaveCount(6)
        ->and($kept->map(fn ($p) => $p->punched_at->format('H:i'))->all())
        ->toBe(['10:27', '13:17', '13:27', '14:16', '14:23', '15:35']);
});

test('the stray 13:20 punch no longer flips parity into a phantom break', function () use ($noisy) {
    // Before the fix this produced a ~49-minute phantom break; the two real
    // tea breaks total 10 + 7 = 17 minutes.
    expect($this->svc->breakMinutes(punchStream($noisy)))->toBe(17);
});

test('classification yields a clean in → break → out timeline', function () use ($noisy) {
    $events = collect($this->svc->classify(punchStream($noisy)));

    expect($events)->toHaveCount(6)
        ->and($events->first()['type'])->toBe('in')
        ->and($events->last()['type'])->toBe('out')
        ->and($events->where('type', 'break'))->toHaveCount(2)
        ->and($events->where('type', 'resume'))->toHaveCount(2);

    $breaks = $events->where('type', 'break')->values();
    expect($breaks[0]['title'])->toBe('Tea break · 10m')
        ->and($breaks[1]['title'])->toBe('Tea break · 7m');
});

test('a clean two-punch day is left intact', function () {
    $events = collect($this->svc->classify(punchStream(['09:30', '18:30'])));

    expect($events)->toHaveCount(2)
        ->and($events->first()['type'])->toBe('in')
        ->and($events->last()['type'])->toBe('out')
        ->and($this->svc->breakMinutes(punchStream(['09:30', '18:30'])))->toBe(0);
});

test('an odd punch count is handled without inventing a clock-out', function () {
    // in, break-out, return → came back from break but never clocked out
    $events = collect($this->svc->classify(punchStream(['09:30', '12:00', '12:30'])));

    expect($events)->toHaveCount(3)
        ->and($events->first()['type'])->toBe('in')
        ->and($events->where('type', 'break'))->toHaveCount(1)
        ->and($events->last()['type'])->toBe('resume');
});
