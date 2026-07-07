<?php

namespace App\Services\Attendance;

use App\Models\AttendancePunch;
use Illuminate\Support\Collection;

/**
 * Turns a noisy stream of raw biometric punches into a clean, de-duplicated
 * in→break→out timeline and the derived break / working minutes.
 *
 * Biometric devices commonly log one physical action several times (a card tap
 * and a face verify seconds apart, or repeated reads), which corrupts a naive
 * "alternating in/out" pairing: a single stray punch flips the parity and turns
 * a real return-to-work into a phantom multi-hour break.
 *
 * The classifier collapses any run of punches spaced closer than
 * DEBOUNCE_MINUTES (a "minimum meaningful gap") into one presence event, then
 * alternates the survivors in → out → in → out … so the first punch is the
 * clock-in, the last is the clock-out, and the pairs between are real breaks.
 *
 * This is the single source of truth used by the employee Attendance Journey,
 * the admin punch panel, and the break-minute recomputation — so every screen
 * shows the same, consistent picture.
 */
class PunchClassifier
{
    /** Punches closer together than this (minutes) are the same presence event. */
    public const DEBOUNCE_MINUTES = 4;

    /**
     * Collapse device noise: drop any punch that lands within DEBOUNCE_MINUTES
     * of the punch immediately before it (chain-based, so a whole burst of
     * repeated reads collapses to its first punch). Input is sorted ascending.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return Collection<int, AttendancePunch>
     */
    public function dedupe(Collection $punches): Collection
    {
        $sorted = $punches->sortBy('punched_at')->values();
        $kept = collect();
        $prev = null;

        foreach ($sorted as $p) {
            $isNoise = $prev !== null
                && $prev->punched_at->diffInSeconds($p->punched_at) < self::DEBOUNCE_MINUTES * 60;

            if (! $isNoise) {
                $kept->push($p);
            }

            // Compare against the immediately preceding RAW punch so a long
            // burst of closely-spaced reads all collapse together.
            $prev = $p;
        }

        return $kept->values();
    }

    /**
     * De-duplicate and classify a day's punches into timeline events
     * (Clocked in / Break started / Returned from break / Clocked out).
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array<int, array<string, mixed>>
     */
    public function classify(Collection $punches): array
    {
        $punches = $this->dedupe($punches);
        $n = $punches->count();

        if ($n === 0) {
            return [];
        }

        return $punches->map(function (AttendancePunch $p, int $i) use ($n, $punches) {
            $isEntry = $i % 2 === 0; // even index = entered / clocked in

            [$title, $type] = match (true) {
                $i === 0 => ['Clocked in', 'in'],
                $i === $n - 1 && ! $isEntry => ['Clocked out', 'out'],
                $isEntry => ['Returned from break', 'resume'],
                default => ['Break started', 'break'],
            };

            if ($type === 'break') {
                $next = $punches->get($i + 1);
                $gapMin = $next ? (int) $p->punched_at->diffInMinutes($next->punched_at) : null;
                $hour = (int) $p->punched_at->format('G');
                $kind = match (true) {
                    $gapMin === null => 'Break (no return punch)',
                    $gapMin > 90 => 'Long break',
                    $hour >= 12 && $hour < 16 && $gapMin >= 20 => 'Lunch break',
                    $gapMin < 20 => 'Tea break',
                    default => 'Personal break',
                };
                $title = $kind.($gapMin !== null ? " · {$gapMin}m" : '');
            }

            $prev = $i > 0 ? $punches->get($i - 1) : null;

            return [
                'time' => $p->punched_at->format('h:i A'),
                'title' => $title,
                'type' => $type,
                'method' => $p->methodEnum()?->value,
                'source' => $p->source,
                'location' => $p->location,
                'device' => $p->device_serial,
                'lat' => $p->lat,
                'lng' => $p->lng,
                'gap_min' => $prev ? (int) $prev->punched_at->diffInMinutes($p->punched_at) : null,
                'verify' => $p->verify_raw,
            ];
        })->all();
    }

    /**
     * Total real break minutes for the day: the sum of every break segment
     * (a "break started" punch to its paired return), after de-duplication.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     */
    public function breakMinutes(Collection $punches): int
    {
        $punches = $this->dedupe($punches);
        $n = $punches->count();
        $total = 0;

        // Odd-indexed punches (1,3,5…) that are not the final clock-out start a
        // break; the next punch ends it.
        for ($i = 1; $i < $n - 1; $i += 2) {
            $start = $punches->get($i);
            $end = $punches->get($i + 1);
            if ($start && $end) {
                $total += (int) $start->punched_at->diffInMinutes($end->punched_at);
            }
        }

        return $total;
    }
}
