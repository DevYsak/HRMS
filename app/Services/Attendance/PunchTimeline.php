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
 *  - Direction: the engine's real IN/OUT tag when present; otherwise inferred
 *    by alternation across the kept punches.
 *  - Missing punches: a same-direction pair beyond the merge window
 *    (IN→IN = missing OUT, OUT→OUT = missing IN) or a trailing IN on a past
 *    day. Never auto-fixed — flagged for regularization.
 *  - Totals: working hours / break / session figures come ONLY from validated
 *    sessions; a synced engine summary overrides the headline totals.
 */
class PunchTimeline
{
    /**
     * Two punches within this window are the same physical action recorded
     * twice (a repeated read, a Face+Card re-verify, or a reader flip-flop that
     * logs one tap as both an IN and an OUT edge). One is kept, the other merged.
     */
    public const MERGE_WINDOW_SECONDS = 60;

    /**
     * Process one day's raw punches into the canonical timeline payload.
     *
     * @param  Collection<int, AttendancePunch>  $raw
     * @return array<string, mixed>
     */
    public function process(Collection $raw, Carbon $day, ?AttendanceDailySummary $summary = null): array
    {
        if ($raw->isEmpty()) {
            return $this->emptyResult();
        }

        $ordered = $raw->sortBy('punched_at')->values();
        [$kept, $flags, $duplicateCount, $conflictCount] = $this->mergeNoise($ordered);

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

        return $this->assemble($kept, $directions, $stray, $ordered->count(), $rawEvents, $duplicateCount, $conflictCount, $day, $summary);
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

        $ordered = $punches->sortBy('punched_at')->values();
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
     * Collapse device noise into kept punches and annotate EVERY raw punch for
     * the HR/admin audit view. Two punches within the merge window are the same
     * physical action recorded twice — KEEP EXACTLY ONE, never both, never none:
     *
     *  - Same direction (or same method) → a duplicate read; keep the first.
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

                // Same direction within the window → a duplicate read (or a
                // Face+Card re-verify of the same edge when the method differs).
                if (! $opposite) {
                    if ($sameMethod) {
                        // Rule 2 — a Face reader firing several times in a burst.
                        // Keep the LATEST punch; the earlier reads are the echoes.
                        $duplicates++;
                        $kept->pop();
                        $flag[spl_object_id($prev)] = ['duplicate', 'Duplicate read — superseded by the '.$p->punched_at->format('h:i:s A').' punch'];
                        $kept->push($p);
                        $flag[spl_object_id($p)] = ['kept', null];
                    } else {
                        // A Face+Card / Face+Fingerprint re-verify of one edge —
                        // keep the first, merge the second in.
                        $conflicts++;
                        $flag[spl_object_id($p)] = ['retry', 'Authentication retry — merged into the '.$prev->punched_at->format('h:i:s A').' punch'];
                    }

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
                ];
            } else {
                $sessions[] = $this->incompleteSession(count($sessions) + 1, $openIn, null);
            }
        }

        $workingMinutes += $liveElapsed;

        // Working / break come only from these validated sessions. The engine's
        // synced summary is NOT trusted for totals — on this device it mis-pairs
        // (Face punches tagged OUT), reporting e.g. 21m worked / 191m break for a
        // day these sessions correctly total to 4h+.
        $lastOut = $lastOutIndex !== null ? $kept->get($lastOutIndex) : null;

        return [
            'nodes' => $nodes,
            'sessions' => $sessions,
            'raw_events' => $rawEvents,
            'first_in' => $kept->first()->punched_at->format('h:i A'),
            'last_out' => (! $trailingIn && $lastOut) ? $lastOut->punched_at->format('h:i A') : null,
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
            'type' => 'missing', 'method' => null, 'method_label' => null,
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
        return [
            'time' => $p->punched_at->format('h:i:s A'),
            // The resolved direction (method-derived), so a Face punch never
            // shows a misleading "OUT" the audit view would then keep.
            'direction' => $this->effectiveDirection($p),
            'method' => $p->methodEnum()?->label() ?? ucfirst((string) $p->method) ?: null,
            'method_icon' => $p->methodEnum()?->icon() ?? 'clock',
            'device' => $p->device_serial,
            'source' => $p->source,
            'verify' => $p->verify_raw,
            'flag' => $flag,                       // kept | duplicate | retry
            'note' => $note,
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
     * - net    = worked (session minutes are already net of the between-session gaps)
     * - idle   = break time beyond the shift's paid allowance (the "over limit" figure)
     * - overtime / remaining = worked measured against the expected day
     *
     * @return array{expected:int, worked:int, break:int, idle:int, overtime:int, net:int, remaining:int, worked_pct:int, break_pct:int, idle_pct:int}
     */
    public function hoursBreakdown(int $workedMinutes, int $breakMinutes, int $expectedMinutes, int $breakAllowanceMinutes = 0): array
    {
        $idle = max(0, $breakMinutes - max(0, $breakAllowanceMinutes));
        $denominator = max(1, $expectedMinutes);

        return [
            'expected' => $expectedMinutes,
            'worked' => $workedMinutes,
            'break' => $breakMinutes,
            'idle' => $idle,
            'overtime' => max(0, $workedMinutes - $expectedMinutes),
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
            'raw_count' => 0, 'kept_count' => 0, 'duplicate_count' => 0, 'conflict_count' => 0,
            'working_minutes' => 0, 'break_minutes' => 0, 'session_count' => 0,
            'live' => false, 'live_start_ms' => null, 'live_start_label' => null,
            'live_elapsed_minutes' => 0, 'missing_out' => false, 'needs_regularization' => false,
            'ignored' => [], 'ignored_count' => 0,
        ];
    }
}
