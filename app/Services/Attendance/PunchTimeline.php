<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SINGLE SOURCE OF TRUTH for attendance processing.
 *
 * Takes one day's raw biometric/web punches and produces the one processed
 * timeline every screen renders from: neutral IN/OUT nodes, validated work
 * sessions, working/break totals, duplicate + device-conflict merges, and
 * missing-punch flags. Raw device logs are NEVER modified — they are only
 * annotated (kept / duplicate / retry) so HR & admins can audit them.
 *
 * Rules
 *  - Merge window (60s): any punch within MERGE_WINDOW_SECONDS of the previous
 *    kept punch is device noise — a repeated read (duplicate), a Face + Card
 *    double verify (device conflict), or an accidental re-punch straight after
 *    checkout. It is merged into the kept punch and excluded from every
 *    calculation, so it can never open a phantom session.
 *  - Direction is decided by the method: Face = IN, ID Card = OUT
 *    (config biometric.method_direction). A device IN/OUT tag that disagrees
 *    is overridden and the punch is flagged "direction corrected"; the raw
 *    row keeps what the device sent. Punches with no mapped method fall back
 *    to the device tag, then to alternation.
 *  - Duplicates: punches of the SAME effective direction within the window
 *    are one action read several times — the LATEST is kept, the earlier
 *    ones are flagged duplicate. Opposite actions (Face IN, Card OUT) are
 *    never merged.
 *  - A punch dated on another calendar day never joins this day — no OUT is
 *    carried across midnight, so there are no 20h/24h phantom sessions.
 *  - Missing punches: a same-direction pair beyond the merge window
 *    (IN→IN = missing OUT, OUT→OUT = missing IN) or a trailing IN on a past
 *    day. Never auto-fixed — flagged for regularization.
 *  - Totals (Pulse v3.1): working minutes = final OUT − first IN (or now while
 *    live). Breaks are NEVER deducted — the gaps between sessions are reported
 *    as break minutes for information only. {@see AttendanceCalculator} reads
 *    first_in_at / last_out_at from here; the session list is for display.
 */
class PunchTimeline
{
    /**
     * Two punches within this window are the same physical action recorded
     * twice (a repeated read, a Face+Card re-verify, or a reader flip-flop that
     * logs one tap as both an IN and an OUT edge). One is kept, the other merged.
     */
    public const MERGE_WINDOW_SECONDS = 60;

    /** A worked span longer than this is flagged as impossible for one day. */
    public const IMPOSSIBLE_MINUTES = 16 * 60;

    /**
     * Process one day's raw punches into the canonical timeline payload.
     *
     * @param  Collection<int, AttendancePunch>  $raw
     * @return array<string, mixed>
     */
    public function process(Collection $raw, Carbon $day, ?AttendanceDailySummary $summary = null, ?ResolvedShift $shift = null): array
    {
        if ($raw->isEmpty()) {
            return $this->emptyResult();
        }

        $ordered = $raw->sortBy('punched_at')->values();

        // Rule 7 — a punch stamped on another calendar day is never part of
        // this day (an OUT carried past midnight is how 20h/24h phantom
        // sessions appear). It stays in the raw audit list, flagged.
        // A night shift legitimately runs past midnight: its punches up to the
        // missing-checkout cutoff (shift end + 1h) still belong to the work day.
        $windowEnd = $shift?->crossesMidnight() ? $shift->end->copy()->addMinutes(AttendanceCalculator::MISSING_CHECKOUT_AFTER_MINUTES) : null;
        [$sameDay, $otherDay] = $ordered->partition(fn (AttendancePunch $p) => $p->punched_at->isSameDay($day)
            || ($windowEnd !== null && $p->punched_at->greaterThan($day) && $p->punched_at->lessThanOrEqualTo($windowEnd)));
        [$kept, $flags, $duplicateCount, $conflictCount] = $this->mergeNoise($sameDay->values());
        foreach ($otherDay as $p) {
            $flags[spl_object_id($p)] = ['ignored', 'Ignored — stamped '.$p->punched_at->format('d M h:i A').', another day'];
        }

        $hasDirection = $kept->contains(fn (AttendancePunch $p) => $this->effectiveDirection($p) !== null);
        $directions = $this->resolveDirections($kept, $hasDirection);

        // Rule 1 — Face starts attendance. A Card/ID tap with no active session
        // (a stray tap before the first Face IN, or after the day already
        // closed) is ignored, not turned into a phantom missing-IN session.
        $stray = $this->detectStray($kept, $directions);
        foreach (array_keys($stray) as $i) {
            $flags[spl_object_id($kept[$i])] = ['ignored', 'Ignored — card scan with no active check-in (Face required to start attendance)'];
        }

        $rawEvents = $ordered->map(function (AttendancePunch $p) use ($flags) {
            [$f, $note] = $flags[spl_object_id($p)] ?? ['kept', null];

            return $this->annotate($p, $f, $note);
        })->all();

        $result = $this->assemble($kept, $directions, $stray, $ordered->count(), $rawEvents, $duplicateCount, $conflictCount, $day, $summary);

        $result['flags'] = $this->flagsFor($result, $ordered, $otherDay->count());

        return $result;
    }

    /**
     * Problems on the day, by kind — for HR exception views and the rebuild
     * command's preview.
     *
     * @param  array<string, mixed>  $result
     * @param  Collection<int, AttendancePunch>  $ordered
     * @return array{duplicate: int, retry: int, stray: int, missing_out: bool, missing_checkout: bool, regularised: bool, impossible_duration: bool, other_day: int, direction_corrected: int}
     */
    protected function flagsFor(array $result, Collection $ordered, int $otherDay): array
    {
        $missingOuts = collect($result['nodes'])->filter(fn (array $n) => $n['type'] === 'missing' && $n['dir'] === 'OUT')->count();

        return [
            'duplicate' => (int) $result['duplicate_count'],
            'retry' => (int) $result['conflict_count'],
            'stray' => (int) $result['ignored_count'],
            'missing_out' => $missingOuts - ($result['missing_out'] ? 1 : 0) > 0,   // repeated IN mid-day
            'missing_checkout' => (bool) $result['missing_out'],
            'regularised' => $ordered->contains(fn (AttendancePunch $p) => $p->source === 'regularisation'),
            'impossible_duration' => (int) $result['working_minutes'] > self::IMPOSSIBLE_MINUTES,
            'other_day' => $otherDay,
            'direction_corrected' => $ordered->filter(fn (AttendancePunch $p) => $this->directionCorrected($p))->count(),
        ];
    }

    /**
     * Neutral IN/OUT display events for history lists (Punch In/Out Timeline).
     * Never labels WHY someone stepped out — no lunch/tea/break guessing.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array<int, array<string, mixed>>
     */
    public function neutralEvents(Collection $punches): array
    {
        if ($punches->isEmpty()) {
            return [];
        }

        // A punch stamped on another calendar day than its punch_date never
        // joins the day (no cross-midnight gaps), as in process().
        $ordered = $punches->sortBy('punched_at')
            ->filter(fn (AttendancePunch $p) => $p->punch_date === null || $p->punched_at->isSameDay($p->punch_date))
            ->values();
        [$kept] = $this->mergeNoise($ordered);
        $hasDirection = $kept->contains(fn (AttendancePunch $p) => $this->effectiveDirection($p) !== null);
        $directions = $this->resolveDirections($kept, $hasDirection);

        // Drop stray card taps (Rule 1) so the history timeline shows only the
        // punches the engine actually acted on.
        $stray = $this->detectStray($kept, $directions);
        if ($stray !== []) {
            $kept = $kept->reject(fn (AttendancePunch $p, int $i) => isset($stray[$i]))->values();
            $directions = array_values(array_filter($directions, fn ($d, $i) => ! isset($stray[$i]), ARRAY_FILTER_USE_BOTH));
        }

        $n = $kept->count();
        if ($n === 0) {
            return [];
        }
        $lastOutIndex = null;
        foreach ($directions as $i => $dir) {
            if ($dir === 'out') {
                $lastOutIndex = $i;
            }
        }

        return $kept->map(function (AttendancePunch $p, int $i) use ($n, $kept, $directions, $lastOutIndex) {
            $isIn = $directions[$i] !== 'out';
            $isLastOut = $lastOutIndex !== null ? $i === $lastOutIndex : $i === $n - 1;
            $prev = $i > 0 ? $kept->get($i - 1) : null;

            return [
                'time' => $p->punched_at->format('h:i A'),
                'title' => $isIn ? ($i === 0 ? 'Clocked in' : 'Punch in') : ($isLastOut ? 'Clocked out' : 'Punch out'),
                'type' => $isIn ? 'in' : 'out',
                'direction' => $isIn ? 'in' : 'out',
                'method' => $p->methodEnum()?->value,
                'guidance' => $p->methodEnum()?->guidance($isIn ? 'in' : 'out'),
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
     * Collapse device noise into kept punches and annotate EVERY raw punch for
     * the HR/admin audit view. Two punches within the merge window are the same
     * physical action recorded twice — KEEP EXACTLY ONE, never both, never none:
     *
     *  - Same effective direction → a duplicate read / retry; keep the LATEST.
     *  - Opposite direction → a reader flip-flop (one tap logged as both an IN
     *    and an OUT edge, e.g. 10:28:59 IN + 10:29:00 OUT). Keep the single edge
     *    whose direction keeps the day alternating with the punch before the
     *    pair — so a real 6 pm OUT that bounces an IN keeps the OUT, and a
     *    morning IN that bounces an OUT keeps the IN. Drop only the echo.
     *
     * @param  Collection<int, AttendancePunch>  $ordered
     * @return array{0: Collection<int, AttendancePunch>, 1: array<int, array{0: string, 1: ?string}>, 2: int, 3: int}
     */
    protected function mergeNoise(Collection $ordered): array
    {
        $kept = collect();
        $flag = [];               // spl_object_id => [flag, note]
        $duplicates = 0;
        $conflicts = 0;

        foreach ($ordered as $p) {
            $prev = $kept->last();
            $withinWindow = $prev && (int) $prev->punched_at->diffInSeconds($p->punched_at) <= self::MERGE_WINDOW_SECONDS;

            if ($withinWindow) {
                $prevDir = $this->effectiveDirection($prev);
                $curDir = $this->effectiveDirection($p);
                $opposite = $prevDir !== null && $curDir !== null && $prevDir !== $curDir;
                $sameMethod = (string) $prev->method === (string) $p->method;

                // A flip-flop is ONE reader firing both edges of a single tap —
                // so it must be the SAME method. A Face IN next to a Card OUT is
                // two distinct real actions (Card OUT is compulsory here), even
                // seconds apart: keep both.
                if ($opposite && $sameMethod) {
                    $conflicts++;
                    // Which single edge keeps the sequence alternating?
                    $before = $kept->count() >= 2 ? $kept->get($kept->count() - 2) : null;
                    $beforeDir = $before ? $this->effectiveDirection($before) : null;
                    $wantDir = $beforeDir === 'in' ? 'out' : 'in';

                    if ($curDir === $wantDir && $prevDir !== $wantDir) {
                        // The later edge fits better — swap it in for the first.
                        $kept->pop();
                        $flag[spl_object_id($prev)] = ['retry', 'Reader flip-flop — replaced by the '.$p->punched_at->format('h:i:s A').' edge'];
                        $kept->push($p);
                        $flag[spl_object_id($p)] = ['kept', null];
                    } else {
                        // The first edge fits — drop this echo.
                        $flag[spl_object_id($p)] = ['retry', 'Reader flip-flop — single tap double-read; kept the '.$prev->punched_at->format('h:i:s A').' edge'];
                    }

                    continue;
                }

                // Same effective direction within the window → one action
                // read several times (a retry). Business rule: keep the
                // LATEST punch of the burst; every earlier one is flagged.
                // Comparing against the last KEPT punch chains a burst, so
                // Face 10:30:01 / 10:30:22 / 10:30:48 keeps 10:30:48 only.
                if (! $opposite) {
                    if ($sameMethod) {
                        $duplicates++;
                        $label = 'Duplicate read';
                    } else {
                        $conflicts++;
                        $label = 'Authentication retry';
                    }

                    $kept->pop();
                    $flag[spl_object_id($prev)] = [$sameMethod ? 'duplicate' : 'retry', $label.' — superseded by the later '.$p->punched_at->format('h:i:s A').' punch'];
                    $kept->push($p);
                    $flag[spl_object_id($p)] = ['kept', null];

                    continue;
                }

                // Opposite direction, different method → two real actions; keep both.
            }

            $kept->push($p);
            $flag[spl_object_id($p)] = ['kept', null];
        }

        return [$kept->values(), $flag, $duplicates, $conflicts];
    }

    /**
     * Stray out-only taps (Rule 1): a Card/ID punch that arrives while no Face
     * session is open — a leading tap before the first Face IN, or a lone tap on
     * a day the employee never Face-scanned. Returns the kept-punch indices to
     * ignore. A genuinely ambiguous OUT (one with no method-derived direction) is
     * left alone so it still surfaces as a missing-IN needing regularization.
     *
     * @param  Collection<int, AttendancePunch>  $kept
     * @param  array<int, string>  $directions
     * @return array<int, true>
     */
    protected function detectStray(Collection $kept, array $directions): array
    {
        $stray = [];
        $open = false;

        foreach ($kept as $i => $p) {
            if ($directions[$i] !== 'out') {   // an IN opens (or keeps open) a session
                $open = true;

                continue;
            }
            if (! $open) {
                if ($this->isOutOnlyMethod($p)) {
                    $stray[$i] = true;   // stray card tap — ignore
                }

                continue;                 // ambiguous OUT with no opener: leave for missing-IN handling
            }
            $open = false;                // this OUT closes the open session
        }

        return $stray;
    }

    /** The device sent an explicit IN/OUT that the method rule overrode. */
    public function directionCorrected(AttendancePunch $p): bool
    {
        $effective = $this->effectiveDirection($p);

        return in_array($p->direction, ['in', 'out'], true) && $effective !== null && $effective !== $p->direction;
    }

    /** Whether a punch's method is configured as an out-only edge (e.g. Card). */
    protected function isOutOnlyMethod(AttendancePunch $p): bool
    {
        $map = config('biometric.method_direction', []);
        $method = (string) $p->method;

        return $method !== '' && ($map[$method] ?? null) === 'out';
    }

    /**
     * A punch's true IN/OUT direction. The verification method is the primary
     * signal (config biometric.method_direction — e.g. Face = IN, Card = OUT on
     * this deployment, where the engine's own IN/OUT tag mis-labels face punches
     * as OUT). Falls back to the engine's stored tag, then null (undecided).
     */
    protected function effectiveDirection(AttendancePunch $p): ?string
    {
        // Punches the system wrote itself (an approved regularisation, the auto
        // punch-out, a web clock) carry the direction they were written with.
        // The method map is for device reads only: a regularised IN recorded
        // with method "id_card" must not be read as a Card OUT.
        if (in_array($p->source, ['regularisation', 'system_auto', 'web'], true) && in_array($p->direction, ['in', 'out'], true)) {
            return $p->direction;
        }

        $map = config('biometric.method_direction', []);
        $method = (string) $p->method;
        if ($method !== '' && isset($map[$method]) && in_array($map[$method], ['in', 'out'], true)) {
            return $map[$method];
        }

        return in_array($p->direction, ['in', 'out'], true) ? $p->direction : null;
    }

    /**
     * Real direction per kept punch: method-derived when known, then the
     * engine's tag, otherwise alternation (even = IN, odd = OUT).
     *
     * @param  Collection<int, AttendancePunch>  $kept
     * @return array<int, string>
     */
    protected function resolveDirections(Collection $kept, bool $hasDirection): array
    {
        return $kept->map(function (AttendancePunch $p, int $i) {
            return $this->effectiveDirection($p) ?? ($i % 2 === 0 ? 'in' : 'out');
        })->all();
    }

    /**
     * Build nodes, validated sessions, totals and flags from the kept punches.
     *
     * @param  Collection<int, AttendancePunch>  $kept
     * @param  array<int, string>  $directions
     * @param  array<int, true>  $stray
     * @param  array<int, array<string, mixed>>  $rawEvents
     * @return array<string, mixed>
     */
    protected function assemble(Collection $kept, array $directions, array $stray, int $rawCount, array $rawEvents, int $duplicateCount, int $conflictCount, Carbon $day, ?AttendanceDailySummary $summary): array
    {
        // Rule 1 — set aside ignored stray taps; sessions/nodes are built only
        // from the effective punches the engine acts on.
        $ignored = [];
        if ($stray !== []) {
            $effKept = collect();
            $effDir = [];
            foreach ($kept as $i => $p) {
                if (isset($stray[$i])) {
                    $ignored[] = $this->ignoredNode($p);

                    continue;
                }
                $effKept->push($p);
                $effDir[] = $directions[$i];
            }
            $kept = $effKept->values();
            $directions = $effDir;
        }

        $n = $kept->count();
        if ($n === 0) {
            return array_merge($this->emptyResult(), [
                'raw_events' => $rawEvents,
                'raw_count' => $rawCount,
                'duplicate_count' => $duplicateCount,
                'conflict_count' => $conflictCount,
                'ignored' => $ignored,
                'ignored_count' => count($ignored),
            ]);
        }
        $isToday = $day->isToday();
        $trailingIn = $directions[$n - 1] === 'in';
        $live = $trailingIn && $isToday;
        $missingTrailingOut = $trailingIn && ! $isToday;

        $lastOutIndex = null;
        foreach ($directions as $i => $dir) {
            if ($dir === 'out') {
                $lastOutIndex = $i;
            }
        }

        // ── Nodes with ⚠ markers wherever a punch is missing ────────────────
        $nodes = [];
        $needsRegularization = false;
        $prevDir = null;
        foreach ($kept as $i => $p) {
            $dir = $directions[$i];
            if ($prevDir !== null && $dir === $prevDir) {
                $needsRegularization = true;
                $nodes[] = $this->missingNode($prevDir === 'in' ? 'OUT' : 'IN');
            }
            $isIn = $dir !== 'out';
            $type = match (true) {
                $live && $i === $n - 1 => 'live',
                $isIn && $i === 0 => 'first_in',
                ! $isIn && $i === $lastOutIndex => 'last_out',
                $isIn => 'in',
                default => 'out',
            };
            $nodes[] = $this->punchNode($p, $type, $isIn);
            $prevDir = $dir;
        }
        if ($missingTrailingOut) {
            $needsRegularization = true;
            $nodes[] = $this->missingNode('OUT');
        }

        // ── Validated sessions: an IN opens, the next OUT closes ────────────
        $sessions = [];
        $workingMinutes = 0;
        $breakMinutes = 0;
        $openIn = null;
        $prevOut = null;
        foreach ($kept as $i => $p) {
            if ($directions[$i] !== 'out') {          // IN
                if ($openIn === null) {
                    $openIn = $p;
                    if ($prevOut !== null) {
                        $breakMinutes += (int) $prevOut->punched_at->diffInMinutes($p->punched_at);
                    }
                } else {
                    $sessions[] = $this->incompleteSession(count($sessions) + 1, $openIn, null);
                    $openIn = $p;
                }

                continue;
            }
            if ($openIn !== null) {                    // OUT closes the session
                $mins = (int) $openIn->punched_at->diffInMinutes($p->punched_at);
                $workingMinutes += $mins;
                $sessions[] = [
                    'index' => count($sessions) + 1,
                    'in' => $openIn->punched_at->format('h:i A'),
                    'out' => $p->punched_at->format('h:i A'),
                    'minutes' => $mins,
                    'label' => $this->minutesToHm($mins),
                    'live' => false,
                    'missing' => false,
                    'in_ms' => (int) $openIn->punched_at->getTimestampMs(),
                    'out_ms' => (int) $p->punched_at->getTimestampMs(),
                ];
                $openIn = null;
                $prevOut = $p;
            } else {                                   // OUT with no open IN
                $sessions[] = $this->incompleteSession(count($sessions) + 1, null, $p);
                $prevOut = $p;
            }
        }

        // Trailing open IN → live session today, incomplete on a past day.
        $liveStartMs = null;
        $liveStartLabel = null;
        $liveElapsed = 0;
        if ($openIn !== null) {
            if ($live) {
                $liveElapsed = (int) $openIn->punched_at->diffInMinutes(now());
                $liveStartMs = (int) $openIn->punched_at->getTimestampMs();
                $liveStartLabel = $openIn->punched_at->format('h:i A');
                if ($prevOut !== null) {
                    $breakMinutes += (int) $prevOut->punched_at->diffInMinutes($openIn->punched_at);
                }
                $sessions[] = [
                    'index' => count($sessions) + 1,
                    'in' => $liveStartLabel,
                    'out' => null,
                    'minutes' => $liveElapsed,
                    'label' => $this->minutesToHm($liveElapsed),
                    'live' => true,
                    'missing' => false,
                    'in_ms' => (int) $openIn->punched_at->getTimestampMs(),
                    'out_ms' => null,
                ];
            } else {
                $sessions[] = $this->incompleteSession(count($sessions) + 1, $openIn, null);
            }
        }

        // Pulse v3.1 — worked = final OUT − first IN (now − first IN while
        // live). The session sum would silently deduct every gap between
        // punches as if it were unpaid; it is kept per session for display only.
        $firstInPunch = null;
        foreach ($kept as $i => $p) {
            if ($directions[$i] !== 'out') {
                $firstInPunch = $p;
                break;
            }
        }
        $firstInAt = $firstInPunch ? Carbon::parse($firstInPunch->punched_at) : null;
        $latestOut = $lastOutIndex !== null ? $kept->get($lastOutIndex) : null;
        $latestOutAt = ($latestOut && $firstInAt && $latestOut->punched_at->greaterThan($firstInAt)) ? Carbon::parse($latestOut->punched_at) : null;
        $lastOutAt = ($trailingIn) ? null : $latestOutAt;
        $spanEnd = $live ? Carbon::now() : $lastOutAt;
        $workingMinutes = ($firstInAt && $spanEnd && $spanEnd->greaterThan($firstInAt))
            ? (int) floor($firstInAt->diffInSeconds($spanEnd, true) / 60)
            : 0;

        // Per-session break-after (the gap until the next session opens) and
        // productivity (worked ÷ worked+break for that block). Both come straight
        // from the validated session times — the Session Summary cards read these
        // rather than re-deriving anything in the view.
        $sessCount = count($sessions);
        for ($si = 0; $si < $sessCount; $si++) {
            $breakAfter = 0;
            $thisOut = $sessions[$si]['out_ms'] ?? null;
            $nextIn = $sessions[$si + 1]['in_ms'] ?? null;
            if ($thisOut !== null && $nextIn !== null && $nextIn > $thisOut) {
                $breakAfter = (int) round(($nextIn - $thisOut) / 60000);
            }
            $mins = (int) ($sessions[$si]['minutes'] ?? 0);
            $sessions[$si]['break_after'] = $breakAfter;
            $sessions[$si]['break_after_label'] = $breakAfter > 0 ? $this->minutesToHm($breakAfter) : '—';
            $sessions[$si]['productivity'] = $mins > 0 ? (int) round($mins / max(1, $mins + $breakAfter) * 100) : 0;
        }

        // The engine's synced summary is NOT trusted for totals — on this device
        // it mis-pairs (Face punches tagged OUT).
        $lastOut = $lastOutIndex !== null ? $kept->get($lastOutIndex) : null;

        return [
            'nodes' => $nodes,
            'sessions' => $sessions,
            'raw_events' => $rawEvents,
            'first_in' => $kept->first()->punched_at->format('h:i A'),
            'last_out' => (! $trailingIn && $lastOut) ? $lastOut->punched_at->format('h:i A') : null,
            // Canonical instants for AttendanceCalculator: the first IN and the
            // final OUT (null while still clocked in / when the OUT is missing).
            'first_in_at' => $firstInAt,
            'last_out_at' => $lastOutAt,
            'raw_count' => $rawCount,
            'kept_count' => $n,
            'duplicate_count' => $duplicateCount,
            'conflict_count' => $conflictCount,
            'working_minutes' => $workingMinutes,
            'break_minutes' => $breakMinutes,
            'session_count' => count($sessions),
            'live' => $live,
            'live_start_ms' => $liveStartMs,
            'live_start_label' => $liveStartLabel,
            'live_elapsed_minutes' => $liveElapsed,
            'missing_out' => $missingTrailingOut,
            'needs_regularization' => $needsRegularization,
            'ignored' => $ignored,
            'ignored_count' => count($ignored),
        ];
    }

    /** A collapsed node for a stray tap the engine ignored (Rule 1). */
    protected function ignoredNode(AttendancePunch $p): array
    {
        $method = $p->methodEnum();

        return [
            'time' => $p->punched_at->format('h:i A'),
            'time_short' => $p->punched_at->format('g:i'),
            'ts_ms' => (int) $p->punched_at->getTimestampMs(),
            'dir' => 'IGN',
            'type' => 'ignored',
            'method' => $method?->value,
            'method_label' => $method?->label() ?? ucfirst((string) $p->method),
            'guidance' => $method?->guidance(),
            'method_icon' => $method?->icon() ?? 'no-symbol',
            'device' => $p->device_serial,
            'location' => $p->location,
            'verify' => $p->verify_raw,
            'source' => $p->source,
            'lat' => $p->lat,
            'lng' => $p->lng,
            'reason' => 'Ignored — card scan with no active check-in',
        ];
    }

    /** A single timeline node for a real punch. */
    protected function punchNode(AttendancePunch $p, string $type, bool $isIn): array
    {
        $method = $p->methodEnum();

        return [
            'time' => $p->punched_at->format('h:i A'),
            'time_short' => $p->punched_at->format('g:i'),
            'ts_ms' => (int) $p->punched_at->getTimestampMs(),
            'dir' => $isIn ? 'IN' : 'OUT',
            'type' => $type,
            'method' => $method?->value,
            'method_label' => $method?->label(),
            'guidance' => $method?->guidance($isIn ? 'in' : 'out'),
            'method_icon' => $method?->icon() ?? 'finger-print',
            'device' => $p->device_serial,
            'location' => $p->location,
            'verify' => $p->verify_raw,
            'source' => $p->source,
            'lat' => $p->lat,
            'lng' => $p->lng,
        ];
    }

    /** A ⚠ placeholder node for a detected missing punch of the given direction. */
    protected function missingNode(string $dir): array
    {
        return [
            'time' => '—', 'time_short' => '—', 'ts_ms' => null, 'dir' => $dir,
            'type' => 'missing', 'method' => null, 'method_label' => null, 'guidance' => null,
            'method_icon' => 'exclamation-triangle', 'device' => null,
            'location' => null, 'verify' => null, 'source' => null, 'lat' => null, 'lng' => null,
        ];
    }

    /** A session missing one of its punches — never auto-paired, needs regularization. */
    protected function incompleteSession(int $index, ?AttendancePunch $in, ?AttendancePunch $out): array
    {
        return [
            'index' => $index,
            'in' => $in?->punched_at->format('h:i A'),
            'out' => $out?->punched_at->format('h:i A'),
            'minutes' => 0,
            'label' => 'Missing '.($in ? 'OUT' : 'IN'),
            'live' => false,
            'missing' => true,
        ];
    }

    /** An annotated raw device event for the HR/admin audit view. */
    protected function annotate(AttendancePunch $p, string $flag, ?string $note): array
    {
        $corrected = $this->directionCorrected($p);
        if ($corrected && $note === null) {
            $note = 'Direction corrected — the device recorded '.strtoupper((string) $p->direction).'; '.($p->methodEnum()?->label() ?? 'this method').' = '.strtoupper((string) $this->effectiveDirection($p));
        }

        return [
            'time' => $p->punched_at->format('h:i:s A'),
            // The resolved direction (method-derived), so a Face punch never
            // shows a misleading "OUT" the audit view would then keep.
            'direction' => $this->effectiveDirection($p),
            'method' => $p->methodEnum()?->label() ?? ucfirst((string) $p->method) ?: null,
            'guidance' => $p->methodEnum()?->guidance($this->effectiveDirection($p)),
            'method_icon' => $p->methodEnum()?->icon() ?? 'clock',
            'device' => $p->device_serial,
            'source' => $p->source,
            'verify' => $p->verify_raw,
            'flag' => $flag,                       // kept | duplicate | retry | ignored
            'note' => $note,
            'raw_direction' => in_array($p->direction, ['in', 'out'], true) ? $p->direction : null,
            'direction_corrected' => $corrected,
        ];
    }

    /** Format a minute count as "9h 00m" (or "45m" under an hour). */
    public function minutesToHm(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.'m';
        }

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /**
     * Working-hours breakdown for the dashboard, derived only from validated
     * session totals so the UI never re-derives (or mis-derives) it.
     *
     * - net    = worked (Pulse v3.1: first IN → final OUT; breaks are not deducted)
     * - idle   = break time beyond the allowance — informational only
     * - overtime = APPROVED overtime only (pass $approvedOtMinutes); time beyond
     *   the standard day without an approved request is `beyond_shift`
     * - remaining = worked measured against the expected day
     *
     * @return array{expected:int, worked:int, break:int, idle:int, overtime:int, beyond_shift:int, net:int, remaining:int, worked_pct:int, break_pct:int, idle_pct:int}
     */
    public function hoursBreakdown(int $workedMinutes, int $breakMinutes, int $expectedMinutes, int $breakAllowanceMinutes = 0, int $approvedOtMinutes = 0): array
    {
        $idle = max(0, $breakMinutes - max(0, $breakAllowanceMinutes));
        $denominator = max(1, $expectedMinutes);

        return [
            'expected' => $expectedMinutes,
            'worked' => $workedMinutes,
            'break' => $breakMinutes,
            'idle' => $idle,
            'overtime' => max(0, $approvedOtMinutes),
            'beyond_shift' => max(0, $workedMinutes - $expectedMinutes),
            'net' => $workedMinutes,
            'remaining' => max(0, $expectedMinutes - $workedMinutes),
            'worked_pct' => (int) round(min(100, $workedMinutes / $denominator * 100)),
            'break_pct' => (int) round(min(100, $breakMinutes / $denominator * 100)),
            'idle_pct' => (int) round(min(100, $idle / $denominator * 100)),
        ];
    }

    /** The canonical empty payload for a day with no punches. */
    public function emptyResult(): array
    {
        return [
            'nodes' => [], 'sessions' => [], 'raw_events' => [], 'first_in' => null, 'last_out' => null,
            'first_in_at' => null, 'last_out_at' => null,
            'raw_count' => 0, 'kept_count' => 0, 'duplicate_count' => 0, 'conflict_count' => 0,
            'working_minutes' => 0, 'break_minutes' => 0, 'session_count' => 0,
            'live' => false, 'live_start_ms' => null, 'live_start_label' => null,
            'live_elapsed_minutes' => 0, 'missing_out' => false, 'needs_regularization' => false,
            'ignored' => [], 'ignored_count' => 0,
            'flags' => ['duplicate' => 0, 'retry' => 0, 'stray' => 0, 'missing_out' => false, 'missing_checkout' => false, 'regularised' => false, 'impossible_duration' => false, 'other_day' => 0, 'direction_corrected' => 0],
        ];
    }
}
